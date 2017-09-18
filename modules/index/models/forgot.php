<?php
/**
 * @filesource modules/index/models/forgot.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Forgot;

use \Kotchasan\Language;
use \Kotchasan\Email;

/**
 * คลาสสำหรับการขอรหัสผ่านใหม่
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{

  /**
   * ฟังก์ชั่นส่งอีเมล์ขอรหัสผ่านใหม่
   *
   * @param int $id
   * @param string $password
   * @param string $username
   * @return string
   */
  public static function execute($id, $password, $username)
  {
    // ข้อมูลอีเมล์
    $subject = Language::get('Get new password').' '.self::$cfg->web_title;
    $msg = Language::get('Your new password is').' : '.$password;
    // send mail
    $err = Email::send($username, self::$cfg->noreply_email, $subject, $msg);
    if ($err->error()) {
      // คืนค่า error
      return $err->getErrorMessage();
    } else {
      // อัปเดทรหัสผ่านใหม่
      $model = new \Kotchasan\Model;
      $model->db()->update($model->getTableName('user'), (int)$id, array('password' => sha1($password.$username)));
      // สำเร็จ คืนค่าข้อความว่าง
      return '';
    }
  }
}
