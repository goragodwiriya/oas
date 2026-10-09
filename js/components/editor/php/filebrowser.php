<?php
/**
 * FileBrowser API Endpoint
 *
 * Authentication  : Bearer JWT validated via \Kotchasan\Jwt using the
 *                   jwt_secret from settings/config.php.
 * Path resolution : ROOT_PATH / DATA_FOLDER — uploads live under …/images;
 *                   optional read-only "Prepared file" tab reads …/prepared
 *                   (see presetStorageFolder in config.php; not auto-created).
 * Config          : js/components/editor/php/config.php (file-storage
 *                   settings only — auth and paths are handled here).
 *
 * @author Goragod Wiriya
 * @version 2.0
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Loads Kotchasan autoloader and defines ROOT_PATH, DATA_FOLDER, WEB_URL.
require_once '../../../../load.php';

// ── File-storage configuration ────────────────────────────────────────────────
// config.php contains only file/image settings; paths are overridden below.
$config = require __DIR__.'/config.php';

// ── Framework settings (jwt_secret, api_cors, …) ─────────────────────────────
// Read directly from settings/config.php via the ROOT_PATH constant.
$gcmsSettings = include ROOT_PATH.'settings/config.php';

// ── Derived paths from Kotchasan constants ────────────────────────────────────
// ROOT_PATH  = absolute filesystem path to project root (with trailing slash)
// DATA_FOLDER = 'datas/'
// WEB_URL    = full URL to project root (scheme + host + path, trailing slash)
$config['baseDir'] = ROOT_PATH.DATA_FOLDER.'images';
$config['webUrl'] = WEB_URL.DATA_FOLDER.'images';

// Optional read-only library for the "Prepared file" tab (separate from uploads).
// Folder name comes from presetStorageFolder in config.php (validated to a safe
// relative path under DATA_FOLDER — no traversal, no absolute paths).
$presetFolder = isset($config['presetStorageFolder']) ? trim((string) $config['presetStorageFolder'], '/') : 'prepared';
if ($presetFolder === '' || !preg_match('#^[a-zA-Z0-9_\-]+(/[a-zA-Z0-9_\-]+)*$#', $presetFolder)) {
    $presetFolder = 'prepared';
}
$config['presetBaseDir'] = ROOT_PATH.DATA_FOLDER.$presetFolder;
$config['presetWebUrl'] = WEB_URL.DATA_FOLDER.$presetFolder;

/**
 * Public URL of this very script, used to build thumbnail URLs.
 * Derived from __FILE__ rather than REQUEST_URI, which the client controls.
 */
$config['selfUrl'] = WEB_URL.ltrim(str_replace(ROOT_PATH, '', str_replace('\\', '/', __FILE__)), '/');

// ── Override image config from Gcms\Config (runtime serialized values) ────────
// stored_img_size  → imageMaxWidth  (Gcms default: 800)
// stored_img_type  → imageConvertToWebP  ('.webp' means convert, anything else means keep format)
// image_quality    → imageQuality   (also used for the thumbnail cache)
if (isset($gcmsSettings['stored_img_size']) && $gcmsSettings['stored_img_size'] > 0) {
    $config['imageMaxWidth'] = (int) $gcmsSettings['stored_img_size'];
}
if (isset($gcmsSettings['stored_img_type'])) {
    $config['imageConvertToWebP'] = (strtolower(trim($gcmsSettings['stored_img_type'], '.')) === 'webp');
}
if (isset($gcmsSettings['image_quality']) && $gcmsSettings['image_quality'] > 0) {
    $config['imageQuality'] = (int) $gcmsSettings['image_quality'];
}

