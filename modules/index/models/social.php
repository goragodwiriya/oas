<?php
/**
 * @filesource modules/index/models/social.php
 *
 * Social Login Model
 *
 * Handles social authentication user management:
 * - Find existing social users
 * - Create new users from social data
 * - Link social accounts to existing users
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Social;

use Kotchasan\Language;

/**
 * Social Authentication Model
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Supported social providers stored in the user.social enum column.
     */
    const PROVIDERS = ['facebook', 'google', 'line', 'telegram'];

    /**
     * Authenticate a social-login payload using the shared register/login rules.
     *
     * A failure carries `reason` (failed, inactive, exists, disabled) for callers that
     * cannot show `message` themselves (LINE callback). A new account that has to wait
     * for approval is a success without `token`.
     *
     * @param array       $data
     * @param string|null $baseUrl
     * @param string      $providerLabel
     * @param string|null $clientIp
     *
     * @return array
     */
    public static function authenticate(array $data, $baseUrl, $providerLabel, $clientIp = null)
    {
        $provider = self::normalizeProvider($data['social'] ?? '');
        if (!self::isSupportedProvider($provider)) {
            return [
                'success' => false,
                'message' => 'Invalid social user data',
                'code' => 400,
                'reason' => 'failed'
            ];
        }

        $data['social'] = $provider;

        $existingUser = self::findAuthUser($provider, $data);
        if ($existingUser !== null) {
            // กติกาเดียวกับ Auth\Model::authenticate() · เดิมไม่ได้เช็ก จึงออก token ให้บัญชีที่ถูกระงับ
            // ทั้งที่ getUserByToken() ปฏิเสธบัญชี active = 0 ทุกครั้ง ผลคือ "เข้าระบบสำเร็จ" แล้วกลายเป็น
            // Guest ทันที (ชื่อ/รูป avatar ไม่ขึ้น รีโหลดแล้วหลุด) — id 1 ล็อกตัวเองออกไม่ได้เหมือนกัน
            if ((int) $existingUser->active !== 1 && (int) $existingUser->id !== 1) {
                return [
                    'success' => false,
                    'message' => 'Your account is not active',
                    'code' => 403,
                    'reason' => 'inactive'
                ];
            }

            if (self::canLoginWithProvider($existingUser, $provider, $data)) {
                self::saveMissingAvatar($existingUser->id, $data['picture'] ?? '');

                return self::loginUser($existingUser, $providerLabel, $clientIp);
            }

            if ($provider === 'line' && !empty($data['line_uid']) && self::canAttachLineUid($existingUser)) {
                self::attachLineUid($existingUser->id, $data['line_uid'], $data['picture'] ?? '');
                $linkedUser = \Kotchasan\DB::create()->first('user', [['id', $existingUser->id]]);

                return self::loginUser($linkedUser ?: $existingUser, $providerLabel, $clientIp);
            }

            return [
                'success' => false,
                'message' => 'An account with this email already exists. Please use email login.',
                'code' => 401,
                'reason' => 'exists'
            ];
        }

        // ไม่มีบัญชีตรงกับที่เข้ามา = ต้องสมัครใหม่
        //
        // ต้องเคารพสวิตช์ user_register เดียวกับหน้าสมัครปกติ · เดิมเส้นทางนี้
        // ไม่เคยเช็กเลย สร้างบัญชีให้ทันทีพร้อม active = 1 — ปิดหน้าสมัครไปแล้ว
        // ก็ยังสมัครผ่านปุ่ม "เข้าด้วย Telegram/Google" ได้อยู่ดี ซึ่งเป็นประตู
        // ที่มองไม่เห็นเพราะไม่มีใครคิดว่าปุ่มเข้าระบบคือปุ่มสมัครสมาชิก
        //
        // ยกเว้น demo_mode · เปิดไว้เพื่อให้คนนอกลองระบบด้วยบัญชีโซเชียลได้
        // โดยไม่ต้องเปิดหน้าสมัครสมาชิกให้คนทั่วไป บัญชีที่ได้เป็นบัญชีตัวอย่าง
        // (สถานะแอดมินแต่อ่านอย่างเดียว ดู Register\Model::getDefaultUserStatus()
        // กับ Api::isNotDemoMode()) จึงไม่ใช่ประตูสมัครสมาชิกจริง
        if (empty(self::$cfg->user_register) && empty(self::$cfg->demo_mode)) {
            return [
                'success' => false,
                'message' => Language::get('This feature is currently disabled'),
                'code' => 403,
                'reason' => 'disabled'
            ];
        }

        return \Index\Register\Model::registerFromSocial($data, $baseUrl);
    }

    /**
     * Find user by social ID
     *
     * @param string $provider
     * @param string $socialId
     *
     * @return object|null
     */
    public static function findBySocialId($provider, $socialId)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) $socialId);
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return null;
        }

        if ($provider === 'line') {
            return static::createQuery()
                ->select('*')
                ->from('user')
                ->where(['line_uid', $socialId])
                ->first();
        }

        if ($provider === 'telegram') {
            return static::createQuery()
                ->select('*')
                ->from('user')
                ->where(['telegram_id', $socialId])
                ->first();
        }

        $user = static::createQuery()
            ->select('*')
            ->from('user')
            ->where([
                ['username', $socialId],
                ['social', $provider]
            ])
            ->first();

        if ($user) {
            return $user;
        }

        return static::createQuery()
            ->select('*')
            ->from('user')
            ->where([
                ['username', $provider.'_'.$socialId],
                ['social', $provider]
            ])
            ->first();
    }

    /**
     * @param string $provider
     * @param array  $data
     *
     * @return object|null
     */
    private static function findAuthUser($provider, array $data)
    {
        if ($provider === 'line' && !empty($data['line_uid'])) {
            $user = self::findBySocialId('line', $data['line_uid']);
            if ($user !== null) {
                return $user;
            }
        }

        if ($provider === 'telegram' && !empty($data['telegram_id'])) {
            $user = self::findBySocialId('telegram', $data['telegram_id']);
            if ($user !== null) {
                return $user;
            }
        }

        $username = trim((string) ($data['username'] ?? ''));

        return $username === '' ? null : \Index\UserRepository\Model::findByUsername($username);
    }

    /**
     * @param object $user
     * @param string $provider
     * @param array  $data
     *
     * @return bool
     */
    private static function canLoginWithProvider($user, $provider, array $data)
    {
        if (($user->social ?? 'user') === $provider) {
            return true;
        }

        if ($provider === 'line' && !empty($data['line_uid']) && !empty($user->line_uid)) {
            return (string) $user->line_uid === (string) $data['line_uid'];
        }

        if ($provider === 'telegram' && !empty($data['telegram_id']) && !empty($user->telegram_id)) {
            return (string) $user->telegram_id === (string) $data['telegram_id'];
        }

        return false;
    }

    /**
     * @param object $user
     *
     * @return bool
     */
    private static function canAttachLineUid($user)
    {
        $social = (string) ($user->social ?? 'user');

        return $social !== 'line' && empty($user->line_uid);
    }

    /**
     * @param int    $userId
     * @param string $lineUid
     * @param string $avatar
     *
     * @return void
     */
    private static function attachLineUid($userId, $lineUid, $avatar = '')
    {
        $updateData = [
            'line_uid' => $lineUid
        ];

        \Kotchasan\DB::create()->update('user', ['id', $userId], $updateData);

        self::saveMissingAvatar($userId, $avatar);
    }

    /**
     * บันทึกรูปจากผู้ให้บริการเป็น avatar (ไฟล์ DATA_FOLDER/avatar/{id}) ถ้าสมาชิกยังไม่มีรูป
     * ไม่ทับรูปที่สมาชิกอัปโหลดเอง · ดาวน์โหลดไม่สำเร็จก็ยัง login ได้ตามปกติ
     *
     * @param int    $userId
     * @param string $pictureUrl
     *
     * @return void
     */
    private static function saveMissingAvatar($userId, $pictureUrl)
    {
        $pictureUrl = trim((string) $pictureUrl);
        if ($pictureUrl === '' || \Gcms\Api::getAvatarUrl($userId) !== null) {
            return;
        }

        \Index\Register\Model::downloadSocialAvatar($userId, $pictureUrl);
    }

    /**
     * @param object      $existingUser
     * @param string      $providerLabel
     * @param string|null $clientIp
     *
     * @return array
     */
    private static function loginUser($existingUser, $providerLabel, $clientIp = null)
    {
        $loginResult = \Index\Auth\Model::generateTokens($existingUser->id);
        if (!$loginResult) {
            return [
                'success' => false,
                'message' => 'Login failed',
                'code' => 401
            ];
        }

        \Index\Auth\Model::updateUserToken(
            $existingUser->id,
            $loginResult['access_token'],
            $loginResult['expires_at']
        );

        \Index\Auth\Model::logLoginActivity(
            $existingUser->id,
            $providerLabel.' Sign-in: '.$existingUser->username,
            $clientIp
        );

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => \Index\Auth\Model::sanitizeUserData($existingUser),
            'token' => $loginResult['access_token'],
            'refresh_token' => $loginResult['refresh_token'],
            'expires_in' => \Index\Auth\Model::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * @param string $provider
     *
     * @return bool
     */
    private static function isSupportedProvider($provider)
    {
        return in_array(self::normalizeProvider($provider), self::PROVIDERS, true);
    }

    /**
     * @param string $provider
     *
     * @return string
     */
    private static function normalizeProvider($provider)
    {
        return strtolower(trim((string) $provider));
    }

}
