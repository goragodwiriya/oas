<?php
/**
 * @filesource Gcms/Config.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * Config Class สำหรับ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Config extends \Kotchasan\Config
{
    /**
     * กำหนดอายุของแคช (วินาที)
     * 0 หมายถึงไม่มีการใช้งานแคช
     *
     * @var int
     */
    public $cache_expire = 5;
    /**
     * สีของสมาชิกตามสถานะ
     *
     * @var array
     */
    public $color_status = [
        0 => '#259B24',
        1 => '#FF0000',
        2 => '#0000FF'
    ];
    /**
     * ถ้ากำหนดเป็น true บัญชีที่เข้าระบบด้วยโซเชียลจะเป็นบัญชีตัวอย่าง
     * ได้รับสถานะแอดมิน (สมาชิกใหม่) แต่อ่านได้อย่างเดียว
     * และสามารถสมัคร/เข้าระบบด้วยโซเชียลได้ถึงแม้จะปิด user_register ไว้
     *
     * @var bool
     */
    public $demo_mode = false;
    /**
     * App ID สำหรับการเข้าระบบด้วย Facebook https://gcms.in.th/howto/การขอ_app_id_จาก_facebook.html
     *
     * @var string
     */
    public $facebook_appId = '';
    /**
     * Client ID สำหรับการเข้าระบบโดย Google
     *
     * @var string
     */
    public $google_client_id = '';
    /**
     * รายชื่อฟิลด์จากตารางสมาชิก สำหรับตรวจสอบการ login
     *
     * @var array
     */
    public $login_fields = ['username'];
    /**
     * สถานะสมาชิก
     * 0 สมาชิกทั่วไป
     * 1 ผู้ดูแลระบบ
     * 2 เจ้าหน้าที่
     *
     * @var array
     */
    public $member_status = [
        0 => 'สมาชิก',
        1 => 'ผู้ดูแลระบบ',
        2 => 'เจ้าหน้าที่'
    ];
    /**
     * คีย์สำหรับการเข้ารหัส ควรแก้ไขให้เป็นรหัสของตัวเอง
     * ตัวเลขหรือภาษาอังกฤษเท่านั้น ไม่น้อยกว่า 10 ตัว
     *
     * @var string
     */
    public $password_key = '1234567890';
    /**
     * สามารถขอรหัสผ่านในหน้าเข้าระบบได้
     *
     * @var bool
     */
    public $user_forgot = true;
    /**
     * บุคคลทั่วไป สามารถสมัครสมาชิกได้
     *
     * @var bool
     */
    public $user_register = true;
    /**
     * ตั้งค่าการเข้าระบบของสมาชิกใหม่
     * 1 สมัครสมาชิกแล้วเข้าระบบได้ทันที (ค่าเริ่มต้น)
     * 0 สมัครสมาชิกแล้วยังไม่สามารถเข้าระบบได้ ต้องรอแอดมินอนุมัติ
     *
     * @var int
     */
    public $new_members_active = 1;
    /**
     * ส่งอีเมลต้อนรับ เมื่อบุคคลทั่วไปสมัครสมาชิก
     *
     * @var bool
     */
    public $welcome_email = true;
    /**
     * ข้อความแสดงในหน้า login
     *
     * @var string
     */
    public $login_message = '';
    /**
     * ชื่อคลาสของข้อความแสดงในหน้า login warning,tip,message
     *
     * @var string
     */
    public $login_message_style = 'hidden';
    /**
     * Channel ID
     * จาก Line Login
     *
     * @var string
     */
    public $line_channel_id = '';
    /**
     * Channel secret
     * จาก Line Login
     *
     * @var string
     */
    public $line_channel_secret = '';
    /**
     * Bot basic ID
     * จาก Messaging API
     *
     * @var string
     */
    public $line_official_account = '';
    /**
     * Channel access token (long-lived)
     * จาก Messaging API
     *
     * @var string
     */
    public $line_channel_access_token = '';
    /**
     * Bot Username
     * Bot Username จาก Telegram
     *
     * @var string
     */
    public $telegram_bot_username = '';
    /**
     * Chat ID
     * Bot Chat ID จาก Telegram
     *
     * @var string
     */
    public $telegram_chat_id = '';
    /**
     * Bot token
     * API Token จาก Telegram
     *
     * @var string
     */
    public $telegram_bot_token = '';
    /**
     * Telegram webhook secret token
     * ใช้ตรวจสอบ header ของ Telegram webhook
     *
     * @var string
     */
    public $telegram_webhook_secret = '';
    /**
     * Guest can view dashboard without logging in (default false).
     *
     * @var bool
     */
    public $dashboard_guest = false;
    /**
     * รายการหมวดหมู่ของสมาชิก ที่ต้องระบุ
     *
     * @var array
     */
    public $categories_required = [];
    /**
     * รายการหมวดหมู่ที่สมาชิกไม่สามารถแก้ไขได้
     *
     * @var array
     */
    public $categories_disabled = [];
    /**
     * รายการหมวดหมู่สมาชิกที่สามารถมีได้หลายรายการ
     *
     * @var array
     */
    public $categories_multiple = [];
    /**
     * แผนกเริ่มต้นสำหรับสมาชิกใหม่ ใช้ในกรณีที่สมาชิกจำเป็นต้องระบุแผนก
     *
     * @var string
     */
    public $default_department = '';
    /**
     * รายการรูปภาพอัปโหลดของสมาชิก และ ชื่อ
     *
     * @var array
     */
    public $member_images = [
        'avatar' => '{LNG_Avatar}',
        // ลายเซ็นของสมาชิก (หน้าข้อมูลส่วนตัว) — ผู้มีอำนาจลงนามของบริษัทใช้รูปนี้บนเอกสารที่พิมพ์
        'signature' => '{LNG_Signature}'
    ];
    /**
     * ชนิดของไฟล์รูปภาพของสมาชิกที่รองรับ
     *
     * @var array
     */
    public $member_img_typies = ['jpg', 'jpeg', 'png', 'webp'];
    /**
     * ขนาดรูปภาพสมาชิกที่จัดเก็บ (พิกเซล)
     *
     * @var int
     */
    public $member_img_size = 250;
    /**
     * ชนิดของไฟล์รูปภาพที่รองรับ (ค่าเรี่มต้น)
     *
     * @var array
     */
    public $img_typies = ['jpg', 'jpeg', 'png', 'webp'];
    /**
     * ขนาดรูปภาพที่จัดเก็บ (พิกเซล)
     * สำหรับรูปภาพทั่วไป
     *
     * @var int
     */
    public $stored_img_size = 800;
    /**
     * ชนิดของไฟล์รูปภาที่จัดเก็บ
     * ต้องมี . ด้านหน้าด้วย
     *
     * @var array
     */
    public $stored_img_type = '.webp';
    /**
     * เวลาหมดอายุของ Token ในกระบวนการ login (วินาที)
     * 0 = ตรวจสอบกับฐานข้อมูลเสมอ
     * 3600 = 1 ชม.
     *
     * @var int
     */
    public $token_login_expire_time = 3600;
    /**
     * กำหนดเวลาในการขอ OTP ครั้งต่อไป เป็นวินาที
     *
     * @var int
     */
    public $otp_request_timeout = 300;
    /**
     * JWT secret used for signing access tokens. Set a long random value in production.
     * If empty, JWT will not be issued by the login API.
     *
     * @var string
     */
    public $jwt_secret = '';
    /**
     * JWT access token lifetime in seconds (default 15 minutes).
     *
     * @var int
     */
    public $jwt_ttl = 900;
    /**
     * Whether to set access_token as HttpOnly secure cookie on login (default true).
     *
     * @var bool
     */
    public $jwt_cookie = true;
    /**
     * Refresh token lifetime in seconds (used for documentation purposes).
     * Refresh token persistence and rotation handled by user->token field.
     *
     * @var int
     */
    public $refresh_ttl = 604800; // 7 days
    /**
     * API token for authentication.
     *
     * @var array
     */
    public $api_tokens = [];
    /**
     * API secret for signature validation.
     *
     * @var string
     */
    public $api_secret = '';
    /**
     * Allowed IP addresses for API access.
     *
     * @var array
     */
    public $api_ips = ['0.0.0.0'];
    /**
     * CORS origin setting for API.
     *
     * @var string
     */
    public $api_cors = '';

    /**
     * Timeline Provider — ชื่อไม่ซ้ำของระบบนี้ในสายตา Hub
     *
     * ต้องตรงกับ slug ที่ตั้งไว้ฝั่ง Hub · ค่าว่าง = ยังไม่เปิดใช้ provider
     *
     * @see TIMELINE-PROTOCOL.md
     *
     * @var string
     */
    public $timeline_slug = '';

    /**
     * Timeline Provider — ชื่อที่แสดงบนหน้าจอ Hub (ว่างไว้จะใช้ web_title)
     *
     * @var string
     */
    public $timeline_name = '';

    /**
     * Timeline Provider — คลาสที่แปลงข้อมูลของแต่ละโมดูลเป็น timeline item
     *
     * ต้อง implement \Gcms\Timeline\MapperInterface และควรอยู่ในโมดูลของตัวเอง
     * (เช่น \Ar\Timeline\Model จาก modules/ar/models/timeline.php) ไม่ใช่ใน
     * modules/timeline ซึ่งเป็นโค้ดกลางที่ก๊อปทับจาก adminframework ได้ตรง ๆ
     *
     * @var array
     */
    public $timeline_mappers = [];
    /**
     * กำหนดค่าคีย์ของ Login session ระบุให้แตกต่างกันในแต่ละแอพพลิเคชั่น หากต้องการให้แยกจากกัน
     * ค่าเริ่มต้นคือ 'login'
     * @var string
     */
    public $session_key = '';
    /**
     * หน่วยสกุลเงิน
     *
     * @var string
     */
    public $currency_unit = 'THB';
    /**
     * Default max attempts before lockout
     */
    public $max_login_attempts = 5;
    /**
     * Default lockout duration in minutes
     */
    public $lockout_duration = 30;

    // -------------------------------------------------------------------------
    // AI connector settings
    // -------------------------------------------------------------------------

    /**
     * Enable or disable the AI connector globally.
     *
     * @var int
     */
    public $ai_enabled = 0;

    /**
     * AI provider to use by default.
     * Supported: openai, groq, deepseek, openrouter, ollama, lmstudio, gemini, claude
     *
     * @var string
     */
    public $ai_provider = 'openai';

    /**
     * API key for the selected AI provider.
     * Legacy cache for the active provider.
     * Provider-specific values are stored in ai_connections.
     *
     * @var string
     */
    public $ai_api_key = '';

    /**
     * Override the provider's default API endpoint URL.
     * Legacy cache for the active provider.
     * Provider-specific values are stored in ai_connections.
     *
     * @var string
     */
    public $ai_api_url = '';

    /**
     * Default model identifier sent to the AI provider.
     * Legacy cache for the active provider.
     * Provider-specific values are stored in ai_connections.
     *
     * @var string
     */
    public $ai_model = '';

    /**
     * Provider-specific AI settings keyed by provider name.
     *
     * Example:
     * [
     *   'openai' => ['api_key' => '...', 'model' => 'gpt-4o-mini'],
     *   'gemini' => ['api_key' => '...', 'model' => 'gemini-2.0-flash'],
     * ]
     *
     * @var array
     */
    public $ai_connections = [];

    /**
     * Provider-specific default models used by the admin settings page and
     * as runtime fallbacks when ai_model is empty.
     *
     * @var array
     */
    public $ai_default_models = [
        'openai' => 'gpt-4o-mini',
        'gemini' => 'gemini-2.0-flash',
        'claude' => 'claude-haiku-3-5',
        'groq' => 'llama-3.3-70b-versatile',
        'deepseek' => 'deepseek-v4-flash',
        'openrouter' => 'openrouter/auto',
        'ollama' => 'llama3.2',
        'lmstudio' => 'llama3.2'
    ];

    /**
     * Provider-specific default API URLs used by the admin settings page and
     * as runtime fallbacks when ai_api_url is empty.
     *
     * @var array
     */
    public $ai_default_api_urls = [
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'claude' => 'https://api.anthropic.com/v1/messages',
        'groq' => 'https://api.groq.com/openai/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'ollama' => 'http://localhost:11434/v1',
        'lmstudio' => 'http://localhost:1234/v1'
    ];

    /**
     * Maximum number of tokens to generate per response.
     *
     * @var int
     */
    public $ai_max_tokens = 1024;
    /**
     * Sampling temperature (0.0 – 2.0).
     * Lower values produce more deterministic output.
     *
     * @var float
     */
    public $ai_temperature = 0.7;

    // =========================================================================
    // ค่ากำหนดของโมดูล inventory (e-OMS)
    // ชื่อและค่าเริ่มต้นตรงกับระบบเดิม เพื่อให้ไซต์ที่ย้ายมาได้พฤติกรรมเท่าเดิม
    // แม้ยังไม่ได้บันทึกหน้าตั้งค่าใหม่
    // =========================================================================

    /**
     * อัตราภาษีมูลค่าเพิ่ม (%)
     *
     * @var float
     */
    public $vat = 7;
    /**
     * รูปแบบรหัสลูกค้า
     *
     * @var string
     */
    public $customer_no = 'CU%04d';
    /**
     * สถานะเอกสารที่นับเป็นการรับเข้าสต๊อก
     * ใช้เป็นค่าสำรองเมื่อยังไม่มีแม่แบบเอกสารในฐานข้อมูล
     *
     * @var array
     */
    public $in_stock_status = ['IN', 'RET'];
    /**
     * สถานะเอกสารที่นับเป็นการตัดออกจากสต๊อก
     *
     * @var array
     */
    public $out_stock_status = ['OUT'];
    /**
     * สถานะเอกสารฝั่งซื้อ
     *
     * @var array
     */
    public $buy_status = ['PO', 'RET', 'IN'];
    /**
     * สถานะเอกสารฝั่งขาย
     *
     * @var array
     */
    public $sell_status = ['OUT', 'QUO', 'INV', 'TAX', 'DEP', 'DES'];
}
