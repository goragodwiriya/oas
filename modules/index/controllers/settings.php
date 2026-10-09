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
     * รายชื่อค่าตั้งค่าที่ห้ามส่งกลับไปแสดงบนหน้าจอ
     *
     * คีย์ที่อยู่ในรายการนี้จะถูกแทนที่ด้วยค่าว่างในทุก response ของหน้าตั้งค่า
     * (ผ่าน hideValues() ที่ getSettingsResponse() เรียกให้อัตโนมัติ) ผู้ใช้จึง
     * เห็นช่องกรอกเปล่า · ถ้าต้องการเปลี่ยนค่าให้กรอกค่าใหม่ทับลงไป ถ้าเว้นว่างไว้
     * ระบบจะคงค่าเดิมในไฟล์ config ไว้ (ผ่าน hasNewValue() ที่ parseXxxSettings() เรียก)
     *
     * เพิ่มคีย์ใหม่ได้ที่นี่จุดเดียว ไม่ต้องแก้ getXxxData() และไม่ต้องแก้เงื่อนไข
     * ในการบันทึก · สิ่งเดียวที่ต้องทำเพิ่มคือใส่คำอธิบาย
     * "Leave blank to keep existing" กำกับช่องกรอกในไฟล์ template
     *
     * @var array
     */
    const HIDDEN_VALUES = [
        'facebook_appId',
        'google_client_id',
        'email_Username',
        'email_Password',
        'sms_password'
    ];

    /**
     * ซ่อนค่าใน HIDDEN_VALUES ก่อนส่งออกไปแสดงผล
     *
     * นอกจากล้างค่าทิ้งแล้วยังแนบคีย์ <ชื่อคีย์>_placeholder กลับไปด้วย
     * เพื่อให้ช่องกรอกแยกได้ว่า "ตั้งค่าไว้แล้วแต่ไม่แสดง" ต่างจาก "ยังไม่เคยตั้งค่า"
     * - ตั้งค่าไว้แล้ว = ได้ข้อความบอกว่าเว้นว่างไว้จะใช้ค่าเดิม
     * - ยังไม่เคยตั้ง  = ได้ null · data-attr ฝั่ง client จะถอด placeholder ทิ้ง
     *   ช่องกรอกจึงว่างเปล่าตามปกติ
     *
     * @param array $data
     *
     * @return array
     */
    private static function hideValues(array $data)
    {
        foreach (self::HIDDEN_VALUES as $key) {
            if (array_key_exists($key, $data)) {
                $configured = trim((string) $data[$key]) !== '';
                $data[$key] = '';
                // <key>_configured → data-if ของ checkbox "<key>_clear" ในเทมเพลต
                // <key>_placeholder → data-attr="placeholder:<key>_placeholder" ของช่องกรอก
                $data[$key.'_configured'] = $configured;
                $data[$key.'_placeholder'] = $configured ? Language::get('A value is saved — leave blank to keep it') : null;
            }
        }

        return $data;
    }

    /**
     * ล้างค่าที่ซ่อนไว้ตามที่ผู้ใช้ติ๊ก checkbox <key>_clear
     *
     * ช่องกรอกของ HIDDEN_VALUES ส่งค่าว่างมาเสมอถ้าไม่ได้แตะ จึงใช้ค่าว่างแทน
     * "ล้างค่า" ไม่ได้ · เทมเพลตจะแสดง checkbox <key>_clear เฉพาะเมื่อ
     * <key>_configured เป็นจริง และเรียกหลัง parseXxxSettings() เพื่อให้ชนะค่าใหม่
     *
     * @param array  $body
     * @param object $config
     */
    private static function clearHiddenValues($body, $config)
    {
        foreach (self::HIDDEN_VALUES as $key) {
            if (!empty($body[$key.'_clear'])) {
                $config->$key = '';
            }
        }
    }

    /**
     * ผู้ใช้กรอกค่าใหม่สำหรับคีย์นี้เข้ามาหรือไม่
     *
     * คีย์ที่ไม่ได้อยู่ใน HIDDEN_VALUES จะบันทึกตามปกติเสมอ (ค่าว่างคือการล้างค่า)
     * ส่วนคีย์ที่ซ่อนไว้ ค่าว่างแปลว่าผู้ใช้ไม่ได้แตะช่องนั้น ต้องคงค่าเดิมไว้
     * ไม่งั้นแค่เปิดหน้าตั้งค่าแล้วกดบันทึกก็ล้างค่าที่ซ่อนอยู่ทิ้งทั้งหมด
     *
     * @param array  $body
     * @param string $key
     *
     * @return bool
     */
    private static function hasNewValue($body, $key)
    {
        if (!isset($body[$key])) {
            return false;
        }

        if (!in_array($key, self::HIDDEN_VALUES, true)) {
            return true;
        }

        return trim((string) $body[$key]) !== '';
    }

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
            'data' => (object) self::hideValues($data)
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
            // ไซต์ที่ติดตั้งใหม่ settings/config.php ยังไม่มีคีย์นี้ (แบบเดียวกับ auth.php · system.php)
            'activate_user' => !empty(self::$cfg->activate_user),
            'facebook_appId' => self::$cfg->facebook_appId,
            'google_client_id' => self::$cfg->google_client_id,
            'demo_mode' => self::$cfg->demo_mode,
            'cache_expire' => self::$cfg->cache_expire,
            'default_department' => self::$cfg->default_department,
            'max_login_attempts' => self::$cfg->max_login_attempts,
            'lockout_duration' => self::$cfg->lockout_duration,
            'dashboard_guest' => !empty(self::$cfg->dashboard_guest)
        ];

        // Logo image
        $result['logo'] = self::imageField('logo', 'logo');

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
            'email_SMTPSecure' => self::$cfg->email_SMTPSecure,
            // สองคีย์นี้อยู่ใน HIDDEN_VALUES · hideValues() จะล้างค่าทิ้งก่อนส่งออกเสมอ
            // ที่ต้องใส่มาเพราะ hideValues() ใช้ค่าจริงตัดสินว่าจะแสดง placeholder หรือไม่
            'email_Username' => self::$cfg->email_Username ?? '',
            'email_Password' => self::$cfg->email_Password ?? ''
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
            'sms_type' => self::$cfg->sms_type ?? '',
            // อยู่ใน HIDDEN_VALUES · hideValues() ล้างค่าทิ้งก่อนส่งออก เหลือแค่ _configured
            'sms_password' => self::$cfg->sms_password ?? ''
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
        // ไซต์ที่ติดตั้งใหม่ยังไม่มีคีย์ theme (แบบเดียวกับ config.php)
        foreach (self::$cfg->theme ?? [] as $key => $value) {
            $key = str_replace('-', '', ucwords(trim($key, '-'), '-'));
            $result[$key] = $value;
        }
        // Body background image
        $result['bg_image'] = self::imageField('bg_image', 'bg_image');

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
        // ผู้มีอำนาจลงนามเป็นสมาชิกในระบบ ไซต์ที่ยังมีแต่ชื่อแบบเดิมจะได้คนที่ชื่อตรงกันมาให้
        // ชื่อและลายเซ็นบนเอกสารมาจากโปรไฟล์ของสมาชิกคนนั้น (Index\Company\Model)
        $authorizedId = \Index\Company\Model::authorizedId();

        $result = [
            'company_name' => $company['name'] ?? '',
            'company_name_en' => $company['name_en'] ?? '',
            'company_address' => $company['address'] ?? '',
            'company_phone' => $company['phone'] ?? '',
            'company_fax' => $company['fax'] ?? '',
            'company_email' => $company['email'] ?? '',
            'company_tax_id' => $company['tax_id'] ?? '',
            'promptpay_id' => $company['promptpay_id'] ?? '',
            // ใช้บนเอกสารที่พิมพ์ออก (ใบแจ้งหนี้ ใบเสร็จ ซองจดหมาย)
            'company_branch' => $company['branch'] ?? '',
            'company_zipcode' => $company['zipcode'] ?? '',
            'company_authorized_id' => $authorizedId > 0 ? (string) $authorizedId : '',
            'company_bank' => $company['bank'] ?? '',
            'company_bank_name' => $company['bank_name'] ?? '',
            'company_bank_no' => $company['bank_no'] ?? ''
        ];

        // Company logo
        $result['company_logo'] = self::imageField('company_logo', 'Company logo');

        // Company stamp
        $result['company_stamp'] = self::imageField('company_stamp', 'Company stamp');

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
        // หน้าตั้งค่าอีเมลถูกซ่อนจากเมนูของบัญชีตัวอย่าง (ดู Index\Menus\Controller)
        // route ฝั่ง client เป็น requireAuth อย่างเดียว เปิด URL ตรงยังเข้าถึงได้
        // จึงต้องกันที่ endpoint ด้วย ไม่งั้นค่า SMTP ของเจ้าของเว็บรั่วออกไป
        $login = $this->authenticateRequest($request);
        if ($login && !ApiController::isNotDemoMode($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

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
        $data = $this->getCompanyData();

        return $this->getSettingsResponse($request, $data, [
            'company_authorized_id' => \Index\Company\Model::authorizedOptions((int) $data['company_authorized_id'])
        ], 'Company');
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
            $permission = $moduleKey === 'ai-chat' ? ['can_use_ai_chat'] : ['can_config'];
            if (!ApiController::canModify($login, $permission)) {
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

            // "<key>_clear" checkboxes (see clearHiddenValues)
            self::clearHiddenValues($body, $config);

            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                // Log
                \Index\Log\Model::add(0, 'index', 'Save', 'Save '.str_replace('-', ' ', $module).' Settings', $login->id);

                // Reload page
                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }
        } catch (\Kotchasan\ApiException $e) {
            // Keep original HTTP code (e.g. 403 CSRF, 405 method)
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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

        $boolKeys = ['activate_user', 'cookie_policy', 'demo_mode', 'new_members_active', 'user_forgot', 'user_register', 'dashboard_guest'];
        foreach ($boolKeys as $key) {
            if (isset($body[$key])) {
                $config->$key = $this->toBoolean($body, $key);
            }
        }

        $textKeys = ['cache_expire', 'max_login_attempts', 'lockout_duration'];
        foreach ($textKeys as $key) {
            if (isset($body[$key])) {
                $config->$key = intval($body[$key]);
            }
        }

        $textKeys = ['timezone', 'default_department'];
        foreach ($textKeys as $key) {
            if (self::hasNewValue($body, $key)) {
                $config->$key = Text::topic($body[$key]);
            }
        }

        // Facebook App ID is numeric only; a pasted page URL must not be kept
        if (self::hasNewValue($body, 'facebook_appId')) {
            $config->facebook_appId = Text::number($body['facebook_appId']);
        }

        if (self::hasNewValue($body, 'google_client_id')) {
            $parts = explode('.', $body['google_client_id']);
            $config->google_client_id = !empty($parts) ? $parts[0] : '';
        }

        // New file version for ?v= in index.php so browsers drop cached CSS/JS
        $config->reversion = time();

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
            // '' (STARTTLS อัตโนมัติถ้าเซิร์ฟเวอร์รองรับ) · tls = STARTTLS บังคับ · ssl = SSL/TLS ตั้งแต่เริ่ม
            $config->email_SMTPSecure = \Kotchasan\Email::smtpSecure($body['email_SMTPSecure'] ?? '');
            if (self::hasNewValue($body, 'email_Username')) {
                $config->email_Username = Text::username($body['email_Username']);
            }
            if (self::hasNewValue($body, 'email_Password')) {
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
        if (self::hasNewValue($body, 'sms_password')) {
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
        // allowno ไม่มีที่ใดใช้ (ไม่มีตัวแทนค่าบนเอกสาร) ตัดออกจากค่าที่บันทึกไว้ด้วย
        unset($config->company['allowno']);
        $config->company['promptpay_id'] = Text::number($body['company_promptpay_id'] ?? '');
        // ข้อมูลที่ใช้บนเอกสารที่พิมพ์ออก
        $config->company['branch'] = Text::topic($body['company_branch'] ?? '');
        $config->company['zipcode'] = Text::number($body['company_zipcode'] ?? '');
        // ผู้มีอำนาจลงนาม = สมาชิกในระบบ ชื่อที่พิมพ์เองแบบเดิมเลิกใช้แล้ว
        $authorizedId = (int) ($body['company_authorized_id'] ?? 0);
        if ($authorizedId > 0 && !\Kotchasan\DB::create()->first('user', [['id', $authorizedId]])) {
            $authorizedId = 0;
        }
        $config->company['authorized_id'] = $authorizedId;
        unset($config->company['authorized']);
        \Index\Company\Model::adoptLegacySignature($authorizedId);
        $config->company['bank'] = Text::topic($body['company_bank'] ?? '');
        $config->company['bank_name'] = Text::topic($body['company_bank_name'] ?? '');
        $config->company['bank_no'] = Text::topic($body['company_bank_no'] ?? '');

        // Keep existing logo/stamp paths if they exist
        $logoPath = self::storedImage('company/logo');
        $stampPath = self::storedImage('company/stamp');

        if ($logoPath !== null) {
            $config->company['logo'] = DATA_FOLDER.$logoPath;
        }
        if ($stampPath !== null) {
            $config->company['stamp'] = DATA_FOLDER.$stampPath;
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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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
        $settings = new \Gcms\Ai\SettingsRepository();
        $connector = $settings->connector();
        $activeProvider = $connector['ai_provider'] ?? (self::$cfg->ai_provider ?? 'openai');
        $editProvider = $activeProvider;
        $connections = \Gcms\Ai::connectionSettings();
        $connection = !empty($connections[$editProvider]) ? $connections[$editProvider] : [];

        // Never send stored API keys to the browser — expose only whether a key
        // is set per provider (has_api_key). The form field is for entering a NEW
        // key; a blank submit keeps the stored key (see buildAiConnection()).
        $safeConnections = [];
        foreach ($connections as $providerName => $providerConnection) {
            if (is_array($providerConnection)) {
                $providerConnection['has_api_key'] = !empty($providerConnection['api_key']);
                unset($providerConnection['api_key']);
            }
            $safeConnections[$providerName] = $providerConnection;
        }

        return [
            'ai_enabled' => $connector['ai_enabled'] ?? (self::$cfg->ai_enabled ?? 0),
            'ai_provider' => $activeProvider,
            'ai_edit_provider' => $editProvider,
            'ai_api_url' => $connection['api_url'] ?? '',
            'ai_model' => $connection['model_option'] ?? '',
            'ai_custom_model' => $connection['custom_model'] ?? '',
            'ai_max_tokens' => $connection['max_tokens'] ?? 1024,
            'ai_temperature' => $connection['temperature'] ?? 0.7,
            'ai_thinking_enabled' => !empty($connection['thinking_enabled']) ? 1 : 0,
            'ai_reasoning_effort' => $connection['reasoning_effort'] ?? 'high',
            'ai_chat_workflow_value' => max(1, min(10080, (int) (self::$cfg->ai_chat_workflow_value ?? 60))),
            'ai_connections' => $safeConnections,
            'ai_provider_defaults' => \Gcms\Ai::providerDefaults()
        ];
    }

    /**
     * Get AI chat content management data.
     *
     * @return array
     */
    private function getAiChatData()
    {
        $settings = new \Gcms\Ai\SettingsRepository();
        $messages = $settings->messages();
        $workflow = $settings->workflow();

        return [
            'ai_message_templates' => $this->aiMessageTemplates($messages),
            'ai_workflow_settings' => $workflow['sla_minutes'],
            'ai_quick_answers' => (new \Gcms\Chat\QuickAnswerRepository())->all(false)
        ];
    }

    /**
     * AI chat content management endpoint (GET).
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function aiChat(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::canModify($login, ['can_use_ai_chat'])) {
            return $this->errorResponse('Forbidden', 403);
        }

        return $this->successResponse([
            'data' => (object) $this->getAiChatData()
        ], 'AI chat content loaded');
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
        $config->ai_chat_workflow_value = max(1, min(10080, (int) ($body['ai_chat_workflow_value'] ?? $body['value'] ?? 60)));
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
     * Save AI chat content settings.
     *
     * @param array  $body
     * @param object $config
     *
     * @return array
     */
    private function parseAiChatSettings($body, $config)
    {
        $ret = [];
        $messages = $this->parseAiMessageTemplates($body['ai_message_templates_json'] ?? '[]', $ret);
        $workflow = $this->parseAiWorkflowSettings($body['ai_workflow_json'] ?? '[]', $ret);
        $quickAnswers = $this->parseAiQuickAnswers($body['ai_quick_answers_json'] ?? '[]', $ret);
        if (!empty($ret)) {
            return $ret;
        }

        $settings = new \Gcms\Ai\SettingsRepository();
        $saved = $settings->saveMessages($messages)
        && $settings->saveWorkflow($workflow)
        && (new \Gcms\Chat\QuickAnswerRepository())->saveMany($quickAnswers);
        if (!$saved) {
            $ret['ai_message_templates_json'] = 'Unable to persist AI chat content';
        }

        return $ret;
    }

    /**
     * Parse message-template rows from admin form input.
     *
     * @param mixed $json
     * @param array $errors
     *
     * @return array
     */
    private function parseAiMessageTemplates($json, array &$errors)
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            if (trim((string) $json) !== '') {
                $errors['ai_message_templates_json'] = 'Invalid message-template data';
            }

            return [];
        }

        $allowedKeys = array_column($this->aiMessageTemplates([]), 'key');
        $allowedKeys = array_flip($allowedKeys);
        $messages = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '' || !isset($allowedKeys[$key])) {
                continue;
            }
            $messages[$key] = trim(str_replace(["\r\n", "\r"], "\n", (string) ($item['value'] ?? '')));
        }

        return $messages;
    }

    /**
     * Parse workflow rows from admin form input.
     *
     * @param mixed $json
     * @param array $errors
     *
     * @return array
     */
    private function parseAiWorkflowSettings($json, array &$errors)
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            if (trim((string) $json) !== '') {
                $errors['ai_workflow_json'] = 'Invalid workflow data';
            }

            return ['sla_minutes' => 60];
        }

        $workflow = ['sla_minutes' => 60];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key !== 'sla_minutes') {
                continue;
            }
            $workflow['sla_minutes'] = max(1, min(10080, (int) ($item['value'] ?? 60)));
        }

        return $workflow;
    }

    /**
     * Normalize AI chat message-template rows for admin UI.
     *
     * @param array $messages
     *
     * @return array
     */
    private function aiMessageTemplates(array $messages)
    {
        $definitions = [
            ['key' => 'starter_message', 'label' => 'Starter Message', 'description' => 'Initial message shown in the web chat widget before the first user message.'],
            ['key' => 'welcome_message', 'label' => 'Greeting Reply', 'description' => 'Reply used when the user starts with a greeting.'],
            ['key' => 'capability_message', 'label' => 'Capabilities Reply', 'description' => 'Reply used when the user asks what the AI chat can do.'],
            ['key' => 'escalation_created_message', 'label' => 'Handoff Created Reply', 'description' => 'Reply sent after a staff handoff is created. Supports placeholders :id, :channel, :requester, :message.'],
            ['key' => 'handoff_accepted_message', 'label' => 'Handoff Accepted Reply', 'description' => 'Reply sent to the requester when staff accepts a handoff. Supports placeholders :id, :requester.'],
            ['key' => 'handoff_closed_message', 'label' => 'Handoff Closed Reply', 'description' => 'Reply sent to the requester when staff closes a handoff.'],
            ['key' => 'fallback_help_message', 'label' => 'Fallback Help Reply', 'description' => 'Shown when AI fallback cannot answer or when a tool is not connected.'],
            ['key' => 'ai_disabled_message', 'label' => 'AI Disabled Reply', 'description' => 'Shown when the AI connector is disabled.'],
            ['key' => 'ai_unavailable_message', 'label' => 'AI Unavailable Reply', 'description' => 'Shown when the AI provider request fails.'],
            ['key' => 'ai_empty_response_message', 'label' => 'AI Empty Response Reply', 'description' => 'Shown when the AI provider returns no usable answer.']
        ];

        foreach ($definitions as $index => $item) {
            $definitions[$index]['value'] = (string) ($messages[$item['key']] ?? '');
        }

        return $definitions;
    }

    /**
     * Normalize AI workflow rows for admin UI.
     *
     * @param array $workflow
     *
     * @return array
     */
    private function aiWorkflowSettings(array $workflow)
    {
        return [
            [
                'key' => 'sla_minutes',
                'label' => 'Handoff SLA Minutes',
                'description' => 'Open handoffs older than this limit are marked as overdue in the inbox.',
                'value' => max(1, min(10080, (int) ($workflow['sla_minutes'] ?? 60)))
            ]
        ];
    }

    /**
     * Parse quick-answer rows from the admin form.
     *
     * @param mixed $json
     * @param array $errors
     *
     * @return array
     */
    private function parseAiQuickAnswers($json, array &$errors)
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            if (trim((string) $json) !== '') {
                $errors['ai_quick_answers_json'] = 'Invalid quick-answer data';
            }

            return [];
        }

        $items = [];
        foreach ($decoded as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $keywords = trim((string) ($item['keywords'] ?? ''));
            $answerText = trim(str_replace(["\r\n", "\r"], "\n", (string) ($item['answer_text'] ?? '')));
            if ($title === '' && $keywords === '' && $answerText === '') {
                continue;
            }
            if ($keywords === '' || $answerText === '') {
                $errors['ai_quick_answers_json'] = 'Each quick answer requires keywords and an answer';

                return [];
            }

            $items[] = [
                'title' => $title,
                'keywords' => $keywords,
                'match_mode' => !empty($item['match_mode']) && $item['match_mode'] === 'exact' ? 'exact' : 'contains',
                'answer_text' => $answerText,
                'sort_order' => isset($item['sort_order']) && $item['sort_order'] !== '' ? (int) $item['sort_order'] : $index + 1,
                'published' => !empty($item['published']) ? 1 : 0
            ];
        }

        return $items;
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
            if ($provider === 'deepseek') {
                $config['thinking_enabled'] = !empty($connection['thinking_enabled']);
                $config['reasoning_effort'] = $connection['reasoning_effort'] ?? 'high';
            }

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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
        } catch (\Exception $e) {
            return $this->errorResponse('AI test failed: '.$e->getMessage(), 500, $e);
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
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
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

        $resolved = null;
        if ($provider === 'deepseek' && empty($errors)) {
            $candidate = $useCustomModel ? $customModel : $model;
            $thinkingHint = isset($body['ai_thinking_enabled']) ? $this->toBoolean($body, 'ai_thinking_enabled') : 0;
            $resolved = \Gcms\Ai\Drivers\DeepSeek::resolveModel($candidate, $thinkingHint);
            if (in_array($resolved['model'], $models, true)) {
                $model = $resolved['model'];
                $customModel = '';
                $useCustomModel = 0;
            } elseif ($useCustomModel) {
                $customModel = $resolved['model'];
            } else {
                $model = $resolved['model'];
            }
        }

        $apiUrl = trim((string) ($body['ai_api_url'] ?? ''));
        $apiUrl = $apiUrl !== '' ? \Kotchasan\Text::url($apiUrl) : '';
        if (!empty($defaults['default_api_url']) && $apiUrl === $defaults['default_api_url']) {
            $apiUrl = '';
        }

        // A blank field means "keep the stored key" — the saved key is never sent
        // to the browser, so it can only be changed by typing a new value. This
        // keeps both Save and the AI test working without exposing the secret.
        $apiKey = \Kotchasan\Text::topic($body['ai_api_key'] ?? '');
        if ($apiKey === '') {
            $storedConnections = \Gcms\Ai::connectionSettings();
            $apiKey = isset($storedConnections[$provider]['api_key']) ? (string) $storedConnections[$provider]['api_key'] : '';
        }

        return [
            'api_key' => $apiKey,
            'api_url' => $apiUrl,
            'model' => $model,
            'custom_model' => $customModel,
            'use_custom_model' => $useCustomModel,
            'max_tokens' => max(1, (int) ($body['ai_max_tokens'] ?? 1024)),
            'temperature' => min(2.0, max(0.0, (float) ($body['ai_temperature'] ?? 0.7))),
            'thinking_enabled' => $provider === 'deepseek'
                ? (($this->toBoolean($body, 'ai_thinking_enabled') || ($resolved && !empty($resolved['thinking']))) ? 1 : 0)
                : 0,
            'reasoning_effort' => $provider === 'deepseek'
                ? \Gcms\Ai\Drivers\DeepSeek::normalizeEffort($body['ai_reasoning_effort'] ?? 'high')
                : 'high'
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
            if (in_array($item, ['logo', 'bg_image', 'company_logo', 'company_stamp', 'site_logo', 'pwa_icon', 'screenshot'])) {
                if (!File::makeDirectory($dir)) {
                    // The directory cannot be created.
                    $errors[$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'images/');
                } elseif ($file->hasUploadFile()) {
                    try {
                        $file->resizeImage(self::$cfg->img_typies, $dir, $item.self::$cfg->stored_img_type, self::$cfg->stored_img_size);
                        // รูปเดิมที่เป็นนามสกุลอื่น (บันทึกก่อนเปลี่ยน stored_img_type) ไม่ใช้แล้ว
                        // ลบทิ้ง ไม่งั้นลบรูปใหม่ทีหลังแล้วรูปเก่าจะโผล่กลับมาแทน
                        self::removeStoredImage('images/'.$item, true);
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
     * ค่าของช่องอัปโหลดรูปในหน้าตั้งค่า (รูปที่มีอยู่ หรือรูปว่างให้เลือกไฟล์)
     *
     * @param string $item ชื่อไฟล์ใน DATA_FOLDER/images ไม่มีนามสกุล
     * @param string $name ชื่อที่แสดงใต้รูป
     *
     * @return array
     */
    private static function imageField($item, $name)
    {
        $file = self::storedImage('images/'.$item);
        if ($file === null) {
            return [
                [
                    'url' => WEB_URL.'images/no-image.webp',
                    'name' => 'Choose file'
                ]
            ];
        }

        return [
            [
                // เวลาแก้ไขไฟล์ต่อท้าย ให้รูปใหม่แสดงทันทีหลังอัปโหลดแทนรูปใน cache
                'url' => WEB_URL.DATA_FOLDER.$file.'?'.filemtime(ROOT_PATH.DATA_FOLDER.$file),
                'name' => $name
            ]
        ];
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
            $allowedItems = ['logo', 'bg_image', 'company_logo', 'company_stamp', 'site_logo', 'pwa_icon', 'screenshot'];
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

            // ลบทุกนามสกุล ไฟล์ที่บันทึกก่อนเปลี่ยน stored_img_type ยังเป็นนามสกุลเก่า
            $removed = self::removeStoredImage('images/'.$item);
            if ($removed === false) {
                return $this->errorResponse('Failed to delete image', 500);
            }

            if ($removed > 0) {
                // Log the action
                \Index\Log\Model::add(0, 'index', 'Delete', 'Removed '.$item.' image', $login->id);

                return $this->redirectResponse('reload', ucfirst(str_replace('_', ' ', $item)).' removed successfully', 200, 1000);
            }

            // File doesn't exist, still success (idempotent)
            return $this->redirectResponse('reload', ucfirst(str_replace('_', ' ', $item)).' already removed', 200, 1000);
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to remove image: '.$e->getMessage(), 500, $e);
        }
    }
}
