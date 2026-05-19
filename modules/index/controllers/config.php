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
        'company'
    ];

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

        $config = [];

        foreach (self::PUBLIC_KEYS as $key) {
            if (isset(self::$cfg->$key)) {
                $config[$key] = self::$cfg->$key;
            }
        }

        // Add logo URL if exists
        $img = DATA_FOLDER.'images/logo'.self::$cfg->stored_img_type;
        if (is_file(ROOT_PATH.$img)) {
            $config['logo'] = WEB_URL.$img;
        } else {
            $config['logo'] = WEB_URL.'images/logo.svg';
        }

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
            $img = DATA_FOLDER.'images/'.$key.self::$cfg->stored_img_type;
            if (file_exists(ROOT_PATH.$img)) {
                $variables['--'.$key] = WEB_URL.$img;
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
        foreach (self::PUBLIC_KEYS as $key) {
            if (isset(self::$cfg->$key)) {
                $config[$key] = self::$cfg->$key;
            }
        }

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

        $config = [];

        foreach (self::PUBLIC_KEYS as $key) {
            if (isset(self::$cfg->$key)) {
                $config[$key] = self::$cfg->$key;
            }
        }

        // Add telegram bot ID (without token)
        if (isset(self::$cfg->telegram_bot_token)) {
            $parts = explode(':', self::$cfg->telegram_bot_token);
            $config['telegram_bot_id'] = $parts[0] ?? '';
        }

        return $this->successResponse($config, 'Configuration loaded');
    }
}