// ── Storages ──────────────────────────────────────────────────────────────────
// config 'storages' keeps kinds of files in separate folders under DATA_FOLDER
// (e.g. images in image/, documents in file/), each shown as its own tab. The
// client picks one with the 'storage' parameter; a missing or unknown id means
// the first one. Without 'storages' the single baseDir set above is used.
$storages = [];
foreach ((array) ($config['storages'] ?? []) as $storageKey => $storageDef) {
    $storageFolder = trim((string) ($storageDef['folder'] ?? ''), '/');
    if (!preg_match('/^[a-z0-9_\-]+$/i', (string) $storageKey)
        || !preg_match('#^[a-zA-Z0-9_\-]+(/[a-zA-Z0-9_\-]+)*$#', $storageFolder)
    ) {
        continue;
    }
    $storageExtensions = $config['allowedExtensions'] ?? [];
    if (!empty($storageDef['allowedExtensions']) && is_array($storageDef['allowedExtensions'])) {
        // A storage narrows the global whitelist, never widens it
        $storageExtensions = array_values(array_intersect(array_map('strtolower', $storageDef['allowedExtensions']), $storageExtensions));
    }
    $storages[(string) $storageKey] = [
        'folder' => $storageFolder,
        'name' => (string) ($storageDef['name'] ?? $storageKey),
        'allowedExtensions' => $storageExtensions
    ];
}
$storageId = '';
if (!empty($storages)) {
    $storageId = (string) getParam('storage', '');
    if (!isset($storages[$storageId])) {
        $storageId = (string) array_key_first($storages);
    }
    $config['baseDir'] = ROOT_PATH.DATA_FOLDER.$storages[$storageId]['folder'];
    $config['webUrl'] = WEB_URL.DATA_FOLDER.$storages[$storageId]['folder'];
    $config['allowedExtensions'] = $storages[$storageId]['allowedExtensions'];
}

/**
 * Storage list for the client tabs: [{id, name, extensions}], plus the one in use.
 *
 * @param  array  $storages
 * @param  string $storageId
 * @return array
 */
function storageInfo(array $storages, $storageId)
{
    $list = [];
    foreach ($storages as $id => $storage) {
        $list[] = ['id' => $id, 'name' => $storage['name'], 'extensions' => $storage['allowedExtensions']];
    }

    return ['storages' => $list, 'storage' => $storageId];
}

