<?php
/**
 * @filesource modules/index/models/register.php
 *
 * User Registration Model - API Version
 *
 * Production-grade registration with:
 * - Email verification
 * - Rate limiting
 * - Duplicate checking
 * - Secure password hashing
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Register;

use Kotchasan\Language;

/**
 * Registration Model (API)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Registration rate limit (per hour)
     */
    const RATE_LIMIT = 5;

    /**
     * Register a new user (guest registration)
     * Public API for guest/user self-registration
     *
     * @param array $data User data
     * @param string $baseUrl Base URL for email links
     *
     * @return array Registration result
     */
    public static function register(array $data, $baseUrl)
    {
        // For backward compatibility, call registerGuest
        return self::registerGuest($data, $baseUrl);
    }

    /**
     * Register a new guest user
     * Full validation with duplicate checking
     *
     * @param array $data User data (username, password, name, phone, id_card)
     * @param string $baseUrl Base URL for email links
     *
     * @return array Registration result
     */
    public static function registerGuest(array $data, $baseUrl)
    {
        // Validate input data
        $validation = \Index\RegistrationValidator\Controller::validateGuestRegistration($data);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
                'errors' => $validation['errors'],
                'code' => 400
            ];
        }

        // Create user with default options
        return self::createUser($data, $baseUrl, [
            'send_email' => true,
            'auto_login' => !empty(self::$cfg->new_members_active) && !empty(self::$cfg->activate_user)
        ]);
    }

    /**
     * Create user by admin
     * Flexible validation - can skip duplicate checks
     *
     * @param array $data User data
     * @param array $options Creation options
     *
     * @return array Creation result
     */
    public static function createUserByAdmin(array $data, array $options = [])
    {
        $options = array_merge([
            'check_duplicate' => true,
            'require_password' => true,
            'send_email' => false,
            'base_url' => WEB_URL
        ], $options);

        // Validate input data
        $validation = \Index\RegistrationValidator\Controller::validateAdminCreation($data, [
            'check_duplicate' => $options['check_duplicate'],
            'require_password' => $options['require_password']
        ]);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
                'errors' => $validation['errors'],
                'code' => 400
            ];
        }

        // Generate random password if not provided
        if (empty($data['password'])) {
            $data['password'] = \Kotchasan\Password::uniqid(8);
        }

        // Create user
        return self::createUser($data, $options['base_url'], [
            'send_email' => $options['send_email'],
            'auto_login' => false
        ]);
    }

    /**
     * Register user from social login
     * Minimal validation - no duplicate username check
     *
     * @param array $data User data from OAuth provider
     * @param string $baseUrl Base URL for email links
     *
     * @return array Registration result
     */
    public static function registerFromSocial(array $data, $baseUrl)
    {
        // Validate input data
        $validation = \Index\RegistrationValidator\Controller::validateSocialLogin($data);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
                'errors' => $validation['errors'],
                'code' => 400
            ];
        }

        // Generate random password for social login
        $data['password'] = \Kotchasan\Password::uniqid(12);

        // Check duplicates to avoid DB unique violations
        if (!\Index\UserRepository\Model::isFieldUnique('username', $data['username'])) {
            return [
                'success' => false,
                'message' => 'This username is already registered',
                'errors' => ['username' => 'This username is already registered'],
                'code' => 409
            ];
        }

        // Create user (social login users are always active)
        return self::createUser($data, $baseUrl, [
            'send_email' => false, // Don't send email for social login
            'auto_login' => true, // Auto-login after social registration
            'download_avatar' => !empty($data['picture']) ? $data['picture'] : null
        ]);
    }

    /**
     * Core user creation logic (private)
     * Shared by all registration methods
     *
     * @param array $data User data
     * @param string $baseUrl Base URL for email links
     * @param array $options Creation options
     *
     * @return array Creation result
     */
    public static function createUser(array $data, $baseUrl, array $options = [])
    {
        $options = array_merge([
            'send_email' => true,
            'auto_login' => false,
            'download_avatar' => null
        ], $options);

        // Prepare user data for insertion
        $save = self::prepareUserData($data);

        // Insert user using repository
        $userId = \Index\UserRepository\Model::createUser($save);

        if (!$userId) {
            throw new \Exception('Registration failed. Please try again.');
        }

        $save['id'] = $userId;

        // Download social avatar if URL provided
        if (!empty($options['download_avatar'])) {
            try {
                self::downloadSocialAvatar($userId, $options['download_avatar']);
            } catch (\Exception $e) {
                // Log error but don't fail registration
                error_log('Failed to download social avatar: '.$e->getMessage());
            }
        }

        // Send welcome/verification email if enabled
        if ($options['send_email']) {
            self::sendRegistrationEmail($save, $data['password'], $baseUrl);
        }

        // Log registration
        self::logRegistration($userId);

        // Generate token for auto-login if enabled
        $token = null;
        if ($options['auto_login'] && empty($save['activatecode'])) {
            $tokens = \Index\Auth\Model::generateTokens($userId);
            \Index\Auth\Model::updateUserToken($userId, $tokens['access_token'], $tokens['expires_at']);
            $token = $tokens['access_token'];
        }

        // Prepare response user data (sanitized)
        $userData = self::sanitizeUserData($save);

        return [
            'success' => true,
            'message' => self::getSuccessMessage($save),
            'user' => $userData,
            'token' => $token,
            'activatecode' => $save['activatecode'] ?? null,
            'requiresVerification' => !empty($save['activatecode'])
        ];
    }

    /**
     * Download and save social login avatar
     *
     * @param int $userId User ID
     * @param string $pictureUrl URL of the profile picture
     *
     * @return bool Success
     */
    private static function downloadSocialAvatar($userId, $pictureUrl)
    {
        try {
            // Create avatar directory if not exists
            $dir = ROOT_PATH.DATA_FOLDER.'avatar/';
            if (!\Kotchasan\File::makeDirectory($dir)) {
                throw new \Exception('Cannot create avatar directory');
            }

            // Download image from URL
            $imageData = @file_get_contents($pictureUrl);
            if ($imageData === false) {
                throw new \Exception('Failed to download image from URL');
            }

            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'avatar_');
            file_put_contents($tempFile, $imageData);

            // Verify it's a valid image
            if (@getimagesize($tempFile) === false) {
                unlink($tempFile);
                throw new \Exception('Invalid image file');
            }

            // Target size (square)
            $targetSize = self::$cfg->member_img_size ?? 200;

            // Output path
            $outputFile = $dir.$userId.self::$cfg->stored_img_type;

            // Use Kotchasan\Image to crop and resize
            $result = \Kotchasan\Image::crop($tempFile, $outputFile, $targetSize, $targetSize);

            // Clean up temp file
            unlink($tempFile);

            return $result;
        } catch (\Exception $e) {
            error_log('Avatar download error: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Prepare user data for insertion
     *
     * @param array $data User data (username, password, name, phone, id_card, social)
     * @param array|null $permission Custom permissions (if null, use defaults)
     * @param array $user_meta Additional user metadata
     *
     * @return array Prepared data for database insertion
     */
    private static function prepareUserData(array $data, $permission = null, $user_meta = [])
    {
        // 1. Password Security: Hash password with salt
        $hashedPasswordData = self::hashPassword($data['password']);

        // 2. Core Fields: Required user information
        $save = [
            'username' => $data['username'], // Username is required, no null allowed
            'password' => $hashedPasswordData['hashed'],
            'salt' => $hashedPasswordData['salt'],
            'name' => $data['name'], // Name is required
            'phone' => empty($data['phone']) ? null : $data['phone'],
            'id_card' => empty($data['id_card']) ? null : $data['id_card'],
            'telegram_id' => empty($data['telegram_id']) ? null : $data['telegram_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];

        // 3. User Status: Set status and social login type
        $save['status'] = isset($data['status']) ? (int) $data['status'] : self::getDefaultUserStatus();
        $save['social'] = isset($data['social']) ? $data['social'] : 'user';

        // 4. Account Activation: Handle email verification
        $activationData = self::prepareActivationData($data);
        $save['active'] = $activationData['active'];
        $save['activatecode'] = $activationData['activatecode'];

        // 5. Permissions: Set user permissions
        $save['permission'] = self::preparePermissions($permission);

        // 6. Metadata: Keep meta fields in their source shape for repository persistence
        if (!empty($user_meta)) {
            $save['metas'] = $user_meta;
        }

        return $save;
    }

    /**
     * Hash password with salt
     *
     * @param string $password Plain password
     *
     * @return array ['hashed' => string, 'salt' => string]
     */
    private static function hashPassword($password)
    {
        $salt = \Kotchasan\Password::uniqid();
        $passwordKey = self::$cfg->password_key ?? '';
        $hashed = sha1($passwordKey.$password.$salt);

        return [
            'hashed' => $hashed,
            'salt' => $salt
        ];
    }

    /**
     * Prepare activation data based on configuration
     *
     * @param array $data User data
     *
     * @return array ['active' => int, 'activatecode' => string]
     */
    private static function prepareActivationData(array $data)
    {
        $isSocialLogin = !empty($data['social']);
        $requiresEmailVerification = !empty(self::$cfg->activate_user);

        // Social login users are active immediately (no email verification needed)
        if ($isSocialLogin) {
            return [
                'active' => 1,
                'activatecode' => ''
            ];
        }

        // Regular registration: Check if email verification is required
        if ($requiresEmailVerification) {
            return [
                'active' => 0,
                'activatecode' => md5(($data['username'] ?? '').uniqid().time())
            ];
        }

        // No verification required: Activate immediately
        return [
            'active' => 1,
            'activatecode' => ''
        ];
    }

    /**
     * Get default user status based on configuration
     *
     * @return int User status (0 = regular user, 1 = admin)
     */
    private static function getDefaultUserStatus()
    {
        // Demo mode: Users get admin status
        if (!empty(self::$cfg->demo_mode)) {
            return 1;
        }

        // Regular mode: Default to regular user
        return 0;
    }

    /**
     * Prepare user permissions
     *
     * @param array|null $permission Custom permissions (null = use defaults)
     *
     * @return string Formatted permission string
     */
    private static function preparePermissions($permission)
    {
        // Use custom permissions if provided
        if ($permission !== null && is_array($permission)) {
            return empty($permission) ? '' : ','.implode(',', $permission).',';
        }

        // Get default permissions from config
        $defaultPermissions = self::$cfg->default_user_permissions ?? [];

        // Demo mode: Remove sensitive permissions
        if (!empty(self::$cfg->demo_mode)) {
            $restrictedPermissions = ['can_config', 'can_view_usage_history'];
            $defaultPermissions = array_diff($defaultPermissions, $restrictedPermissions);
        }

        return empty($defaultPermissions) ? '' : ','.implode(',', $defaultPermissions).',';
    }

    /**
     * Activate user account
     *
     * @param int $userId
     * @param string $code
     *
     * @return array
     */
    public static function activateAccount($userId, $code)
    {
        $user = static::createQuery()
            ->select('id', 'activatecode', 'active')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => Language::get('No data available')
            ];
        }

        if ($user->active == 1) {
            return [
                'success' => false,
                'message' => Language::get('Account is already activated')
            ];
        }

        if (empty($user->activatecode) || !hash_equals($user->activatecode, $code)) {
            return [
                'success' => false,
                'message' => Language::get('Invalid activation code')
            ];
        }

        // Activate account
        \Kotchasan\DB::create()->update('user', [['id', $userId]], [
            'active' => 1,
            'activatecode' => ''
        ]);

        // Log activation
        \Index\Log\Model::add($userId, 'index', 'Auth', 'ACCOUNT_ACTIVATED', $userId);

        return [
            'success' => true,
            'message' => Language::get('Account activated successfully')
        ];
    }

    /**
     * Activate user account by activation code only
     * Used for email verification links
     *
     * @param string $code Activation code from email
     *
     * @return array
     */
    public static function activateUser($code)
    {
        // Find user by activation code
        $user = static::createQuery()
            ->select('id', 'username', 'name', 'active', 'activatecode')
            ->from('user')
            ->where([['activatecode', $code]])
            ->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => Language::get('Invalid or expired activation link')
            ];
        }

        if ($user->active == 1) {
            return [
                'success' => false,
                'message' => Language::get('Account is already activated')
            ];
        }

        // Activate account
        \Kotchasan\DB::create()->update('user', [['id', $user->id]], [
            'active' => 1,
            'activatecode' => ''
        ]);

        // Log activation
        try {
            \Index\Log\Model::add($user->id, 'index', 'Auth', 'ACCOUNT_ACTIVATED_VIA_EMAIL', $user->id);
        } catch (\Exception $e) {
            // Silently fail
        }

        return [
            'success' => true,
            'message' => Language::get('Your email has been verified. You can now login.'),
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name
            ]
        ];
    }

    /**
     * Check registration rate limit
     *
     * @param string $clientIp
     *
     * @return bool
     */
    public static function checkRegistrationRateLimit($clientIp)
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        $key = 'register_'.md5($clientIp);
        $now = time();
        $hourAgo = $now - 3600;

        $attempts = $_SESSION[$key] ?? [];
        $attempts = array_filter($attempts, function ($timestamp) use ($hourAgo) {
            return $timestamp > $hourAgo;
        });

        if (count($attempts) >= self::RATE_LIMIT) {
            return false;
        }

        $attempts[] = $now;
        $_SESSION[$key] = $attempts;

        return true;
    }

    /**
     * Send registration email
     *
     * @param array $userData
     * @param string $plainPassword
     * @param string $baseUrl Base URL for email links
     *
     * @return bool|string
     */
    private static function sendRegistrationEmail($userData, $plainPassword, $baseUrl)
    {
        try {
            // Use existing email model if available
            if (!empty($userData['activatecode'])) {
                // Send activation email
                return \Index\Email\Model::send($userData, $plainPassword, $baseUrl);
            } elseif (!empty(self::$cfg->welcome_email)) {
                // Send welcome email
                return \Index\Email\Model::send($userData, $plainPassword, $baseUrl);
            }
            return true;
        } catch (\Exception $e) {
            \Kotchasan\Logger::error('Registration email error: '.$e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Get success message based on configuration
     *
     * @param array $userData
     *
     * @return string
     */
    private static function getSuccessMessage($userData)
    {
        if (!empty($userData['activatecode'])) {
            return Language::replace('Please check your email :email to verify your account', [
                ':email' => $userData['username']
            ]);
        }

        if (empty(($userData['active']))) {
            return Language::get('Registration successful! Awaiting admin approval.');
        }

        return Language::get('Registration successful! You can now login.');
    }

    /**
     * Log registration activity
     *
     * @param int $userId
     */
    private static function logRegistration($userId)
    {
        try {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            \Index\Log\Model::add($userId, 'index', 'Auth', 'REGISTRATION IP: '.$clientIp, $userId);
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Sanitize user data for response
     *
     * @param array $userData
     *
     * @return array
     */
    private static function sanitizeUserData($userData)
    {
        $safe = [
            'id' => $userData['id'] ?? null,
            'username' => $userData['username'] ?? null,
            'name' => $userData['name'] ?? null,
            'status' => $userData['status'] ?? 0,
            'active' => $userData['active'] ?? 0
        ];

        return $safe;
    }
}
