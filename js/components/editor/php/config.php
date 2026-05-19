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

    // ============================================
    // IMAGE PROCESSING CONFIGURATION
    // ============================================

    /**
     * Auto-resize uploaded images to this maximum width (pixels).
     * Images narrower than this value are left unchanged.
     * Set to 0 to disable automatic resizing.
     * Overridden at runtime by Gcms\Config::$stored_img_size when available.
     */
    'imageMaxWidth' => 1200,

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
     * Minimum JWT 'status' value required to perform write operations
     * (upload, create_folder, rename, delete, copy, move).
     * 0 = any authenticated user (default)
     * 1 = admin only
     * 2 = staff or above
     */
    'uploadMinStatus' => 0,

    // ============================================
    // PRESET CATEGORIES
    // ============================================

    /**
     * Preset file categories shown in the "Prepared files" tab.
     */
    'presetCategories' => [
        ['id' => 'images', 'name' => 'Images', 'icon' => 'icon-image'],
        ['id' => 'documents', 'name' => 'Documents', 'icon' => 'icon-file'],
        ['id' => 'media', 'name' => 'Media', 'icon' => 'icon-video']
    ]
];