// ── JSON response headers ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS — use api_cors from framework settings, fall back to '*'
$allowOrigin = !empty($gcmsSettings['api_cors']) ? $gcmsSettings['api_cors'] : '*';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
// If configured as '*' and an Origin header is present, echo it back as the allowed origin
if ($requestOrigin) {
    if ($allowOrigin === '*') {
        $allowOrigin = $requestOrigin;
    } elseif (strpos($allowOrigin, ',') !== false) {
        $allowed = array_map('trim', explode(',', $allowOrigin));
        if (!in_array($requestOrigin, $allowed)) {
            $allowOrigin = '';
        }
    }
}
if (!empty($allowOrigin)) {
    header('Access-Control-Allow-Origin: '.$allowOrigin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Include the Files model ───────────────────────────────────────────────────
require_once __DIR__.'/models/files.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send a JSON response and terminate.
 *
 * @param array $data
 * @param int   $code HTTP status code
 */
function sendResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and terminate.
 *
 * @param string $message
 * @param int    $code HTTP status code
 */
function sendError($message, $code = 400)
{
    sendResponse(['success' => false, 'error' => $message], $code);
}

/**
 * Extract the raw token string from the request.
 *
 * Priority order (mirrors ApiController::getAccessToken()):
 *   1. Authorization: Bearer <token>  header
 *   2. X-Access-Token                 header
 *   3. auth_token                     cookie  (used by the admin frontend)
 *
 * @return string|null
 */
function extractToken()
{
    // 1. Authorization: Bearer
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+([A-Za-z0-9\-_\.]+)/i', $authHeader, $m)) {
        return $m[1];
    }

    // 2. X-Access-Token header
    $xat = $_SERVER['HTTP_X_ACCESS_TOKEN'] ?? '';
    if (!empty($xat)) {
        return $xat;
    }

    // 3. auth_token cookie (admin frontend stores the token here)
    $cookieName = 'auth_token';
    if (!empty($_COOKIE[$cookieName])) {
        $cookie = $_COOKIE[$cookieName];
        // Validate cookie characters before using it
        if (preg_match('/^[A-Za-z0-9\-_\.]+$/', $cookie)) {
            return $cookie;
        }
    }

    return null;
}

/**
 * Validate an access token against the configured jwt_secret.
 *
 * Supports both token formats issued by the GCMS auth system:
 *
 *   2-part (custom) : base64url(payload).hex_hmac_sha256(base64url(payload), secret)
 *   3-part (JWT)    : base64url(header).base64url(payload).base64url(signature)
 *
 * Logic mirrors Web/Login::verifyTokenInternal() +
 *              Web/Login::verifyCustomToken()     +
 *              Web/Login::verifyStandardJwt()
 *
 * @param  string $token
 * @param  string $secret  jwt_secret from settings/config.php
 * @return array|null  Decoded payload on success, null on failure
 */
function verifyToken($token, $secret)
{
    if (empty($token) || empty($secret)) {
        return null;
    }

    $parts = explode('.', $token);

    // ── 2-part custom token: payload_b64.hex_sig ──────────────────────────
    if (count($parts) === 2) {
        [$b64payload, $hexsig] = $parts;

        $expected = hash_hmac('sha256', $b64payload, $secret);
        if (!hash_equals($expected, $hexsig)) {
            return null;
        }

        return decodeAndValidatePayload($b64payload);
    }

    // ── 3-part standard JWT: header_b64.payload_b64.sig_b64 ──────────────
    if (count($parts) === 3) {
        [$b64header, $b64payload, $b64sig] = $parts;

        $headerJson = base64_decode(strtr($b64header, '-_', '+/'));
        $header = json_decode($headerJson, true);

        // Accept HS256 only (reject "none" etc.)
        if (!$header || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $signingInput = $b64header.'.'.$b64payload;
        $expected = hash_hmac('sha256', $signingInput, $secret, true);
        $provided = base64_decode(strtr($b64sig, '-_', '+/'));

        if (!hash_equals($expected, $provided)) {
            return null;
        }

        return decodeAndValidatePayload($b64payload);
    }

    return null;
}

/**
 * Decode a base64url payload and validate time claims.
 *
 * @param  string $b64payload
 * @return array|null
 */
function decodeAndValidatePayload($b64payload)
{
    $json = base64_decode(strtr($b64payload, '-_', '+/'));
    if ($json === false) {
        return null;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }

    // sub and exp are required
    if (!isset($payload['sub']) || !isset($payload['exp'])) {
        return null;
    }

    $now = time();

    // Expired?
    if ($payload['exp'] < $now) {
        return null;
    }

    // Not-before?
    if (isset($payload['nbf']) && $payload['nbf'] > $now) {
        return null;
    }

    return $payload;
}

/**
 * Authenticate the incoming request.
 *
 * Extracts the token (header or cookie) and validates it using
 * jwt_secret from settings/config.php.
 *
 * @param  array $gcmsSettings The array returned by settings/config.php
 * @return array|null Decoded token payload on success, null on failure
 */
function authenticateRequest(array $gcmsSettings)
{
    $token = extractToken();
    if ($token === null) {
        return null;
    }

    $secret = $gcmsSettings['jwt_secret'] ?? null;
    if (empty($secret)) {
        return null;
    }

    return verifyToken($token, $secret);
}

/**
 * Check whether the authenticated user may perform write operations
 * (upload, create_folder, rename, delete, copy, move).
 *
 * GCMS tokens do not carry a status claim, so the user's real status is
 * loaded from the database using the 'sub' claim. Rules:
 *   - status 1 (admin) is always allowed
 *   - a user holding one of the user permissions listed in 'writePermissions'
 *     (config.php, e.g. can_config) is allowed
 *   - otherwise the user's status must be listed in one of the permission
 *     keys ('writePermissionKeys' in config.php, default can_write/moderator)
 *     of at least one installed document module — the same rule as the
 *     article write screen of the project
 *
 * @param  array $payload Verified token payload
 * @param  array $config  FileBrowser config (uses 'writePermissionKeys')
 * @return bool
 */
function canWriteFiles(array $payload, array $config = [])
{
    $userId = (int) ($payload['sub'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    // Load the user's real status from the database (never trust the client)
    $user = null;
    try {
        if (class_exists('\Index\Auth\Model') && method_exists('\Index\Auth\Model', 'getUserById')) {
            $user = \Index\Auth\Model::getUserById($userId);
        } else {
            $user = \Kotchasan\DB::create()->first('user', [['id', $userId]]);
        }
    } catch (\Exception $e) {
        return false;
    }
    if (!$user || !isset($user->status)) {
        return false;
    }

    $status = (int) $user->status;
    if ($status === 1) {
        // Admin
        return true;
    }

    // User permissions (user.permission, stored as ",perm1,perm2,")
    $writePermissions = isset($config['writePermissions']) && is_array($config['writePermissions'])
        ? $config['writePermissions']
        : [];
    if (!empty($writePermissions) && isset($user->permission)) {
        $granted = is_array($user->permission)
            ? $user->permission
            : explode(',', trim((string) $user->permission, " \t\n\r\0\x0B,"));
        if (array_intersect($writePermissions, array_map('trim', $granted))) {
            return true;
        }
    }

    // Collect write permissions from every installed document module and
    // reuse the same checkStatus() rule as the article write controller.
    // Keys are configurable per project (e.g. gcms uses can_write/moderator,
    // other projects may use can_write/can_approve).
    $permissionKeys = isset($config['writePermissionKeys']) && is_array($config['writePermissionKeys'])
        ? $config['writePermissionKeys']
        : ['can_write', 'moderator'];
    try {
        $modules = \Kotchasan\Model::createQuery()
            ->select('config')
            ->from('modules')
            ->where([['owner', 'document']])
            ->cacheOn()
            ->fetchAll();
    } catch (\Exception $e) {
        return false;
    }

    $login = (object) ['status' => $status];
    foreach ($modules as $module) {
        $moduleConfig = json_decode((string) $module->config);
        if (is_object($moduleConfig)
            && \Kotchasan\Login::checkStatus($login, $moduleConfig, $permissionKeys)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Get a request parameter.
 *
 * Checks GET first (action is usually in the URL query string),
 * then JSON body, then POST (form data / multipart).
 *
 * @param  string $key
 * @param  mixed  $default
 * @return mixed
 */
function getParam($key, $default = null)
{
    // GET query string
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    // JSON body (application/json requests)
    /**
     * @var mixed
     */
    static $jsonBody = null;
    if ($jsonBody === null) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $jsonBody = json_decode($raw, true) ?: [];
        } else {
            $jsonBody = [];
        }
    }
    if (isset($jsonBody[$key])) {
        return $jsonBody[$key];
    }

    // POST / multipart form data
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    return $default;
}

/**
 * Process an uploaded image: resize and/or convert to WebP.
 *
 * Rules:
 *  - imageMaxWidth > 0 AND image wider → resize
 *  - imageConvertToWebP = true AND image is a resizable raster (not GIF, not already WebP) → convert
 *  - Animated GIFs are never converted (GD destroys animation frames)
 *  - SVG, documents, audio, video pass through unchanged
 *
 * \Kotchasan\Image is autoloaded via load.php — no manual require needed.
 *
 * @param  array  $fileInfo File info array returned by FileBrowserFiles::upload()
 * @param  string $baseDir  Absolute base directory of the file storage
 * @param  array  $config   FileBrowser config array
 * @return array  Updated $fileInfo
 */
function resizeUploadedImage(array $fileInfo, $baseDir, array $config)
{
    if (empty($fileInfo['path'])) {
        return $fileInfo;
    }

    $fullPath = $baseDir.$fileInfo['path'];
    if (!file_exists($fullPath)) {
        return $fileInfo;
    }

    // Detect MIME type via finfo (don't trust client-supplied type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fullPath);

    // Raster formats supported by GD for read + write
    $rasterMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $rasterMimes)) {
        return $fileInfo; // SVG, PDF, video, etc. — pass through
    }

    $maxWidth = isset($config['imageMaxWidth']) ? (int) $config['imageMaxWidth'] : 1440;
    $convertToWebP = !empty($config['imageConvertToWebP']) && function_exists('imagewebp');
    $quality = isset($config['imageQuality']) ? (int) $config['imageQuality'] : 85;

    // Animated GIFs: never convert (GD flattens animation to a single frame)
    if ($mimeType === 'image/gif' && $convertToWebP) {
        // Count GIF frames: if > 1, skip conversion
        $gifData = file_get_contents($fullPath);
        if ($gifData !== false && substr_count($gifData, "\x00\x21\xF9\x04") > 1) {
            $convertToWebP = false;
        }
    }

    // Already WebP — only resize if needed, no format conversion
    if ($mimeType === 'image/webp') {
        $convertToWebP = false;
    }

    // Check current dimensions
    $imageSize = @getimagesize($fullPath);
    if (!$imageSize) {
        return $fileInfo;
    }

    $width = $imageSize[0];

    // JPEGs from phone cameras are frequently stored in the sensor's native
    // (landscape) orientation with an EXIF Orientation tag requesting a 90/270°
    // rotation for display. \Kotchasan\Image::orient() applies that rotation
    // before resizing, so the *visual* width can differ from getimagesize()'s
    // raw width. Swap dimensions here too, or a portrait photo narrower than
    // $maxWidth gets wrongly flagged for resize (and then over-shrunk, since
    // Image::processImageResource() also bounds the rotated image's height
    // against $maxWidth).
    if (($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') && function_exists('exif_read_data')) {
        $exif = @exif_read_data($fullPath);
        $orientation = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 0;
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            $width = $imageSize[1];
        }
    }

    $needsResize = ($maxWidth > 0 && $width > $maxWidth);
    if (!$needsResize && !$convertToWebP) {
        return $fileInfo; // Nothing to do
    }

    \Kotchasan\Image::setQuality($quality);

    $dir = dirname($fullPath).'/';
    $name = basename($fullPath);

    if ($convertToWebP) {
        // Replace extension with .webp
        $newName = preg_replace('/\.[^.]+$/', '.webp', $name);
    } else {
        $newName = $name; // Resize in place, keep original format
    }

    $resizeWidth = $needsResize ? $maxWidth : 0; // 0 = convert format only, no resize
    $result = \Kotchasan\Image::resize($fullPath, $dir, $newName, $resizeWidth);

    if ($result === false) {
        return $fileInfo; // Conversion failed — return original unchanged
    }

    // Remove the original file when the filename changed (format conversion)
    if ($newName !== $name && file_exists($dir.$newName)) {
        @unlink($fullPath);
    }

    // Update fileInfo to reflect new filename / URL
    $fileInfo['name'] = $newName;
    $fileInfo['path'] = preg_replace('/[^\/]+$/', $newName, $fileInfo['path']);
    $fileInfo['url'] = preg_replace('/[^\/]+$/', $newName, $fileInfo['url']);
    $fileInfo['extension'] = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
    $fileInfo['width'] = $result['width'];
    $fileInfo['height'] = $result['height'];
    $fileInfo['size'] = filesize($dir.$newName);

    return $fileInfo;
}

/**
 * Stream a file to the browser with validator headers, answering 304 when the
 * client already holds it.
 *
 * No filename is put in a header: names come from disk and a header is the one
 * place a stray CR/LF would matter.
 *
 * @param string $file     absolute path, already validated by the caller
 * @param string $mimeType
 * @param bool   $immutable true when the URL changes whenever the bytes do
 */
function streamFile($file, $mimeType, $immutable = false)
{
    $mtime = filemtime($file);
    $etag = '"'.md5($file.'|'.$mtime.'|'.filesize($file)).'"';

    // Private: these bytes sit behind an access-token check and must never be
    // held by a shared proxy for the next visitor.
    header('Content-Type: '.$mimeType);
    /**
     * Neutralises an SVG opened directly in a tab rather than through <img>:
     * sandbox blocks scripts, so a legacy file that predates isSafeSvg() cannot
     * run anything in this origin.
     */
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age='.($immutable ? 31536000 : 3600).($immutable ? ', immutable' : ''));
    header('ETag: '.$etag);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
    header_remove('Pragma');

    $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModified = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
        || ($ifNoneMatch === '' && $ifModified !== '' && @strtotime($ifModified) >= $mtime)
    ) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: '.filesize($file));
    // Drop any buffer the bootstrap opened, or the image gets a JSON prologue
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($file);
    exit;
}

/**
 * Drop thumbnails nobody has asked for in a long time.
 *
 * Runs on roughly one generation in fifty and touches at most a few hundred
 * entries, so it never turns one request into a directory-wide scan. Entries
 * are pure derivatives: deleting one only costs a regeneration.
 *
 * @param string $cacheDir
 * @param int    $maxAge seconds since last access (default 60 days)
 */
function sweepThumbnailCache($cacheDir, $maxAge = 5184000)
{
    if (random_int(1, 50) !== 1) {
        return;
    }

    $handle = @opendir($cacheDir);
    if ($handle === false) {
        return;
    }

    $cutoff = time() - $maxAge;
    $checked = 0;
    while (($entry = readdir($handle)) !== false && $checked < 500) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        ++$checked;
        $file = $cacheDir.$entry;
        // atime on a noatime mount tracks mtime, which is still the write time
        // of a file that is only ever written once — good enough for an upper
        // bound on "unused".
        $stamp = @fileatime($file);
        if ($stamp === false) {
            $stamp = @filemtime($file);
        }
        if ($stamp !== false && $stamp < $cutoff) {
            @unlink($file);
        }
    }
    closedir($handle);
}

/**
 * Serve a cached, down-scaled copy of one image from the storage.
 *
 * Exists because the listing used to hand the grid the original files: a real
 * folder here holds 2,649 images / 254 MB, and asking the browser for all of it
 * at once froze the tab. Thumbnails are generated once and reused; the URL
 * carries the source mtime, so a changed file gets a new URL instead of a stale
 * cache entry.
 *
 * Always exits.
 *
 * @param FileBrowserFiles $model  the store the path belongs to
 * @param string           $path   path relative to that store's baseDir
 * @param array            $config FileBrowser config
 */
function serveThumbnail(FileBrowserFiles $model, $path, array $config)
{
    // Containment (traversal, symlinks) is enforced by getFullPath()
    $fullPath = $model->getFullPath($path);
    if ($fullPath === false || !is_file($fullPath) || is_link($fullPath)) {
        sendError('Not found', 404);
    }

    $name = basename($fullPath);
    // Images only. Never let this endpoint become a reader for other file types.
    if (!FileBrowserFiles::isImage($name)) {
        sendError('Not an image', 404);
    }

    $width = isset($config['thumbnailWidth']) ? (int) $config['thumbnailWidth'] : 240;
    $width = max(60, min(600, $width));

    $mimeTypes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'
    ];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    /**
     * SVG cannot be rastered by GD, and it is vector anyway — send it through
     * untouched. Browsers never run scripts in an image loaded as <img>/CSS,
     * and uploads are screened by isSafeSvg() besides.
     */
    if (!FileBrowserFiles::isRasterImage($name)) {
        streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
    }

    // Small enough that a thumbnail would not pay for itself
    if (filesize($fullPath) <= 32768) {
        streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
    }

    $imageSize = @getimagesize($fullPath);
    if ($imageSize === false) {
        sendError('Not an image', 404);
    }

    /**
     * Decompression-bomb guard: GD allocates roughly 4 bytes per pixel, so a
     * small file declaring 20000x20000 would exhaust the worker's memory. Such
     * a file is served as-is (it is tiny on the wire) rather than decoded.
     */
    $maxPixels = isset($config['thumbnailMaxPixels']) ? (int) $config['thumbnailMaxPixels'] : 30000000;
    if ($imageSize[0] * $imageSize[1] > $maxPixels) {
        streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
    }

    // Already smaller than the thumbnail box — nothing to gain by re-encoding
    if ($imageSize[0] <= $width && $imageSize[1] <= $width) {
        streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
    }

    /**
     * WebP keeps the alpha channel of PNG/GIF sources and is the smallest of
     * the three; PNG is the fallback when GD has no WebP support. JPEG is only
     * safe for sources that cannot be transparent.
     */
    if (function_exists('imagewebp')) {
        $thumbExt = 'webp';
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $thumbExt = 'jpg';
    } else {
        $thumbExt = 'png';
    }

    $mtime = filemtime($fullPath);
    $quality = isset($config['imageQuality']) ? (int) $config['imageQuality'] : 85;
    // Hash covers everything that can change the output, so a stale entry is
    // impossible and the name itself can never carry a path.
    $key = sha1(implode('|', [$fullPath, $mtime, filesize($fullPath), $width, $quality, $thumbExt]));

    // Kept outside baseDir: the cache must not turn up in the file listing.
    $cacheDir = ROOT_PATH.DATA_FOLDER.'cache/fbthumbs/';
    $cacheFile = $cacheDir.$key.'.'.$thumbExt;

    if (!is_file($cacheFile)) {
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
        }

        try {
            \Kotchasan\Image::setQuality($quality);
            // Write to a temp name first: two requests for the same new
            // thumbnail must not read each other's half-written file.
            $tmpName = $key.'.'.getmypid().'.tmp.'.$thumbExt;
            $result = \Kotchasan\Image::resize($fullPath, $cacheDir, $tmpName, $width);
            if ($result === false || !is_file($cacheDir.$tmpName)) {
                @unlink($cacheDir.$tmpName);
                streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
            }
            @chmod($cacheDir.$tmpName, 0644);
            if (!@rename($cacheDir.$tmpName, $cacheFile)) {
                @unlink($cacheDir.$tmpName);
                streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
            }
            // A replaced or deleted source leaves its old thumbnail behind
            // (the hash covers the mtime), so sweep occasionally.
            sweepThumbnailCache($cacheDir);
        } catch (\Throwable $e) {
            // Corrupt or exotic image: fall back to the original
            streamFile($fullPath, $mimeTypes[$ext] ?? 'application/octet-stream', true);
        }
    }

    streamFile($cacheFile, $mimeTypes[$thumbExt], true);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication — read-only actions require a valid token;
