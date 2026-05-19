<?php
/**
 * @filesource modules/index/models/auth.php
 *
 * Authentication Model - Production-grade security
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Auth;

use Kotchasan\Database\Sql;

/**
 * Authentication Model
 *
 * Handles secure authentication with database
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Maximum login attempts before lockout
     */
    const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Lockout duration in seconds (30 minutes)
     */
    const LOCKOUT_DURATION = 1800;

    /**
     * Token expiry time in seconds (24 hours)
     */
    const TOKEN_EXPIRY = 86400;

    /**
     * Refresh token expiry time in seconds (7 days)
     */
    const REFRESH_TOKEN_EXPIRY = 604800;

    /**
     * Authenticate user with username/email and password
     *
     * @param string $username Username or email
     * @param string $password Plain text password
     * @param string $clientIp Client IP for logging
     *
     * @return array Authentication result
     */
    public static function authenticate($username, $password, $clientIp = null, array $deviceContext = [])
    {
        // Sanitize input
        $username = trim($username);

        // Check for empty credentials
        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => 'Username and password are required.',
                'code' => 'INVALID_INPUT'
            ];
        }

        // Check rate limiting
        $rateLimitResult = self::checkRateLimit($username, $clientIp);
        if (!$rateLimitResult['allowed']) {
            return [
                'success' => false,
                'message' => $rateLimitResult['message'],
                'code' => 'RATE_LIMITED',
                'retry_after' => $rateLimitResult['retry_after']
            ];
        }

        // Query user from database
        $user = self::findUserByCredentials($username);

        if (!$user) {
            // Record failed attempt
            self::recordLoginAttempt($username, $clientIp, false);

            // Generic error message to prevent user enumeration
            return [
                'success' => false,
                'message' => 'Invalid username or password',
                'code' => 'AUTH_FAILED'
            ];
        }

        // Verify password
        if (!self::verifyPassword($password, $user->password, $user->salt)) {
            // Record failed attempt
            self::recordLoginAttempt($username, $clientIp, false);

            return [
                'success' => false,
                'message' => 'Invalid username or password',
                'code' => 'AUTH_FAILED'
            ];
        }

        // Check if account is active
        if ($user->active != 1 && $user->id != 1) {
            return [
                'success' => false,
                'message' => 'Your account is not active',
                'code' => 'ACCOUNT_INACTIVE'
            ];
        }

        // Check if email verification is pending
        if (!empty($user->activatecode) && $user->id != 1) {
            return [
                'success' => false,
                'message' => 'Please verify your email address first',
                'code' => 'EMAIL_NOT_VERIFIED'
            ];
        }

        // Record successful login
        self::recordLoginAttempt($username, $clientIp, true);

        // Generate tokens
        $tokens = self::generateTokens($user->id, $deviceContext);

        // Update user record with new token
        self::updateUserToken($user->id, $tokens['access_token'], $tokens['expires_at']);

        // Register active auth session/device metadata
        self::touchSession($user->id, $tokens, $deviceContext, 'login');

        // Log successful login
        self::logLoginActivity($user->id, 'Login successful', $clientIp);

        // Prepare user data (exclude sensitive fields)
        $userData = self::sanitizeUserData($user);

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $userData,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Find user by username or email
     *
     * @param string $username
     *
     * @return object|null
     */
    public static function findUserByCredentials($username)
    {
        // Build WHERE conditions based on login fields
        $where = [];
        $loginFields = self::$cfg->login_fields ?? ['username'];

        foreach ($loginFields as $field) {
            $fieldName = ($field === 'email' || $field === 'username') ? 'username' : $field;
            $where[$fieldName] = ['U.'.$fieldName, $username];
        }

        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas', ','))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.username', '!=', ''])
            ->where(array_values($where), 'OR')
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Verify password against stored hash
     *
     * @param string $password Plain text password
     * @param string $hash Stored password hash
     * @param string $salt User's salt
     *
     * @return bool
     */
    public static function verifyPassword($password, $hash, $salt)
    {
        $passwordKey = self::$cfg->password_key ?? '';
        $computedHash = sha1($passwordKey.$password.$salt);

        return hash_equals($hash, $computedHash);
    }

    /**
     * Hash password for storage
     *
     * @param string $password Plain text password
     * @param string|null $salt Optional salt (generates new one if not provided)
     *
     * @return array ['hash' => string, 'salt' => string]
     */
    public static function hashPassword($password, $salt = null)
    {
        $salt = $salt ?? \Kotchasan\Password::uniqid();
        $passwordKey = self::$cfg->password_key ?? '';
        $hash = sha1($passwordKey.$password.$salt);

        return [
            'hash' => $hash,
            'salt' => $salt
        ];
    }

    /**
     * Set authentication cookie
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     */
    public static function setCookie($name, $value)
    {
        $options = [
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'expires' => time() + self::TOKEN_EXPIRY
        ];

        setcookie($name, $value, $options);
    }

    /**
     * Generate access and refresh tokens
     *
     * @param int $userId
     *
     * @return array
     */
    public static function generateTokens($userId, array $deviceContext = [], $sessionId = null)
    {
        $now = time();
        $sessionId = $sessionId ?: bin2hex(random_bytes(12));
        $fingerprint = $deviceContext['fingerprint'] ?? null;

        $extraPayload = [
            'sid' => $sessionId
        ];
        if (!empty($fingerprint)) {
            $extraPayload['dfp'] = $fingerprint;
        }

        // Generate cryptographically secure tokens
        $accessToken = self::generateSecureToken($userId, $now, self::TOKEN_EXPIRY, 'access', $extraPayload);
        $refreshToken = self::generateSecureToken($userId, $now, self::REFRESH_TOKEN_EXPIRY, 'refresh', $extraPayload);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $now + self::TOKEN_EXPIRY,
            'refresh_expires_at' => $now + self::REFRESH_TOKEN_EXPIRY,
            'session_id' => $sessionId,
            'device_fingerprint' => $fingerprint
        ];
    }

    /**
     * Generate a secure token
     *
     * @param int $userId
     * @param int $timestamp
     * @param int $expiry
     * @param string $type
     * @param array $extraPayload Additional payload data (e.g., impersonated_by)
     *
     * @return string
     */
    private static function generateSecureToken($userId, $timestamp, $expiry, $type = 'access', $extraPayload = [])
    {
        // Create payload
        $payload = [
            'sub' => $userId,
            'iat' => $timestamp,
            'exp' => $timestamp + $expiry,
            'type' => $type,
            'jti' => bin2hex(random_bytes(16))
        ];

        // Merge extra payload (e.g., impersonated_by for impersonation)
        if (!empty($extraPayload)) {
            $payload = array_merge($payload, $extraPayload);
        }

        // Encode payload
        $payloadJson = json_encode($payload);
        $payloadBase64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');

        // Create signature
        $secret = self::getTokenSecret();
        $signature = hash_hmac('sha256', $payloadBase64, $secret);

        // Return token
        return $payloadBase64.'.'.$signature;
    }

    /**
     * Verify and decode a token
     *
     * @param string $token
     *
     * @return array|null Returns decoded payload or null if invalid
     */
    public static function verifyToken($token)
    {
        if (empty($token)) {
            return null;
        }

        // Split token
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        list($payloadBase64, $signature) = $parts;

        // Verify signature
        $secret = self::getTokenSecret();
        $expectedSignature = hash_hmac('sha256', $payloadBase64, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payloadJson = base64_decode(strtr($payloadBase64, '-_', '+/'));
        $payload = json_decode($payloadJson, true);

        if (!$payload || !isset($payload['exp']) || !isset($payload['sub'])) {
            return null;
        }

        if (self::isTokenRevoked($payload)) {
            return null;
        }

        // Check expiration
        if ($payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Get token secret from config
     *
     * @return string
     */
    private static function getTokenSecret()
    {
        // Try to get from config
        $secret = self::$cfg->jwt_secret ?? self::$cfg->password_key ?? null;

        if (empty($secret)) {
            // Generate and store if not exists
            $secret = bin2hex(random_bytes(32));
            // In production, this should be stored in config
        }

        return $secret;
    }

    /**
     * Update user token in database
     *
     * @param int $userId
     * @param string $token
     * @param int $expiresAt Unix timestamp
     */
    public static function updateUserToken($userId, $token, $expiresAt)
    {
        // Convert timestamp to datetime format for database
        $expiresDateTime = date('Y-m-d H:i:s', $expiresAt);

        \Kotchasan\DB::create()->update('user', ['id', $userId], [
            'token' => $token,
            'token_expires' => $expiresDateTime,
            'visited' => Sql::create('`visited` + 1')
        ]);
    }

    /**
     * Get user by token
     *
     * @param string $token
     *
     * @return object|null
     */
    public static function getUserByToken($token)
    {
        // First verify the token structure
        $payload = self::verifyToken($token);
        if (!$payload) {
            return null;
        }

        $userId = $payload['sub'];
        $currentDateTime = date('Y-m-d H:i:s');

        // Get user from database
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas'))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where([
                ['U.id', $userId],
                ['U.token', $token],
                ['U.token_expires', '>=', $currentDateTime]
            ])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Get user by ID
     *
     * @param int $userId
     *
     * @return object|null
     */
    public static function getUserById($userId)
    {
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas', ','))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.id', $userId])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Refresh access token
     *
     * @param string $refreshToken
     *
     * @return array
     */
    public static function refreshAccessToken($refreshToken, array $deviceContext = [])
    {
        // Verify refresh token
        $payload = self::verifyToken($refreshToken);

        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            return [
                'success' => false,
                'message' => 'Invalid refresh token',
                'code' => 'INVALID_TOKEN'
            ];
        }

        // Device/session binding check
        $payloadFingerprint = $payload['dfp'] ?? null;
        $currentFingerprint = $deviceContext['fingerprint'] ?? null;
        if (!empty($payloadFingerprint) && !empty($currentFingerprint) && !hash_equals($payloadFingerprint, $currentFingerprint)) {
            return [
                'success' => false,
                'message' => 'Device mismatch for refresh token',
                'code' => 'INVALID_DEVICE'
            ];
        }

        $userId = $payload['sub'];

        // Get user
        $user = self::getUserById($userId);
        if (!$user || $user->active != 1) {
            return [
                'success' => false,
                'message' => 'No data available or inactive',
                'code' => 'USER_INVALID'
            ];
        }

        // Generate new tokens
        // Rotate refresh token and revoke previous refresh JTI.
        self::revokeTokenPayload($payload);
        $tokens = self::generateTokens($userId, $deviceContext, $payload['sid'] ?? null);

        // Update user record
        self::updateUserToken($userId, $tokens['access_token'], $tokens['expires_at']);
        self::touchSession($userId, $tokens, $deviceContext, 'refresh');

        return [
            'success' => true,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer',
            'user' => self::sanitizeUserData($user)
        ];
    }

    /**
     * Logout user
     *
     * @param string $token
     * @param int|null $userId
     *
     * @return bool
     */
    public static function logout($token, $userId = null)
    {
        $where = [];
        $payload = null;
        if (!empty($token)) {
            $payload = self::verifyToken($token);
            if ($payload) {
                self::revokeTokenPayload($payload);
            }
        }
        if ($userId) {
            $where[] = ['id', $userId];
        }
        if ($token) {
            $where[] = ['token', $token];
        }
        if (!empty($where)) {
            // Clear token in database
            \Kotchasan\Model::createQuery()
                ->update('user')
                ->where($where, count($where) == 1 ? 'AND' : 'OR')
                ->set([
                    'token' => null,
                    'token_expires' => null
                ])->execute();

            // Log logout
            self::logLoginActivity($userId, 'Logout', null);
        }

        if (!empty($userId) && !empty($payload['sid'])) {
            self::removeSession($userId, $payload['sid']);
        }

        return true;
    }

    /**
     * Store and update session/device metadata.
     *
     * @param int $userId
     * @param array $tokens
     * @param array $deviceContext
     * @param string $event
     */
    private static function touchSession($userId, array $tokens, array $deviceContext, $event)
    {
        if (empty($tokens['session_id'])) {
            return;
        }

        $store = self::readJsonStore(self::getSessionStorePath());
        if (!isset($store[$userId]) || !is_array($store[$userId])) {
            $store[$userId] = [];
        }

        $sid = $tokens['session_id'];
        $store[$userId][$sid] = [
            'session_id' => $sid,
            'fingerprint' => $tokens['device_fingerprint'] ?? ($deviceContext['fingerprint'] ?? null),
            'ip' => $deviceContext['ip'] ?? null,
            'user_agent' => $deviceContext['user_agent'] ?? null,
            'last_event' => $event,
            'last_seen' => date('c'),
            'refresh_expires_at' => date('c', $tokens['refresh_expires_at'] ?? time() + self::REFRESH_TOKEN_EXPIRY)
        ];

        self::writeJsonStore(self::getSessionStorePath(), $store);
    }

    /**
     * Remove one tracked session by session ID.
     *
     * @param int $userId
     * @param string $sessionId
     */
    private static function removeSession($userId, $sessionId)
    {
        if (empty($sessionId)) {
            return;
        }

        $store = self::readJsonStore(self::getSessionStorePath());
        if (isset($store[$userId][$sessionId])) {
            unset($store[$userId][$sessionId]);
            if (empty($store[$userId])) {
                unset($store[$userId]);
            }
            self::writeJsonStore(self::getSessionStorePath(), $store);
        }
    }

    /**
     * Revoke token payload by JTI until its expiration.
     *
     * @param array $payload
     */
    private static function revokeTokenPayload(array $payload)
    {
        if (empty($payload['jti']) || empty($payload['exp'])) {
            return;
        }

        $store = self::readJsonStore(self::getRevokedStorePath());
        $store[$payload['jti']] = (int) $payload['exp'];
        self::writeJsonStore(self::getRevokedStorePath(), $store);
    }

    /**
     * Check whether token JTI is revoked.
     *
     * @param array $payload
     *
     * @return bool
     */
    private static function isTokenRevoked(array $payload)
    {
        if (empty($payload['jti'])) {
            return false;
        }

        $storePath = self::getRevokedStorePath();
        $store = self::readJsonStore($storePath);
        $now = time();

        // Cleanup expired revocation entries.
        $dirty = false;
        foreach ($store as $jti => $exp) {
            if ((int) $exp <= $now) {
                unset($store[$jti]);
                $dirty = true;
            }
        }
        if ($dirty) {
            self::writeJsonStore($storePath, $store);
        }

        return isset($store[$payload['jti']]);
    }

    /**
     * Read JSON store from disk.
     *
     * @param string $path
     *
     * @return array
     */
    private static function readJsonStore($path)
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write JSON store to disk.
     *
     * @param string $path
     * @param array $data
     */
    private static function writeJsonStore($path, array $data)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Path to revoked token store.
     *
     * @return string
     */
    private static function getRevokedStorePath()
    {
        return ROOT_PATH.DATA_FOLDER.'cache/revoked_tokens.json';
    }

    /**
     * Path to auth session store.
     *
     * @return string
     */
    private static function getSessionStorePath()
    {
        return ROOT_PATH.DATA_FOLDER.'cache/auth_sessions.json';
    }

    /**
     * Check rate limiting for login attempts
     *
     * @param string $username
     * @param string|null $clientIp
     *
     * @return array
     */
    public static function checkRateLimit($username, $clientIp = null)
    {
        $key = self::getRateLimitKey($username, $clientIp);
        $now = time();

        // Check from cache/session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        $attempts = $_SESSION['login_attempts'][$key] ?? null;

        if ($attempts) {
            // Check if locked out
            if ($attempts['locked_until'] && $attempts['locked_until'] > $now) {
                $retryAfter = $attempts['locked_until'] - $now;
                return [
                    'allowed' => false,
                    'message' => "Too many login attempts. Please try again in ".ceil($retryAfter / 60)." minutes.",
                    'retry_after' => $retryAfter
                ];
            }

            // Reset if lockout expired
            if ($attempts['locked_until'] && $attempts['locked_until'] <= $now) {
                unset($_SESSION['login_attempts'][$key]);
            }
        }

        return ['allowed' => true];
    }

    /**
     * Record login attempt for rate limiting
     *
     * @param string $username
     * @param string|null $clientIp
     * @param bool $success
     */
    public static function recordLoginAttempt($username, $clientIp, $success)
    {
        $key = self::getRateLimitKey($username, $clientIp);
        $now = time();

        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if ($success) {
            // Clear attempts on successful login
            unset($_SESSION['login_attempts'][$key]);
            return;
        }

        // Initialize or update attempts
        if (!isset($_SESSION['login_attempts'][$key])) {
            $_SESSION['login_attempts'][$key] = [
                'count' => 0,
                'first_attempt' => $now,
                'locked_until' => null
            ];
        }

        $attempts = &$_SESSION['login_attempts'][$key];
        $attempts['count']++;
        $attempts['last_attempt'] = $now;

        // Check if should lock
        if ($attempts['count'] >= self::MAX_LOGIN_ATTEMPTS) {
            $attempts['locked_until'] = $now + self::LOCKOUT_DURATION;
        }
    }

    /**
     * Get rate limit key
     *
     * @param string $username
     * @param string|null $clientIp
     *
     * @return string
     */
    private static function getRateLimitKey($username, $clientIp)
    {
        // Use both username and IP for more granular control
        return md5($username.':'.($clientIp ?? 'unknown'));
    }

    /**
     * Log login activity
     *
     * @param int $userId
     * @param string $action
     * @param string|null $clientIp
     */
    public static function logLoginActivity($userId, $action, $clientIp)
    {
        try {
            \Index\Log\Model::add($userId, 'index', 'Auth', $action.' IP: '.($clientIp ?? 'unknown'), $userId);
        } catch (\Exception $e) {
            // Silently fail - logging should not break authentication
        }
    }

    /**
     * Sanitize user data for response (remove sensitive fields)
     *
     * @param object $user
     *
     * @return array
     */
    public static function sanitizeUserData($user)
    {
        $data = (array) $user;

        // Remove sensitive fields
        unset(
            $data['password'],
            $data['salt'],
            $data['token'],
            $data['token_expires'],
            $data['activatecode']
        );

        return $data;
    }

    /**
     * Parse and Normalize permission
     * Ensure API always returns an array of non-empty permission keys
     *
     * @param mixed $permission
     *
     * @return array
     */
    public static function parsePermission($permission)
    {
        if (is_array($permission)) {
            $perms = $permission;
        } elseif (is_string($permission)) {
            $perms = empty($permission)
                ? []
                : explode(',', trim($permission, " \t\n\r\0\x0B,"));
        } else {
            $perms = [];
        }

        $perms = array_map('trim', $perms);
        $perms = array_filter($perms, function ($v) {
            return $v !== '';
        });
        return array_values($perms);
    }

    /**
     * Parse and normalize meta data from GROUP_CONCAT format
     * Converts 'key1|value1,key2|value2' into ['key1' => ['value1'], 'key2' => ['value2']]
     *
     * @param string|null $metaData The concatenated meta data string
     *
     * @return array Associative array of meta keys to value arrays
     */
    public static function parseMeta($metaData)
    {
        if (empty($metaData)) {
            return [];
        }

        $metas = [];
        foreach (explode(',', $metaData) as $meta) {
            if (strpos($meta, '|') === false) {
                continue; // Skip malformed entries
            }

            [$key, $value] = explode('|', $meta, 2);
            $metas[$key][] = $value;
        }

        return $metas;
    }

    /**
     * Update user profile
     *
     * @param int $userId
     * @param array $data
     * @param string|null $newPassword
     *
     * @return array
     */
    public static function updateProfile($userId, array $data, $newPassword = null)
    {
        // Validate user exists
        $user = self::getUserById($userId);
        if (!$user) {
            return [
                'success' => false,
                'message' => 'No data available',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Prepare update data
        $updateData = [];

        // Allowed fields for update
        $allowedFields = ['name', 'phone', 'sex', 'provinceID', 'address', 'address2', 'zipcode', 'birthday', 'website', 'company'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // Handle password change
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                return [
                    'success' => false,
                    'message' => 'Password must be at least 8 characters',
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            $passwordData = self::hashPassword($newPassword);
            $updateData['password'] = $passwordData['hash'];
            $updateData['salt'] = $passwordData['salt'];
        }

        // Update database
        if (!empty($updateData)) {
            \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
        }

        return [
            'success' => true,
            'message' => 'Profile updated successfully'
        ];
    }

    /**
     * Impersonate user (SuperAdmin only)
     * Reuses generateSecureToken() with extra impersonation flag
     *
     * @param int $adminId SuperAdmin ID (must be ID 1)
     * @param int $targetUserId Target user ID to impersonate
     * @param string $clientIp Client IP for logging
     *
     * @return array Result with token or error
     */
    public static function impersonateUser($adminId, $targetUserId, $clientIp = null)
    {
        // Security check: Only SuperAdmin (ID 1) can impersonate
        if ($adminId != 1) {
            return [
                'success' => false,
                'message' => 'Only SuperAdmin can impersonate users',
                'code' => 'FORBIDDEN'
            ];
        }

        // Cannot impersonate yourself
        if ($adminId == $targetUserId) {
            return [
                'success' => false,
                'message' => 'Cannot impersonate yourself',
                'code' => 'INVALID_TARGET'
            ];
        }

        // Reuse getUserById() - same query as normal login (with parse permission)
        $targetUser = self::getUserById($targetUserId);

        if (!$targetUser) {
            return [
                'success' => false,
                'message' => 'Target user not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Reuse generateSecureToken() with extra impersonation flag
        $now = time();
        $accessToken = self::generateSecureToken($targetUserId, $now, self::TOKEN_EXPIRY, 'access', [
            'impersonated_by' => $adminId
        ]);

        // Reuse updateUserToken() - same as normal login
        self::updateUserToken($targetUserId, $accessToken, $now + self::TOKEN_EXPIRY);

        // Reuse logLoginActivity() - same pattern as normal login
        self::logLoginActivity($targetUserId, 'Impersonate start by Admin#'.$adminId, $clientIp);

        // Reuse sanitizeUserData() - same as normal login
        $userData = self::sanitizeUserData($targetUser);

        return [
            'success' => true,
            'message' => 'Impersonation started',
            'user' => $userData,
            'token' => $accessToken,
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Check if current token is impersonating
     *
     * @param string $token
     *
     * @return array|null Returns ['admin_id' => int, 'user_id' => int] or null
     */
    public static function isImpersonating($token)
    {
        $payload = self::verifyToken($token);

        if (!$payload || !isset($payload['impersonated_by'])) {
            return null;
        }

        return [
            'admin_id' => $payload['impersonated_by'],
            'user_id' => $payload['sub']
        ];
    }

    /**
     * Restore admin session from impersonation
     * Reuses getUserById(), generateTokens(), updateUserToken(), sanitizeUserData()
     *
     * @param string $currentToken Current impersonated token
     * @param string $clientIp Client IP for logging
     *
     * @return array Result with admin token or error
     */
    public static function restoreAdmin($currentToken, $clientIp = null)
    {
        // Check if currently impersonating
        $impersonateInfo = self::isImpersonating($currentToken);

        if (!$impersonateInfo) {
            return [
                'success' => false,
                'message' => 'Not currently impersonating',
                'code' => 'NOT_IMPERSONATING'
            ];
        }

        $adminId = $impersonateInfo['admin_id'];
        $userId = $impersonateInfo['user_id'];

        // Get user
        $admin = self::getUserById($adminId);

        if (!$admin) {
            return [
                'success' => false,
                'message' => 'Original admin not found',
                'code' => 'ADMIN_NOT_FOUND'
            ];
        }

        // Reuse generateTokens() - same as normal login (clean token, no impersonation flag)
        $tokens = self::generateTokens($adminId);

        // Reuse updateUserToken() - same as normal login
        self::updateUserToken($adminId, $tokens['access_token'], $tokens['expires_at']);

        // Reuse logLoginActivity() - same pattern as normal login
        self::logLoginActivity($adminId, 'Impersonate end from User#'.$userId, $clientIp);

        // Reuse sanitizeUserData() - same as normal login
        $adminData = self::sanitizeUserData($admin);

        return [
            'success' => true,
            'message' => 'Restored to admin session',
            'user' => $adminData,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }
}
