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
        /**
         * Endpoint that serves cached thumbnails, already carrying action + store,
         * e.g. '…/filebrowser.php?action=thumb&store=files'. getThumbUrl() appends
         * &v=<mtime>&path=<file>. Empty means "no thumbnailer": listings then point
         * the client at the original image, as they always did.
         */
        'thumbBaseUrl' => '',
        /** When true (default), create baseDir on disk if missing. Set false for optional read-only roots (e.g. prepared files). */
        'createBaseDir' => true,
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

        // Create baseDir if not exists (skipped when createBaseDir is false)
        if (!empty($this->config['createBaseDir']) && !is_dir($this->config['baseDir'])) {
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

        /**
         * Reject by class instead of by whitelist. The old whitelist
         * (a-z0-9/_-. plus space and Thai) rejected every legacy upload holding
         * a parenthesis, an ampersand or a Latin-1 letter — "1(1).jpg" and friends
         * could be listed but never renamed, moved or deleted, because every
         * write path resolves through here. Containment is enforced by the
         * realpath check in getFullPath(), so this only has to block the
         * characters that break path resolution itself.
         */
        if ($path !== '/') {
            /**
             * Control characters (incl. the null byte and newlines) are never
             * valid in a filename and hide traversal from log inspection.
             * ':' goes with them: on Windows it opens an NTFS alternate data
             * stream ("x.jpg:evil") or names a drive, neither of which any real
             * file here uses. Characters that are merely awkward in a URL, such
             * as '?' and '%', stay allowed — legacy uploads do contain them and
             * the URLs are percent-encoded now.
             */
            if (preg_match('/[\x00-\x1F\x7F:]/', $path)) {
                return false;
            }
            // Must be valid UTF-8: invalid sequences can be normalised into
            // something different by the filesystem or by the browser.
            if (!mb_check_encoding($path, 'UTF-8')) {
                return false;
            }
            // Every segment must be a real name, never "." or "" (the latter
            // cannot occur after the slash collapse above, but stay explicit).
            foreach (explode('/', ltrim($path, '/')) as $segment) {
                if ($segment === '' || $segment === '.') {
                    return false;
                }
            }
            if (strlen($path) > 1024) {
                return false;
            }
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

        // Verify path is still under base directory (realpath check).
        // Compare against base + separator to avoid prefix collisions
        // (e.g. /datas/image-evil passing a check against /datas/image).
        if (file_exists($fullPath)) {
            $realPath = realpath($fullPath);
            $realBase = realpath($this->getBasePath());
            if ($realPath === false || $realBase === false
                || ($realPath !== $realBase && strpos($realPath, $realBase.DIRECTORY_SEPARATOR) !== 0)
            ) {
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

        // Must be valid UTF-8 (see sanitizePath)
        if (!mb_check_encoding($name, 'UTF-8')) {
            return false;
        }

        /**
         * Applies to names the user creates (upload metadata, new folder, rename),
         * so it stays a deny list of characters that are unsafe in a path, a URL
         * or on a Windows share — not a whitelist, which used to reject any name
         * holding a parenthesis and made renaming legacy files impossible.
         */
        if (preg_match('#[\x00-\x1F\x7F/\\\\:*?"<>|]#', $name)) {
            return false;
        }

        // No double dots
        if (strpos($name, '..') !== false) {
            return false;
        }

        // A leading dot hides the entry from the listing (which skips dotfiles),
        // and ".htaccess"-style names must never be creatable through the API.
        if (substr($name, 0, 1) === '.') {
            return false;
        }

        // Trailing dot/space is stripped by Windows and by some SMB mounts,
        // which would silently resolve "evil.php " to "evil.php".
        if (rtrim($name, ". \t") !== $name) {
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
     * Reject SVG files containing active content (stored-XSS vector when
     * the file is opened directly in the browser from the same origin).
     *
     * @param string $filePath Absolute path to the SVG file to inspect
     * @return bool true when the SVG is safe to store/serve
     */
    public function isSafeSvg($filePath)
    {
        $content = @file_get_contents($filePath, false, null, 0, 1048576);
        if ($content === false) {
            return false;
        }

        // Strip XML comments so patterns cannot hide inside them after re-parse tricks
        $checked = preg_replace('/<!--.*?-->/s', '', $content);

        $dangerous = [
            '/<\s*script/i', // inline scripts
            '/\son[a-z]+\s*=/i', // event handler attributes (onload, onclick, …)
            '/javascript\s*:/i', // javascript: URIs
            '/<\s*(foreignobject|iframe|embed|object)/i', // embedded active content
            '/<!ENTITY/i', // XXE / entity expansion
            '/data:\s*text\/html/i' // data:text/html URIs
        ];
        foreach ($dangerous as $pattern) {
            if (preg_match($pattern, $checked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of files and folders in a directory
     *
     * @param string $path
     * @param string $search Substring filter on the name, case-insensitive ('' = no filter)
     * @param string $sortBy  name|size|modified
     * @param string $sortDir asc|desc
     * @return array
     */
    public function getFiles($path, $search = '', $sortBy = 'name', $sortDir = 'asc')
    {
        $fullPath = $this->getFullPath($path);
        if ($fullPath === false || !is_dir($fullPath)) {
            return ['error' => 'Invalid path'];
        }

        $search = trim((string) $search);
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

            // Filter before doing any further per-item work
            if ($search !== '' && mb_stripos($name, $search, 0, 'UTF-8') === false) {
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

                /**
                 * MIME from the extension, not finfo: finfo opens and reads every
                 * single file, which cost 1.2s on a 2,649-file folder here. The
                 * client only uses mimeType to decide "is this an image", so the
                 * extension answers it. Uploads still verify with finfo (upload()).
                 */
                $itemData['mimeType'] = self::mimeTypeFromExtension($itemData['extension']);

                // Generate URL for files
                $itemData['url'] = $this->getFileUrl($itemData['path']);

                /**
                 * Images get a thumbnail URL, never the original: one legacy
                 * folder here holds 2,649 images totalling 254 MB, and pointing
                 * the grid at the full-size files made the browser fetch and
                 * decode all of it at once until the tab stopped responding.
                 */
                if (self::isImage($name)) {
                    $thumb = $this->getThumbUrl($itemData['path'], $itemData['modified']);
                    $itemData['thumbnail'] = $thumb === '' ? $itemData['url'] : $thumb;
                }
            }

            $items[] = $itemData;
        }

        $this->sortItems($items, $sortBy, $sortDir);

        return [
            'success' => true,
            'path' => $path,
            'items' => $items
        ];
    }

    /**
     * Sort a listing in place: folders first, then by the requested key.
     * Names compare naturally and case-insensitively so "img9" precedes "img10".
     *
     * @param array $items
     * @param string $sortBy  name|size|modified
     * @param string $sortDir asc|desc
     */
    private function sortItems(array &$items, $sortBy, $sortDir)
    {
        $sortBy = in_array($sortBy, ['name', 'size', 'modified'], true) ? $sortBy : 'name';
        $factor = strtolower((string) $sortDir) === 'desc' ? -1 : 1;

        usort($items, function ($a, $b) use ($sortBy, $factor) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            if ($sortBy === 'name' || $a['type'] === 'folder') {
                // Folders carry no size; always compare them by name
                return $factor * strnatcasecmp($a['name'], $b['name']);
            }
            $cmp = ($a[$sortBy] ?? 0) <=> ($b[$sortBy] ?? 0);
            // Equal size/date keeps a stable, predictable order by name
            return $cmp === 0 ? strnatcasecmp($a['name'], $b['name']) : $factor * $cmp;
        });
    }

    /**
     * MIME type for a file extension — listing only, never for validating an upload.
     * Unknown extensions fall back to application/octet-stream.
     *
     * @param string $ext lowercase extension without the dot
     * @return string
     */
    private static function mimeTypeFromExtension($ext)
    {
        static $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'rtf' => 'application/rtf',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm'
        ];
        return $map[$ext] ?? 'application/octet-stream';
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

        // Validate filename from upload metadata (before generating storage name)
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

        // SVG: reject files containing scripts/event handlers (stored XSS)
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (($ext === 'svg' || $mimeType === 'image/svg+xml') && !$this->isSafeSvg($file['tmp_name'])) {
            return ['error' => 'SVG contains disallowed active content'];
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

        // Store using a safe generated name instead of user-provided filename
        $safeName = $this->generateSafeFilename($originalName);
        $filename = $this->getUniqueFilename($destFullPath, $safeName);
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

        // Renaming into .svg must pass the same active-content check as uploads,
        // otherwise a harmless .txt could be turned into a scriptable SVG.
        if (is_file($fullPath)
            && strtolower(pathinfo($newName, PATHINFO_EXTENSION)) === 'svg'
            && !$this->isSafeSvg($fullPath)
        ) {
            return ['error' => 'SVG contains disallowed active content'];
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

        // Must be a folder, as move() already requires: copying into a path that
        // names an existing file would build "file.jpg/name" and fail obscurely.
        if ($destFullPath === false || !is_dir($destFullPath)) {
            return ['error' => 'Invalid destination'];
        }

        /**
         * Copying a folder into itself (or into one of its own children) makes
         * copyDirectory() recurse into the copy it is still writing and fill the
         * disk. rename() fails on the same move, so both guard here.
         */
        if (is_dir($sourceFullPath) && self::isInside($destFullPath, $sourceFullPath)) {
            return ['error' => 'Cannot copy a folder into itself'];
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

        // Same guard as copy(): a folder cannot be moved inside itself
        if (is_dir($sourceFullPath) && self::isInside($destFullPath, $sourceFullPath)) {
            return ['error' => 'Cannot move a folder into itself'];
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
     * True when $path is $ancestor itself or sits underneath it.
     * Both are absolute filesystem paths; resolved through realpath so a
     * symlinked destination cannot dodge the comparison.
     *
     * @param string $path
     * @param string $ancestor
     * @return bool
     */
    private static function isInside($path, $ancestor)
    {
        $path = realpath($path);
        $ancestor = realpath($ancestor);
        if ($path === false || $ancestor === false) {
            return false;
        }

        return $path === $ancestor || strpos($path, $ancestor.DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Check if file is an image
     *
     * @param string $filename
     * @return bool
     */
    public static function isImage($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    }

    /**
     * Image formats GD can decode and re-encode, i.e. the ones the thumbnailer
     * can actually shrink. SVG is an image but not one of these.
     *
     * @param string $filename
     * @return bool
     */
    public static function isRasterImage($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
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
     * Generate a safe storage filename from an uploaded name.
     * Keeps only a normalized ASCII stem and an allowed extension.
     */
    private function generateSafeFilename($originalName)
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $name = strtolower(pathinfo($originalName, PATHINFO_FILENAME));

        // Replace unsafe characters with separators, then trim.
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim((string) $name, '-');

        if ($name === '') {
            $name = 'file';
        }

        // Keep basename short and append random token to avoid collisions.
        $name = substr($name, 0, 50);
        $token = bin2hex(random_bytes(8));

        return $name.'-'.$token.'.'.$ext;
    }

    /**
     * Percent-encode a stored path for use inside a URL.
     *
     * Encodes per segment so the slashes survive. Without this a legacy name
     * such as "015 กพ_68.jpg" produced a URL holding a raw space, which the
     * browser drops from an unquoted CSS url() — the file listed fine and its
     * thumbnail silently stayed blank.
     *
     * @param string $path leading-slash path relative to baseDir
     * @return string
     */
    private static function encodePath($path)
    {
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            $segments[$i] = rawurlencode($segment);
        }

        return implode('/', $segments);
    }

    /**
     * URL of the cached thumbnail for a file, or '' when no thumbnailer is
     * configured (the caller then falls back to the full-size image).
     *
     * The modification time rides along as &v= so the URL changes whenever the
     * file does, which lets the endpoint answer with a long immutable cache.
     *
     * @param string $path
     * @param int $mtime
     * @return string
     */
    private function getThumbUrl($path, $mtime)
    {
        if (empty($this->config['thumbBaseUrl'])) {
            return '';
        }
        $base = $this->config['thumbBaseUrl'];

        return $base.(strpos($base, '?') === false ? '?' : '&')
            .'v='.(int) $mtime
            .'&path='.rawurlencode($path);
    }

    /**
     * Get public URL for file
     */
    private function getFileUrl($path)
    {
        if (empty($this->config['webUrl'])) {
            return $path;
        }
        return rtrim($this->config['webUrl'], '/').self::encodePath($path);
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
            /**
             * is_link() first: is_dir() follows symlinks, so a link pointing
             * outside baseDir would have this recurse into the target and empty
             * a directory the caller never named. Unlinking removes the link
             * itself and leaves the target alone.
             */
            if (!is_link($path) && is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
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

            if (!is_link($srcPath) && is_dir($srcPath)) {
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
