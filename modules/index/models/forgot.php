<?php
/**
 * @filesource modules/index/models/forgot.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Forgot;

use Kotchasan\Language;

/**
 * module=forgot
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * ฟังก์ชั่นส่งอีเมลขอรหัสผ่านใหม่
     *
     * @param int    $id
     * @param string $username
     *
     * @return string
     */
    public static function execute($id, $username)
    {
        // รหัสผ่านใหม่
        $password = \Kotchasan\Password::uniqid(6);
        // ข้อมูลอีเมล
        $subject = '['.self::$cfg->web_title.'] '.Language::get('Get new password');
        $msg = $username.' '.Language::get('Your new password is').' : '.$password;
        // send mail
        $err = \Kotchasan\Email::send($username, self::$cfg->noreply_email, $subject, $msg);
        if ($err->error()) {
            // คืนค่า error
            return strip_tags($err->getErrorMessage());
        } else {
            // อัปเดตรหัสผ่านใหม่
            $model = new \Kotchasan\Model();
            $salt = \Kotchasan\Password::uniqid();
            $model->db()->update($model->getTableName('user'), (int) $id, array(
                'salt' => $salt,
                'password' => sha1(self::$cfg->password_key.$password.$salt)
            ));
            // สำเร็จ คืนค่าข้อความว่าง
            return '';
        }
    }
}
