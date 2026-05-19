<?php
/**
 * FileBrowser Files Model
 * Secure file operations with path traversal protection
 * Standalone version - no framework dependency
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
class FileBrowserFiles
{
    /**
     * Configuration
     */
    private $config = [
        'baseDir' => '', // Absolute path to upload directory
        'webUrl' => '', // Web URL prefix for files
        'maxFileSize' => 10485760, // 10MB default
        'allowedExtensions' => [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico',
            // Documents
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'rtf', 'csv',
            // Archives
            'zip', 'rar', '7z',
            // Media
            'mp3', 'mp4', 'webm', 'ogg'
        ],
        'allowedMimeTypes' => [
            // Images
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon',
            // Documents
            'application/pdf',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'application/rtf',
            // Archives
            'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
            // Media
            'audio/mpeg', 'video/mp4', 'video/webm', 'audio/ogg'
        ]
    ];

    /**
     * Constructor
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        // Merge config
        $this->config = array_merge($this->config, $config);

        // Validate required config
        if (empty($this->config['baseDir'])) {
            throw new Exception('baseDir configuration is required');
        }

        // Normalize baseDir (remove trailing slash)
        $this->config['baseDir'] = rtrim($this->config['baseDir'], '/\\');

        // Create baseDir if not exists
        if (!is_dir($this->config['baseDir'])) {
            mkdir($this->config['baseDir'], 0755, true);
        }
    }

    /**
     * Get configuration value
     * @param string $key
     * @return mixed
     */
    public function getConfig($key)
    {
        return $this->config[$key] ?? null;
    }

    /**
     * Set configuration value
     * @param string $key
     * @param mixed $value
     */
    public function setConfig($key, $value)
    {
        $this->config[$key] = $value;
    }

    /**
     * Get absolute base path
     * @return string
     */
    private function getBasePath()
    {
        return $this->config['baseDir'];
    }

    /**
     * Sanitize and validate path to prevent directory traversal
     *
     * @param string $path
     * @return string|false Sanitized path or false if invalid
     */
    public function sanitizePath($path)
    {
        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Remove double slashes
        $path = preg_replace('#/+#', '/', $path);

        // Check for directory traversal attempts
        if (preg_match('/\.\./', $path)) {
            return false;
        }

        // Must start with /
        if (substr($path, 0, 1) !== '/') {
            $path = '/'.$path;
        }

        // Remove trailing slash except for root
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Validate characters (alphanumeric, dash, underscore, dot, slash, space, Thai chars)
        if (!preg_match('#^[a-zA-Z0-9/_\-\. ก-๙]+$#u', $path) && $path !== '/') {
            return false;
        }

        return $path;
    }

    /**
     * Get full system path from relative path
     *
     * @param string $relativePath
     * @return string|false
     */
    public function getFullPath($relativePath)
    {
        $sanitized = $this->sanitizePath($relativePath);
        if ($sanitized === false) {
            return false;
        }

        $fullPath = $this->getBasePath().$sanitized;

        // Verify path is still under base directory (realpath check)
        if (file_exists($fullPath)) {
            $realPath = realpath($fullPath);
            $realBase = realpath($this->getBasePath());
            if ($realPath === false || strpos($realPath, $realBase) !== 0) {
                return false;
            }
        }

        return $fullPath;
    }

    /**
     * Validate filename
     *
     * @param string $name
     * @return bool
     */
    public function isValidFilename($name)
    {
        // Check for empty or special names
        if (empty($name) || $name === '.' || $name === '..') {
            return false;
        }

        // Max length
        if (strlen($name) > 255) {
            return false;
        }

        // Valid characters only (alphanumeric, dash, underscore, dot, space, Thai)
        if (!preg_match('/^[a-zA-Z0-9_\-\. ก-๙]+$/u', $name)) {
            return false;
        }

        // No double dots
        if (strpos($name, '..') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Check if extension is allowed
     *
     * @param string $filename
     * @return bool
     */
    public function isAllowedExtension($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->config['allowedExtensions']);
    }

    /**
     * Check if MIME type is allowed
     *
     * @param string $mimeType
     * @return bool
     */
    public function isAllowedMimeType($mimeType)
    {
        return in_array($mimeType, $this->config['allowedMimeTypes']);
    }

    /**
     * Get list of files and folders in a directory
     *
     * @param string $path
     * @return array
     */
    public function getFiles($path)
    {
        $fullPath = $this->getFullPath($path);
        if ($fullPath === false || !is_dir($fullPath)) {
            return ['error' => 'Invalid path'];
        }

        $items = [];
        $iterator = new DirectoryIterator($fullPath);

        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            $name = $item->getFilename();

            // Skip hidden files
            if (substr($name, 0, 1) === '.') {
                continue;
            }

            $itemData = [
                'name' => $name,
                'path' => $path === '/' ? '/'.$name : $path.'/'.$name,
                'type' => $item->isDir() ? 'folder' : 'file',
                'modified' => $item->getMTime()
            ];

            if ($item->isFile()) {
                $itemData['size'] = $item->getSize();
                $itemData['extension'] = strtolower($item->getExtension());

                // Get MIME type
                $filePath = $fullPath.'/'.$name;
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($filePath);
                $itemData['mimeType'] = $mimeType;

                // Generate URL for files
                $itemData['url'] = $this->getFileUrl($itemData['path']);

                // Check if image for thumbnail
                if ($this->isImage($name)) {
                    $itemData['thumbnail'] = $itemData['url'];
                }
            }

            $items[] = $itemData;
        }

        // Sort: folders first, then by name
        usort($items, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'success' => true,
            'path' => $path,
            'items' => $items
        ];
    }

    /**
     * Get folder tree structure
     *
     * @param string $path
     * @param int $depth
     * @return array
     */
    public function getFolderTree($path = '/', $depth = 3)
    {
        $fullPath = $this->getFullPath($path);
        if ($fullPath === false || !is_dir($fullPath)) {
            return [];
        }

        $tree = [];
        $iterator = new DirectoryIterator($fullPath);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $name = $item->getFilename();
            if (substr($name, 0, 1) === '.') {
                continue;
            }

            $folderPath = $path === '/' ? '/'.$name : $path.'/'.$name;

            $folder = [
                'name' => $name,
                'path' => $folderPath,
                'children' => []
            ];

            if ($depth > 1) {
                $folder['children'] = $this->getFolderTree($folderPath, $depth - 1);
            }

            $tree[] = $folder;
        }

        usort($tree, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $tree;
    }

    /**
     * Upload file
     *
     * @param array $file $_FILES array item
     * @param string $destPath Destination folder path
     * @return array
     */
    public function upload($file, $destPath)
    {
        // Validate file
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
            ];
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            return ['error' => $errorMessages[$code] ?? 'Upload failed'];
        }

        // Check file size
        if ($file['size'] > $this->config['maxFileSize']) {
            return ['error' => 'File too large (max '.$this->formatSize($this->config['maxFileSize']).')'];
        }

        // Validate filename
        $originalName = basename($file['name']);
        if (!$this->isValidFilename($originalName)) {
            return ['error' => 'Invalid filename'];
        }

        // Check extension
        if (!$this->isAllowedExtension($originalName)) {
            return ['error' => 'File type not allowed'];
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!$this->isAllowedMimeType($mimeType)) {
            return ['error' => 'Invalid file type'];
        }

        // Get destination path
        $destFullPath = $this->getFullPath($destPath);
        if ($destFullPath === false) {
            return ['error' => 'Invalid destination path'];
        }

        // Create directory if needed
        if (!is_dir($destFullPath)) {
            if (!mkdir($destFullPath, 0755, true)) {
                return ['error' => 'Failed to create directory'];
            }
        }

        // Generate unique filename if exists
        $filename = $this->getUniqueFilename($destFullPath, $originalName);
        $targetPath = $destFullPath.'/'.$filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['error' => 'Failed to save file'];
        }

        // Set permissions
        chmod($targetPath, 0644);

        $filePath = $destPath === '/' ? '/'.$filename : $destPath.'/'.$filename;

        return [
            'success' => true,
            'file' => [
                'name' => $filename,
                'path' => $filePath,
                'url' => $this->getFileUrl($filePath),
                'size' => filesize($targetPath),
                'type' => 'file',
                'extension' => pathinfo($filename, PATHINFO_EXTENSION)
            ]
        ];
    }

    /**
     * Create folder
     *
     * @param string $parentPath
     * @param string $name
     * @return array
     */
    public function createFolder($parentPath, $name)
    {
        if (!$this->isValidFilename($name)) {
            return ['error' => 'Invalid folder name'];
        }

        $parentFullPath = $this->getFullPath($parentPath);
        if ($parentFullPath === false || !is_dir($parentFullPath)) {
            return ['error' => 'Invalid parent path'];
        }

        $newPath = $parentFullPath.'/'.$name;

        if (file_exists($newPath)) {
            return ['error' => 'Folder already exists'];
        }

        if (!mkdir($newPath, 0755, true)) {
            return ['error' => 'Failed to create folder'];
        }

        $folderPath = $parentPath === '/' ? '/'.$name : $parentPath.'/'.$name;

        return [
            'success' => true,
            'folder' => [
                'name' => $name,
                'path' => $folderPath,
                'type' => 'folder'
            ]
        ];
    }

    /**
     * Rename file or folder
     *
     * @param string $path
     * @param string $newName
     * @return array
     */
    public function rename($path, $newName)
    {
        if (!$this->isValidFilename($newName)) {
            return ['error' => 'Invalid name'];
        }

        $fullPath = $this->getFullPath($path);
        if ($fullPath === false || !file_exists($fullPath)) {
            return ['error' => 'File not found'];
        }

        // For files, check extension
        if (is_file($fullPath) && !$this->isAllowedExtension($newName)) {
            return ['error' => 'File extension not allowed'];
        }

        $parentDir = dirname($fullPath);
        $newFullPath = $parentDir.'/'.$newName;

        if (file_exists($newFullPath)) {
            return ['error' => 'Name already exists'];
        }

        if (!rename($fullPath, $newFullPath)) {
            return ['error' => 'Failed to rename'];
        }

        $parentPath = dirname($path);
        $newPath = $parentPath === '/' ? '/'.$newName : $parentPath.'/'.$newName;

        return [
            'success' => true,
            'newPath' => $newPath,
            'newName' => $newName
        ];
    }

    /**
     * Delete file or folder
     *
     * @param string $path
     * @return array
     */
    public function delete($path)
    {
        // Prevent deleting root
        if ($path === '/' || $path === '') {
            return ['error' => 'Cannot delete root'];
        }

        $fullPath = $this->getFullPath($path);
        if ($fullPath === false || !file_exists($fullPath)) {
            return ['error' => 'File not found'];
        }

        if (is_dir($fullPath)) {
            if (!$this->deleteDirectory($fullPath)) {
                return ['error' => 'Failed to delete folder'];
            }
        } else {
            if (!unlink($fullPath)) {
                return ['error' => 'Failed to delete file'];
            }
        }

        return ['success' => true];
    }

    /**
     * Copy file or folder
     *
     * @param string $sourcePath
     * @param string $destPath
     * @return array
     */
    public function copy($sourcePath, $destPath)
    {
        $sourceFullPath = $this->getFullPath($sourcePath);
        $destFullPath = $this->getFullPath($destPath);

        if ($sourceFullPath === false || !file_exists($sourceFullPath)) {
            return ['error' => 'Source not found'];
        }

        if ($destFullPath === false) {
            return ['error' => 'Invalid destination'];
        }

        $name = basename($sourcePath);
        $targetPath = $destFullPath.'/'.$name;

        // Get unique name if exists
        if (file_exists($targetPath)) {
            $name = $this->getUniqueFilename($destFullPath, $name);
            $targetPath = $destFullPath.'/'.$name;
        }

        if (is_dir($sourceFullPath)) {
            if (!$this->copyDirectory($sourceFullPath, $targetPath)) {
                return ['error' => 'Failed to copy folder'];
            }
        } else {
            if (!copy($sourceFullPath, $targetPath)) {
                return ['error' => 'Failed to copy file'];
            }
            chmod($targetPath, 0644);
        }

        $newPath = $destPath === '/' ? '/'.$name : $destPath.'/'.$name;

        return [
            'success' => true,
            'newPath' => $newPath
        ];
    }

    /**
     * Move file or folder
     *
     * @param string $sourcePath
     * @param string $destPath
     * @return array
     */
    public function move($sourcePath, $destPath)
    {
        $sourceFullPath = $this->getFullPath($sourcePath);
        $destFullPath = $this->getFullPath($destPath);

        if ($sourceFullPath === false || !file_exists($sourceFullPath)) {
            return ['error' => 'Source not found'];
        }

        if ($destFullPath === false || !is_dir($destFullPath)) {
            return ['error' => 'Invalid destination'];
        }

        $name = basename($sourcePath);
        $targetPath = $destFullPath.'/'.$name;

        if (file_exists($targetPath)) {
            return ['error' => 'Item already exists in destination'];
        }

        if (!rename($sourceFullPath, $targetPath)) {
            return ['error' => 'Failed to move'];
        }

        $newPath = $destPath === '/' ? '/'.$name : $destPath.'/'.$name;

        return [
            'success' => true,
            'newPath' => $newPath
        ];
    }

    // ===== Helper Methods =====

    /**
     * Check if file is an image
     */
    private function isImage($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    }

    /**
     * Get unique filename
     */
    private function getUniqueFilename($dir, $filename)
    {
        if (!file_exists($dir.'/'.$filename)) {
            return $filename;
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;

        while (file_exists($dir.'/'.$name.'_'.$counter.'.'.$ext)) {
            $counter++;
        }

        return $name.'_'.$counter.'.'.$ext;
    }

    /**
     * Get public URL for file
     */
    private function getFileUrl($path)
    {
        if (empty($this->config['webUrl'])) {
            return $path;
        }
        return rtrim($this->config['webUrl'], '/').$path;
    }

    /**
     * Format file size
     */
    private function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Recursively copy directory
     */
    private function copyDirectory($src, $dst)
    {
        if (!mkdir($dst, 0755, true)) {
            return false;
        }

        $files = array_diff(scandir($src), ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $src.'/'.$file;
            $dstPath = $dst.'/'.$file;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!copy($srcPath, $dstPath)) {
                    return false;
                }
                chmod($dstPath, 0644);
            }
        }

        return true;
    }
}
