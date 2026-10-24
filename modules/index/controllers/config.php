<?php
/**
 * @filesource modules/index/controllers/config.php
 *
 * Website Configuration Controller
 *
 * Returns website settings from self::$cfg for frontend use
 * Filters sensitive data (passwords, secrets, tokens) from public response
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Config;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Website Configuration Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * List of keys to include in public response
     * Only these keys will be returned for unauthenticated requests
     */
    const PUBLIC_KEYS = [
        'web_title',
        'web_description',
        'login_message',
        'login_message_style',
        'login_header_color',
        'login_footer_color',
        'login_color',
        'login_bg_color',
        'user_register',
        'user_forgot',
        'demo_mode',
        'telegram_bot_username',
        'facebook_appId',
        'google_client_id',
        'line_channel_id',
        'payment_methods',
        'transportation',
        'promptpay_id',
        'discount',
        'company',
        'dashboard_guest'
    ];

    /**
     * Public identifiers of the social login providers that are fully
     * configured; '' for the rest so the login/register templates hide them.
     *
     * Mirrors the checks in modules/index/controllers/social.php: Facebook
     * needs a numeric App ID plus the App Secret, LINE needs channel id +
     * secret, Telegram needs bot username + token, Google only the client id.
     *
     * @return array
     */
    private static function socialLoginProviders()
    {
        $facebookAppId = trim((string) (self::$cfg->facebook_appId ?? ''));
        $facebookSecret = trim((string) (self::$cfg->facebook_appSecret ?? ''));
        $lineChannelId = trim((string) (self::$cfg->line_channel_id ?? ''));
        $lineSecret = trim((string) (self::$cfg->line_channel_secret ?? ''));
        $telegramBot = trim((string) (self::$cfg->telegram_bot_username ?? ''));
        $telegramToken = trim((string) (self::$cfg->telegram_bot_token ?? ''));

        return [
            'facebook_appId' => preg_match('/^\d+$/', $facebookAppId) && $facebookSecret !== '' ? $facebookAppId : '',
            'google_client_id' => trim((string) (self::$cfg->google_client_id ?? '')),
            'line_channel_id' => preg_match('/^\d+$/', $lineChannelId) && $lineSecret !== '' ? $lineChannelId : '',
            'telegram_bot_username' => $telegramBot !== '' && $telegramToken !== '' ? $telegramBot : ''
        ];
    }

    /**
     * Copy PUBLIC_KEYS from self::$cfg into $config, with the social login
     * provider keys replaced by the filtered values above — the login and
     * register templates show a button for every non-empty provider key, so
     * a stray value (e.g. a Facebook page URL saved as the App ID) must never
     * reach the browser.
     *
     * @param array $config
     *
     * @return array
     */
    private static function publicConfig(array $config)
    {
        foreach (self::PUBLIC_KEYS as $key) {
            if (isset(self::$cfg->$key)) {
                $config[$key] = self::$cfg->$key;
            }
        }
        foreach (self::socialLoginProviders() as $key => $value) {
            $config[$key] = $value;
        }
        return $config;
    }

    /**
     * GET index/config/login
     * Get login configuration
     *
     * @param Request $request
     *
     * @return Response
     */
    public function login(Request $request)
    {
        // Validate HTTP method
        \Kotchasan\ApiController::validateMethod($request, 'GET');

        // Set cache headers (5 minutes) to reduce server load
        header('Cache-Control: public, max-age=300');
        header('Expires: '.gmdate('D, d M Y H:i:s', time() + 300).' GMT');

        $config = self::publicConfig([]);

        // Add logo URL if exists
        $img = self::storedImage('images/logo');
        $config['logo'] = $img === null ? WEB_URL.'images/logo.svg' : WEB_URL.DATA_FOLDER.$img;

        return $this->successResponse($config, 'Configuration loaded');
    }

    /**
     * GET index/config/frontend-settings
     * Get theme configuration
     *
     * @param Request $request
     *
     * @return Response
     */
    public function frontendSettings(Request $request)
    {
        // Validate HTTP method
        \Kotchasan\ApiController::validateMethod($request, 'GET');

        // Set cache headers (5 minutes) to reduce server load
        header('Cache-Control: public, max-age=300');
        header('Expires: '.gmdate('D, d M Y H:i:s', time() + 300).' GMT');

        // Build variables for CSS custom properties
        $variables = self::$cfg->theme ?? [];

        foreach (['logo', 'bg_image'] as $key) {
            $img = self::storedImage('images/'.$key);
            if ($img !== null) {
                $variables['--'.$key] = WEB_URL.DATA_FOLDER.$img;
            }
        }

        foreach (self::$cfg->color_status as $key => $value) {
            $variables['--status'.$key] = $value;
        }

        // Build final config (variables only)
        $config = [
            'variables' => $variables
        ];

        // Add public config keys (sent directly for TemplateManager usage)
        $config = self::publicConfig($config);

        // Check authentication and add user
        $login = $this->authenticateRequest($request);
        if ($login) {
            $config['user'] = [
                'id' => $login->id,
                'name' => $login->name,
                'status' => $login->status,
                'avatar' => self::getAvatarUrl($login->id)
            ];
        } else {
            $config['user'] = null;
        }

        return $this->successResponse($config, 'Configuration loaded');
    }

    /**
     * GET index/config/get
     * Get public configuration for checkout
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        // Validate HTTP method
        \Kotchasan\ApiController::validateMethod($request, 'GET');

        // Set cache headers (5 minutes) to reduce server load
        header('Cache-Control: public, max-age=300');
        header('Expires: '.gmdate('D, d M Y H:i:s', time() + 300).' GMT');

        $config = self::publicConfig([]);

        // Add telegram bot ID (without token)
        if (isset(self::$cfg->telegram_bot_token)) {
            $parts = explode(':', self::$cfg->telegram_bot_token);
            $config['telegram_bot_id'] = $parts[0] ?? '';
        }

        return $this->successResponse($config, 'Configuration loaded');
    }
}
