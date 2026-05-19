<?php
/**
 * @filesource modules/index/controllers/settings.php
 *
 * Website Settings Controller
 * Endpoint for settings.html admin form
 * Only Super Admin (status = 1) can access
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Settings;

use Gcms\Api as ApiController;
use Gcms\Config;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Text;
use Kotchasan\Validator;

class Controller extends ApiController
{
    /**
     * Central method for handling settings GET requests
     * Validates authentication and authorization, then returns response with specified data
     *
     * @param Request $request
     * @param array $data Settings data to return
     * @param array $options Optional dropdown/select options
     * @param string $message Success message
     * @return mixed
     */
    private function getSettingsResponse(Request $request, array $data, array $options = [], string $message = '')
    {
        // Validate request method (GET request doesn't need CSRF token)
        ApiController::validateMethod($request, 'GET');

        // Read user from token (Bearer /X-Access-Token param)
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $canConfig = ApiController::hasPermission($login, ['can_config']);
        $isSpecialMessage = in_array($message, ['API', 'SMS', 'LINE', 'Telegram', 'AI']);
        $isSuperAdmin = ApiController::isSuperAdmin($login);
        if ((!$canConfig && !$isSuperAdmin) || (!$isSuperAdmin && $isSpecialMessage)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $response = [
            'data' => (object) $data
        ];

        if (!empty($options)) {
            $response['options'] = $options;
        }

        return $this->successResponse($response, $message.' settings loaded');
    }

    /**
     * Get General settings data
     * @return array
     */
    private function getGeneralData()
    {
        $result = [
            'web_title' => self::$cfg->web_title,
            'web_description' => self::$cfg->web_description,
            'timezone' => self::$cfg->timezone,
            'server_time' => date('d/m/Y H:i:s'),
            'server_version' => 'PHP v'.phpversion(),
            'user_register' => self::$cfg->user_register,
            'user_forgot' => self::$cfg->user_forgot,
            'new_members_active' => self::$cfg->new_members_active,
            'activate_user' => self::$cfg->activate_user,
            'require_terms_acceptance' => self::$cfg->require_terms_acceptance,
            'facebook_appId' => self::$cfg->facebook_appId,
            'google_client_id' => self::$cfg->google_client_id,
            'demo_mode' => self::$cfg->demo_mode,
            'cache_expire' => self::$cfg->cache_expire,
            'default_department' => self::$cfg->default_department
        ];

        // Logo image
        if (file_exists(ROOT_PATH.DATA_FOLDER.'images/logo'.self::$cfg->stored_img_type)) {
            $result['logo'] = [
                [
                    'url' => WEB_URL.DATA_FOLDER.'images/logo'.self::$cfg->stored_img_type.'?'.time(),
                    'name' => 'logo'
                ]
            ];
        } else {
            $result['logo'] = [
                [
                    'url' => WEB_URL.'images/no-image.webp',
                    'name' => 'Choose file'
                ]
            ];
        }

        return $result;
    }

    /**
     * Get Email settings data
     * @return array
     */
    private function getEmailData()
    {
        return [
            'noreply_email' => self::$cfg->noreply_email,
            'email_use_phpMailer' => self::$cfg->email_use_phpMailer,
            'email_Host' => self::$cfg->email_Host,
            'email_Port' => self::$cfg->email_Port,
            'email_SMTPAuth' => self::$cfg->email_SMTPAuth,
            'email_SMTPSecure' => self::$cfg->email_SMTPSecure
        ];
    }

    /**
     * Get API settings data
     * @return array
     */
    private function getApiData()
    {
        return [
            'api_url' => empty(self::$cfg->api_url) ? WEB_URL.'api/' : self::$cfg->api_url,
            'api_token' => empty(self::$cfg->api_tokens['external']) ? \Kotchasan\Password::uniqid(40) : self::$cfg->api_tokens['external'],
            'api_secret' => empty(self::$cfg->api_secret) ? \Kotchasan\Password::uniqid() : self::$cfg->api_secret,
            'api_ips' => !empty(self::$cfg->api_ips) && is_array(self::$cfg->api_ips) ? implode("\n", self::$cfg->api_ips) : '',
            'api_cors' => empty(self::$cfg->api_cors) ? '' : self::$cfg->api_cors
        ];
    }

    /**
     * Get LINE settings data
     * @return array
     */
    private function getLineData()
    {
        return [
            'line_channel_id' => self::$cfg->line_channel_id,
            'line_channel_secret' => self::$cfg->line_channel_secret,
            'line_callback_url' => WEB_URL.'line/callback.php',
            'line_official_account' => self::$cfg->line_official_account,
            'line_channel_access_token' => self::$cfg->line_channel_access_token,
            'line_webhook_url' => WEB_URL.'line/webhook.php'
        ];
    }

    /**
     * Get Telegram settings data
     * @return array
     */
    private function getTelegramData()
    {
        return [
            'telegram_bot_username' => self::$cfg->telegram_bot_username,
            'telegram_chat_id' => self::$cfg->telegram_chat_id,
            'telegram_bot_token' => self::$cfg->telegram_bot_token,
            'telegram_webhook_url' => WEB_URL.'telegram/webhook.php',
            'telegram_webhook_secret' => self::$cfg->telegram_webhook_secret ?? ''
        ];
    }

    /**
     * Get SMS settings data
     * @return array
     */
    private function getSmsData()
    {
        return [
            'sms_username' => self::$cfg->sms_username ?? '',
            'sms_api_key' => self::$cfg->sms_api_key ?? '',
            'sms_api_secret' => self::$cfg->sms_api_secret ?? '',
            'sms_sender' => self::$cfg->sms_sender ?? '',
            'sms_type' => self::$cfg->sms_type ?? ''
        ];
    }

    /**
     * Get Cookie Policy settings data
     * @return array
     */
    private function getCookiePolicyData()
    {
        return [
            'cookie_policy' => self::$cfg->cookie_policy ?? '',
            'data_controller' => self::$cfg->data_controller ?? ''
        ];
    }

    /**
     * Get Theme settings data
     * @return array
     */
    private function getThemeData()
    {
        $result = [];
        foreach (self::$cfg->theme as $key => $value) {
            $key = str_replace('-', '', ucwords(trim($key, '-'), '-'));
            $result[$key] = $value;
        }
        // Body background image
        if (file_exists(ROOT_PATH.DATA_FOLDER.'images/bg_image'.self::$cfg->stored_img_type)) {
            $result['bg_image'] = [
                [
                    'url' => WEB_URL.DATA_FOLDER.'images/bg_image'.self::$cfg->stored_img_type,
                    'name' => 'bg_image'
                ]
            ];
        } else {
            $result['bg_image'] = [
                [
                    'url' => WEB_URL.'images/no-image.webp',
                    'name' => 'Choose file'
                ]
            ];
        }

        if (empty($result['ColorPrimary'])) {
            $result['ColorPrimary'] = '#4361ee';
        }
        if (empty($result['ColorInfo'])) {
            $result['ColorInfo'] = '#0891b2';
        }

        return $result;
    }

    /**
     * Get Company settings data
     * @return array
     */
    private function getCompanyData()
    {
        $company = self::$cfg->company ?? [];

        $result = [
            'company_name' => $company['name'] ?? '',
            'company_name_en' => $company['name_en'] ?? '',
            'company_address' => $company['address'] ?? '',
            'company_phone' => $company['phone'] ?? '',
            'company_fax' => $company['fax'] ?? '',
            'company_email' => $company['email'] ?? '',
            'company_tax_id' => $company['tax_id'] ?? ''
        ];

        // Company logo
        if (file_exists(ROOT_PATH.DATA_FOLDER.'images/company_logo'.self::$cfg->stored_img_type)) {
            $result['company_logo'] = [
                [
                    'url' => WEB_URL.DATA_FOLDER.'images/company_logo'.self::$cfg->stored_img_type,
                    'name' => 'Company logo'
                ]
            ];
        } else {
            $result['company_logo'] = [
                [
                    'url' => WEB_URL.'images/no-image.webp',
                    'name' => 'Choose file'
                ]
            ];
        }

        // Company stamp
        if (file_exists(ROOT_PATH.DATA_FOLDER.'images/company_stamp'.self::$cfg->stored_img_type)) {
            $result['company_stamp'] = [
                [
                    'url' => WEB_URL.DATA_FOLDER.'images/company_stamp'.self::$cfg->stored_img_type,
                    'name' => 'Company stamp'
                ]
            ];
        } else {
            $result['company_stamp'] = [
                [
                    'url' => WEB_URL.'images/no-image.webp',
                    'name' => 'Choose file'
                ]
            ];
        }

        return $result;
    }

    // ==================== Public Endpoints ====================

    /**
     * General settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function general(Request $request)
    {
        return $this->getSettingsResponse(
            $request,
            $this->getGeneralData(),
            [
                'timezone' => $this->getTimezone(),
                'department' => \Gcms\Category::init()->toOptions('department', true, null, ['' => '{LNG_Not specified}'])
            ],
            'General'
        );
    }

    /**
     * Email settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function email(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getEmailData(), [], 'Email');
    }

    /**
     * API settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function api(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getApiData(), [], 'API');
    }

    /**
     * LINE settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function line(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getLineData(), [], 'LINE');
    }

    /**
     * Telegram settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function telegram(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getTelegramData(), [], 'Telegram');
    }

    /**
     * SMS settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function sms(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getSmsData(), ['sms_typies' => $this->getSmsTypies()], 'SMS');
    }

    /**
     * Cookie Policy settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function cookiePolicy(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getCookiePolicyData(), [], 'Cookie Policy');
    }

    /**
     * Theme settings endpoint
     * @param Request $request
     * @return mixed
     */
    public function theme(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getThemeData(), [], 'Theme');
    }

    /**
     * Company settings endpoint
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function company(Request $request)
    {
        return $this->getSettingsResponse($request, $this->getCompanyData(), [], 'Company');
    }

    /**
     * Remove background image
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function removeBgImage(Request $request)
    {
        return $this->removeImage($request, 'bg_image');
    }

    /**
     * Remove logo
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function removeLogo(Request $request)
    {
        return $this->removeImage($request, 'logo');
    }

    /**
     * Remove company logo
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function removeCompanyLogo(Request $request)
    {
        return $this->removeImage($request, 'company_logo');
    }

    /**
     * Remove company stamp
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function removeCompanyStamp(Request $request)
    {
        return $this->removeImage($request, 'company_stamp');
    }

    /**
     * Get timezone list
     * @return array
     */
    private function getTimezone()
    {
        // timezone
        $datas = [];
        foreach (\DateTimeZone::listIdentifiers() as $item) {
            $datas[] = ['text' => $item, 'value' => $item];
        }
        return $datas;
    }

    private function getSmsTypies()
    {
        return [
            ['text' => 'Standard ('.\Thaibluksms\Sms::check_credit(false).')', 'value' => 'standard'],
            ['text' => 'Premium ('.\Thaibluksms\Sms::check_credit(true).')', 'value' => 'premium']
        ];
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            ApiController::validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Get data from request
            $body = $request->getParsedBody();

            if (empty($body['module'])) {
                return $this->errorResponse('Module is required', 400);
            }

            // Normalize module name to a safe format for method call
            $moduleKey = strtolower(preg_replace('/[^a-z\-]/', '', (string) $body['module']));
            $module = ucwords($moduleKey, '-');
            $className = 'parse'.str_replace('-', '', $module).'Settings';

            // Authorization for saving
            if (!ApiController::canModify($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            // Check method exists
            if (!method_exists($this, $className)) {
                return $this->errorResponse('Module not found', 404);
            }

            // Upload image
            $error = $this->imageUpload($request);
            if (!empty($error)) {
                return $this->formErrorResponse($error);
            }

            // Load config
            $config = Config::load(ROOT_PATH.'settings/config.php');

            // Execute
            $ret = $this->$className($body, $config);

            if (!empty($ret)) {
                return $this->formErrorResponse($ret);
            }

            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                // Log
                \Index\Log\Model::add(0, 'index', 'Save', 'Save '.str_replace('-', ' ', $module).' Settings', $login->id);

                // Reload page
                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }
        } catch (\Kotchasan\ApiException $e) {
            // Keep original HTTP code (e.g. 403 CSRF, 405 method)
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        }
        // Error save settings
        return $this->errorResponse('Failed to save settings', 500);
    }

    /**
     * Convert value to boolean
     *
     * @param array $array
     * @param mixed $key
     *
     * @return int
     */
    private function toBoolean($array, $key)
    {
        if (!is_array($array) || !isset($array[$key])) {
            return 0;
        }
        $value = $array[$key];
        return !empty($value) && $value !== '0' && $value !== 'false' ? 1 : 0;
    }

    /**
     * General settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseGeneralSettings($body, $config)
    {
        $ret = [];
        foreach (['web_title', 'web_description'] as $key) {
            if (isset($body[$key])) {
                // allow em, b, strong, i tags
                $value = Text::htmlText($body[$key]);
                if ($value === '') {
                    $ret[$key] = 'Please fill in';
                } else {
                    $config->$key = $value;
                }
            }
        }

        $boolKeys = ['activate_user', 'cookie_policy', 'demo_mode', 'new_members_active', 'require_terms_acceptance', 'user_forgot', 'user_register'];
        foreach ($boolKeys as $key) {
            if (isset($body[$key])) {
                $config->$key = $this->toBoolean($body, $key);
            }
        }

        $textKeys = ['facebook_appId', 'timezone', 'default_department'];
        foreach ($textKeys as $key) {
            if (isset($body[$key])) {
                $config->$key = Text::topic($body[$key]);
            }
        }

        $textKeys = ['cache_expire'];
        foreach ($textKeys as $key) {
            if (isset($body[$key])) {
                $config->$key = intval($body[$key]);
            }
        }

        if (isset($body['google_client_id'])) {
            $parts = explode('.', $body['google_client_id']);
            $config->google_client_id = !empty($parts) ? $parts[0] : '';
        }

        return $ret;
    }

    /**
     * Email settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseEmailSettings($body, $config)
    {
        $ret = [];

        if (!empty($body['noreply_email']) && !Validator::email($body['noreply_email'])) {
            $ret['noreply_email'] = 'Invalid email';
        }

        $config->noreply_email = Text::username($body['noreply_email']);
        if (empty($body['email_Host'])) {
            $config->email_Host = 'localhost';
            $config->email_Port = 25;
            $config->email_SMTPSecure = '';
            $config->email_Username = '';
            $config->email_Password = '';
        } else {
            $config->email_Host = Text::url($body['email_Host']);
            $config->email_Port = (int) $body['email_Port'] ?? 25;
            $config->email_SMTPSecure = Text::filter($body['email_SMTPSecure'], 'a-zA-Z');
            if (!empty($body['email_Username'])) {
                $config->email_Username = Text::username($body['email_Username']);
            }
            if (!empty($body['email_Password'])) {
                $config->email_Password = Text::password($body['email_Password']);
            }
        }
        $config->email_use_phpMailer = (int) $body['email_use_phpMailer'];
        $config->email_SMTPAuth = $this->toBoolean($body, 'email_SMTPAuth');

        return $ret;
    }

    /**
     * API settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseApiSettings($body, $config)
    {
        $config->api_url = Text::url($body['api_url']);
        $config->api_tokens['external'] = Text::password($body['api_token']);
        $config->api_secret = Text::password($body['api_secret']);
        $config->api_cors = Text::url($body['api_cors']);
        $config->api_ips = [];
        foreach (explode("\n", $body['api_ips']) as $ip) {
            if (preg_match('/([0-9\.]+)/', $ip, $match)) {
                $config->api_ips[$match[1]] = $match[1];
            }
        }
        $config->api_ips = array_keys($config->api_ips);

        return [];
    }

    /**
     * Line settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseLineSettings($body, $config)
    {
        $config->line_channel_id = Text::number($body['line_channel_id']);
        $config->line_channel_secret = Text::topic($body['line_channel_secret']);
        $config->line_channel_access_token = Text::topic($body['line_channel_access_token']);
        $config->line_official_account = Text::topic($body['line_official_account']);

        return [];
    }

    /**
     * Telegram settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseTelegramSettings($body, $config)
    {
        $config->telegram_bot_token = Text::topic($body['telegram_bot_token']);
        $config->telegram_chat_id = Text::topic($body['telegram_chat_id']);
        $config->telegram_bot_username = str_replace(['\\', '/', '@'], '', Text::topic($body['telegram_bot_username']));
        $config->telegram_webhook_secret = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($body['telegram_webhook_secret'] ?? ''));

        return [];
    }
    /**
     * SMS settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseSmsSettings($body, $config)
    {
        $config->sms_username = Text::topic($body['sms_username']);
        if (!empty($body['sms_password'])) {
            $config->sms_password = Text::topic($body['sms_password']);
        }
        $config->sms_api_key = Text::topic($body['sms_api_key']);
        $config->sms_api_secret = Text::topic($body['sms_api_secret']);
        $config->sms_sender = Text::topic($body['sms_sender']);
        $config->sms_type = Text::topic($body['sms_type']);

        return [];
    }

    /**
     * Cookie Policy settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseCookiePolicySettings($body, $config)
    {
        $ret = [];

        if (!empty($body['data_controller']) && !Validator::email($body['data_controller'])) {
            $ret['data_controller'] = 'Invalid email';
        }
        $config->cookie_policy = $this->toBoolean($body, 'cookie_policy');
        $config->data_controller = Text::username($body['data_controller']);

        return $ret;
    }

    /**
     * Theme settings
     *
     * @param  array $body
     * @param  object $config
     *
     * @return array
     */
    private function parseThemeSettings($body, $config)
    {
        $primary = Text::color($body['ColorPrimary'] ?? '');

        $config->theme = [
            '--color-background' => Text::color($body['ColorBackground']),
            '--color-text' => Text::color($body['ColorText']),
            '--color-primary' => $primary,
            '--color-info' => Text::color($body['ColorInfo'] ?? ''),
            '--header-color-background' => Text::color($body['HeaderColorBackground']),
            '--header-color-text' => Text::color($body['HeaderColorText']),
            '--sidebar-color-background' => Text::color($body['SidebarColorBackground']),
            '--sidebar-color-text' => Text::color($body['SidebarColorText']),
            '--menu-highlight-bg' => Text::color($body['MenuHighlightBg']),
            '--menu-highlight-text' => Text::color($body['MenuHighlightText']),
            '--footer-color-background' => Text::color($body['FooterColorBackground']),
            '--footer-color-text' => Text::color($body['FooterColorText'])
        ];
        foreach ($config->theme as $key => $value) {
            if (empty($value)) {
                unset($config->theme[$key]);
            }
        }

        return [];
    }

    /**
     * Company settings
     *
     * @param array $body
     * @param object $config
     *
     * @return array
     */
    private function parseCompanySettings($body, $config)
    {
        $ret = [];

        // Initialize company array if not exists
        if (!isset($config->company) || !is_array($config->company)) {
            $config->company = [];
        }

        // Text fields
        $config->company['name'] = Text::topic($body['company_name'] ?? '');
        $config->company['name_en'] = Text::topic($body['company_name_en'] ?? '');
        $config->company['address'] = Text::textarea($body['company_address'] ?? '');
        $config->company['phone'] = Text::topic($body['company_phone'] ?? '');
        $config->company['fax'] = Text::topic($body['company_fax'] ?? '');
        $config->company['email'] = Text::username($body['company_email'] ?? '');
        $config->company['tax_id'] = Text::number($body['company_tax_id'] ?? '');

        // Keep existing logo/stamp paths if they exist
        $logoPath = DATA_FOLDER.'company/logo'.self::$cfg->stored_img_type;
        $stampPath = DATA_FOLDER.'company/stamp'.self::$cfg->stored_img_type;

        if (file_exists(ROOT_PATH.$logoPath)) {
            $config->company['logo'] = $logoPath;
        }
        if (file_exists(ROOT_PATH.$stampPath)) {
            $config->company['stamp'] = $stampPath;
        }

        return $ret;
    }

    /**
     * Send test email
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function testEmail(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            // Get email from logged-in user
            $toEmail = $login->username ?? '';

            if (empty($toEmail) || !Validator::email($toEmail)) {
                return $this->errorResponse('Your account does not have a valid email address', 400);
            }

            // Send test email
            $subject = self::$cfg->web_title.' - Test Email';
            $message = '<h2>Test Email</h2>';
            $message .= '<p>This is a test email from '.self::$cfg->web_title.'.</p>';
            $message .= '<p>If you received this email, your email configuration is working correctly.</p>';
            $message .= '<hr>';
            $message .= '<p><small>Sent at: '.date('Y-m-d H:i:s').'</small></p>';

            $email = \Kotchasan\Email::send($toEmail, '', $subject, $message);

            if ($email->error()) {
                return $this->errorResponse('Failed to send email: '.$email->getErrorMessage(), 500);
            }

            // Log
            \Index\Log\Model::add(0, 'index', 'Other', 'Test email sent to '.$toEmail, $login->id);

            return $this->successResponse([], 'Test email sent to '.$toEmail);
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to send test: '.$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function testTelegram(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            $bot_token = $request->post('bot_token')->topic();
            $chat_id = $request->post('chat_id')->topic();

            // ทดสอบส่งข้อความ Telegram
            $error = \Gcms\Telegram::sendTo($chat_id, strip_tags(self::$cfg->web_title), $bot_token);
            if ($error !== '') {
                return $this->errorResponse($error, 400);
            }

            return $this->successResponse([], 'Test Telegram success');
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to send test: '.$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function setTelegramWebhook(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            $botToken = $request->post('bot_token')->topic();
            $webhookUrl = $request->post('webhook_url')->url();
            $secretToken = preg_replace('/[^A-Za-z0-9_-]/', '', $request->post('secret_token')->topic());

            if ($botToken === '' || $webhookUrl === '') {
                return $this->errorResponse('Bot token and webhook URL are required', 400);
            }

            $result = \Gcms\Telegram::setWebhook($webhookUrl, $botToken, $secretToken);

            return $this->telegramWebhookResponse($result, 'Telegram webhook configured');
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to set Telegram webhook: '.$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function deleteTelegramWebhook(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            $botToken = $request->post('bot_token')->topic();
            if ($botToken === '') {
                return $this->errorResponse('Bot token is required', 400);
            }

            $result = \Gcms\Telegram::deleteWebhook($botToken);

            return $this->telegramWebhookResponse($result, 'Telegram webhook removed');
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete Telegram webhook: '.$e->getMessage(), 500);
        }
    }

    /**
     * @param mixed  $result
     * @param string $defaultMessage
     *
     * @return mixed
     */
    private function telegramWebhookResponse($result, $defaultMessage)
    {
        if ($result === false) {
            return $this->errorResponse('Telegram request failed', 502);
        }
        if (is_string($result) && $result !== '') {
            return $this->errorResponse($result, 400);
        }
        if (!is_array($result)) {
            return $this->errorResponse('Unexpected Telegram response', 502);
        }
        if (isset($result['ok']) && !$result['ok']) {
            return $this->errorResponse($result['description'] ?? 'Telegram API request failed', 400);
        }

        return $this->successResponse($result, $result['description'] ?? $defaultMessage);
    }

    // ==================== AI Connector ====================

    /**
     * Get AI connector settings data
     *
     * @return array
     */
    private function getAiData()
    {
        $settings = new \Gcms\Chat\SettingsRepository();
        $connector = $settings->connector();
        $activeProvider = $connector['ai_provider'] ?? (self::$cfg->ai_provider ?? 'openai');
        $editProvider = $activeProvider;
        $connections = \Gcms\Ai::connectionSettings();
        $connection = !empty($connections[$editProvider]) ? $connections[$editProvider] : [];

        return [
            'ai_enabled' => $connector['ai_enabled'] ?? (self::$cfg->ai_enabled ?? 0),
            'ai_provider' => $activeProvider,
            'ai_edit_provider' => $editProvider,
            'ai_api_key' => $connection['api_key'] ?? '',
            'ai_api_url' => $connection['api_url'] ?? '',
            'ai_model' => $connection['model_option'] ?? '',
            'ai_custom_model' => $connection['custom_model'] ?? '',
            'ai_max_tokens' => $connection['max_tokens'] ?? 1024,
            'ai_temperature' => $connection['temperature'] ?? 0.7,
            'ai_connections' => $connections,
            'ai_provider_defaults' => \Gcms\Ai::providerDefaults()
        ];
    }

    /**
     * AI connector settings endpoint (GET)
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function ai(Request $request)
    {
        return $this->getSettingsResponse(
            $request,
            $this->getAiData(),
            ['ai_providers' => $this->getAiProviders()],
            'AI'
        );
    }

    /**
     * Save AI connector settings
     *
     * @param array  $body
     * @param object $config
     *
     * @return array Validation errors (empty = success)
     */
    private function parseAiSettings($body, $config)
    {
        $ret = [];
        $config->ai_enabled = isset($body['ai_enabled']) ? $this->toBoolean($body, 'ai_enabled') : 0;
        $config->ai_provider = $this->normalizeAiProvider($body['ai_provider'] ?? 'openai');
        $editProvider = $this->normalizeAiProvider($body['ai_edit_provider'] ?? $config->ai_provider);
        $defaults = \Gcms\Ai::providerDefaults($editProvider);
        if (empty($defaults)) {
            $ret['ai_edit_provider'] = 'Invalid provider';

            return $ret;
        }

        $connection = $this->buildAiConnection($editProvider, $body, $ret);
        if (!empty($ret)) {
            return $ret;
        }

        if (!isset($config->ai_connections) || !is_array($config->ai_connections)) {
            $config->ai_connections = [];
        }
        $config->ai_connections[$editProvider] = $connection;

        $activeConnection = !empty($config->ai_connections[$config->ai_provider]) && is_array($config->ai_connections[$config->ai_provider])
            ? $config->ai_connections[$config->ai_provider]
            : [];
        $this->syncLegacyAiConfig($config, $config->ai_provider, $activeConnection);

        return $ret;
    }

    /**
     * Test the AI connector with a simple chat request
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function testAi(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!$this->isSuperAdmin($login) && !$this->isAdmin($login) && !$this->hasPermission($login, 'can_config')) {
                return $this->errorResponse('No data available', 404);
            }

            $body = $request->getParsedBody();
            $provider = $this->normalizeAiProvider($body['ai_edit_provider'] ?? $body['ai_provider'] ?? self::$cfg->ai_provider ?? 'openai');
            $defaults = \Gcms\Ai::providerDefaults($provider);
            if (empty($defaults)) {
                return $this->errorResponse('Invalid provider', 400);
            }
            $errors = [];
            $connection = $this->buildAiConnection($provider, $body, $errors);
            if (!empty($errors)) {
                return $this->errorResponse(reset($errors), 400);
            }

            $config = [
                'api_key' => $connection['api_key'],
                'api_url' => $connection['api_url'] !== '' ? $connection['api_url'] : ($defaults['default_api_url'] ?? ''),
                'model' => !empty($connection['use_custom_model']) ? $connection['custom_model'] : $connection['model']
            ];
            $config['max_tokens'] = 64;
            $config['temperature'] = 0.0;

            $driver = \Gcms\Ai::driver($provider, $config);
            $response = $driver->chat([['role' => 'user', 'content' => 'Reply with the single word: OK']]);

            if ($response->success) {
                \Index\Log\Model::add(0, 'index', 'Index', 'AI test succeeded (provider: '.$provider.')', $login->id);
                return $this->successResponse(
                    ['content' => $response->content, 'model' => $response->model],
                    'AI connection test successful'
                );
            }
            return $this->errorResponse('AI test failed: '.$response->error, 502);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('AI test failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * Generate a theme palette suggestion from AI using a short design brief.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function suggestTheme(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!$this->isSuperAdmin($login) && !$this->isAdmin($login) && !$this->hasPermission($login, 'can_config')) {
                return $this->errorResponse('No data available', 404);
            }
            if (empty(self::$cfg->ai_enabled)) {
                return $this->errorResponse('AI connector is disabled', 400);
            }

            $body = $request->getParsedBody();
            $prompt = trim((string) ($body['theme_prompt'] ?? ''));
            if ($prompt === '') {
                return $this->errorResponse('Theme prompt is required', 400);
            }

            $fallbackColors = $this->getThemeSuggestionFallbackColors();
            $message = 'Design brief: '.$prompt."\n"
            .'Current theme colors: '.json_encode($fallbackColors)."\n"
                .'Return a fresh color palette that is readable and accessible for admin/public website usage. '
                .'ColorPrimary must be a dark saturated brand hue (high contrast on light gray/white), not a light pastel.';

            $response = \Gcms\Ai::driver()->chat(
                [
                    ['role' => 'user', 'content' => $message]
                ],
                [
                    'system' => $this->themeSuggestionSystemPrompt(),
                    'temperature' => 0.45,
                    'max_tokens' => 500
                ]
            );

            if (!$response->success) {
                return $this->errorResponse('AI theme suggestion failed: '.$response->error, 502);
            }

            $decoded = $this->decodeAiJsonResponse($response->content);
            if (!is_array($decoded)) {
                $decoded = $this->repairThemeSuggestionWithAi($response->content);
            }
            if (!is_array($decoded)) {
                $decoded = $this->extractThemeSuggestionFromText($response->content, $fallbackColors);
            }
            if (!is_array($decoded)) {
                $decoded = [
                    'name' => 'AI Theme Concept',
                    'description' => 'Fallback palette used because AI response format was not parseable',
                    'colors' => $fallbackColors
                ];
            }

            $suggestion = $this->normalizeThemeSuggestionPayload($decoded, $fallbackColors);

            return $this->successResponse(
                [
                    'suggestion' => $suggestion,
                    'model' => $response->model,
                    'content' => $response->content
                ],
                'Theme suggestion generated'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Theme suggestion failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * Fixed mapping between form fields and theme CSS variables.
     *
     * @return array
     */
    private function themeColorFieldMap()
    {
        return [
            'ColorBackground' => '--color-background',
            'ColorText' => '--color-text',
            'ColorPrimary' => '--color-primary',
            'ColorInfo' => '--color-info',
            'HeaderColorBackground' => '--header-color-background',
            'HeaderColorText' => '--header-color-text',
            'SidebarColorBackground' => '--sidebar-color-background',
            'SidebarColorText' => '--sidebar-color-text',
            'MenuHighlightBg' => '--menu-highlight-bg',
            'MenuHighlightText' => '--menu-highlight-text',
            'FooterColorBackground' => '--footer-color-background',
            'FooterColorText' => '--footer-color-text'
        ];
    }

    /**
     * Max WCAG relative luminance for ColorPrimary when normalizing AI suggestions (user saves are not clamped).
     */
    private function themePrimaryMaxRelativeLuminance()
    {
        return 0.45;
    }

    /**
     * @return int[]|null [R,G,B] 0–255
     */
    private function themeRgbFromHex($hex)
    {
        $hex = ltrim(Text::color((string) $hex), '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }

    /**
     * WCAG 2 relative luminance for sRGB color.
     *
     * @param int[] $rgb
     */
    private function themeRelativeLuminance(array $rgb)
    {
        $lin = [];
        foreach ($rgb as $c) {
            $c = max(0, min(255, (int) $c)) / 255;
            $lin[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /**
     * Mix color toward black until luminance is at or below the threshold (for AI suggestions).
     *
     * @param string $hex
     *
     * @return string
     */
    private function enforceDarkThemePrimary($hex)
    {
        $maxL = $this->themePrimaryMaxRelativeLuminance();
        $rgb = $this->themeRgbFromHex($hex);
        if ($rgb === null) {
            return '#1e3a5f';
        }
        if ($this->themeRelativeLuminance($rgb) <= $maxL) {
            return Text::color('#'.sprintf('%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]));
        }

        for ($t = 1; $t <= 100; $t++) {
            $k = $t / 100;
            $r = (int) round($rgb[0] * (1 - $k));
            $g = (int) round($rgb[1] * (1 - $k));
            $b = (int) round($rgb[2] * (1 - $k));
            $candidate = [$r, $g, $b];
            if ($this->themeRelativeLuminance($candidate) <= $maxL) {
                return Text::color('#'.sprintf('%02x%02x%02x', $r, $g, $b));
            }
        }

        return '#1e3a5f';
    }

    /**
     * Build baseline colors from config->theme with safe defaults.
     *
     * @return array
     */
    private function getThemeSuggestionFallbackColors()
    {
        $defaults = [
            'ColorBackground' => '#f8fafc',
            'ColorText' => '#1e293b',
            'ColorPrimary' => '#4361ee',
            'ColorInfo' => '#0891b2',
            'HeaderColorBackground' => '#0f172a',
            'HeaderColorText' => '#f8fafc',
            'SidebarColorBackground' => '#e2e8f0',
            'SidebarColorText' => '#0f172a',
            'MenuHighlightBg' => '#0ea5e9',
            'MenuHighlightText' => '#ffffff',
            'FooterColorBackground' => '#0f172a',
            'FooterColorText' => '#cbd5e1'
        ];

        $theme = !empty(self::$cfg->theme) && is_array(self::$cfg->theme) ? self::$cfg->theme : [];
        foreach ($this->themeColorFieldMap() as $field => $token) {
            $color = isset($theme[$token]) ? Text::color((string) $theme[$token]) : '';
            if ($color !== '') {
                $defaults[$field] = $color;
            }
        }

        return $defaults;
    }

    /**
     * Strict JSON instruction for AI theme palette generation.
     *
     * @return string
     */
    private function themeSuggestionSystemPrompt()
    {
        return 'You are a UI theme designer for GCMS. Output JSON only (no markdown). '
            .'Schema: {"name":"...","description":"...","colors":{"ColorBackground":"#RRGGBB","ColorText":"#RRGGBB",'
            .'"ColorPrimary":"#RRGGBB","ColorInfo":"#RRGGBB",'
            .'"HeaderColorBackground":"#RRGGBB","HeaderColorText":"#RRGGBB","SidebarColorBackground":"#RRGGBB",'
            .'"SidebarColorText":"#RRGGBB","MenuHighlightBg":"#RRGGBB","MenuHighlightText":"#RRGGBB",'
            .'"FooterColorBackground":"#RRGGBB","FooterColorText":"#RRGGBB"}}. '
            .'Use only hex colors. ColorPrimary MUST be a dark saturated brand color (like deep blue, deep teal, or deep purple) suitable for links on white/light gray — never light pastel or near-white. '
            .'ColorInfo should be a distinct accent (often teal or cyan) pairing with primary for gradients. Ensure good readability and contrast.';
    }

    /**
     * Decode JSON object from plain text or fenced markdown response.
     *
     * @param string $content
     *
     * @return array|null
     */
    private function decodeAiJsonResponse($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $match)) {
            $decoded = $this->decodeJsonWithNormalization($match[1]);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($content, $start, $end - $start + 1);
            $decoded = $this->decodeJsonWithNormalization($json);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Try decoding JSON after lightweight normalization for common model output issues.
     *
     * @param string $json
     *
     * @return array|null
     */
    private function decodeJsonWithNormalization($json)
    {
        $json = trim((string) $json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $normalized = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $json);
        $normalized = str_replace(['“', '”', '’', '‘'], ['"', '"', "'", "'"], $normalized);
        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized);
        $normalized = preg_replace('/([{,]\s*)([A-Za-z_][A-Za-z0-9_\-]*)\s*:/', '$1"$2":', $normalized);
        $normalized = preg_replace("/'([^'\\]*(?:\\.[^'\\]*)*)'/", '"$1"', $normalized);

        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Ask AI once more to repair non-JSON output into strict JSON schema.
     *
     * @param string $content
     *
     * @return array|null
     */
    private function repairThemeSuggestionWithAi($content)
    {
        try {
            $repair = \Gcms\Ai::driver()->chat(
                [
                    ['role' => 'user', 'content' => (string) $content]
                ],
                [
                    'system' => 'Convert the user message into strict JSON only. No markdown. '
                    .'Schema: {"name":"...","description":"...","colors":{"ColorBackground":"#RRGGBB","ColorText":"#RRGGBB",'
                    .'"ColorPrimary":"#RRGGBB","ColorInfo":"#RRGGBB",'
                    .'"HeaderColorBackground":"#RRGGBB","HeaderColorText":"#RRGGBB","SidebarColorBackground":"#RRGGBB",'
                    .'"SidebarColorText":"#RRGGBB","MenuHighlightBg":"#RRGGBB","MenuHighlightText":"#RRGGBB",'
                    .'"FooterColorBackground":"#RRGGBB","FooterColorText":"#RRGGBB"}}',
                    'temperature' => 0.0,
                    'max_tokens' => 380
                ]
            );

            if (!$repair->success) {
                return null;
            }

            return $this->decodeAiJsonResponse($repair->content);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Fallback parser: extract color values from free-form text and map to theme fields.
     *
     * @param string $content
     * @param array  $fallbackColors
     *
     * @return array|null
     */
    private function extractThemeSuggestionFromText($content, array $fallbackColors)
    {
        $text = (string) $content;
        if ($text === '') {
            return null;
        }

        preg_match_all('/#[0-9a-fA-F]{6}\b/', $text, $matches);
        $hexes = !empty($matches[0]) ? array_values(array_unique($matches[0])) : [];
        if (empty($hexes)) {
            return null;
        }

        $colors = $fallbackColors;
        $fields = array_keys($this->themeColorFieldMap());
        foreach ($fields as $index => $field) {
            if (isset($hexes[$index])) {
                $color = Text::color($hexes[$index]);
                if ($color !== '') {
                    $colors[$field] = $color;
                }
            }
        }

        return [
            'name' => 'AI Theme Concept',
            'description' => 'Auto-parsed from non-JSON AI response',
            'colors' => $colors
        ];
    }

    /**
     * Normalize AI payload into the exact theme form keys.
     *
     * @param array $payload
     * @param array $fallbackColors
     *
     * @return array
     */
    private function normalizeThemeSuggestionPayload(array $payload, array $fallbackColors)
    {
        $palette = !empty($payload['colors']) && is_array($payload['colors']) ? $payload['colors'] : $payload;
        $colors = [];
        foreach ($this->themeColorFieldMap() as $field => $token) {
            $value = $palette[$field] ?? ($palette[$token] ?? '');
            $color = Text::color((string) $value);
            if ($color === '') {
                $color = $fallbackColors[$field];
            }
            if ($field === 'ColorPrimary') {
                $color = $this->enforceDarkThemePrimary($color);
            }
            $colors[$field] = $color;
        }

        $name = trim(strip_tags((string) ($payload['name'] ?? '')));
        $description = trim(strip_tags((string) ($payload['description'] ?? '')));

        return [
            'name' => $name !== '' ? $name : 'AI Theme Concept',
            'description' => $description,
            'colors' => $colors
        ];
    }

    /**
     * Supported AI provider list for the settings dropdown
     *
     * @return array
     */
    private function getAiProviders()
    {
        return \Gcms\Ai::providerOptions();
    }

    /**
     * Normalize provider name.
     *
     * @param string $provider
     *
     * @return string
     */
    private function normalizeAiProvider($provider)
    {
        return \Kotchasan\Text::filter((string) $provider, 'a-z');
    }

    /**
     * Build provider-specific AI connection data from form input.
     *
     * @param string $provider
     * @param array  $body
     * @param array  $errors
     *
     * @return array
     */
    private function buildAiConnection($provider, $body, &$errors)
    {
        $defaults = \Gcms\Ai::providerDefaults($provider);
        $models = !empty($defaults['models']) && is_array($defaults['models']) ? $defaults['models'] : [];
        $modelOption = trim((string) ($body['ai_model'] ?? ''));
        $customModel = trim((string) ($body['ai_custom_model'] ?? ''));

        if ($modelOption === '__custom__') {
            $customModel = \Kotchasan\Text::topic($customModel);
            if ($customModel === '') {
                $errors['ai_custom_model'] = 'Please fill in';
            }
            $model = '';
            $useCustomModel = 1;
        } else {
            $model = $modelOption !== '' ? \Kotchasan\Text::topic($modelOption) : ($defaults['default_model'] ?? '');
            if ($model !== '' && !in_array($model, $models, true)) {
                $errors['ai_model'] = 'Invalid model';
            }
            $customModel = '';
            $useCustomModel = 0;
        }

        $apiUrl = trim((string) ($body['ai_api_url'] ?? ''));
        $apiUrl = $apiUrl !== '' ? \Kotchasan\Text::url($apiUrl) : '';
        if (!empty($defaults['default_api_url']) && $apiUrl === $defaults['default_api_url']) {
            $apiUrl = '';
        }

        return [
            'api_key' => \Kotchasan\Text::topic($body['ai_api_key'] ?? ''),
            'api_url' => $apiUrl,
            'model' => $model,
            'custom_model' => $customModel,
            'use_custom_model' => $useCustomModel,
            'max_tokens' => max(1, (int) ($body['ai_max_tokens'] ?? 1024)),
            'temperature' => min(2.0, max(0.0, (float) ($body['ai_temperature'] ?? 0.7)))
        ];
    }

    /**
     * Keep legacy single-provider fields aligned with the active provider.
     *
     * @param object $config
     * @param string $provider
     * @param array  $connection
     *
     * @return void
     */
    private function syncLegacyAiConfig($config, $provider, array $connection)
    {
        $defaults = \Gcms\Ai::providerDefaults($provider);
        $config->ai_api_key = $connection['api_key'] ?? '';
        $config->ai_api_url = !empty($connection['api_url']) ? $connection['api_url'] : ($defaults['default_api_url'] ?? '');
        $config->ai_model = !empty($connection['use_custom_model']) ? ($connection['custom_model'] ?? '') : (!empty($connection['model']) ? $connection['model'] : ($defaults['default_model'] ?? ''));
        $config->ai_max_tokens = isset($connection['max_tokens']) ? (int) $connection['max_tokens'] : 1024;
        $config->ai_temperature = isset($connection['temperature']) ? (float) $connection['temperature'] : 0.7;
    }

    // ==================== Image helpers ====================

    /**
     * @param Request $request
     */
    private function imageUpload(Request $request)
    {
        $errors = [];
        // File storage directory
        $dir = ROOT_PATH.DATA_FOLDER.'images/';
        // อัปโหลดไฟล์
        foreach ($request->getUploadedFiles() as $item => $file) {
            if (in_array($item, ['logo', 'bg_image', 'company_logo', 'company_stamp'])) {
                if (!File::makeDirectory($dir)) {
                    // The directory cannot be created.
                    $errors[$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'images/');
                } elseif ($file->hasUploadFile()) {
                    try {
                        $file->resizeImage(self::$cfg->img_typies, $dir, $item.self::$cfg->stored_img_type, self::$cfg->stored_img_size);
                    } catch (\Exception $exc) {
                        // Unable to upload
                        $errors[$item] = Language::get($exc->getMessage());
                    }
                } elseif ($err = $file->getErrorMessage()) {
                    // Upload error
                    $errors[$item] = $err;
                }
            }
        }
        return $errors;
    }

    /**
     * Remove image file (logo or bg_image)
     *
     * @param Request $request
     * @param string $item Image type to remove
     *
     * @return mixed
     */
    private function removeImage(Request $request, $item)
    {
        try {
            // Whitelist allowed image types to prevent path traversal
            $allowedItems = ['logo', 'bg_image', 'company_logo', 'company_stamp'];
            if (!in_array($item, $allowedItems, true)) {
                return $this->errorResponse('Invalid image type', 400);
            }

            ApiController::validateMethod($request, 'POST');
            ApiController::validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Authorization for remove image
            if (!ApiController::isSuperAdmin($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $dir = ROOT_PATH.DATA_FOLDER.'images/';
            $filePath = $dir.$item.self::$cfg->stored_img_type;

            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    return $this->errorResponse('Failed to delete image', 500);
                }

                // Log the action
                \Index\Log\Model::add(0, 'index', 'Delete', 'Removed '.$item.' image', $login->id);

                return $this->redirectResponse('reload', ucfirst(str_replace('_', ' ', $item)).' removed successfully', 200, 1000);
            }

            // File doesn't exist, still success (idempotent)
            return $this->redirectResponse('reload', ucfirst(str_replace('_', ' ', $item)).' already removed', 200, 1000);
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to remove image: '.$e->getMessage(), 500);
        }
    }
}