// write actions additionally require document write permission (see below).
// ─────────────────────────────────────────────────────────────────────────────
$tokenPayload = authenticateRequest($gcmsSettings);
if ($tokenPayload === null) {
    sendError('Unauthorized', 401);
}

// ─────────────────────────────────────────────────────────────────────────────
// Get action
// ─────────────────────────────────────────────────────────────────────────────
$action = getParam('action', '');
if (empty($action)) {
    sendError('Missing action parameter');
}

// ─────────────────────────────────────────────────────────────────────────────
// Initialize Files model
// ─────────────────────────────────────────────────────────────────────────────
try {
    $files = new FileBrowserFiles([
        'baseDir' => $config['baseDir'],
        'webUrl' => $config['webUrl'],
        'thumbBaseUrl' => $config['selfUrl'].'?action=thumb&store=files'.($storageId === '' ? '' : '&storage='.rawurlencode($storageId)),
        'maxFileSize' => $config['maxFileSize'],
        'allowedExtensions' => $config['allowedExtensions'] ?? null
    ]);
    $preparedFiles = new FileBrowserFiles([
        'baseDir' => $config['presetBaseDir'],
        'webUrl' => $config['presetWebUrl'],
        'thumbBaseUrl' => $config['selfUrl'].'?action=thumb&store=prepared',
        'maxFileSize' => $config['maxFileSize'],
        'allowedExtensions' => $config['allowedExtensions'] ?? null,
        'createBaseDir' => false
    ]);
} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}

