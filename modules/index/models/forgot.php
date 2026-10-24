<?php
/**
 * @filesource modules/index/models/forgot.php
 *
 * Password Reset Model - Handles forgot password functionality
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Forgot;

use Kotchasan\Language;

/**
 * Forgot Password Model
 *
 * Handles password reset token generation and email sending
 *
 * ลิงก์รีเซ็ตเป็น token ที่ลงลายเซ็น (HMAC) ไม่ได้เก็บในฐานข้อมูล
 * ลายเซ็นผูกกับรหัสผ่านปัจจุบันของบัญชี เปลี่ยนรหัสผ่านเมื่อไร ลิงก์ที่ส่งไปแล้วทุกลิงก์ใช้ไม่ได้ทันที
 * — เดิมเก็บ hash ไว้ใน activatecode/token_expires ร่วมกับการยืนยันอีเมลและการเข้าระบบ
 * ใครกดขอรหัสผ่านให้บัญชีไหน บัญชีนั้นเข้าระบบไม่ได้ ("Please verify your email address first")
 * และลิงก์ activate รับ hash นั้นเปิดบัญชีที่รออนุมัติหรือถูกระงับได้
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Token expiry time in seconds (1 hour)
     */
    const TOKEN_EXPIRY = 3600;

    /**
     * Maximum reset attempts per hour
     */
    const MAX_ATTEMPTS_PER_HOUR = 3;

    /**
     * Execute password reset process
     *
     * @param int $userId User ID
     * @param string $email User email/username
     * @param string $baseUrl Base URL for reset link
     *
     * @return string Empty string on success, error message on failure
     */
    public static function execute($userId, $email, $baseUrl)
    {
        try {
            // Check rate limiting
            if (!self::checkResetRateLimit($userId)) {
                return Language::get('Too many password reset requests. Please try again later.');
            }

            // Generate signed reset token
            $resetToken = self::createResetToken($userId);
            if ($resetToken === null) {
                return Language::get('Unable to process your request. Please try again later.');
            }

            // Log the reset request
            self::logResetRequest($userId, 'REQUEST');

            // Send reset email
            $emailResult = self::sendResetEmail($email, $resetToken, $userId, $baseUrl);

            if ($emailResult !== true) {
                return $emailResult;
            }

            return '';
        } catch (\Exception $e) {
            \Kotchasan\Logger::error('Forgot password error: '.$e->getMessage());

            return Language::get('Unable to process your request. Please try again later.');
        }
    }

    /**
     * Create a reset token for the user: "<expires>.<signature>"
     *
     * @param int $userId
     *
     * @return string|null null when the user or the secret key is missing
     */
    public static function createResetToken($userId)
    {
        $user = self::getResetUser($userId);
        if ($user === null) {
            return null;
        }
        $expires = time() + self::TOKEN_EXPIRY;
        $signature = self::sign($user, $expires);

        return $signature === null ? null : $expires.'.'.$signature;
    }

    /**
     * Verify reset token
     *
     * @param string $token Token from the reset link
     * @param int $userId
     *
     * @return bool
     */
    public static function verifyResetToken($token, $userId)
    {
        if (!preg_match('/^([0-9]{10,})\.([0-9a-f]{64})$/', (string) $token, $match)) {
            return false;
        }
        $expires = (int) $match[1];
        if ($expires < time() || $expires > time() + self::TOKEN_EXPIRY) {
            return false;
        }
        $user = self::getResetUser($userId);
        if ($user === null) {
            return false;
        }
        $signature = self::sign($user, $expires);

        return $signature !== null && hash_equals($signature, $match[2]);
    }

    /**
     * Reset user password
     *
     * @param int $userId
     * @param string $newPassword
     *
     * @return bool
     */
    public static function resetPassword($userId, $newPassword)
    {
        // Hash the new password (bcrypt via Index\Auth\Model — one algorithm for the whole system)
        $passwordData = \Index\Auth\Model::hashPassword($newPassword);

        // รหัสผ่านใหม่ทำให้ลิงก์รีเซ็ตทุกลิงก์ของบัญชีนี้ใช้ไม่ได้ (ลายเซ็นผูกกับรหัสผ่าน)
        $save = [
            'password' => $passwordData['hash'],
            'salt' => $passwordData['salt']
        ];
        $user = static::createQuery()
            ->select('activatecode')
            ->from('user')
            ->where([['id', (int) $userId]])
            ->first();
        if ($user && self::isLegacyResetHash($user->activatecode)) {
            // hash ของลิงก์รีเซ็ตรุ่นก่อน (sha256 = 64 ตัว) ที่ค้างอยู่ทำให้เข้าระบบไม่ได้
            // รหัสยืนยันอีเมลเป็น md5 (32 ตัว) จึงไม่ถูกแตะ
            // '' ไม่ใช่ NULL — ฐานที่ยังไม่ได้ปรับรุ่นกำหนดคอลัมน์นี้เป็น NOT NULL
            $save['activatecode'] = '';
        }
        \Kotchasan\DB::create()->update('user', [['id', $userId]], $save);

        // เหมือนเปลี่ยนรหัสผ่านที่หน้าโปรไฟล์ — ปิดทุกเซสชันที่เปิดค้างไว้ด้วยรหัสผ่านเดิม
        \Index\Auth\Model::logoutAllSessions($userId);

        // Log the password reset
        self::logResetRequest($userId, 'RESET_SUCCESS');

        return true;
    }

    /**
     * hash ของลิงก์รีเซ็ตจากรุ่นก่อน (sha256 hex 64 ตัว) ที่ค้างอยู่ใน user.activatecode
     *
     * รหัสยืนยันอีเมลเป็น md5 (32 ตัว) ทุกที่ ค่า 64 ตัวในคอลัมน์นี้จึงเป็นของค้างเสมอ
     * ใช้ที่ Auth\Model::authenticate() (ล้างทิ้ง ไม่ให้ล็อกบัญชี) และ Register\Model::activateUser()
     * (ไม่รับเป็นรหัสยืนยัน) เพื่อปิดช่องโหว่แม้ไซต์ยังไม่ได้รันตัวปรับรุ่นที่ล้างค่าเหล่านี้
     *
     * @param mixed $code
     *
     * @return bool
     */
    public static function isLegacyResetHash($code)
    {
        return is_string($code) && preg_match('/^[0-9a-f]{64}$/', $code) === 1;
    }

    /**
     * Check rate limiting for password reset requests
     *
     * @param int $userId
     *
     * @return bool True if allowed, false if rate limited
     */
    public static function checkResetRateLimit($userId)
    {
        // Use session to track reset attempts
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        $key = 'pwd_reset_'.$userId;
        $now = time();
        $hourAgo = $now - 3600;

        // Get existing attempts
        $attempts = $_SESSION[$key] ?? [];

        // Filter attempts within the last hour
        $attempts = array_filter($attempts, function ($timestamp) use ($hourAgo) {
            return $timestamp > $hourAgo;
        });

        // Check if under limit
        if (count($attempts) >= self::MAX_ATTEMPTS_PER_HOUR) {
            return false;
        }

        // Record this attempt
        $attempts[] = $now;
        $_SESSION[$key] = $attempts;

        return true;
    }

    /**
     * Send password reset email
     *
     * @param string $email
     * @param string $resetToken
     * @param int $userId
     * @param string $baseUrl Base URL for reset link
     *
     * @return bool|string True on success, error message on failure
     */
    public static function sendResetEmail($email, $resetToken, $userId, $baseUrl = '')
    {
        try {
            // Build reset URL using provided base URL or fallback to WEB_URL
            if (empty($baseUrl)) {
                $baseUrl = WEB_URL;
            }
            $resetUrl = $baseUrl.'reset-password?token='.$resetToken.'&uid='.$userId;

            // Use Email Model
            $result = \Index\Email\Model::sendPasswordReset($email, $resetUrl);
            if ($result === true) {
                return true;
            }
            return $result;
        } catch (\Exception $e) {
            \Kotchasan\Logger::error('Password reset email error: '.$e->getMessage());

            return Language::get('Failed to send email. Please try again later.');
        }
    }

    /**
     * ข้อมูลของบัญชีที่ใช้ลงลายเซ็น
     *
     * @param int $userId
     *
     * @return object|null
     */
    private static function getResetUser($userId)
    {
        $user = static::createQuery()
            ->select('id', 'password', 'salt')
            ->from('user')
            ->where([['id', (int) $userId]])
            ->first();

        return $user ?: null;
    }

    /**
     * ลายเซ็นของลิงก์รีเซ็ต — ผูกกับ id, เวลาหมดอายุ และรหัสผ่านปัจจุบัน
     *
     * @param object $user
     * @param int $expires
     *
     * @return string|null null when no secret key is configured
     */
    private static function sign($user, $expires)
    {
        $secret = (string) (self::$cfg->jwt_secret ?? '');
        if ($secret === '') {
            $secret = (string) (self::$cfg->password_key ?? '');
        }
        if ($secret === '') {
            return null;
        }

        // คีย์แยกจากที่ใช้เซ็น JWT ลายเซ็นของสองระบบจึงใช้แทนกันไม่ได้
        return hash_hmac('sha256', $user->id.'|'.$expires.'|'.$user->password.'|'.$user->salt, 'password-reset|'.$secret);
    }

    /**
     * Log password reset activity
     *
     * @param int $userId
     * @param string $action
     */
    private static function logResetRequest($userId, $action)
    {
        try {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            \Index\Log\Model::add($userId, 'index', 'Auth', $action.' IP: '.$clientIp, $userId);
        } catch (\Exception $e) {
            // Silently fail - logging should not break the password reset flow
        }
    }
}
