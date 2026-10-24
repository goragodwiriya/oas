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
            // เข้าระบบให้ทันทีเมื่อบัญชีใช้งานได้เลย: ไม่ต้องรออนุมัติ และไม่ต้องยืนยันอีเมล
            // (เดิม && !empty(activate_user) — ต้องยืนยันอีเมลก็มี activatecode อยู่แล้ว จึงไม่เคยเข้าระบบให้)
            'auto_login' => !empty(self::$cfg->new_members_active) && empty(self::$cfg->activate_user),
            // ปิด new_members_active = สมาชิกใหม่รอแอดมินตรวจสอบก่อนเข้าระบบ
            'require_approval' => empty(self::$cfg->new_members_active)
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

        // Create user — ไม่ต้องยืนยันอีเมล (ผู้ให้บริการยืนยันแล้ว) แต่ต้องรออนุมัติเหมือนสมัครปกติ
        return self::createUser($data, $baseUrl, [
            'send_email' => false, // Don't send email for social login
            'auto_login' => true, // Auto-login after social registration
            'download_avatar' => !empty($data['picture']) ? $data['picture'] : null,
            // ปิด new_members_active = สมาชิกใหม่รอแอดมินตรวจสอบก่อนเข้าระบบ
            'require_approval' => empty(self::$cfg->new_members_active)
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
            'download_avatar' => null,
            'require_approval' => false
        ], $options);

        // Prepare user data for insertion
        $save = self::prepareUserData($data);

        if ($options['require_approval']) {
            // รอแอดมินอนุมัติ (sendActive) ไม่ต้องยืนยันอีเมล เหมือนเวอร์ชันเก่า
            $save['active'] = 0;
            $save['activatecode'] = '';
        }

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

        $message = self::getSuccessMessage($save);
        if ($options['require_approval']) {
            // แจ้งแอดมินให้ตรวจสอบสมาชิกใหม่
            $message = \Index\Email\Model::sendApprove($baseUrl);
        } elseif ($options['send_email']) {
            // Send welcome/verification email if enabled
            $err = self::sendRegistrationEmail($save, $data['password'], $baseUrl);
            if ($err !== '') {
                // สมัครสำเร็จแล้วแต่ส่งอีเมลไม่ได้ — แจ้งข้อผิดพลาดแทน เหมือนเวอร์ชันเก่า
                $message = $err;
            }
        }

        // Log registration
        self::logRegistration($userId);

        // Generate token for auto-login if enabled
        $token = null;
        if ($options['auto_login'] && !empty($save['active']) && empty($save['activatecode'])) {
            $tokens = \Index\Auth\Model::generateTokens($userId);
            \Index\Auth\Model::updateUserToken($userId, $tokens['access_token'], $tokens['expires_at']);
            $token = $tokens['access_token'];
        }

        // Prepare response user data (sanitized)
        $userData = self::sanitizeUserData($save);

        return [
            'success' => true,
            'message' => $message,
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
    public static function downloadSocialAvatar($userId, $pictureUrl)
    {
        $tempFile = null;
        try {
            // Create avatar directory if not exists
            $dir = ROOT_PATH.DATA_FOLDER.'avatar/';
            if (!\Kotchasan\File::makeDirectory($dir)) {
                throw new \Exception('Cannot create avatar directory');
            }

            // Download image from URL
            // ใช้ Curl แทน file_get_contents · ตอนนี้ดาวน์โหลดระหว่าง login ด้วย ต้องมี timeout สั้น
            // ไม่ให้ปลายทางที่ช้าค้างการเข้าระบบ · รูป Facebook redirect ไป CDN จึงต้องตาม redirect
            $curl = new \Kotchasan\Curl();
            $curl->setOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10
            ]);
            $imageData = $curl->get($pictureUrl);
            if ($imageData === false || $imageData === '' || $curl->error() || $curl->httpStatus() !== 200) {
                throw new \Exception('Failed to download image from URL (HTTP '.$curl->httpStatus().') '.$curl->errorMessage());
            }

            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'avatar_');
            file_put_contents($tempFile, $imageData);

            // Verify it's a valid image
            if (@getimagesize($tempFile) === false) {
                throw new \Exception('Invalid image file');
            }

            // Target size (square)
            $targetSize = self::$cfg->member_img_size ?? 200;

            // Output path
            $outputFile = $dir.$userId.self::$cfg->stored_img_type;

            // Use Kotchasan\Image to crop and resize
            return \Kotchasan\Image::crop($tempFile, $outputFile, $targetSize, $targetSize);
        } catch (\Exception $e) {
            error_log('Avatar download error: '.$e->getMessage());
            return false;
        } finally {
            // Clean up temp file
            if ($tempFile !== null && is_file($tempFile)) {
                unlink($tempFile);
            }
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
            'line_uid' => empty($data['line_uid']) ? null : $data['line_uid'],
            'telegram_id' => empty($data['telegram_id']) ? null : $data['telegram_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];

        // 3. User Status: Set status and social login type
        $save['social'] = isset($data['social']) ? $data['social'] : 'user';
        $save['status'] = isset($data['status']) ? (int) $data['status'] : self::getDefaultUserStatus($save['social']);

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
        // one algorithm for the whole system (bcrypt, see Kotchasan\Password::hash)
        $data = \Index\Auth\Model::hashPassword($password);

        return [
            'hashed' => $data['hash'],
            'salt' => $data['salt']
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
     * สมัครเองได้สมาชิกทั่วไป (0) เสมอ ยกเว้นสมัครผ่านโซเชียลตอนเปิด demo_mode
     * ซึ่งได้บัญชีตัวอย่างสถานะแอดมิน (อ่านอย่างเดียว ดู Gcms\Api::isNotDemoMode())
     * ให้คนนอกลองระบบได้ (Social\Model::authenticate() เปิดทางนี้ไว้แม้ปิดรับสมัคร)
     * — เดิมให้ 1 ทุกช่องทาง สมัครผ่านหน้าสมัครปกติตอนเปิด demo_mode จึงได้สถานะแอดมินด้วย
     *
     * @param string $social 'user' = สมัครผ่านหน้าสมัคร/แอดมินสร้าง, อื่น ๆ = ผู้ให้บริการโซเชียล
     *
     * @return int User status (0 = regular user, 1 = admin)
     */
    private static function getDefaultUserStatus($social)
    {
        return !empty(self::$cfg->demo_mode) && $social !== 'user' ? 1 : 0;
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

        // hash ลิงก์รีเซ็ตของรุ่นก่อนที่ค้างอยู่ไม่ใช่รหัสยืนยัน (ดู activateUser())
        if (empty($user->activatecode) || \Index\Forgot\Model::isLegacyResetHash($user->activatecode) || !hash_equals($user->activatecode, $code)) {
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
        // hash ลิงก์รีเซ็ตรหัสผ่านของรุ่นก่อนที่ค้างใน activatecode ไม่ใช่รหัสยืนยัน — เดิมเจ้าของลิงก์รีเซ็ต
        // คำนวณ sha256 ของ token แล้วใช้เปิดบัญชีที่รออนุมัติหรือถูกระงับของตัวเองได้
        if (\Index\Forgot\Model::isLegacyResetHash($code)) {
            return [
                'success' => false,
                'message' => Language::get('Invalid or expired activation link')
            ];
        }

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

        // active = 1 ที่ยังมี activatecode คือสมาชิกจากรุ่นก่อน (เปิดใช้งานแล้วแต่ยังไม่ยืนยันอีเมล)
        // ต้องล้างรหัสให้ ไม่งั้นเข้าระบบไม่ได้ ("Please verify your email address first") ตลอดไป
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
     * @return string ข้อความ error ส่งสำเร็จหรือไม่ต้องส่งคืนค่าว่าง
     */
    private static function sendRegistrationEmail($userData, $plainPassword, $baseUrl)
    {
        try {
            if (!empty($userData['activatecode'])) {
                // Send activation email/SMS
                return \Index\Email\Model::send($userData, $plainPassword, $baseUrl);
            }
            if (!empty(self::$cfg->welcome_email) && \Kotchasan\Validator::email($userData['username'])) {
                // Send welcome email — username ที่ไม่ใช่อีเมลไม่มีที่ให้ส่ง ไม่ใช่ข้อผิดพลาด
                return \Index\Email\Model::send($userData, $plainPassword, $baseUrl);
            }
            return '';
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
            'active' => $userData['active'] ?? 0,
            // รูปจากผู้ให้บริการ social ถูกดาวน์โหลดเป็นไฟล์ก่อนถึงตรงนี้ (createUser)
            'avatar' => empty($userData['id']) ? null : (\Gcms\Api::getAvatarUrl($userData['id']) ?? WEB_URL.'images/default-avatar.webp')
        ];

        return $safe;
    }
}
