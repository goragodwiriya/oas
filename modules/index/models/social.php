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
     * Find or create user from social login
     *
     * @param string $provider Provider name (google, facebook, line, telegram)
     * @param array $socialUser User data from provider
     *
     * @return array Result with user data
     */
    public static function findOrCreateSocialUser($provider, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) ($socialUser['id'] ?? ''));

        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return [
                'success' => false,
                'message' => 'Invalid social user data'
            ];
        }

        // First, try to find by social ID and provider
        $existingUser = self::findBySocialId($provider, $socialId);

        if ($existingUser) {
            // Update user info from social provider
            self::updateSocialUserInfo($existingUser->id, $socialUser);

            return [
                'success' => true,
                'message' => Language::get('Login successful'),
                'user' => self::sanitizeUserData($existingUser),
                'isNew' => false
            ];
        }

        // Try to find by email if available
        if (!empty($socialUser['email'])) {
            $emailUser = self::findByEmail($socialUser['email']);

            if ($emailUser) {
                // Link social account to existing user
                self::linkSocialAccount($emailUser->id, $provider, $socialId, $socialUser);

                return [
                    'success' => true,
                    'message' => Language::get('Account linked successfully'),
                    'user' => self::sanitizeUserData($emailUser),
                    'isNew' => false,
                    'linked' => true
                ];
            }
        }

        // Create new user
        $newUser = self::createFromSocial($provider, $socialUser);

        if (!$newUser) {
            return [
                'success' => false,
                'message' => Language::get('Failed to create account')
            ];
        }

        return [
            'success' => true,
            'message' => Language::get('Account created successfully'),
            'user' => $newUser,
            'isNew' => true
        ];
    }

    /**
     * Authenticate a social-login payload using the shared register/login rules.
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
                'code' => 400
            ];
        }

        $data['social'] = $provider;

        $existingUser = self::findAuthUser($provider, $data);
        if ($existingUser !== null) {
            if (self::canLoginWithProvider($existingUser, $provider, $data)) {
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
                'code' => 401
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
                ->where([['line_uid', $socialId]])
                ->first();
        }

        if ($provider === 'telegram') {
            return static::createQuery()
                ->select('*')
                ->from('user')
                ->where([['telegram_id', $socialId]])
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
     * Find user by email
     *
     * @param string $email
     *
     * @return object|null
     */
    public static function findByEmail($email)
    {
        return static::createQuery()
            ->select('*')
            ->from('user')
            ->where([['username', $email]])
            ->first();
    }

    /**
     * Create new user from social data
     *
     * @param string $provider
     * @param array $socialUser
     *
     * @return array|null
     */
    public static function createFromSocial($provider, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) ($socialUser['id'] ?? ''));
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return null;
        }

        $username = self::buildUsername($provider, $socialUser);

        // Ensure username uniqueness; if collision, append random suffix
        if (!\Index\UserRepository\Model::isFieldUnique('username', $username)) {
            $username = $provider.'_'.$socialId.'_'.substr(uniqid(), -6);
        }

        // Prepare user data
        $userData = [
            'username' => $username,
            'password' => '', // No password for social users
            'salt' => '',
            'name' => $socialUser['name'] ?? 'User',
            'phone' => '',
            'phone1' => '',
            'status' => 0,
            'active' => 1, // Social users are auto-activated
            'social' => $provider,
            'activatecode' => '',
            'token' => null,
            'token_expires' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'avatar' => $socialUser['avatar'] ?? null,
            'permission' => '',
            'sex' => '',
            'id_card' => '',
            'birthday' => null,
            'website' => '',
            'company' => '',
            'icon' => '',
            'visited' => 0,
            'address' => '',
            'address2' => '',
            'provinceID' => 0,
            'province' => '',
            'zipcode' => '',
            'country' => 'TH',
            'tax_id' => '',
            'line_uid' => ($provider === 'line') ? $socialId : null,
            'telegram_id' => ($provider === 'telegram') ? $socialId : null
        ];

        // Insert user
        $db = \Kotchasan\DB::create();
        $userId = $db->insert('user', $userData);

        if (!$userId) {
            return null;
        }

        $userData['id'] = $userId;

        // Log registration
        self::logSocialRegistration($userId, $provider);

        return self::sanitizeUserData((object) $userData);
    }

    /**
     * Link social account to existing user
     *
     * @param int $userId
     * @param string $provider
     * @param string $socialId
     * @param array $socialUser
     */
    public static function linkSocialAccount($userId, $provider, $socialId, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) $socialId);
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return;
        }

        $updateData = [
            'social' => $provider
        ];

        if ($provider === 'line') {
            $updateData['line_uid'] = $socialId;
        } elseif ($provider === 'telegram') {
            $updateData['telegram_id'] = $socialId;
        }

        // Update avatar if not set
        if (!empty($socialUser['avatar'])) {
            $currentUser = static::createQuery()
                ->select('avatar')
                ->from('user')
                ->where([['id', $userId]])
                ->first();

            if ($currentUser && empty($currentUser->avatar)) {
                $updateData['avatar'] = $socialUser['avatar'];
            }
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);

        // Log linking
        self::logSocialLink($userId, $provider);
    }

    /**
     * Update social user info
     *
     * @param int $userId
     * @param array $socialUser
     */
    public static function updateSocialUserInfo($userId, array $socialUser)
    {
        $updateData = [];

        // Update avatar if changed
        if (!empty($socialUser['avatar'])) {
            $updateData['avatar'] = $socialUser['avatar'];
        }

        // Update name if changed and not manually set
        // (Optionally can be disabled via config)
        if (!empty($socialUser['name']) && empty(self::$cfg->preserve_user_name_on_social_login)) {
            // Only update if current name is generic
            $currentUser = static::createQuery()
                ->select('name')
                ->from('user')
                ->where([['id', $userId]])
                ->first();

            if ($currentUser && (empty($currentUser->name) || $currentUser->name === 'User')) {
                $updateData['name'] = $socialUser['name'];
            }
        }

        if (!empty($updateData)) {
            \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
        }
    }

    /**
     * Unlink social account
     *
     * @param int $userId
     * @param string $provider
     *
     * @return array
     */
    public static function unlinkSocialAccount($userId, $provider)
    {
        // Check if user has password set (can only unlink if has password)
        $provider = self::normalizeProvider($provider);
        $user = static::createQuery()
            ->select('password', 'social', 'line_uid', 'telegram_id')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => Language::get('No data available')
            ];
        }

        $isLinked = $user->social === $provider
            || ($provider === 'line' && !empty($user->line_uid))
            || ($provider === 'telegram' && !empty($user->telegram_id));

        if (!$isLinked) {
            return [
                'success' => false,
                'message' => Language::get('Account is not linked to this provider')
            ];
        }

        if (empty($user->password)) {
            return [
                'success' => false,
                'message' => Language::get('Please set a password before unlinking social account')
            ];
        }

        // Update user
        $updateData = [];
        if ($user->social === $provider) {
            $updateData['social'] = 'user';
        }
        if ($provider === 'line') {
            $updateData['line_uid'] = null;
        } elseif ($provider === 'telegram') {
            $updateData['telegram_id'] = null;
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);

        return [
            'success' => true,
            'message' => Language::get('Social account unlinked successfully')
        ];
    }

    /**
     * Get linked social accounts for user
     *
     * @param int $userId
     *
     * @return array
     */
    public static function getLinkedAccounts($userId)
    {
        $user = static::createQuery()
            ->select('social', 'line_uid', 'telegram_id')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!$user) {
            return [];
        }

        $linked = [];
        if (self::isSupportedProvider($user->social)) {
            $linked[] = $user->social;
        }
        if (!empty($user->line_uid) && !in_array('line', $linked, true)) {
            $linked[] = 'line';
        }
        if (!empty($user->telegram_id) && !in_array('telegram', $linked, true)) {
            $linked[] = 'telegram';
        }

        return $linked;
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

        $currentUser = static::createQuery()
            ->select('avatar')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!empty($avatar) && $currentUser && empty($currentUser->avatar)) {
            $updateData['avatar'] = $avatar;
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
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

    /**
     * @param string $provider
     * @param array  $socialUser
     *
     * @return string
     */
    private static function buildUsername($provider, array $socialUser)
    {
        $email = trim((string) ($socialUser['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        $socialId = trim((string) ($socialUser['id'] ?? ''));
        if ($provider === 'facebook') {
            return $socialId;
        }
        if ($provider === 'telegram') {
            $username = trim((string) ($socialUser['username'] ?? ''));

            return $username !== '' ? 'telegram_'.$socialId.'_'.$username : 'telegram_'.$socialId;
        }

        return $provider.'_'.$socialId;
    }

    /**
     * Sanitize user data for response
     *
     * @param object $user
     *
     * @return array
     */
    private static function sanitizeUserData($user)
    {
        $data = is_array($user) ? $user : (array) $user;

        return [
            'id' => $data['id'] ?? null,
            'username' => $data['username'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['username'] ?? null, // username is email
            'avatar' => $data['avatar'] ?? null,
            'status' => $data['status'] ?? 0,
            'active' => $data['active'] ?? 1
        ];
    }

    /**
     * Log social registration
     *
     * @param int $userId
     * @param string $provider
     */
    private static function logSocialRegistration($userId, $provider)
    {
        try {
            if (class_exists('\\Index\\Log\\Model')) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                \Index\Log\Model::add($userId, 'index', 'Auth', ucfirst($provider)." Registration provider={$provider} IP={$clientIp}", $userId);
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Log social account linking
     *
     * @param int $userId
     * @param string $provider
     */
    private static function logSocialLink($userId, $provider)
    {
        try {
            if (class_exists('\\Index\\Log\\Model')) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                \Index\Log\Model::add($userId, 'index', 'Auth', ucfirst($provider)." Linking provider={$provider} IP={$clientIp}", $userId);
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
