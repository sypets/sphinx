<?php
namespace Causal\Sphinx\Utility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use \TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use \TYPO3\CMS\Core\Utility\GeneralUtility;
use \TYPO3\CMS\Core\Utility\CommandUtility;

/**
 * Sphinx environment setup.
 *
 * @category    Utility
 * @package     TYPO3
 * @subpackage  tx_sphinx
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class Setup {

	/** @var string */
	static protected $extKey = 'sphinx';

	/** @var array */
	static protected $log = array();

	/**
	 * Initializes the environment by creating directories to hold sphinx and 3rd
	 * party tools.
	 *
	 * @return array Error messages, if any
	 */
	static public function createLibraryDirectories() {
		$errors = array();

		if (!CommandUtility::checkCommand('python')) {
			$errors[] = 'Python interpreter was not found.';
		}
		if (!CommandUtility::checkCommand('unzip')) {
			$errors[] = 'Unzip cannot be executed.';
		}
		if (!CommandUtility::checkCommand('tar')) {
			$errors[] = 'Tar cannot be executed.';
		}

		$directories = array(
			'Resources/Private/sphinx/',
			'Resources/Private/sphinx/bin/',
			'Resources/Private/sphinx-sources/',
		);
		$basePath = ExtensionManagementUtility::extPath(self::$extKey);
		foreach ($directories as $directory) {
			if (!is_dir($basePath . $directory)) {
				GeneralUtility::mkdir_deep($basePath, $directory);
			}
			if (is_dir($basePath . $directory)) {
				if (!is_writable($basePath . $directory)) {
					$errors[] = 'Directory ' . $basePath . $directory . ' is read-only.';
				}
			} else {
				$errors[] = 'Cannot create directory ' . $basePath . $directory . '.';
			}
		}

		return $errors;
	}

	/**
	 * Returns TRUE if the source files of Sphinx are available locally.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @return boolean
	 */
	static public function hasSphinxSources($version) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . $version . '/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Sphinx.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @param string $url Complete URL of the zip file containing the sphinx sources
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see https://bitbucket.org/birkenfeld/sphinx/
	 */
	static public function downloadSphinxSources($version, $url, array &$output = NULL) {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		$zipFilename = $tempPath . $version . '.zip';
		self::$log[] = '[INFO] Fetching ' . $url;
		$zipContent = GeneralUtility::getUrl($url);
		if ($zipContent && GeneralUtility::writeFile($zipFilename, $zipContent)) {
			$output[] = '[INFO] Sphinx ' . $version . ' has been downloaded.';
			$targetPath = $sphinxSourcesPath . $version;

			// Unzip the Sphinx archive
			$out = array();
			if (self::unarchive($zipFilename, $targetPath, 'birkenfeld-sphinx-')) {
				$output[] = '[INFO] Sphinx ' . $version . ' has been unpacked.';

				// Patch Sphinx to let us get colored output
				$sourceFilename = $targetPath . '/sphinx/util/console.py';

				// Compatibility with Windows platform
				$sourceFilename = str_replace('/', DIRECTORY_SEPARATOR, $sourceFilename);

				if (file_exists($sourceFilename)) {
					self::$log[] = '[INFO] Patching file ' . $sourceFilename;
					$contents = file_get_contents($sourceFilename);
					$contents = str_replace(
						'def color_terminal():',
						"def color_terminal():\n    if 'COLORTERM' in os.environ:\n        return True",
						$contents
					);
					GeneralUtility::writeFile($sourceFilename, $contents);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not extract Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Cannot fetch file ' . $url . '.';
		}

		return $success;
	}

	/**
	 * Builds and installs Sphinx locally.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildSphinx($version, array &$output = NULL) {
		$success = TRUE;
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = NULL;
		$pythonLib = NULL;
		$setupFile = $sphinxSourcesPath . $version . DIRECTORY_SEPARATOR . 'setup.py';

		if (is_file($setupFile)) {
			$python = escapeshellarg(CommandUtility::getCommand('python'));
			$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
				$python . ' setup.py clean 2>&1 && ' .
				$python . ' setup.py build 2>&1';
			$out = array();
			self::exec($cmd, $out, $ret);
			if ($ret === 0) {
				$pythonHome = $sphinxPath . $version;
				$pythonLib = $pythonHome . '/lib/python';

				// Compatibility with Windows platform
				$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

				self::$log[] = '[INFO] Recreating directory ' . $pythonHome;
				GeneralUtility::rmdir($pythonHome, TRUE);
				GeneralUtility::mkdir_deep($pythonLib . DIRECTORY_SEPARATOR);

				$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
					\Causal\Sphinx\Utility\GeneralUtility::getExportCommand('PYTHONPATH', $pythonLib) . ' && ' .
					$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
				$out = array();
				self::exec($cmd, $out, $ret);
				if ($ret === 0) {
					$output[] = '[OK] Sphinx ' . $version . ' has been successfully installed.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not install Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not build Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		if ($success) {
			$shortcutScripts = array(
				'sphinx-build',
				'sphinx-quickstart',
			);
			$pythonPath = $sphinxPath . $version . '/lib/python';

			// Compatibility with Windows platform
			$pythonPath = str_replace('/', DIRECTORY_SEPARATOR, $pythonPath);

			foreach ($shortcutScripts as $shortcutScript) {
				$shortcutFilename = $sphinxPath . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript . '-' . $version;
				$scriptFilename = $sphinxPath . $version . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript;

				if (TYPO3_OS === 'WIN') {
					$shortcutFilename .= '.bat';
					$scriptFilename .= '.exe';

					$script = <<<EOT
@ECHO OFF
SET PYTHONPATH=$pythonPath

$scriptFilename %*
EOT;
					// Use CRLF under Windows
					$script = str_replace(CR, LF, $script);
					$script = str_replace(LF, CR . LF, $script);
				} else {
					$script = <<<EOT
#!/bin/bash

export PYTHONPATH=$pythonPath

$scriptFilename "\$@"
EOT;
				}

				GeneralUtility::writeFile($shortcutFilename, $script);
				chmod($shortcutFilename, 0755);
			}
		}

		return $success;
	}

	/**
	 * Removes a local version of Sphinx (sources + build).
	 *
	 * @param string $version
	 * @param NULL|array $output
	 * @return void
	 */
	static public function removeSphinx($version, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		if (is_dir($sphinxSourcesPath . $version)) {
			if (GeneralUtility::rmdir($sphinxSourcesPath . $version, TRUE)) {
				$output[] = '[OK] Sources of Sphinx ' . $version . ' have been deleted.';
			} else {
				$output[] = '[ERROR] Could not delete sources of Sphinx ' . $version . '.';
			}
		}
		if (is_dir($sphinxPath . $version)) {
			if (GeneralUtility::rmdir($sphinxPath . $version, TRUE)) {
				$output[] = '[OK] Sphinx ' . $version . ' has been deleted.';
			} else {
				$output[] = '[ERROR] Could not delete Sphinx ' . $version . '.';
			}
		}

		$shortcutScripts = array(
			'sphinx-build-' . $version,
			'sphinx-quickstart-' . $version,
		);
		foreach ($shortcutScripts as $shortcutScript) {
			$shortcutFilename = $sphinxPath . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript;

			if (TYPO3_OS === 'WIN') {
				$shortcutFilename .= '.bat';
			}

			if (is_file($shortcutFilename)) {
				@unlink($shortcutFilename);
			}
		}
	}

	/**
	 * Returns TRUE if the source files of the TYPO3 ReST tools are available locally.
	 *
	 * @return boolean
	 */
	static public function hasRestTools() {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of the TYPO3 ReST tools.
	 *
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://forge.typo3.org/projects/tools-rest
	 */
	static public function downloadRestTools(array &$output = NULL) {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('tar')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command tar. TYPO3-related commands were not installed.';
		} else {
			$url = 'https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3';
			/** @var $http \TYPO3\CMS\Core\Http\HttpRequest */
			$http = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Http\HttpRequest', $url);
			self::$log[] = '[INFO] Fetching ' . $url;
			$body = $http->send()->getBody();
			if (preg_match('#<a .*?href="/Documentation/RestTools\.git/snapshot/([0-9a-f]+)\.tar\.gz">snapshot</a>#', $body, $matches)) {
				$commit = $matches[1];
				$url = 'https://git.typo3.org/Documentation/RestTools.git/snapshot/' . $commit . '.tar.gz';
				$archiveFilename = $tempPath . 'RestTools.tar.gz';
				self::$log[] = '[INFO] Fetching ' . $url;
				$archiveContent = $http->setUrl($url)->send()->getBody();
				if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
					$output[] = '[INFO] TYPO3 ReStructuredText Tools (' . $commit . ') have been downloaded.';

					// Target path is compatible with directory structure of complete git project
					// allowing people to use the official git repository instead, if wanted
					$targetPath = $sphinxSourcesPath . 'RestTools' . DIRECTORY_SEPARATOR . 'ExtendingSphinxForTYPO3';

					// Unpack TYPO3 ReST Tools archive
					$out = array();
					if (self::unarchive($archiveFilename, $targetPath, 'RestTools-' . substr($commit, 0, 7), $out)) {
						$output[] = '[INFO] TYPO3 ReStructuredText Tools have been unpacked.';
					} else {
						$success = FALSE;
						$output[] = '[ERROR] Could not extract TYPO3 ReStructuredText Tools:' . LF . LF . implode($out, LF);
					}
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars('https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3');
			}
		}

		return $success;
	}

	/**
	 * Builds and installs TYPO3 ReST tools locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build the ReST tools for
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildRestTools($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		// Patch RestTools to support rst2pdf. We do it here and not after downloading
		// to let user build RestTools with Git repository as well
		// @see http://forge.typo3.org/issues/49341
		$globalSettingsFilename = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/src/t3sphinx/settings/GlobalSettings.yml';

		// Compatibility with Windows platform
		$globalSettingsFilename = str_replace('/', DIRECTORY_SEPARATOR, $globalSettingsFilename);
		$isPatched = FALSE;

		if (TYPO3_OS !== 'WIN' && \Causal\Sphinx\Utility\Setup::hasLibrary('rst2pdf', $sphinxVersion)) {
			if (is_file($globalSettingsFilename)) {
				$globalSettings = file_get_contents($globalSettingsFilename);
				$rst2pdfLibrary = 'rst2pdf.pdfbuilder';
				$isPatched = strpos($globalSettings, '- ' . $rst2pdfLibrary) !== FALSE;

				if (!$isPatched && is_writable($globalSettingsFilename)) {
					if (strpos($globalSettings, '- ' . $rst2pdfLibrary) === FALSE) {
						$globalSettingsLines = explode(LF, $globalSettings);
						$buffer = array();
						for ($i = 0; $i < count($globalSettingsLines); $i++) {
							if (trim($globalSettingsLines[$i]) === 'extensions:') {
								while (!empty($globalSettingsLines[$i])) {
									$buffer[] = $globalSettingsLines[$i];
									$i++;
								};
								$buffer[] = '  - ' . $rst2pdfLibrary;
							}
							$buffer[] = $globalSettingsLines[$i];
						}
						$isPatched = GeneralUtility::writeFile($globalSettingsFilename, implode(LF, $buffer));
					}
				}
			} else {
				// Should not happen
				$output[] = '[ERROR] Could not find file "' . $globalSettingsFilename . '".';
			}
		}

		if (!$isPatched) {
			$output[] = '[WARNING] Could not patch file "' . $globalSettingsFilename .
				'". Please check file permissions. rst2pdf may fail to run properly with error message "Builder name pdf not registered".';
		}

		$setupFile = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/setup.py';

		// Compatibility with Windows platform
		$setupFile = str_replace('/', DIRECTORY_SEPARATOR, $setupFile);

		if (is_file($setupFile)) {
			$success = self::buildWithPython(
				'TYPO3 RestructuredText Tools',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of PyYAML are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPyYaml() {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'PyYAML/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of PyYAML.
	 *
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://pyyaml.org/
	 */
	static public function downloadPyYaml(array &$output = NULL) {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('tar')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command tar. PyYAML was not installed.';
		} else {
			$url = 'http://pyyaml.org/download/pyyaml/PyYAML-3.10.tar.gz';
			$archiveFilename = $tempPath . 'PyYAML-3.10.tar.gz';
			$archiveContent = GeneralUtility::getUrl($url);
			if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$output[] = '[INFO] PyYAML 3.10 has been downloaded.';

				$targetPath = $sphinxSourcesPath . 'PyYAML';

				// Unpack PyYAML archive
				$out = array();
				if (self::unarchive($archiveFilename, $targetPath, 'PyYAML-3.10', $out)) {
					$output[] = '[INFO] PyYAML has been unpacked.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not extract PyYAML:' . LF . LF . implode($out, LF);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
			}
		}

		return $success;
	}

	/**
	 * Builds and installs PyYAML locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build PyYAML for
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPyYaml($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'PyYAML' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = self::buildWithPython(
				'PyYAML',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of Python Imaging Library are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPIL() {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'Imaging/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Python Imaging Library.
	 *
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see https://pypi.python.org/pypi/PIL
	 */
	static public function downloadPIL(array &$output = NULL) {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('tar')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command tar. Python Imaging Library was not installed.';
		} else {
			$url = 'http://effbot.org/media/downloads/Imaging-1.1.7.tar.gz';
			$archiveFilename = $tempPath . 'Imaging-1.1.7.tar.gz';
			$archiveContent = GeneralUtility::getUrl($url);
			if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$output[] = '[INFO] Python Imaging Library 1.1.7 has been downloaded.';

				$targetPath = $sphinxSourcesPath . 'Imaging';

				// Unpack Python Imaging Library archive
				$out = array();
				if (self::unarchive($archiveFilename, $targetPath, 'Imaging-1.1.7', $out)) {
					$output[] = '[INFO] Python Imaging Library has been unpacked.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Unknown structure in archive ' . $archiveFilename;
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
			}
		}

		return $success;
	}

	/**
	 * Builds and installs Python Imaging Library locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build Python Imaging Library for
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPIL($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'Imaging' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = self::buildWithPython(
				'Python Imaging Library',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of Pygments are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPygments() {
		$sphinxSourcePath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcePath . 'Pygments/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Pygments.
	 *
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://pygments.org/
	 */
	static public function downloadPygments() {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('tar')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command tar. Pygments was not installed.';
		} else {
			$url = 'https://bitbucket.org/birkenfeld/pygments-main/get/1.6.tar.gz';
			$archiveFilename = $tempPath . 'pygments-1.6.tar.gz';
			$archiveContent = GeneralUtility::getUrl($url);
			if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$output[] = '[INFO] Pygments 1.6 has been downloaded.';

				$targetPath = $sphinxSourcesPath . 'Pygments';

				// Unpack Pygments archive
				$out = array();
				if (self::unarchive($archiveFilename, $targetPath, 'birkenfeld-pygments-main-', $out)) {
					$output[] = '[INFO] Pygments has been unpacked.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Unknown structure in archive ' . $archiveFilename;
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
			}
		}

		return $success;
	}

	/**
	 * Builds and installs Pygments locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build Pygments for
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPygments($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'Pygments' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			self::configureTyposcriptForPygments($output);

			$success = self::buildWithPython(
				'Pygments',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Configures TypoScript support for Pygments.
	 *
	 * @param NULL|array $output
	 * @return void
	 */
	static private function configureTyposcriptForPygments(array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$lexersPath = $sphinxSourcesPath . 'Pygments' . DIRECTORY_SEPARATOR . 'pygments' . DIRECTORY_SEPARATOR . 'lexers' . DIRECTORY_SEPARATOR;

		$url = 'https://git.typo3.org/Documentation/RestTools.git/blob_plain/HEAD:/ExtendingPygmentsForTYPO3/_incoming/typoscript.py';
		$libraryFilename = $lexersPath . 'typoscript.py';
		$libraryContent = GeneralUtility::getUrl($url);

		if ($libraryContent) {
			if (!is_file($libraryFilename) || md5_file($libraryFilename) !== md5($libraryContent)) {
				if (GeneralUtility::writeFile($libraryFilename, $libraryContent)) {
					$output[] = '[OK] TypoScript library for Pygments successfully downloaded/updated.';
				}
			}
			if (is_file($libraryFilename)) {
				// Update the list of Pygments lexers
				$python = escapeshellarg(CommandUtility::getCommand('python'));
				$cmd = 'cd ' . escapeshellarg($lexersPath) . ' && ' .
					$python . ' _mapping.py 2>&1';
				$out = array();
				self::exec($cmd, $out, $ret);
				if ($ret === 0) {
					$output[] = '[OK] TypoScript library successfully registered with Pygments.';
				} else {
					$output[] = '[WARNING] Could not install TypoScript library for Pygments.';
				}
			}
		}
	}

	/**
	 * Returns TRUE if the source files of rst2pdf are available locally.
	 *
	 * @return boolean
	 */
	static public function hasRst2Pdf() {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'rst2pdf/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of rst2pdf.
	 *
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://rst2pdf.ralsina.com.ar/
	 */
	static public function downloadRst2Pdf(array &$output = NULL) {
		$success = TRUE;
		$tempPath = self::getTemporaryPath();
		$sphinxSourcesPath = self::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('tar')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command tar. rst2pdf was not installed.';
		} else {
			$url = 'http://rst2pdf.googlecode.com/files/rst2pdf-0.93.tar.gz';
			$archiveFilename = $tempPath . 'rst2pdf-0.93.tar.gz';
			$archiveContent = GeneralUtility::getUrl($url);
			if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$output[] = '[INFO] rst2pdf 0.93 has been downloaded.';

				$targetPath = $sphinxSourcesPath . 'rst2pdf';

				// Unpack rst2pdf archive
				$out = array();
				if (self::unarchive($archiveFilename, $targetPath, 'rst2pdf-0.93', $out)) {
					$output[] = '[INFO] rst2pdf has been unpacked.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not extract rst2pdf:' . LF . LF . implode($out, LF);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
			}
		}

		return $success;
	}

	/**
	 * Builds and installs rst2pdf locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build rst2pdf for
	 * @param NULL|array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildRst2Pdf($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = self::getSphinxSourcesPath();
		$sphinxPath = self::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'rst2pdf' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = self::buildWithPython(
				'rst2pdf',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if a given Python library is present (installed).
	 *
	 * @param string $library Name of the library (without version)
	 * @param string $sphinxVersion The Sphinx version to check for
	 * @return boolean
	 */
	static public function hasLibrary($library, $sphinxVersion) {
		$sphinxPath = self::getSphinxPath();
		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		$directories = GeneralUtility::get_dirs($pythonLib);
		foreach ($directories as $directory) {
			if (GeneralUtility::isFirstPartOfStr($directory, $library . '-')) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Returns a list of online available versions of Sphinx.
	 * Please note: all versions older than 1.0 are automatically discarded
	 * as they are most probably of absolutely no use.
	 *
	 * @return array
	 */
	static public function getSphinxAvailableVersions() {
		$sphinxUrl = 'https://bitbucket.org/birkenfeld/sphinx/downloads';

		$cacheFilename = self::getTemporaryPath() . self::$extKey . '.' . md5($sphinxUrl) . '.html';
		if (!file_exists($cacheFilename) || filemtime($cacheFilename) < (time() - 86400) || filesize($cacheFilename) == 0) {
			$html = GeneralUtility::getURL($sphinxUrl);
			GeneralUtility::writeFile($cacheFilename, $html);
		} else {
			$html = file_get_contents($cacheFilename);
		}

		$tagsHtml = substr($html, strpos($html, '<section class="tabs-pane" id="tag-downloads">'));
		$tagsHtml = substr($tagsHtml, 0, strpos($tagsHtml, '</section>'));

		$versions = array();
		preg_replace_callback(
			'#<tr class="iterable-item">.*?<td class="name"><a href="[^>]+>([^<]*)</a></td>.*?<a href="([^"]+)">zip</a>#s',
			function($matches) use (&$versions) {
				if ($matches[1] !== 'tip' && version_compare($matches[1], '1.0', '>=')) {
					$versions[$matches[1]] = array(
						'name' => $matches[1],
						'url'  => $matches[2],
					);
				}
			},
			$tagsHtml
		);

		krsort($versions);
		return $versions;
	}

	/**
	 * Returns a list of locally available versions of Sphinx.
	 *
	 * @return array
	 */
	static public function getSphinxLocalVersions() {
		$sphinxPath = self::getSphinxPath();
		$versions = array();
		if (is_dir($sphinxPath)) {
			$versions = GeneralUtility::get_dirs($sphinxPath);
		}
		return $versions;
	}

	/**
	 * Logs and executes a command.
	 *
	 * @param string $cmd
	 * @param NULL|array $output
	 * @param integer $returnValue
	 * @return NULL|array
	 */
	static protected function exec($cmd, &$output = NULL, &$returnValue = 0) {
		self::$log[] = '[CMD] ' . $cmd;
		$lastLine = CommandUtility::exec($cmd, $out, $returnValue);
		self::$log = array_merge(self::$log, $out);
		$output = $out;
		return $lastLine;
	}

	/**
	 * Untars/Unzips an archive into a given target directory.
	 *
	 * @param string $archiveFilename
	 * @param string $targetDirectory
	 * @param string|NULL $moveContentOutsideOfDirectoryPrefix
	 * @param array &$out
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 */
	static public function unarchive($archiveFilename, $targetDirectory, $moveContentOutsideOfDirectoryPrefix = NULL, array &$out = NULL) {
		$success = FALSE;

		self::$log[] = '[INFO] Recreating directory ' . $targetDirectory;
		GeneralUtility::rmdir($targetDirectory, TRUE);
		GeneralUtility::mkdir_deep($targetDirectory . DIRECTORY_SEPARATOR);

		if (substr($archiveFilename, -4) === '.zip') {
			$unzip = escapeshellarg(CommandUtility::getCommand('unzip'));
			$cmd = $unzip . ' ' . escapeshellarg($archiveFilename) . ' -d ' . escapeshellarg($targetDirectory) . ' 2>&1';
		} else {
			$tar = escapeshellarg(CommandUtility::getCommand('tar'));
			$cmd = $tar . ' xzvf ' . escapeshellarg($archiveFilename) . ' -C ' . escapeshellarg($targetDirectory) . ' 2>&1';
		}
		self::exec($cmd, $out, $ret);
		if ($ret === 0) {
			$success = TRUE;
			if ($moveContentOutsideOfDirectoryPrefix !== NULL) {
				// When unpacking the sources, content is located under a directory
				$directories = GeneralUtility::get_dirs($targetDirectory);
				if (GeneralUtility::isFirstPartOfStr($directories[0], $moveContentOutsideOfDirectoryPrefix)) {
					$fromDirectory = $targetDirectory . DIRECTORY_SEPARATOR . $directories[0];
					\Causal\Sphinx\Utility\GeneralUtility::recursiveCopy($fromDirectory, $targetDirectory);
					GeneralUtility::rmdir($fromDirectory, TRUE);

					// Remove tar.gz archive as we don't need it anymore
					@unlink($archiveFilename);
				} else {
					$success = FALSE;
				}
			}
		}

		return $success;
	}

	/**
	 * Builds a library with Python.
	 *
	 * @param string $name
	 * @param string $setupFile
	 * @param string $pythonHome
	 * @param string $pythonLib
	 * @param array $output
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 */
	static protected function buildWithPython($name, $setupFile, $pythonHome, $pythonLib, array &$output = NULL) {
		$python = escapeshellarg(CommandUtility::getCommand('python'));
		$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
			$python . ' setup.py clean 2>&1 && ' .
			$python . ' setup.py build 2>&1';
		$out = array();
		self::exec($cmd, $out, $ret);
		if ($ret === 0) {
			$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
				\Causal\Sphinx\Utility\GeneralUtility::getExportCommand('PYTHONPATH', $pythonLib) . ' && ' .
				$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
			$out = array();
			self::exec($cmd, $out, $ret);
			if ($ret === 0) {
				$success = TRUE;
				$output[] = '[OK] ' . $name . ' successfully installed.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not install ' . $name . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not build ' . $name . ':' . LF . LF . implode($out, LF);
		}

		return $success;
	}

	/**
	 * Clears the log of operations.
	 *
	 * @return void
	 */
	static public function clearLog() {
		self::$log = array();
	}

	/**
	 * Dumps the log of operations.
	 *
	 * @param string $filename If empty, will return the complete log of operations instead of writing it to a file
	 * @return void|string
	 */
	static public function dumpLog($filename = '') {
		$content = implode(LF, self::$log);
		if ($filename) {
			$directory = dirname($filename);
			GeneralUtility::mkdir($directory);
			GeneralUtility::writeFile($filename, $content);
		} else {
			return $content;
		}
	}

	/**
	 * Returns the path to Sphinx sources base directory.
	 *
	 * @return string
	 */
	static private function getSphinxSourcesPath() {
		$sphinxSourcesPath = ExtensionManagementUtility::extPath(self::$extKey) . 'Resources/Private/sphinx-sources/';
		// Compatibility with Windows platform
		$sphinxSourcesPath = str_replace('/', DIRECTORY_SEPARATOR, $sphinxSourcesPath);

		return $sphinxSourcesPath;
	}

	/**
	 * Returns the path to Sphinx binaries.
	 *
	 * @return string
	 */
	static private function getSphinxPath() {
		$sphinxPath = ExtensionManagementUtility::extPath(self::$extKey) . 'Resources/Private/sphinx/';
		// Compatibility with Windows platform
		$sphinxPath = str_replace('/', DIRECTORY_SEPARATOR, $sphinxPath);

		return $sphinxPath;
	}

	/**
	 * Returns the path to the website's temporary directory.
	 *
	 * @return string
	 */
	static private function getTemporaryPath() {
		$temporaryPath = PATH_site . 'typo3temp/';
		// Compatibility with Windows platform
		$temporaryPath = str_replace('/', DIRECTORY_SEPARATOR, $temporaryPath);

		return $temporaryPath;
	}

}

?>