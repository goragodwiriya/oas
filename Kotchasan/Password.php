<?php
/**
 * @filesource Kotchasan/Password.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Password Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 *
 * @setupParam '1234567890'
 */
class Password extends \Kotchasan\KBase
{
  /**
   * คีย์สำหรับการเข้ารหัส ถอดรหัส
   *
   * @var string
   */
  protected $password_key;

  /**
   * class constructor
   *
   * @param string $key กำหนดคีย์สำหรับการเข้ารหัส ถอดรหัส ถ้าไม่กำหนดมาจะใช้ค่าจาก Config
   */
  public function __construct($key = null)
  {
    $this->password_key = $key === null ? self::$cfg->password_key : $key;
  }

  /**
   * ฟังก์ชั่น เข้ารหัสข้อความ
   *
   * @param string $string ข้อความที่ต้องการเข้ารหัส
   * @return string ข้อความที่เข้ารหัสแล้ว
   */
  public function encode($string)
  {
    $key = sha1($this->password_key);
    $str_len = strlen($string);
    $key_len = strlen($key);
    $j = 0;
    $hash = '';
    for ($i = 0; $i < $str_len; $i++) {
      $ordStr = ord(substr($string, $i, 1));
      $j = $j == $key_len ? 0 : $j;
      $ordKey = ord(substr($key, $j, 1));
      $j++;
      $hash .= strrev(base_convert(dechex($ordStr + $ordKey), 16, 36));
    }
    return $hash;
  }

  /**
   * ฟังก์ชั่น ถอดรหัสข้อความ
   *
   * @param string $string ข้อความที่เข้ารหัสจาก encode()
   * @assert ($this->object->encode("ทดสอบภาษาไทย")) [==] "ทดสอบภาษาไทย"
   * @assert ($this->object->encode(1234)) [==] 1234
   * @return string ข้อความที่ถอดรหัสแล้ว
   */
  public function decode($string)
  {
    $key = sha1($this->password_key);
    $str_len = strlen($string);
    $key_len = strlen($key);
    $j = 0;
    $hash = '';
    for ($i = 0; $i < $str_len; $i+=2) {
      $ordStr = hexdec(base_convert(strrev(substr($string, $i, 2)), 36, 16));
      $j = $j == $key_len ? 0 : $j;
      $ordKey = ord(substr($key, $j, 1));
      $j++;
      $hash .= chr($ordStr - $ordKey);
    }
    return $hash;
  }
}
