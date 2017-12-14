<?php
session_start();
/*
 * FCKeditor - The text editor for Internet - http://www.fckeditor.net
 * Copyright (C) 2003-2009 Frederico Caldeira Knabben

 * modify by กรกฎ วิริยะ http://www.goragod.com 15 กย. 2553
 * สำหรับการใช้งาน multi user
 * และการจัดการ path และ ไดเร็คทอรี่ต่างๆ

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
 * Configuration file for the File Manager Connector for PHP.
 */
// load Kotchasan
include '../../../../load.php';
// Initial Kotchasan Framework
Kotchasan::createWebApplication();
// ตรวจสอบการ login สำหรับสมาชิกเท่านั้น
$config['Enabled'] = Kotchasan\CKEditor::enabledUpload();
// กำหนดการอัปโหลดไฟล์โดยใช้ชื่อเดิม หรือเป็นตัวเลข (เวลา)
// true ใช้ชื่อเดิมของไฟล์ (rename ชื่อซ้ำ)
// false ใช้ชื่อไฟล์เป็นเวลา (mktime)
$config['UploadOrginalFilename'] = false;
// โฟลเดอร์ ที่เก็บไฟล์
$config['UserFilesPath'] = DATA_FOLDER;
// path ที่เก็บไฟล์ตั้งแต่ root ของ Server
$config['UserFilesAbsolutePath'] = ROOT_PATH.DATA_FOLDER;
// Due to security issues with Apache modules, it is recommended to leave the
// following setting enabled.
$config['ForceSingleExtension'] = true;
// Perform additional checks for image files.
// If set to true, validate image size (using getimagesize).
$config['SecureImageUploads'] = true;
// What the user can do with this connector.
$config['ConfigAllowedCommands'] = array('QuickUpload', 'FileUpload', 'GetFolders', 'GetFoldersAndFiles', 'CreateFolder');
// Allowed Resource Types.
$config['ConfigAllowedTypes'] = array('File', 'Image', 'Flash', 'Media');
// For security, HTML is allowed in the first Kb of data for files having the
// following extensions only.
$config['HtmlExtensions'] = array("html", "htm", "xml", "xsd", "txt", "js");
// After file is uploaded, sometimes it is required to change its permissions
// so that it was possible to access it at the later time.
// If possible, it is recommended to set more restrictive permissions, like 0755.
// Set to 0 to disable this feature.
// Note: not needed on Windows-based servers.
$config['ChmodOnUpload'] = 0755;
// See comments above.
// Used when creating folders that does not exist.
$config['ChmodOnFolderCreate'] = 0755;
/*
  Configuration settings for each Resource Type

  - AllowedExtensions: the possible extensions that can be allowed.
  If it is empty then any file type can be uploaded.
  - DeniedExtensions: The extensions that won't be allowed.
  If it is empty then no restrictions are done here.

  For a file to be uploaded it has to fulfill both the AllowedExtensions
  and DeniedExtensions (that's it: not being denied) conditions.

  - FileTypesPath: the virtual folder relative to the document root where
  these resources will be located.
  Attention: It must start and end with a slash: '/'

  - FileTypesAbsolutePath: the physical path to the above folder. It must be
  an absolute path.
  If it's an empty string then it will be autocalculated.
  Useful if you are using a virtual directory, symbolic link or alias.
  Examples: 'C:\\MySite\\userfiles\\' or '/root/mysite/userfiles/'.
  Attention: The above 'FileTypesPath' must point to the same directory.
  Attention: It must end with a slash: '/'

  - QuickUploadPath: the virtual folder relative to the document root where
  these resources will be uploaded using the Upload tab in the resources
  dialogs.
  Attention: It must start and end with a slash: '/'

  - QuickUploadAbsolutePath: the physical path to the above folder. It must be
  an absolute path.
  If it's an empty string then it will be autocalculated.
  Useful if you are using a virtual directory, symbolic link or alias.
  Examples: 'C:\\MySite\\userfiles\\' or '/root/mysite/userfiles/'.
  Attention: The above 'QuickUploadPath' must point to the same directory.
  Attention: It must end with a slash: '/'

  NOTE: by default, QuickUploadPath and QuickUploadAbsolutePath point to
  "userfiles" directory to maintain backwards compatibility with older versions of FCKeditor.
  This is fine, but you in some cases you will be not able to browse uploaded files using file browser.
  Example: if you click on "image button", select "Upload" tab and send image
  to the server, image will appear in FCKeditor correctly, but because it is placed
  directly in /userfiles/ directory, you'll be not able to see it in built-in file browser.
  The more expected behaviour would be to send images directly to "image" subfolder.
  To achieve that, simply change
  $config['QuickUploadPath']['Image']			= $config['UserFilesPath'] ;
  $config['QuickUploadAbsolutePath']['Image']	= $config['UserFilesAbsolutePath'] ;
  into:
  $config['QuickUploadPath']['Image']			= $config['FileTypesPath']['Image'] ;
  $config['QuickUploadAbsolutePath']['Image'] 	= $config['FileTypesAbsolutePath']['Image'] ;

 */
