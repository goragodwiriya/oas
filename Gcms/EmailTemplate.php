<?php
/**
 * @filesource Gcms/EmailTemplate.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

use Kotchasan\Language;

/**
 * Email Template Service
 *
 * Provides centralized email template management with:
 * - Variable substitution ({VAR_NAME})
 * - Consistent base layout
 * - Future database support ready
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class EmailTemplate extends \Kotchasan\KBase
{
    /**
     * Runtime-registered templates (code => ['subject', 'detail']).
     * Used for dynamic one-off emails that are not stored in the database.
     *
     * @var array
     */
    private static $registered = [];

    /**
     * Register a dynamic template for this request.
     *
     * @param string $code     Template code
     * @param array  $template ['subject' => ..., 'body' => ...] (body may also be given as 'detail')
     */
    public static function register(string $code, array $template): void
    {
        self::$registered[$code] = [
            'subject' => (string) ($template['subject'] ?? ''),
            'detail' => (string) ($template['detail'] ?? $template['body'] ?? '')
        ];
    }

    /**
     * Get template by code
     *
     * Future: Override this method to fetch from database
     *
     * @param string $code Template code
     * @param string $lang Language code (for future i18n support)
     *
     * @return array|null Template data or null if not found
     */
    public static function get(string $code, string $lang = 'th'): ?array
    {
        if (isset(self::$registered[$code])) {
            return self::$registered[$code];
        }

        try {
            // first(true) — first() คืน object เป็นค่าปริยาย ซึ่งชนกับชนิด ?array
            // ของเมธอดนี้ (TypeError ทันทีที่มีแม่แบบในฐานข้อมูล)
            $template = \Kotchasan\Model::createQuery()
                ->select()
                ->from('emailtemplate')
                ->where([['code', $code], ['language', $lang]])
                ->cacheOn()
                ->first(true);
        } catch (\Kotchasan\Exception\DatabaseException $e) {
            // ฐานที่ไม่มีตาราง emailtemplate (หรือไม่มีคอลัมน์ code) — ถือว่าไม่พบแม่แบบ
            // send() จึงคืนข้อความแทนการ throw ตามที่ผู้เรียกคาดไว้
            $template = null;
        }

        return $template ? (array) $template : null;
    }

    /**
     * Render template with variable substitution
     *
     * @param string $template Template string
     * @param array  $variables Variables to substitute
     *
     * @return string Rendered template
     */
    public static function render(string $template, array $variables, bool $escapeHtml = true): string
    {
        // Replace %VAR_NAME% with values. By default the substituted value is
        // HTML-escaped so user-supplied variables cannot inject markup/script
        // into the email body (stored/reflected XSS). Pass $escapeHtml=false
        // only for non-HTML contexts (e.g. the subject line).
        foreach ($variables as $key => $value) {
            $value = is_scalar($value) ? (string) $value : '';
            if ($escapeHtml) {
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            $template = str_replace('%'.$key.'%', $value, $template);
        }

        return $template;
    }

    /**
     * Send email using template
     *
     * @param string $code      Template code
     * @param string $to        Recipient email
     * @param array  $variables Template variables
     * @param array  $options   buttonUrl, buttonLabel, headerTitle — ใช้ในแม่แบบได้เป็น
     *                          %BUTTON_URL% %BUTTON_LABEL% %HEADER_TITLE% (แม่แบบที่ไม่ได้วางไว้
     *                          ได้หัวเรื่อง/ปุ่มต่อรอบเนื้อหาให้)
     *
     * @return bool|string True on success, error message on failure
     */
    public static function send(string $code, string $to, array $variables, array $options = [])
    {
        // ภาษาของผู้ใช้ก่อน ไม่มีค่อยใช้ภาษาไทย
        $template = self::get($code, Language::name()) ?? self::get($code);

        if (!$template) {
            return 'Email template not found: '.$code;
        }

        // Add default variables
        $variables['WEBTITLE'] = $variables['WEBTITLE'] ?? self::$cfg->web_title ?? 'Website';
        $variables['WEBURL'] = WEB_URL;
        $variables['TIME'] = date('Y-m-d H:i');
        // ลิงก์ของปุ่ม (เช่นลิงก์ยืนยันอีเมลตอนสมัครสมาชิก) ผู้เรียกส่งมาทาง $options
        // ถ้าไม่ส่งมา ปุ่มจะพากลับหน้าเว็บ แทนที่จะเหลือ %BUTTON_URL% ในอีเมล
        $variables['BUTTON_URL'] = $options['buttonUrl'] ?? WEB_URL;
        $variables['BUTTON_LABEL'] = $options['buttonLabel'] ?? $variables['WEBTITLE'];
        $variables['HEADER_TITLE'] = $options['headerTitle'] ?? $variables['WEBTITLE'];

        // Render. Subject is plain text — don't HTML-escape it, but strip CR/LF
        // to prevent mail-header injection (extra Bcc/Cc). Body is HTML-escaped.
        $subject = self::render($template['subject'], $variables, false);
        $subject = str_replace(["\r", "\n"], '', $subject);
        $html = self::render($template['detail'], $variables, true);

        // แม่แบบที่ไม่ได้วาง %HEADER_TITLE% / %BUTTON_URL% เอง (เช่นที่มาจาก register())
        // ได้หัวเรื่องและปุ่มต่อรอบเนื้อหา
        if (!empty($options['headerTitle']) && strpos((string) $template['detail'], '%HEADER_TITLE%') === false) {
            $html = '<h2>'.htmlspecialchars((string) $options['headerTitle'], ENT_QUOTES, 'UTF-8').'</h2>'.$html;
        }
        if (!empty($options['buttonUrl']) && strpos((string) $template['detail'], '%BUTTON_URL%') === false) {
            $url = (string) $options['buttonUrl'];
            if (preg_match('/^https?:\/\//i', $url)) {
                $label = trim((string) ($options['buttonLabel'] ?? '')) ?: Language::get('Click here');
                $html .= '<p><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" target="_blank">'
                .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a></p>';
            }
        }

        // Send email
        $from = self::$cfg->noreply_email ?? null;
        $mail = \Kotchasan\Email::send($to, $from, $subject, $html);

        if ($mail->error()) {
            return $mail->getErrorMessage();
        }

        return true;
    }
}
