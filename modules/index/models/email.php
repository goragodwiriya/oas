<?php
/**
 * @filesource modules/index/models/email.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Email;

use Kotchasan\Language;

/**
 * ส่งอีเมลและ LINE และ SMS ไปยังผู้ที่เกี่ยวข้อง
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * ส่งอีเมลสมัครสมาชิก, ยืนยันสมาชิก
     *
     * @param array $save
     * @param string $password
     * @param string $baseUrl Base URL for email links (frontend URL, not API)
     *
     * @return string
     */
    public static function send($save, $password, $baseUrl)
    {
        if (preg_match('/^[0-9]{10,10}$/', $save['username'])) {
            // Code
            $codes = explode(':', $save['activatecode']);
            // OTP
            $msg = Language::replace('Your OTP code is :otp. Please enter this code on the website to confirm your phone number.', [':otp' => $codes[0]]);
            // send SMS
            $err = \Gcms\Sms::send($save['username'], $msg);
        } else {
            // Prepare activation section
            $activateSection = '';
            $options = [];

            if (!empty($save['activatecode'])) {
                $url = $baseUrl.'activate?id='.$save['activatecode'];
                $activateSection = '<p>{LNG_Please click the link to verify your email address.}</p>';
                $options = [
                    'buttonUrl' => $url,
                    'buttonLabel' => Language::get('Verify Email'),
                    'headerTitle' => Language::get('Welcome new members')
                ];
            }

            // Send using EmailTemplate
            $result = \Gcms\EmailTemplate::send('registration', $save['username'], [
                'USERNAME' => $save['username'],
                'PASSWORD' => $password,
                'NAME' => $save['name'],
                'ACTIVATE_SECTION' => $activateSection
            ], $options);

            $err = $result === true ? '' : $result;
        }
        return strip_tags($err);
    }

    /**
     * ส่งข้อความแจ้งเตือนการสมัครสมาชิกของ user
     *
     * @param string $baseUrl Base URL for email links (frontend URL, not API)
     *
     * @return string
     */
    public static function sendApprove($baseUrl)
    {
        $adminUrl = $baseUrl.'admin/members?status=pending';

        // แอดมิน (สามารถอนุมัติสมาชิกได้)
        $query = \Kotchasan\Model::createQuery()
            ->select('username', 'telegram_id')
            ->from('user')
            ->where([
                ['status', 1],
                ['active', 1]
            ]);
        $emails = [];
        $telegrams = [];
        if (!empty(self::$cfg->telegram_chat_id)) {
            $telegrams[self::$cfg->telegram_chat_id] = self::$cfg->telegram_chat_id;
        }
        foreach ($query->execute() as $item) {
            $emails[] = $item->username;
            if (!empty($item->telegram_id)) {
                $telegrams[$item->telegram_id] = $item->telegram_id;
            }
        }

        // ส่งอีเมลไปยังแอดมิน using EmailTemplate
        \Gcms\EmailTemplate::send('admin_new_member', implode(',', $emails), [
            'ADMIN_URL' => $adminUrl
        ], [
            'buttonUrl' => $adminUrl,
            'buttonLabel' => Language::get('Review Members'),
            'headerTitle' => Language::get('New Member Registration')
        ]);

        // ส่งข้อความไปยัง Telegram
        $msg = Language::get('Please check the new member registration.')."\n".$adminUrl;
        \Gcms\Telegram::sendTo($telegrams, $msg);

        // ข้อความแจ้งไปยัง user
        return Language::get('The message has been sent to the admin successfully. Please wait a moment for the admin to approve the registration. You can log back in later if approved.');
    }

    /**
     * Send user approval message
     *
     * @param array  $ids     User IDs to notify
     * @param string $baseUrl Base URL for email links (frontend URL, not API)
     *
     * @return int Number of users notified
     */
    public static function sendActive($ids, $baseUrl)
    {
        $loginUrl = $baseUrl.'login';

        // Update users
        \Kotchasan\DB::create()->update('user', [
            ['id', $ids],
            ['id', '!=', 1],
            ['active', 0]
        ], ['active' => 1]);

        // query users
        $users = \Kotchasan\DB::create()->select('user', [
            ['id', $ids],
            ['id', '!=', 1],
            ['active', 1]
        ], [], ['username', 'name', 'line_uid', 'telegram_id']);

        $sent = 0;
        foreach ($users as $item) {
            $sent++;

            // Plain text message for SMS/Line/Telegram
            $plainMsg = Language::get('Your account has been approved.').' '.Language::get('You can login at').': '.$loginUrl;

            if (preg_match('/^[0-9]{10,10}$/', $item->username)) {
                // send SMS
                \Gcms\Sms::send($item->username, $plainMsg);
            } else {
                // send Email using EmailTemplate
                \Gcms\EmailTemplate::send('account_approved', $item->name.'<'.$item->username.'>', [
                    'NAME' => $item->name,
                    'LOGIN_URL' => $loginUrl
                ], [
                    'buttonUrl' => $loginUrl,
                    'buttonLabel' => Language::get('Login'),
                    'headerTitle' => Language::get('Account Approved')
                ]);
            }

            // ส่งข้อความไปยัง Line
            \Gcms\Line::sendTo($item->line_uid, $plainMsg);
            // ส่งข้อความไปยัง Telegram Bot
            \Gcms\Telegram::sendTo($item->telegram_id, $plainMsg);
        }
        return $sent;
    }

    /**
     * Send email with custom template - unified method for various modules
     * Now uses EmailTemplate for consistent styling
     *
     * @param array $params Email parameters:
     *   - to|email: Recipient email
     *   - subject: Email subject
     *   - body: HTML body content
     *   - headerTitle: Optional header title (defaults to subject)
     *   - buttonUrl: Optional CTA button URL
     *   - buttonLabel: Optional CTA button label
     *   - variables: Optional additional template variables
     *
     * @return bool|string True on success, error message on failure
     */
    public static function sendTemplate(array $params)
    {
        try {
            $to = $params['to'] ?? $params['email'] ?? null;
            $subject = $params['subject'] ?? '';
            $body = $params['body'] ?? '';

            if (empty($to) || empty($subject) || empty($body)) {
                return 'Missing required email parameters (to, subject, or body)';
            }

            // Register dynamic template
            $templateCode = 'custom_'.md5($subject.$body);
            \Gcms\EmailTemplate::register($templateCode, [
                'subject' => $subject,
                'body' => $body
            ]);

            // Prepare variables
            $variables = $params['variables'] ?? [];
            $variables['SITE_NAME'] = self::$cfg->web_title ?? 'Website';

            // Prepare options
            $options = [];
            if (!empty($params['buttonUrl'])) {
                $options['buttonUrl'] = $params['buttonUrl'];
                $options['buttonLabel'] = $params['buttonLabel'] ?? Language::get('Click here');
            }
            if (!empty($params['headerTitle'])) {
                $options['headerTitle'] = $params['headerTitle'];
            }

            // Send using EmailTemplate for consistent styling
            return \Gcms\EmailTemplate::send($templateCode, $to, $variables, $options);
        } catch (\Exception $e) {
            return 'Failed to send email: '.$e->getMessage();
        }
    }

    /**
     * Send password reset email
     *
     * @param string $email Recipient email
     * @param string $resetUrl Reset password URL
     *
     * @return bool|string True on success, error message on failure
     */
    public static function sendPasswordReset($email, $resetUrl)
    {
        return \Gcms\EmailTemplate::send('password_reset', $email, [
            'EMAIL' => $email,
            'RESET_URL' => $resetUrl,
            'EXPIRY_MINUTES' => '60'
        ], [
            'buttonUrl' => $resetUrl,
            'buttonLabel' => Language::get('Reset Password'),
            'headerTitle' => Language::get('Password Reset Request')
        ]);
    }

    /**
     * Send activation/verification email
     *
     * @param string $email Recipient email
     * @param string $activateUrl Activation URL
     * @param string $userName User's name
     *
     * @return bool|string True on success, error message on failure
     */
    public static function sendActivation($email, $activateUrl, $userName = 'User')
    {
        return \Gcms\EmailTemplate::send('activation', $email, [
            'NAME' => $userName,
            'ACTIVATE_URL' => $activateUrl
        ], [
            'buttonUrl' => $activateUrl,
            'buttonLabel' => Language::get('Verify Email Address'),
            'headerTitle' => Language::get('Welcome!')
        ]);
    }
}