// Thumbnail
$onfig['ImageThumbnail']['Folder'] = $config['UserFilesAbsolutePath'].'thumb/';
$onfig['ImageThumbnail']['Size'] = 100;
// ไฟล์อัปโหลด
$config['AllowedExtensions']['File'] = array('7z', 'aiff', 'asf', 'avi', 'bmp', 'csv', 'doc', 'fla', 'flv', 'gif', 'gz', 'gzip', 'jpeg', 'jpg', 'mid', 'mov', 'mp3', 'mp4', 'mpc', 'mpeg', 'mpg', 'ods', 'odt', 'pdf', 'png', 'ppt', 'pxd', 'qt', 'ram', 'rar', 'rm', 'rmi', 'rmvb', 'rtf', 'sdc', 'sitd', 'swf',
	'sxc', 'sxw', 'tar', 'tgz', 'tif', 'tiff', 'txt', 'vsd', 'wav', 'wma', 'wmv', 'xls', 'xml', 'zip');
$config['DeniedExtensions']['File'] = array();
$config['FileTypesPath']['File'] = $config['UserFilesPath'].'file/';
$config['FileTypesAbsolutePath']['File'] = ($config['UserFilesAbsolutePath'] == '') ? '' : $config['UserFilesAbsolutePath'].'file/';
$config['QuickUploadPath']['File'] = $config['UserFilesPath'].'file/';
$config['QuickUploadAbsolutePath']['File'] = $config['UserFilesAbsolutePath'].'file/';
// รูปภาพอัปโหลด
$config['AllowedExtensions']['Image'] = array('gif', 'jpeg', 'jpg', 'png');
$config['DeniedExtensions']['Image'] = array();
$config['FileTypesPath']['Image'] = $config['UserFilesPath'].'image/';
$config['FileTypesAbsolutePath']['Image'] = ($config['UserFilesAbsolutePath'] == '') ? '' : $config['UserFilesAbsolutePath'].'image/';
$config['QuickUploadPath']['Image'] = $config['UserFilesPath'].'image/';
$config['QuickUploadAbsolutePath']['Image'] = $config['UserFilesAbsolutePath'].'image/';
// แฟลชอัปโหลด
$config['AllowedExtensions']['Flash'] = array('swf', 'flv');
$config['DeniedExtensions']['Flash'] = array();
$config['FileTypesPath']['Flash'] = $config['UserFilesPath'].'flash/';
$config['FileTypesAbsolutePath']['Flash'] = ($config['UserFilesAbsolutePath'] == '') ? '' : $config['UserFilesAbsolutePath'].'flash/';
$config['QuickUploadPath']['Flash'] = $config['UserFilesPath'].'flash/';
$config['QuickUploadAbsolutePath']['Flash'] = $config['UserFilesAbsolutePath'].'flash/';
// มีเดียอัปโหลด
$config['AllowedExtensions']['Media'] = array('aiff', 'asf', 'avi', 'bmp', 'fla', 'flv', 'gif', 'jpeg', 'jpg', 'mid', 'mov', 'mp3', 'mp4', 'mpc', 'mpeg', 'mpg', 'png', 'qt', 'ram', 'rm', 'rmi', 'rmvb', 'swf', 'tif', 'tiff', 'wav', 'wma', 'wmv');
$config['DeniedExtensions']['Media'] = array();
$config['FileTypesPath']['Media'] = $config['UserFilesPath'].'media/';
$config['FileTypesAbsolutePath']['Media'] = ($config['UserFilesAbsolutePath'] == '') ? '' : $config['UserFilesAbsolutePath'].'media/';
$config['QuickUploadPath']['Media'] = $config['UserFilesPath'].'media/';
$config['QuickUploadAbsolutePath']['Media'] = $config['UserFilesAbsolutePath'].'media/';
