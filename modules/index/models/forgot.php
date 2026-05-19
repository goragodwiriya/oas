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

            // Generate secure reset token
            $resetToken = self::generateResetToken();
            $tokenHash = self::hashResetToken($resetToken);
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY);

            // Store token in database
            self::storeResetToken($userId, $tokenHash, $expiresAt);

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
     * Generate a secure reset token
     *
     * @return string
     */
    public static function generateResetToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash the reset token for storage
     *
     * @param string $token
     *
     * @return string
     */
    public static function hashResetToken($token)
    {
        return hash('sha256', $token);
    }

    /**
     * Store reset token in database
     *
     * @param int $userId
     * @param string $tokenHash
     * @param string $expiresAt
     */
    public static function storeResetToken($userId, $tokenHash, $expiresAt)
    {
        // Use activatecode field for reset token
        \Kotchasan\DB::create()->update('user', [['id', $userId]], [
            'activatecode' => $tokenHash,
            'token_expires' => $expiresAt
        ]);
    }

    /**
     * Verify reset token
     *
     * @param string $token Plain text token from URL
     * @param int $userId
     *
     * @return bool
     */
    public static function verifyResetToken($token, $userId)
    {
        $tokenHash = self::hashResetToken($token);
        $currentDateTime = date('Y-m-d H:i:s');

        $user = static::createQuery()
            ->select('id', 'activatecode', 'token_expires')
            ->from('user')
            ->where([
                ['id', $userId],
                ['activatecode', $tokenHash],
                ['token_expires', '>=', $currentDateTime]
            ])
            ->first();

        return $user !== false;
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
        // Generate new salt and hash password
        $salt = \Kotchasan\Password::uniqid();
        $passwordKey = self::$cfg->password_key ?? '';
        $passwordHash = sha1($passwordKey.$newPassword.$salt);

        // Update password and clear reset token
        \Kotchasan\DB::create()->update('user', [['id', $userId]], [
            'password' => $passwordHash,
            'salt' => $salt,
            'activatecode' => '',
            'token_expires' => null
        ]);

        // Log the password reset
        self::logResetRequest($userId, 'RESET_SUCCESS');

        return true;
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

    /**
     * Clear expired reset tokens (maintenance task)
     *
     * @return int Number of cleared tokens
     */
    public static function clearExpiredTokens()
    {
        $currentDateTime = date('Y-m-d H:i:s');

        return \Kotchasan\DB::create()->update('user',
            [
                ['activatecode', '!=', ''],
                ['token_expires', '<', $currentDateTime]
            ],
            [
                'activatecode' => '',
                'token_expires' => null
            ]
        );
    }
}
