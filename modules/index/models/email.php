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
 * ข้อความอีเมลประกอบในโค้ดแล้วส่งด้วย Kotchasan\Email ตรง ๆ แบบเวอร์ชันเก่า
 * ไม่ต้องมีตาราง emailtemplate ยกเว้นโปรเจ็คที่มีแม่แบบ code นั้นอยู่ในตาราง
 * emailtemplate (ตระกูล GCMS) จะส่งด้วยแม่แบบตามเดิม — ดู deliver()
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
     * @return string ข้อความ error ส่งสำเร็จคืนค่าว่าง
     */
    public static function send($save, $password, $baseUrl)
    {
        $url = empty($save['activatecode']) ? '' : $baseUrl.'activate?id='.$save['activatecode'];
        if (self::isPhone($save['username'])) {
            // send SMS — ลิงก์ยืนยันบัญชี (ระบบไม่มีหน้ากรอก OTP) ไม่มีลิงก์ก็ไม่ต้องส่ง
            $err = $url === '' ? '' : \Gcms\Sms::send($save['username'], Language::get('Please click the link to verify your account.').' '.$url);
        } else {
            // ข้อความอีเมล
            $msg = Language::get('Your registration information')."<br>\n<br>\n";
            $msg .= Language::get('Username').' : '.self::escape($save['username'])."<br>\n";
            $msg .= Language::get('Password').' : '.self::escape($password)."<br>\n";
            $msg .= Language::get('Name').' : '.self::escape($save['name']);
            $options = [];
            if ($url !== '') {
                $msg .= "<br>\n".Language::get('Please click the link to verify your email address.').' : '.self::link($url);
                $options = [
                    'buttonUrl' => $url,
                    'buttonLabel' => Language::get('Verify Email'),
                    'headerTitle' => Language::get('Welcome new members')
                ];
            }
            // send Email
            $result = self::deliver('registration', $save['username'], Language::get('Welcome new members'), $msg, [
                'USERNAME' => $save['username'],
                'PASSWORD' => $password,
                'NAME' => $save['name'],
                // ข้อความล้วน — ค่าของตัวแปรถูก escape ก่อนแทนลงแม่แบบ
                'ACTIVATE_SECTION' => $url === '' ? '' : Language::get('Please click the link to verify your email address.')
            ], $options);
            $err = $result === true ? '' : $result;
        }
        return strip_tags($err);
    }

    /**
     * ส่งข้อความแจ้งเตือนการสมัครสมาชิกของ user
     * ไปยังแอดมิน (สามารถอนุมัติสมาชิกได้)
     *
     * @param string $baseUrl Base URL for email links (frontend URL, not API)
     *
     * @return string
     */
    public static function sendApprove($baseUrl)
    {
        $adminUrl = $baseUrl.'users';
        $title = Language::get('Please check the new member registration.');

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
        foreach ($query->fetchAll() as $item) {
            if (\Kotchasan\Validator::email($item->username)) {
                $emails[] = $item->username;
            }
            if (!empty($item->telegram_id)) {
                $telegrams[$item->telegram_id] = $item->telegram_id;
            }
        }

        // ส่งอีเมลไปยังแอดมิน
        if (!empty($emails)) {
            $msg = $title."<br>\n<br>\n".self::link($adminUrl);
            self::deliver('admin_new_member', implode(',', $emails), $title, $msg, [
                'ADMIN_URL' => $adminUrl
            ], [
                'buttonUrl' => $adminUrl,
                'buttonLabel' => Language::get('Review Members'),
                'headerTitle' => Language::get('New Member Registration')
            ]);
        }

        // ส่งข้อความไปยัง Telegram
        \Gcms\Telegram::sendTo($telegrams, $title."\n".$adminUrl);

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
        $db = \Kotchasan\DB::create();

        $loginUrl = $baseUrl.'login';
        $title = Language::get('Your account has been approved.');
        $text = $title.' '.Language::get('You can login at');

        // Update users
        $db->update('user', [
            ['id', $ids],
            ['id', '!=', 1],
            ['active', 0]
        ], ['active' => 1]);

        // query users
        $users = $db->select('user', [
            ['id', $ids],
            ['id', '!=', 1],
            ['active', 1]
        ], [], ['username', 'name', 'line_uid', 'telegram_id']);

        $sent = 0;
        foreach ($users as $item) {
            $sent++;

            // Plain text message for SMS/Line/Telegram
            $plainMsg = $text.': '.$loginUrl;

            if (self::isPhone($item->username)) {
                // send SMS
                \Gcms\Sms::send($item->username, $plainMsg);
            } else {
                // send Email
                self::deliver('account_approved', $item->name.'<'.$item->username.'>', $title, $text."<br>\n<br>\n".self::link($loginUrl), [
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
        } catch (\Throwable $e) {
            return 'Failed to send email: '.$e->getMessage();
        }
    }

    /**
     * Send password reset link
     * username ที่เป็นเบอร์โทรศัพท์ส่งเป็น SMS แบบเวอร์ชันเก่า
     *
     * @param string $email Recipient email (username)
     * @param string $resetUrl Reset password URL
     *
     * @return bool|string True on success, error message on failure
     */
    public static function sendPasswordReset($email, $resetUrl)
    {
        $minutes = (int) (\Index\Forgot\Model::TOKEN_EXPIRY / 60);
        $text = Language::get('Please click the link to set a new password.');
        $expiry = Language::replace('The link is valid for :minutes minutes.', [':minutes' => $minutes]);

        if (self::isPhone($email)) {
            // send SMS
            $err = \Gcms\Sms::send($email, $text.' '.$resetUrl.' '.$expiry);

            return empty($err) ? true : strip_tags($err);
        }

        $msg = Language::get('Username').' : '.self::escape($email)."<br>\n<br>\n";
        $msg .= $text.' : '.self::link($resetUrl)."<br>\n";
        $msg .= $expiry;

        return self::deliver('password_reset', $email, Language::get('Password Reset Request'), $msg, [
            'EMAIL' => $email,
            'RESET_URL' => $resetUrl,
            'EXPIRY_MINUTES' => (string) $minutes
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
        if (self::isPhone($email)) {
            // send SMS
            $err = \Gcms\Sms::send($email, Language::get('Please click the link to verify your account.').' '.$activateUrl);

            return empty($err) ? true : strip_tags($err);
        }

        $msg = Language::get('Name').' : '.self::escape($userName)."<br>\n<br>\n";
        $msg .= Language::get('Please click the link to verify your email address.').' : '.self::link($activateUrl);

        return self::deliver('activation', $email, Language::get('Verify Email Address'), $msg, [
            'NAME' => $userName,
            'ACTIVATE_URL' => $activateUrl
        ], [
            'buttonUrl' => $activateUrl,
            'buttonLabel' => Language::get('Verify Email Address'),
            'headerTitle' => Language::get('Welcome!')
        ]);
    }

    /**
     * ส่งอีเมล 1 ฉบับ
     *
     * โปรเจ็คที่มีแม่แบบ $code ในตาราง emailtemplate (ตระกูล GCMS) ส่งด้วยแม่แบบนั้น
     * ($variables, $options ใช้กับแม่แบบ) นอกนั้นส่ง $subject และ $msg ที่ประกอบในโค้ด
     * ด้วย Kotchasan\Email ตรง ๆ แบบเวอร์ชันเก่า
     *
     * @param string $code      Template code
     * @param string $to        ผู้รับ คั่นหลายคนด้วย ,
     * @param string $subject   หัวเรื่อง (ไม่รวมชื่อเว็บ)
     * @param string $msg       เนื้อหา HTML — ค่าที่มาจากผู้ใช้ต้อง escape มาแล้ว
     * @param array  $variables ตัวแปรของแม่แบบ
     * @param array  $options   buttonUrl, buttonLabel, headerTitle ของแม่แบบ
     *
     * @return bool|string True on success, error message on failure
     */
    private static function deliver($code, $to, $subject, $msg, array $variables, array $options)
    {
        if (self::hasTemplate($code)) {
            return \Gcms\EmailTemplate::send($code, $to, $variables, $options);
        }
        $subject = '['.strip_tags(self::$cfg->web_title).'] '.$subject;
        $mail = \Kotchasan\Email::send($to, self::$cfg->noreply_email, $subject, $msg);

        return $mail->error() ? $mail->getErrorMessage() : true;
    }

    /**
     * มีแม่แบบ $code ในตาราง emailtemplate หรือไม่ (ภาษาที่ใช้งานอยู่ หรือ th)
     *
     * @param string $code
     *
     * @return bool
     */
    private static function hasTemplate($code)
    {
        try {
            return \Gcms\EmailTemplate::get($code, Language::name()) !== null || \Gcms\EmailTemplate::get($code) !== null;
        } catch (\Throwable $e) {
            // ฐานที่ไม่มีตาราง emailtemplate
            return false;
        }
    }

    /**
     * username เป็นเบอร์โทรศัพท์ (ส่ง SMS แทนอีเมล)
     *
     * @param string $username
     *
     * @return bool
     */
    public static function isPhone($username)
    {
        return preg_match('/^[0-9]{10,10}$/', (string) $username) === 1;
    }

    /**
     * ลิงก์ในเนื้ออีเมล
     *
     * @param string $url
     *
     * @return string
     */
    private static function link($url)
    {
        $url = self::escape($url);

        return '<a href="'.$url.'" target="_blank">'.$url.'</a>';
    }

    /**
     * ค่าที่มาจากผู้ใช้ก่อนใส่ลงเนื้อหา HTML
     *
     * @param string $text
     *
     * @return string
     */
    private static function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
