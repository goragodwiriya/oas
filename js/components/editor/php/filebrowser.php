<?php
/**
 * FileBrowser API Endpoint
 *
 * Authentication  : Bearer JWT validated via \Kotchasan\Jwt using the
 *                   jwt_secret from settings/config.php.
 * Path resolution : ROOT_PATH / DATA_FOLDER / WEB_URL constants
 *                   defined by load.php (Kotchasan bootstrap).
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

// ── Override image config from Gcms\Config (runtime serialized values) ────────
// stored_img_size  → imageMaxWidth  (Gcms default: 800)
// stored_img_type  → imageConvertToWebP  ('.webp' means convert, anything else means keep format)
// image_quality    → imageQuality   (if present in runtime settings)
if (isset($gcmsSettings['stored_img_size']) && $gcmsSettings['stored_img_size'] > 0) {
    $config['imageMaxWidth'] = (int) $gcmsSettings['stored_img_size'];
}
if (isset($gcmsSettings['stored_img_type'])) {
    $config['imageConvertToWebP'] = (strtolower(trim($gcmsSettings['stored_img_type'], '.')) === 'webp');
}
if (isset($gcmsSettings['image_quality']) && $gcmsSettings['image_quality'] > 0) {
    $config['imageQuality'] = (int) $gcmsSettings['image_quality'];
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
 * @param  int   $minStatus    Minimum JWT 'status' value required (0 = any authenticated user)
 * @return bool
 */
function authenticateRequest(array $gcmsSettings, int $minStatus = 0)
{
    $token = extractToken();
    if ($token === null) {
        return false;
    }

    $secret = $gcmsSettings['jwt_secret'] ?? null;
    if (empty($secret)) {
        return false;
    }

    $payload = verifyToken($token, $secret);
    if ($payload === null) {
        return false;
    }

    // Enforce minimum status level when required
    if ($minStatus > 0) {
        $userStatus = isset($payload['status']) ? (int) $payload['status'] : -1;
        if ($userStatus < $minStatus) {
            return false;
        }
    }

    return true;
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

    $maxWidth = isset($config['imageMaxWidth']) ? (int) $config['imageMaxWidth'] : 1200;
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

    $needsResize = ($maxWidth > 0 && $imageSize[0] > $maxWidth);
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

// ─────────────────────────────────────────────────────────────────────────────
// Authentication — read-only actions require a valid token;
// write actions additionally enforce the configured minimum status level.
// ─────────────────────────────────────────────────────────────────────────────
if (!authenticateRequest($gcmsSettings)) {
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
        'maxFileSize' => $config['maxFileSize'],
        'allowedExtensions' => $config['allowedExtensions'] ?? null
    ]);
} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}

// Write operations must use POST
$writeActions = ['upload', 'create_folder', 'rename', 'delete', 'copy', 'move'];
if (in_array($action, $writeActions) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

// Write operations require the configured minimum status level
$uploadMinStatus = isset($config['uploadMinStatus']) ? (int) $config['uploadMinStatus'] : 0;
if (in_array($action, $writeActions) && $uploadMinStatus > 0) {
    if (!authenticateRequest($gcmsSettings, $uploadMinStatus)) {
        sendError('Forbidden: insufficient privileges', 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Route action
// ─────────────────────────────────────────────────────────────────────────────
try {
    switch ($action) {
        case 'get_preset_categories':
            // Scan actual subdirectories in baseDir as categories
            $categories = [];
            $rootResult = $files->getFolderTree('/', 1);
            if (is_array($rootResult)) {
                foreach ($rootResult as $folder) {
                    $categories[] = [
                        'id' => ltrim($folder['path'], '/'),
                        'name' => $folder['name'],
                        'icon' => 'icon-folder'
                    ];
                }
            }
            // Fallback to config categories if no real folders found
            if (empty($categories)) {
                $categories = $config['presetCategories'];
            }
            $result = [
                'success' => true,
                'data' => ['categories' => $categories]
            ];
            break;

        case 'get_presets':
            $category = getParam('category', '');
            $presetsPath = ($category && $category !== 'all') ? '/'.$category : '/';
            $presetsResult = $files->getFiles($presetsPath);
            if (!isset($presetsResult['error'])) {
                $presetFiles = array_values(array_filter(
                    $presetsResult['items'] ?? [],
                    fn($item) => $item['type'] !== 'folder'
                ));
                $result = [
                    'success' => true,
                    'data' => [
                        'files' => $presetFiles,
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
            $filesResult = $files->getFiles($path);
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

        case 'get_folder_tree':
            $path = getParam('path', '/');
            $depth = (int) getParam('depth', 3);
            $treeData = $files->getFolderTree($path, min($depth, 5));
            $result = [
                'success' => true,
                'data' => ['folders' => is_array($treeData) ? $treeData : []]
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
    sendError($e->getMessage(), 500);
}
