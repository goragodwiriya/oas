<?php
/**
 * FileBrowser File-Storage Configuration
 *
 * Path settings (baseDir, webUrl) are derived automatically from Kotchasan
 * constants (ROOT_PATH, DATA_FOLDER, WEB_URL) defined in load.php.
 * Authentication is handled in filebrowser.php via \Kotchasan\Jwt and
 * the jwt_secret stored in settings/config.php.
 *
 * @author Goragod Wiriya
 * @version 2.0
 */
return [
    // ============================================
    // FILE STORAGE CONFIGURATION
    // ============================================

    /**
     * Maximum file size in bytes (default: 10 MB)
     */
    'maxFileSize' => 10 * 1024 * 1024,

    /**
     * Allowed file extensions (whitelist)
     */
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

    /**
     * Optional: separate folders (under DATA_FOLDER) per kind of file, one
     * FileBrowser tab each. The first is the default (images from the editor go
     * there); the link dialog uploads to 'file'. 'allowedExtensions' narrows the
     * list above. Without this key everything stays in one folder.
     *
     * 'storages' => [
     *     'image' => ['folder' => 'images', 'name' => 'Images',
     *                 'allowedExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico']],
     *     'file' => ['folder' => 'file', 'name' => 'Files']
     * ],
     */

    // ============================================
    // IMAGE PROCESSING CONFIGURATION
    // ============================================

    /**
     * Auto-resize uploaded images to this maximum width (pixels).
     * Images narrower than this value are left unchanged.
     * Set to 0 to disable automatic resizing.
     * Overridden at runtime by Gcms\Config::$stored_img_size when available.
     */
    'imageMaxWidth' => 1440,

    /**
     * JPEG / WebP save quality (0-100).
     * Higher = better quality but larger file size.
     * Overridden at runtime by Gcms\Config::$image_quality when available.
     */
    'imageQuality' => 85,

    /**
     * Convert raster images to WebP whenever they are resized or when
     * the uploaded image is in a format that should be stored as WebP.
     * Set to false to keep the original file format.
     * Overridden at runtime by Gcms\Config::$stored_img_type when available.
     */
    'imageConvertToWebP' => true,

    /**
     * Longest side, in pixels, of the thumbnails the file grid displays.
     * Generated on demand by action=thumb and cached under
     * DATA_FOLDER/cache/fbthumbs, so the grid never downloads the originals.
     * Clamped to 60-600 by the endpoint.
     */
    'thumbnailWidth' => 240,

    /**
     * Refuse to decode an image larger than this many pixels (width x height)
     * when making a thumbnail — a decompression-bomb guard, since GD needs
     * roughly 4 bytes of memory per pixel. Oversized images are served as-is.
     */
    'thumbnailMaxPixels' => 30000000,

    /**
     * Require write permission for write operations
     * (upload, create_folder, rename, delete, copy, move).
     *
     * true (default): the check defined by 'canWrite' below must pass.
     * false: any authenticated user may write.
     */
    'uploadRequiresWritePermission' => true,

    /**
     * Permission keys checked against document-module configs by the built-in
     * rule. Each key holds an array of user statuses in the module config.
     * Adjust per project, e.g. wk uses ['can_write', 'can_approve'].
     */
    'writePermissionKeys' => ['can_write', 'can_approve'],

    /**
     * User permissions (user.permission) that also grant write access, e.g.
     * ['can_config']. Empty = only admins and the document-module rule above.
     */
    'writePermissions' => [],

    /**
     * Write-permission rule (project-specific override point).
     *
     * null (default): use the built-in rule — admin (status 1) or a user
     * whose status is listed in one of 'writePermissionKeys' of at least one
     * installed document module. The user's real status is loaded from the
     * database (tokens carry no status claim).
     *
     * Projects with entirely different permission models replace this with a
     * closure receiving the verified token payload and returning bool, e.g.:
     *
     *   'canWrite' => function (array $payload) {
     *       $user = \Kotchasan\DB::create()->first('user', [['id', (int) $payload['sub']]]);
     *       return $user && (int) $user->status <= 2;
     *   },
     */
    'canWrite' => null,

    // ============================================
    // PREPARED FILES (read-only library, optional)
    // ============================================

    /**
     * Folder under DATA_FOLDER for the "Prepared file" tab (e.g. datas/prepared).
     * Not created automatically if missing — when the folder does not exist the
     * client hides the "Prepared file" tab entirely.
     * Subfolders appear as categories; files in the root appear under "all".
     */
    'presetStorageFolder' => 'prepared'
];
