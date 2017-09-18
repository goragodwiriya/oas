<?php
/**
 * @filesource Kotchasan/Validator.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * คลาสสำหรับตรวจสอบความถูกต้องของตัวแปรต่างๆ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Validator extends \Kotchasan\KBase
{

  /**
   * ตรวจสอบความถูกของอีเมล์
   *
   * @param string $email
   * @return boolean คืนค่า true ถ้ารูปแบบอีเมล์ถูกต้อง
   *
   * @assert ('admin@localhost.com') [==] true
   * @assert ('admin@localhost') [==] true
   * @assert ('ทดสอบ@localhost') [==] false
   */
  public static function email($email)
  {
    if (function_exists('idn_to_ascii') && preg_match('/(.*)@(.*)/', $email, $match)) {
      // โดเมนภาษาไทย
      $email = $match[1].'@'.idn_to_ascii($match[2]);
    }
    if (preg_match('/^[a-zA-Z0-9\._\-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD', $email)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * ฟังก์ชั่นตรวจสอบไฟล์อัปโหลดว่าเป็นรูปภาพหรือไม่
   *
   * @param array $excepts ชนิดของไฟล์ที่ยอมรับเช่น array('jpg', 'gif', 'png')
   * @param array $file_upload รับค่ามาจาก $_FILES
   * @return array|bool คืนค่าแอเรย์ [width, height, mime] ของรูปภาพ หรือ  false ถ้าไม่ใช่รูปภาพ
   */
  public static function isImage($excepts, $file_upload)
  {
    // ext
    $imageinfo = explode('.', $file_upload['name']);
    $imageinfo = array('ext' => strtolower(end($imageinfo)));
    if (in_array($imageinfo['ext'], $excepts)) {
      // Exif
      $info = getImageSize($file_upload['tmp_name']);
      if ($info[0] == 0 || $info[1] == 0 || !Mime::check($excepts, $info['mime'])) {
        return false;
      } else {
        $imageinfo['width'] = $info[0];
        $imageinfo['height'] = $info[1];
        $imageinfo['mime'] = $info['mime'];
        return $imageinfo;
      }
    } else {
      return false;
    }
  }

  /**
   * ฟังก์ชั่นสำหรับตรวจสอบความถูกต้องของเลขประชาชน
   *
   * @param string $id ตัวเลข 13 หลัก
   * @return boolean คืนค่า true=ถูกต้อง และ false=ไม่ถูกต้อง
   *
   * @assert ('0123456789016') [==] true
   * @assert ('0123456789015') [==] false
   */
  public static function idCard($id)
  {
    if (preg_match('/^[0-9]{13,13}$/', $id)) {
      for ($i = 0, $sum = 0; $i < 12; $i++) {
        $sum += (int)($id{$i}) * (13 - $i);
      }
      if ((11 - ($sum % 11)) % 10 == (int)($id{12})) {
        return true;
      }
    }
    return false;
  }
}
