<?php
/**
 * @filesource modules/index/controllers/social.php
 *
 * Social Login Controller - OAuth Integration
 *
 * Supports:
 * - Google OAuth 2.0
 * - Facebook Login
 * - Telegram Login
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Social;

use Gcms\Api as ApiController;
use Kotchasan\Curl;
use Kotchasan\Http\Request;

/**
 * Social Authentication Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /index/social
     * Handle social login requests
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function index(Request $request)
    {
        try {
            $this->validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Check rate limiting (same as registration)
            $clientIp = $request->getClientIp();
            if (!\Index\Register\Model::checkRegistrationRateLimit($clientIp)) {
                return $this->errorResponse('Please wait a moment before trying again', 429);
            }

            $provider = $request->post('provider')->filter('a-z');

            if ($provider === 'google') {
                return $this->google($request);
            } elseif ($provider === 'facebook') {
                return $this->facebook($request);
            } elseif ($provider === 'telegram') {
                return $this->telegram($request);
            }
            return $this->errorResponse('Invalid social provider '.$provider, 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to initiate social login: '.$e->getMessage(), 500);
        }
    }

    /**
     * Handle Google login
     *
     * @param Request $request
     */
    private function google(Request $request)
    {
        if (empty(self::$cfg->google_client_id)) {
            return $this->errorResponse('Google login is not configured', 400);
        }

        $googleUser = $this->verifyGoogleToken(trim((string) $request->post('access_token')->toString()));
        if ($googleUser === null) {
            return $this->errorResponse('Invalid Google login', 401);
        }

        $googleId = trim((string) ($googleUser['sub'] ?? ''));
        if ($googleId === '') {
            return $this->errorResponse('Invalid Google login', 401);
        }

        $email = trim((string) ($googleUser['email'] ?? ''));
        $username = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'google_'.$googleId;
        $name = trim((string) ($googleUser['name'] ?? (($googleUser['given_name'] ?? '').' '.($googleUser['family_name'] ?? ''))));

        $data = [
            'username' => $username,
            'name' => $name !== '' ? $name : 'Google User',
            'picture' => $this->safeUrl($googleUser['picture'] ?? ''),
            'social' => 'google'
        ];

        return $this->handleSocialLogin(
            $request,
            'Google',
            $data,
            $request->post('intended_url')->toString()
        );
    }

    /**
     * Handle Facebook login
     *
     * @param Request $request
     */
    private function facebook(Request $request)
    {
        if (empty(self::$cfg->facebook_appId) || empty(self::$cfg->facebook_appSecret)) {
            return $this->errorResponse('Facebook login is not configured', 400);
        }

        $facebookUser = $this->verifyFacebookToken(trim((string) $request->post('access_token')->toString()));
        if ($facebookUser === null) {
            return $this->errorResponse('Invalid Facebook login', 401);
        }

        $facebookId = trim((string) ($facebookUser['id'] ?? ''));
        if ($facebookId === '') {
            return $this->errorResponse('Invalid Facebook login', 401);
        }

        $name = trim((string) ($facebookUser['name'] ?? (($facebookUser['first_name'] ?? '').' '.($facebookUser['last_name'] ?? ''))));

        $data = [
            'username' => $facebookId,
            'name' => $name !== '' ? $name : 'Facebook User',
            'picture' => $this->safeUrl($facebookUser['picture']['data']['url'] ?? ''),
            'social' => 'facebook'
        ];

        return $this->handleSocialLogin(
            $request,
            'Facebook',
            $data,
            $request->post('intended_url')->toString()
        );
    }

    /**
     * Handle Telegram login
     *
     * @param Request $request
     */
    private function telegram(Request $request)
    {
        $authData = [
            'id' => $request->post('id')->number(),
            'first_name' => $request->post('first_name')->topic(),
            'last_name' => $request->post('last_name')->topic(),
            'username' => $request->post('username')->username(),
            'photo_url' => $request->post('photo_url')->url(),
            'auth_date' => $request->post('auth_date')->number(),
            'hash' => trim((string) $request->post('hash')->toString())
        ];

        $authData = array_filter($authData, static function ($value) {
            return $value !== '' && $value !== null;
        });

        $telegramUser = \Gcms\Telegram::validateLogin($authData);
        if ($telegramUser === false) {
            return $this->errorResponse('Invalid Telegram login', 401);
        }

        $telegramId = trim((string) ($telegramUser['id'] ?? ''));
        if ($telegramId === '') {
            return $this->errorResponse('Invalid Telegram login', 401);
        }

        $displayName = trim((string) (($telegramUser['first_name'] ?? '').' '.($telegramUser['last_name'] ?? '')));
        $username = trim((string) ($telegramUser['username'] ?? ''));

        $data = [
            'username' => $username !== '' ? 'telegram_'.$telegramId.'_'.$username : 'telegram_'.$telegramId,
            'name' => $displayName !== '' ? $displayName : 'Telegram User',
            'picture' => $telegramUser['photo_url'] ?? '',
            'telegram_id' => $telegramId,
            'social' => 'telegram'
        ];

        return $this->handleSocialLogin(
            $request,
            'Telegram',
            $data,
            $request->post('intended_url')->toString()
        );
    }

    /**
     * Shared social login handler
     *
     * @param Request $request
     * @param string $provider Provider name (for logging)
     * @param array $data
     * @param string $intended_url Intended URL after login
     *
     * @return mixed
     */
    private function handleSocialLogin(Request $request, $provider, $data, $intended_url)
    {
        $result = \Index\Social\Model::authenticate(
            $data,
            $intended_url,
            $provider,
            $request->getClientIp()
        );
        if (!$result['success']) {
            return $this->errorResponse($result['message'], $result['code'] ?? 400);
        }

        if (!empty($result['token'])) {
            \Index\Auth\Model::setCookie('auth_token', $result['token']);
        }

        return $this->successResponse([
            'user' => $result['user'],
            'token' => $result['token'],
            'refresh_token' => $result['refresh_token'] ?? null,
            'expires_in' => $result['expires_in'] ?? null,
            'token_type' => $result['token_type'] ?? null,
            'actions' => [
                [
                    'type' => 'notification',
                    'message' => $result['message'],
                    'variant' => 'success'
                ],
                [
                    'type' => 'redirect',
                    'url' => $intended_url ?: '/'
                ]
            ]
        ], $result['message']);
    }

    /**
     * @param string $idToken
     *
     * @return array|null
     */
    private function verifyGoogleToken($idToken)
    {
        if ($idToken === '') {
            return null;
        }

        $payload = $this->fetchJson('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken
        ]);
        if (empty($payload['sub'])) {
            return null;
        }

        $audience = trim((string) ($payload['aud'] ?? ''));
        $configured = trim((string) self::$cfg->google_client_id);
        if ($audience === '' || $configured === '' || !$this->googleOAuthClientIdsMatch($audience, $configured)) {
            return null;
        }

        $issuer = trim((string) ($payload['iss'] ?? ''));
        if ($issuer !== '' && !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param string $accessToken
     *
     * @return array|null
     */
    private function verifyFacebookToken($accessToken)
    {
        if ($accessToken === '') {
            return null;
        }

        $appToken = self::$cfg->facebook_appId.'|'.self::$cfg->facebook_appSecret;
        $debug = $this->fetchJson('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $appToken
        ]);
        $debugData = isset($debug['data']) && is_array($debug['data']) ? $debug['data'] : [];
        if (empty($debugData['is_valid']) || empty($debugData['user_id'])) {
            return null;
        }
        if ((string) ($debugData['app_id'] ?? '') !== (string) self::$cfg->facebook_appId) {
            return null;
        }

        $profile = $this->fetchJson('https://graph.facebook.com/'.$debugData['user_id'], [
            'fields' => 'id,first_name,last_name,name,picture',
            'access_token' => $accessToken
        ]);
        if (($profile['id'] ?? '') !== $debugData['user_id']) {
            return null;
        }

        return $profile;
    }

    /**
     * @param string $url
     * @param array  $params
     *
     * @return array
     */
    private function fetchJson($url, array $params)
    {
        try {
            $content = (new Curl())->get($url, $params);
        } catch (\Exception $e) {
            return [];
        }

        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function safeUrl($url)
    {
        $url = trim((string) $url);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * Whether token aud matches configured Google OAuth client ID (full or without .apps... suffix).
     *
     * @param string $audience from id_token
     * @param string $configured from settings
     */
    private function googleOAuthClientIdsMatch($audience, $configured)
    {
        $aud = strtolower(trim((string) $audience));
        $cfg = strtolower(trim((string) $configured));
        if ($aud === '' || $cfg === '') {
            return false;
        }
        if ($aud === $cfg) {
            return true;
        }
        $suffix = '.apps.googleusercontent.com';
        $cfgFull = (substr($cfg, -strlen($suffix)) === $suffix) ? $cfg : $cfg.$suffix;
        if ($aud === $cfgFull) {
            return true;
        }
        $audFirst = explode('.', $aud)[0];
        $cfgFirst = explode('.', $cfg)[0];

        return $audFirst !== '' && $cfgFirst !== '' && $audFirst === $cfgFirst;
    }
}
