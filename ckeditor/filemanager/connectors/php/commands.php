<?php
	/*
	* FCKeditor - The text editor for Internet - http://www.fckeditor.net
	* Copyright (C) 2003-2009 Frederico Caldeira Knabben
	*
	* == BEGIN LICENSE ==
	*
	* Licensed under the terms of any of the following licenses at your
	* choice:
	*
	* - GNU General Public License Version 2 or later (the "GPL")
	*  http://www.gnu.org/licenses/gpl.html
	*
	* - GNU Lesser General Public License Version 2.1 or later (the "LGPL")
	*  http://www.gnu.org/licenses/lgpl.html
	*
	* - Mozilla Public License Version 1.1 or later (the "MPL")
	*  http://www.mozilla.org/MPL/MPL-1.1.html
	*
	* == END LICENSE ==
	*
	* This is the File Manager Connector for PHP.
	*/
	function GetFolders($resourceType, $currentFolder) {
		// Map the virtual path to the local server path.
		$sServerDir = ServerMapFolder($resourceType, $currentFolder, 'GetFolders');
		// Array that will hold the folders names.
		$aFolders = array();
		$oCurrentFolder = @opendir($sServerDir);
		if ($oCurrentFolder !== false) {
			while ($sFile = readdir($oCurrentFolder)) {
				if ($sFile != '.' && $sFile != '..' && is_dir($sServerDir.$sFile)) {
					$aFolders[] = '<Folder name="'.ConvertToXmlAttribute($sFile).'" />';
				}
			}
			closedir($oCurrentFolder);
		}
		// Open the "Folders" node.
		echo "<Folders>";
		natcasesort($aFolders);
		foreach ($aFolders as $sFolder) {
			echo $sFolder;
		}
		// Close the "Folders" node.
		echo "</Folders>";
	}
	function GetFoldersAndFiles($resourceType, $currentFolder) {
		// Map the virtual path to the local server path.
		$sServerDir = ServerMapFolder($resourceType, $currentFolder, 'GetFoldersAndFiles');
		// Arrays that will hold the folders and files names.
		$aFolders = array();
		$aFiles = array();
		$oCurrentFolder = @opendir($sServerDir);
		if ($oCurrentFolder !== false) {
			while ($sFile = readdir($oCurrentFolder)) {
				if ($sFile != '.' && $sFile != '..') {
					if (is_dir($sServerDir.$sFile)) {
						$aFolders[] = '<Folder name="'.ConvertToXmlAttribute($sFile).'" />';
					} else {
						$iFileSize = @filesize($sServerDir.$sFile);
						if (!$iFileSize) {
							$iFileSize = 0;
						}
						if ($iFileSize > 0) {
							$iFileSize = round($iFileSize / 1024);
							if ($iFileSize < 1) {
								$iFileSize = 1;
							}
						}
						$aFiles[] = '<File name="'.ConvertToXmlAttribute($sFile).'" size="'.$iFileSize.'" />';
					}
				}
			}
			closedir($oCurrentFolder);
		}
		// Send the folders
		natcasesort($aFolders);
		echo '<Folders>';
		foreach ($aFolders as $sFolder) {
			echo $sFolder;
		}
		echo '</Folders>';
		// Send the files
		natcasesort($aFiles);
		echo '<Files>';
		foreach ($aFiles as $sFiles) {
			echo $sFiles;
		}
		echo '</Files>';
	}
	function CreateFolder($resourceType, $currentFolder) {
		if (!isset($_GET)) {
			global $_GET;
		}
		$sErrorNumber = '0';
		$sErrorMsg = '';
		if (isset($_GET['NewFolderName'])) {
			$sNewFolderName = $_GET['NewFolderName'];
			$sNewFolderName = SanitizeFolderName($sNewFolderName);
			if (strpos($sNewFolderName, '..') !== FALSE) {
				$sErrorNumber = '102'; // Invalid folder name.
			} else {
				// Map the virtual path to the local server path of the current folder.
				$sServerDir = ServerMapFolder($resourceType, $currentFolder, 'CreateFolder');
				if (is_writable($sServerDir)) {
					$sServerDir .= $sNewFolderName;
					$sErrorMsg = CreateServerFolder($sServerDir);
					switch ($sErrorMsg) {
						case '':
							$sErrorNumber = '0';
							break;
						case 'Invalid argument':
						case 'No such file or directory':
							$sErrorNumber = '102'; // Path too long.
							break;
						default:
							$sErrorNumber = '110';
							break;
					}
				} else {
					$sErrorNumber = '103';
				}
			}
		} else {
			$sErrorNumber = '102';
		}
		// Create the "Error" node.
		echo '<Error number="'.$sErrorNumber.'" />';
	}
	// Notice the last paramter added to pass the CKEditor callback function
	function FileUpload($resourceType, $currentFolder, $sCommand, $CKEcallback = '') {
		if (!isset($_FILES)) {
			global $_FILES;
		}
		$sErrorNumber = '0';
		$sFileName = '';
		//PATCH to detect a quick file upload.
		if ((isset($_FILES['NewFile']) && !is_null($_FILES['NewFile']['tmp_name'])) || (isset($_FILES['upload']) && !is_null($_FILES['upload']['tmp_name']))) {
			global $config;
			//PATCH to detect a quick file upload.
			$oFile = isset($_FILES['NewFile']) ? $_FILES['NewFile'] : $_FILES['upload'];
			// Map the virtual path to the local server path.
			$sServerDir = ServerMapFolder($resourceType, $currentFolder, $sCommand);
			// Get the uploaded file name.
			$sFileName = $oFile['name'];
			$sFileName = SanitizeFileName($sFileName);
			$sOriginalFileName = $sFileName;
			// Get the extension.
			$sExtension = substr($sFileName, (strrpos($sFileName, '.') + 1));
			$sExtension = strtolower($sExtension);
			if (isset($config['SecureImageUploads'])) {
				if (($isImageValid = IsImageValid($oFile['tmp_name'], $sExtension)) == false) {
					$sErrorNumber = '202';
				}
			}
			if (isset($config['HtmlExtensions'])) {
				if (!IsHtmlExtension($sExtension, $config['HtmlExtensions']) && ($detectHtml = DetectHtml($oFile['tmp_name'])) == true) {
					$sErrorNumber = '202';
				}
			}
			// Check if it is an allowed extension.
			if (!$sErrorNumber && IsAllowedExt($sExtension, $resourceType)) {
				if ($config['UploadOrginalFilename']) {
					// อัปโหลดใช้ชื่อเดิม
					$iCounter = 0;
					while (true) {
						$sFilePath = $sServerDir.$sFileName;
						if (is_file($sFilePath)) {
							$iCounter++;
							$sFileName = RemoveExtension($sOriginalFileName).'('.$iCounter.').'.$sExtension;
							$sErrorNumber = '201';
						} else {
							move_uploaded_file($oFile['tmp_name'], $sFilePath);
							if (is_file($sFilePath)) {
								if (isset($config['ChmodOnUpload']) && !$config['ChmodOnUpload']) {
									break;
								}
								$permissions = 0777;
								if (isset($config['ChmodOnUpload']) && $config['ChmodOnUpload']) {
									$permissions = $config['ChmodOnUpload'];
								}
								$oldumask = umask(0);
								chmod($sFilePath, $permissions);
								umask($oldumask);
							}
							break;
						}
					}
				} else {
					// อัปโหลดโดยใช้เวลาเป็นชื่อไฟล์
					$iCounter = date('U');
					while (true) {
						$sFileName = "$iCounter.$sExtension";
						$sFilePath = $sServerDir.$sFileName;
						if (is_file($sFilePath)) {
							$iCounter++;
							$sFileName = "$iCounter.$sExtension";
						} else {
							move_uploaded_file($oFile['tmp_name'], $sFilePath);
							if (is_file($sFilePath)) {
								if (isset($config['ChmodOnUpload']) && !$config['ChmodOnUpload']) {
									break;
								}
								$permissions = 0777;
								if (isset($config['ChmodOnUpload']) && $config['ChmodOnUpload']) {
									$permissions = $config['ChmodOnUpload'];
								}
								$oldumask = umask(0);
								chmod($sFilePath, $permissions);
								umask($oldumask);
							}
							break;
						}
					}
				}
				if (file_exists($sFilePath)) {
					//previous checks failed, try once again
					if (isset($isImageValid) && $isImageValid == -1 && IsImageValid($sFilePath, $sExtension) == false) {
						@unlink($sFilePath);
						$sErrorNumber = '202';
					} elseif (isset($detectHtml) && $detectHtml == -1 && DetectHtml($sFilePath) == true) {
						@unlink($sFilePath);
						$sErrorNumber = '202';
					}
				}
			} else {
				$sErrorNumber = '202';
			}
		} else {
			$sErrorNumber = '202';
		}
		$sFileUrl = CombinePaths(GetResourceTypePath($resourceType, $sCommand), $currentFolder);
		$sFileUrl = CombinePaths($sFileUrl, $sFileName);
		if ($CKEcallback == '') {
			SendUploadResults($sErrorNumber, $sFileUrl, $sFileName);
		} else {
			//issue the CKEditor Callback
			SendCKEditorResults($sErrorNumber, $CKEcallback, WEB_URL.$sFileUrl, $sFileName);
		}
		exit;
	}