// Write operations must use POST
$writeActions = ['upload', 'create_folder', 'rename', 'delete', 'copy', 'move'];
if (in_array($action, $writeActions) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

// CSRF protection for write operations: when authentication relies on the
// auth_token cookie (sent automatically by the browser), require a custom
// request header. Custom headers cannot be attached by cross-site HTML forms
// and force a CORS preflight for cross-origin fetch/XHR, which blocks CSRF.
if (in_array($action, $writeActions)) {
    $hasHeaderAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_ACCESS_TOKEN']);
    $hasCustomHeader = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
    if (!$hasHeaderAuth && !$hasCustomHeader) {
        sendError('Missing CSRF protection header', 403);
    }
}

// Write operations require write permission. The rule is pluggable:
// config 'canWrite' (callable payload => bool) overrides the built-in
// canWriteFiles() GCMS rule. Disable entirely with
// 'uploadRequiresWritePermission' => false.
$requiresWritePermission = !isset($config['uploadRequiresWritePermission']) || !empty($config['uploadRequiresWritePermission']);
if (in_array($action, $writeActions) && $requiresWritePermission) {
    $writeCheck = isset($config['canWrite']) && is_callable($config['canWrite'])
        ? $config['canWrite']
        : 'canWriteFiles';
    if (!$writeCheck($tokenPayload, $config)) {
        sendError('Forbidden: insufficient privileges', 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Route action
// ─────────────────────────────────────────────────────────────────────────────
try {
    switch ($action) {
    case 'get_preset_categories':
        // Subfolders under presetBaseDir only (read-only library, optional on disk).
        // 'available' tells the client whether the prepared folder exists at all,
        // so it can hide the "Prepared file" tab when the folder is missing.
        $presetAvailable = is_dir($config['presetBaseDir']);
        $categories = [];
        if ($presetAvailable) {
            $rootResult = $preparedFiles->getFolderTree('/', 1);
            if (is_array($rootResult)) {
                foreach ($rootResult as $folder) {
                    $categories[] = [
                        'id' => ltrim($folder['path'], '/'),
                        'name' => $folder['name'],
                        'icon' => 'icon-folder'
                    ];
                }
            }
        }
        $result = [
            'success' => true,
            'data' => ['categories' => $categories, 'available' => $presetAvailable] + storageInfo($storages, $storageId)
        ];
        break;

    case 'get_presets':
        $category = trim((string) getParam('category', ''));
        // Prepared files: only inside named subfolders (no root "all" listing)
        if ($category === '' || strcasecmp($category, 'all') === 0) {
            $result = [
                'success' => true,
                'data' => ['files' => [], 'path' => '/']
            ];
            break;
        }
        $presetsPath = '/'.$category;
        $presetsResult = $preparedFiles->getFiles(
            $presetsPath,
            (string) getParam('search', ''),
            (string) getParam('sort_by', 'name'),
            (string) getParam('sort_dir', 'asc')
        );
        if (!isset($presetsResult['error'])) {
            $presetFileItems = array_values(array_filter(
                $presetsResult['items'] ?? [],
                fn($item) => $item['type'] !== 'folder'
            ));
            $result = [
                'success' => true,
                'data' => [
                    'files' => $presetFileItems,
                    'path' => $presetsResult['path'] ?? $presetsPath
                ]
            ];
        } else {
            $result = [
                'success' => true,
                'data' => ['files' => [], 'path' => $presetsPath]
            ];
        }
        break;

    case 'get_files':
        $path = getParam('path', '/');
        $filesResult = $files->getFiles(
            $path,
            (string) getParam('search', ''),
            (string) getParam('sort_by', 'name'),
            (string) getParam('sort_dir', 'asc')
        );
        if (!isset($filesResult['error'])) {
            $result = [
                'success' => true,
                'data' => [
                    'files' => $filesResult['items'] ?? [],
                    'path' => $filesResult['path'] ?? $path
                ]
            ];
        } else {
            $result = $filesResult;
        }
        break;

    case 'thumb':
        // Read-only, token-checked like every other GET; always exits
        $store = getParam('store', 'files');
        serveThumbnail(
            $store === 'prepared' ? $preparedFiles : $files,
            (string) getParam('path', ''),
            $config
        );
        break;

    case 'get_folder_tree':
        $path = getParam('path', '/');
        $depth = (int) getParam('depth', 3);
        $treeData = $files->getFolderTree($path, min($depth, 5));
        $result = [
            'success' => true,
            'data' => ['folders' => is_array($treeData) ? $treeData : []] + storageInfo($storages, $storageId)
        ];
        break;

    case 'upload':
        $path = getParam('path', '/');
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            // Multiple files upload
            $uploadedFiles = $_FILES['files'];
            $count = count($uploadedFiles['name']);
            $uploaded = 0;
            $uploadErrors = [];
            $lastResult = null;

            for ($i = 0; $i < $count; $i++) {
                $singleFile = [
                    'name' => $uploadedFiles['name'][$i],
                    'type' => $uploadedFiles['type'][$i],
                    'tmp_name' => $uploadedFiles['tmp_name'][$i],
                    'error' => $uploadedFiles['error'][$i],
                    'size' => $uploadedFiles['size'][$i]
                ];
                $r = $files->upload($singleFile, $path);
                if (!empty($r['success'])) {
                    if (!empty($r['file'])) {
                        $r['file'] = resizeUploadedImage(
                            $r['file'],
                            $files->getConfig('baseDir'),
                            $config
                        );
                    }
                    $uploaded++;
                    $lastResult = $r;
                } else {
                    $uploadErrors[] = $singleFile['name'].': '.($r['error'] ?? 'failed');
                }
            }

            $result = [
                'success' => $uploaded > 0,
                'uploaded' => $uploaded,
                'total' => $count,
                'errors' => $uploadErrors,
                'message' => "Uploaded {$uploaded}/{$count} files",
                'file' => $lastResult['file'] ?? null
            ];
        } else {
            // Single file upload (field name: file or files)
            $uploadFile = $_FILES['file'] ?? $_FILES['files'] ?? null;
            if (!$uploadFile) {
                sendError('No file uploaded');
            }
            $r = $files->upload($uploadFile, $path);
            if (!empty($r['success']) && !empty($r['file'])) {
                $r['file'] = resizeUploadedImage(
                    $r['file'],
                    $files->getConfig('baseDir'),
                    $config
                );
            }
            $result = array_merge($r, [
                'uploaded' => !empty($r['success']) ? 1 : 0,
                'total' => 1
            ]);
        }
        break;

    case 'create_folder':
        $path = getParam('path', '/');
        $name = getParam('name', '');
        if (empty($name)) {
            sendError('Folder name required');
        }
        $result = $files->createFolder($path, $name);
        break;

    case 'rename':
        $path = getParam('path', '');
        $name = getParam('new_name', '') ?: getParam('name', '');
        if (empty($path) || empty($name)) {
            sendError('Path and name required');
        }
        $result = $files->rename($path, $name);
        break;

    case 'delete':
        $path = getParam('path', '');
        if (empty($path)) {
            sendError('Path required');
        }
        $result = $files->delete($path);
        break;

    case 'copy':
        $source = getParam('source', '');
        $destination = getParam('destination', '');
        if (empty($source) || empty($destination)) {
            sendError('Source and destination required');
        }
        $result = $files->copy($source, $destination);
        break;

    case 'move':
        $source = getParam('source', '');
        $destination = getParam('destination', '');
        if (empty($source) || empty($destination)) {
            sendError('Source and destination required');
        }
        $result = $files->move($source, $destination);
        break;

    default:
        sendError('Unknown action: '.$action);
    }

    if (isset($result['error'])) {
        sendError($result['error']);
    }

    sendResponse($result);
} catch (Exception $e) {
    // Do not leak internal exception details (paths, framework messages) to clients
    error_log('FileBrowser error: '.$e->getMessage());
    sendError('Internal server error', 500);
}
