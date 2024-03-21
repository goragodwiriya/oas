<?php
/**
 * @filesource modules/index/models/email.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Email;

use Kotchasan\Language;

/**
 * ส่งอีเมลสมัครสมาชิก
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
     *
     * @return string
     */
    public static function send($save, $password)
    {
        $msg = "{LNG_Your registration information}<br>\n<br>\n";
        $msg .= '{LNG_Username} : '.$save['username']."<br>\n";
        $msg .= '{LNG_Password} : '.$password."<br>\n";
        $msg .= '{LNG_Name} : '.$save['name'];
        if (!empty($save['activatecode'])) {
            $url = WEB_URL.'index.php?module=welcome&amp;id='.$save['activatecode'];
            $msg .= "<br>\n{LNG_Please click the link to verify your email address.} : <a href='$url'>".$url.'</a>';
        }
        $msg = Language::trans($msg);
        $subject = '['.self::$cfg->web_title.'] '.Language::get('Welcome new members');
        $err = \Kotchasan\Email::send($save['username'], self::$cfg->noreply_email, $subject, $msg);
        if ($err->error()) {
            // คืนค่า error
            return strip_tags($err->getErrorMessage());
        }
        // success
        return '';
    }

    /**
     * ส่งข้อความแจ้งเตือนการสมัครสมาชิกของ user
     *
     * @return string
     */
    public static function sendApprove()
    {
        $title = Language::get('Please check the new member registration.');
        $msg = $title."<br>\n<br>\n".WEB_URL.'index.php?module=member&sort=active';
        $msg = Language::trans($msg);
        $subject = '['.self::$cfg->web_title.'] '.$title;
        // แอดมิน (สามารถอนุมัติสมาชิกได้)
        $query = \Kotchasan\Model::createQuery()
            ->select('username')
            ->from('user')
            ->where(array(
                array('status', 1),
                array('active', 1)
            ));
        $emails = [];
        foreach ($query->execute() as $item) {
            $emails[] = $item->username;
        }
        // ส่งอีเมลไปยังแอดมิน
        \Kotchasan\Email::send(implode(',', $emails), self::$cfg->noreply_email, $subject, $msg);
        // ส่งข้อความไปยัง Line notify
        \Gcms\Line::notify($msg, self::$cfg->line_api_key);
        // ข้อความแจ้งไปยัง user
        return Language::get('The message has been sent to the admin successfully. Please wait a moment for the admin to approve the registration. You can log back in later if approved.');
    }

    /**
     * ส่งข้อความอนุมัติ user
     *
     * @param array $ids
     *
     * @return string
     */
    public static function sendActive($ids = [])
    {
        $title = Language::get('Your account has been approved.');
        $msg = $title.' '.Language::get('You can login at')."<br>\n<br>\n".WEB_URL.'index.php';
        $msg = Language::trans($msg);
        $subject = '['.self::$cfg->web_title.'] '.$title;
        // แอดมิน (สามารถอนุมัติสมาชิกได้)
        $query = \Kotchasan\Model::createQuery()
            ->select('username', 'name', 'line_uid')
            ->from('user')
            ->where(array(
                array('id', $ids),
                array('id', '!=', 1),
                array('active', 1)
            ));
        foreach ($query->execute() as $item) {
            // ส่งอีเมลไปยังแอดมิน
            \Kotchasan\Email::send($item->name.'<'.$item->username.'>', self::$cfg->noreply_email, $subject, $msg);
            // ส่งข้อความไปยัง Line notify
            \Gcms\Line::sendTo($item->line_uid, $msg);
        }
    }
}
