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
     * Token expiry time in seconds (24 hours)
     */
    const TOKEN_EXPIRY = 86400;

    /**
     * Refresh token expiry time in seconds (7 days)
     */
    const REFRESH_TOKEN_EXPIRY = 604800;

    /**
     * Odds of sweeping expired sessions, per token issued (1 in N).
     *
     * Mirrors PHP's own session.gc_probability/gc_divisor so no cron job is
     * required — many shared hosts do not offer one. 100 means roughly one
     * sweep per 100 logins/refreshes: often enough that dead rows never pile
     * up, cheap enough not to slow a login down (the delete uses the
     * expires_at index).
     *
     * Set to 0 to disable the automatic sweep and drive
     * purgeExpiredSessions() from a scheduled task instead.
     */
    const SESSION_GC_DIVISOR = 100;

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

        if (!$user || !self::verifyPassword($password, $user->password, $user->salt)) {
            // Record failed attempt
            self::recordLoginAttempt($username, $clientIp, false, (string) ($deviceContext['user_agent'] ?? ''));

            // Generic error message to prevent user enumeration
            return [
                'success' => false,
                'message' => 'Invalid username or password',
                'code' => 'AUTH_FAILED'
            ];
        }

        // Transparent upgrade of legacy sha1 hashes: the plain password is only
        // known here, so this is the moment to store it in the current form
        if (self::passwordNeedsRehash($user->password)) {
            $rehashed = self::hashPassword($password, $user->salt);
            \Kotchasan\DB::create()->update('user', [['id', (int) $user->id]], ['password' => $rehashed['hash']]);
            $user->password = $rehashed['hash'];
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
        return \Kotchasan\Password::verify($password, $hash, $salt, self::$cfg->password_key ?? '');
    }

    /**
     * Whether the stored hash is in the legacy sha1 form (or an outdated bcrypt cost)
     * and should be rewritten with hashPassword() now that the plain password is known
     *
     * @param string $hash Stored password hash
     *
     * @return bool
     */
    public static function passwordNeedsRehash($hash)
    {
        return \Kotchasan\Password::needsRehash($hash);
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
        // bcrypt carries its own salt; the column is kept (NOT NULL) for the legacy sha1 form
        return [
            'hash' => \Kotchasan\Password::hash($password, self::$cfg->password_key ?? ''),
            'salt' => $salt ?? \Kotchasan\Password::uniqid()
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
        // Harden cookie flags consistently with Auth\Controller: mark Secure and
        // upgrade SameSite to Strict over HTTPS. HttpOnly is always set so the
        // token is never readable from JavaScript (XSS cannot exfiltrate it).
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        $options = [
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $secure ? 'Strict' : 'Lax',
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
    public static function generateTokens($userId, array $deviceContext = [], $sessionId = null, $event = 'login')
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

        $tokens = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $now + self::TOKEN_EXPIRY,
            'refresh_expires_at' => $now + self::REFRESH_TOKEN_EXPIRY,
            'session_id' => $sessionId,
            'device_fingerprint' => $fingerprint
        ];

        // Register your sid here.
        self::touchSession($userId, $tokens, $deviceContext, $event);

        return $tokens;
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

        // Issue a standard 3-part JWT (header.payload.signature, HS256). This
        // replaces the previous non-standard 2-part token so the value is a real
        // JWT that the API/JS layers can parse and validate consistently.
        return \Kotchasan\Jwt::encode($payload, self::getTokenSecret(), 'HS256');
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

        // Verify the standard JWT: checks the HS256 signature, the algorithm
        // allow-list (rejects alg:none), and the exp/nbf claims.
        $payload = \Kotchasan\Jwt::decode($token, self::getTokenSecret(), ['HS256']);

        if (!$payload || !isset($payload['exp']) || !isset($payload['sub'])) {
            return null;
        }

        // Revocation (jti/session) is application state, not part of the JWT spec.
        if (self::isTokenRevoked($payload)) {
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
        self::createDB()->update('user', ['id', $userId], [
            'token_expires' => date('Y-m-d H:i:s', $expiresAt),
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

        // Access tokens only. A refresh token lives far longer (7 days) and exists
        // only to mint new access tokens at auth/refresh — honouring one here would
        // hand out a week-long API key. Tokens minted before `type` was stamped are
        // read as access tokens so sessions opened by older releases keep working.
        if (($payload['type'] ?? 'access') !== 'access') {
            return null;
        }

        // "impersonation" token with claim `imp` — believed from signature/
        // Expiration date/revocation status only Intended not to be tied to the session register of the account.
        // The goal is to allow the account owner's real session to continue. (double login)
        if (!empty($payload['imp'])) {
            return self::getUserById($payload['sub']);
        }

        $userId = $payload['sub'];

        // The determinant of which session is still valid is "sid" in the session register of this account.
        // or not" is not a token mapping to a column. `user`.`token` remains the same.
        //
        // Previously, only one token could be stored per account. The second login is then overwritten and
        // Kick off the first machine immediately. New registration form that can store multiple sids per account → Access
        // Can be used on many devices simultaneously You can also revoke individual devices as before (logout, delete
        // only own sid /logoutAllSessions delete entire account)
        //
        // JWT has already verified itself (signature + exp + revocation of jti) this registration.
        // Acts as an allowlist for sessions that have not been closed only.
        if (empty($payload['sid']) || !self::sessionIsActive($userId, $payload['sid'])) {
            return null;
        }

        // Get user from database
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas'))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.id', $userId])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            // A suspended account (active = 0) must stop working at once — `active`
            // used to be checked only at login and refresh, so a token minted before
            // the suspension kept working for up to 24 hours. Same rule as login():
            // the primary administrator (id 1) can never be locked out this way.
            if ((int) $user->active !== 1 && (int) $user->id !== 1) {
                self::logoutAllSessions($userId);
                return null;
            }
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
        // Send the old sid back. so that the renewal is still the same session, not
        // Open a new session every time you refresh (otherwise the register will keep swelling).
        $tokens = self::generateTokens($userId, $deviceContext, $payload['sid'] ?? null, 'refresh');

        // Update user record
        self::updateUserToken($userId, $tokens['access_token'], $tokens['expires_at']);

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
        $payload = null;
        if (!empty($token)) {
            $payload = self::verifyToken($token);
            if ($payload) {
                self::revokeTokenPayload($payload);
            }
        }

        // Log out = Close "only the session that was pressed out" (delete that sid from the register
        // below) does not clear the column. `user`.`token` remains the same, which is a single value.
        // Per account — Clearing this value kicks all user computers at once, contrary to
        // Log in to multiple devices If you really want to turn off all machines, call
        // logoutAllSessions() instead (for example, when changing your password)
        $isImpersonation = !empty($payload['imp']);

        if ($userId) {
            self::logLoginActivity($userId, $isImpersonation ? 'Logout (impersonation session ended)' : 'Logout', null);
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

        $db = static::createDatabase();
        $table = $db->getTableName('user_session');

        // upsert in one command — sid is PRIMARY KEY so it's safe when refreshing
        // With the original sid and without the read-modify-write range. Let the two requests collide like
        // When the registration was still a JSON file
        //
        // It doesn't intentionally catch errors: if the register fails to write Recently issued tokens
        // It won't work at all. (getUserByToken can't find it) Ignoring the error will cause
        // Login "successful" but cannot continue. Which is much more difficult to find the cause.
        $db->raw(
            'INSERT INTO `'.$table.'` '
            .'(`sid`, `member_id`, `expires_at`, `ip`, `user_agent`, `fingerprint`, `last_event`, `last_seen`) '
            .'VALUES (:sid, :member_id, :expires_at, :ip, :ua, :fp, :event, :seen) '
            .'ON DUPLICATE KEY UPDATE '
            .'`expires_at` = VALUES(`expires_at`), `ip` = VALUES(`ip`), `user_agent` = VALUES(`user_agent`), '
            .'`fingerprint` = VALUES(`fingerprint`), `last_event` = VALUES(`last_event`), `last_seen` = VALUES(`last_seen`)',
            [
                ':sid' => $tokens['session_id'],
                ':member_id' => (int) $userId,
                ':expires_at' => (int) ($tokens['refresh_expires_at'] ?? time() + self::REFRESH_TOKEN_EXPIRY),
                ':ip' => $deviceContext['ip'] ?? null,
                ':ua' => mb_substr((string) ($deviceContext['user_agent'] ?? ''), 0, 255),
                ':fp' => $tokens['device_fingerprint'] ?? ($deviceContext['fingerprint'] ?? null),
                ':event' => $event,
                ':seen' => date('Y-m-d H:i:s')
            ]
        );

        self::collectExpiredSessions();
    }

    /**
     * Sweep expired sessions at random, standing in for a cron job.
     *
     * Allowed to fail without affecting the login: this is housekeeping, not
     * part of authentication. If this round does not succeed, a later one
     * still gets its chance.
     *
     * @return void
     */
    private static function collectExpiredSessions()
    {
        if (self::SESSION_GC_DIVISOR < 1) {
            return;
        }

        try {
            if (random_int(1, self::SESSION_GC_DIVISOR) === 1) {
                self::purgeExpiredSessions();
            }
        } catch (\Throwable $e) {
            \Kotchasan\Logger::exception($e, 'Session GC failed');
        }
    }

    /**
     * Is this sid still an open session for this account?
     *
     * Acts as an allowlist: one account can have multiple sids simultaneously (multiple machines)
     * Read with PRIMARY KEY, so it is consistently fast no matter how many millions of sessions the system has.
     *
     * @param int $userId
     * @param string $sessionId
     *
     * @return bool
     */
    private static function sessionIsActive($userId, $sessionId)
    {
        try {
            $row = static::createQuery()
                ->select('expires_at')
                ->from('user_session')
                ->where([
                    ['sid', $sessionId],
                    ['member_id', (int) $userId]
                ])
                ->first();
        } catch (\Throwable $e) {
            // The registration table is not working. (Not yet created/Database down) — Deny for now.
            // Allow login not working It's better than letting go and not being able to revoke the session at all.
            \Kotchasan\Logger::exception($e, 'Session registry unavailable');

            return false;
        }

        if (!$row) {
            return false;
        }

        $expiresAt = (int) $row->expires_at;
        if ($expiresAt > 0 && $expiresAt <= time()) {
            self::removeSession($userId, $sessionId);

            return false;
        }

        return true;
    }

    /**
     * Close all sessions on one account. ("Log out of all devices")
     *
     * Used when changing passwords, suspending accounts, or users log out from any device — because
     * One account can now have multiple sessions. Just deleting the current sid is not enough in these cases.
     *
     * @param int $userId
     *
     * @return void
     */
    public static function logoutAllSessions($userId)
    {
        // limit 0 = delete all rows that meet the condition (The default value of DB::delete() is 1)
        self::createDB()->delete('user_session', ['member_id', (int) $userId], 0);
    }

    /**
     * Discard the expired session. (Called from work on time)
     *
     * The register doesn't delete itself every time it is read — sessionIsActive() deletes only those rows.
     * Only referred to Rows of machines that were no longer used were stuck.
     *
     * @return int number of rows deleted
     */
    public static function purgeExpiredSessions()
    {
        return self::createDB()->delete('user_session', [['expires_at', '>', 0], ['expires_at', '<=', time()]], 0);
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

        self::createDB()->delete('user_session', [['sid', $sessionId], ['member_id', (int) $userId]], 1);
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
     * Check rate limiting for login attempts
     *
     * @param string $username
     * @param string|null $clientIp
     *
     * @return array
     */
    public static function checkRateLimit($username, $clientIp = null)
    {
        $ip = (string) ($clientIp ?? '');

        if (!class_exists('\\Gcms\\LoginAttempt') || !\Gcms\LoginAttempt::isLocked($username, $ip)) {
            return ['allowed' => true];
        }

        $retryAfter = \Gcms\LoginAttempt::getRemainingLockTime($username, $ip);
        if ($retryAfter >= 60) {
            $message = 'Too many login attempts. Please try again in '.ceil($retryAfter / 60).' minutes.';
        } elseif ($retryAfter > 0) {
            $message = 'Too many login attempts. Please try again in '.$retryAfter.' seconds.';
        } else {
            $message = 'Too many login attempts. Please try again later.';
        }

        return [
            'allowed' => false,
            'message' => $message,
            'retry_after' => max(0, (int) $retryAfter)
        ];
    }

    /**
     * Record login attempt in the shared DB-backed brute-force store.
     *
     * @param string $username
     * @param string|null $clientIp
     * @param bool $success
     * @param string $userAgent
     */
    public static function recordLoginAttempt($username, $clientIp, $success, $userAgent = '')
    {
        if (!class_exists('\\Gcms\\LoginAttempt')) {
            return;
        }

        $ip = (string) ($clientIp ?? '');

        if ($success) {
            \Gcms\LoginAttempt::clear($username, $ip);
            return;
        }

        \Gcms\LoginAttempt::record($username, $ip, $userAgent);
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
        } catch (\Throwable $e) {
            // Silently fail - logging should not break authentication.
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

        // Reuse generateSecureToken() with extra impersonation flags.
        $now = time();
        $accessToken = self::generateSecureToken($targetUserId, $now, self::TOKEN_EXPIRY, 'access', [
            'impersonated_by' => $adminId,
            'imp' => true
        ]);

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
