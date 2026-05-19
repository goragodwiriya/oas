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
     * Design tokens
     */
    private static string $primaryColor = '#667eea';
    private static string $secondaryColor = '#764ba2';
    private static string $fontFamily = 'Arial, sans-serif';

    /**
     * In-memory template storage
     * Future: Replace with database queries
     *
     * @var array
     */
    private static array $templates = [];

    /**
     * Initialize flag
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * Initialize default templates
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // Registration email
        self::register('registration', [
            'subject' => '[{SITE_NAME}] {LNG_Welcome new members}',
            'body' => '
                <p>{LNG_Your registration information}</p>
                <p><strong>{LNG_Username}:</strong> {USERNAME}</p>
                <p><strong>{LNG_Password}:</strong> {PASSWORD}</p>
                <p><strong>{LNG_Name}:</strong> {NAME}</p>
                {ACTIVATE_SECTION}
            '
        ]);

        // Admin notification for new member
        self::register('admin_new_member', [
            'subject' => '[{SITE_NAME}] {LNG_Please check the new member registration.}',
            'body' => '
                <p>{LNG_Please check the new member registration.}</p>
                <p>{LNG_Click the link below to review:}</p>
                {BUTTON}
            '
        ]);

        // Account approved notification
        self::register('account_approved', [
            'subject' => '[{SITE_NAME}] {LNG_Your account has been approved.}',
            'body' => '
                <p>{LNG_Hello} <strong>{NAME}</strong>,</p>
                <p>{LNG_Your account has been approved.} {LNG_You can login at}</p>
                {BUTTON}
            '
        ]);

        // Password reset email
        self::register('password_reset', [
            'subject' => '[{SITE_NAME}] {LNG_Password Reset Request}',
            'body' => '
                <p>{LNG_Hello} <strong>{EMAIL}</strong>,</p>
                <p>{LNG_We received a request to reset your password.}</p>
                <p>{LNG_Click the button below to reset your password:}</p>
                {BUTTON}
                <p class="note">{LNG_This link will expire in} <strong>{EXPIRY_MINUTES} {LNG_minutes}</strong>.</p>
                <p class="note">{LNG_If you did not request a password reset, you can safely ignore this email.}</p>
            '
        ]);

        // Email activation/verification
        self::register('activation', [
            'subject' => '[{SITE_NAME}] {LNG_Please verify your email address}',
            'body' => '
                <p>{LNG_Hello} <strong>{NAME}</strong>,</p>
                <p>{LNG_Thank you for registering! Please verify your email address to complete your registration.}</p>
                {BUTTON}
                <p class="note">{LNG_If you did not create an account, you can safely ignore this email.}</p>
            '
        ]);
    }

    /**
     * Register a template
     *
     * @param string $code     Template code
     * @param array  $template Template data (subject, body)
     */
    public static function register(string $code, array $template): void
    {
        self::$templates[$code] = $template;
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
        self::init();

        // Future DB implementation:
        // $template = \Kotchasan\Model::createQuery()
        //     ->from('email_templates')
        //     ->where([['code', $code], ['language', $lang], ['active', 1]])
        //     ->first();
        // return $template ? (array) $template : self::$templates[$code] ?? null;

        return self::$templates[$code] ?? null;
    }

    /**
     * Render template with variable substitution
     *
     * @param string $template Template string
     * @param array  $variables Variables to substitute
     *
     * @return string Rendered template
     */
    public static function render(string $template, array $variables): string
    {
        // Replace {VAR_NAME} with values
        foreach ($variables as $key => $value) {
            $template = str_replace('{'.$key.'}', $value, $template);
        }

        // Translate language keys
        $template = Language::trans($template);

        return $template;
    }

    /**
     * Generate styled button HTML
     *
     * @param string $url   Button URL
     * @param string $label Button text
     *
     * @return string Button HTML
     */
    public static function button(string $url, string $label): string
    {
        $gradient = 'linear-gradient(135deg, '.self::$primaryColor.' 0%, '.self::$secondaryColor.' 100%)';

        return '
            <div style="text-align: center; margin: 30px 0;">
                <a href="'.$url.'" style="background: '.$gradient.'; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">'.$label.'</a>
            </div>
            <p style="font-size: 12px; color: #999;">
                '.Language::get('If the button does not work, copy and paste this link:').'<br>
                <a href="'.$url.'" style="color: '.self::$primaryColor.'; word-break: break-all;">'.$url.'</a>
            </p>
        ';
    }

    /**
     * Wrap content with base email layout
     *
     * @param string $title   Email title (header)
     * @param string $content Body content
     *
     * @return string Complete HTML email
     */
    public static function wrapLayout(string $title, string $content): string
    {
        $gradient = 'linear-gradient(135deg, '.self::$primaryColor.' 0%, '.self::$secondaryColor.' 100%)';
        $siteName = self::$cfg->web_title ?? 'Website';
        $year = date('Y');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: '.self::$fontFamily.'; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: '.$gradient.'; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">'.$title.'</h1>
    </div>

    <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
        '.$content.'
    </div>

    <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #999;">
        <p>&copy; '.$year.' '.$siteName.'. All rights reserved.</p>
    </div>
</body>
</html>';
    }

    /**
     * Send email using template
     *
     * @param string $code      Template code
     * @param string $to        Recipient email
     * @param array  $variables Template variables
     * @param array  $options   Additional options (buttonUrl, buttonLabel, headerTitle)
     *
     * @return bool|string True on success, error message on failure
     */
    public static function send(string $code, string $to, array $variables, array $options = [])
    {
        $template = self::get($code);

        if (!$template) {
            return 'Email template not found: '.$code;
        }

        // Add default variables
        $variables['SITE_NAME'] = $variables['SITE_NAME'] ?? self::$cfg->web_title ?? 'Website';

        // Render subject
        $subject = self::render($template['subject'], $variables);

        // Generate button if URL provided
        if (!empty($options['buttonUrl'])) {
            $buttonLabel = $options['buttonLabel'] ?? Language::get('Click here');
            $variables['BUTTON'] = self::button($options['buttonUrl'], $buttonLabel);
        } else {
            $variables['BUTTON'] = '';
        }

        // Render body
        $body = self::render($template['body'], $variables);

        // Wrap with layout
        $headerTitle = $options['headerTitle'] ?? strip_tags($subject);
        $html = self::wrapLayout($headerTitle, $body);

        // Send email
        $from = self::$cfg->noreply_email ?? null;
        $mail = \Kotchasan\Email::send($to, $from, $subject, $html);

        if ($mail->error()) {
            return $mail->getErrorMessage();
        }

        return true;
    }
}
