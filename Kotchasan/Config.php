<?php
/**
 * @filesource Kotchasan/Config.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Class สำหรับการโหลด config
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Config
{
  /**
   * @var Singleton สำหรับเรียกใช้ class นี้เพียงครั้งเดียวเท่านั้น
   */
  private static $instance = null;
  /**
   * ตั้งค่าเขตเวลาของ Server ให้ตรงกันกับเวลาท้องถิ่น
   * สำหรับ Server ที่อยู่ในประเทศไทยใช้ Asia/Bankok
   *
   * @var string
   */
  public $timezone = 'Asia/Bangkok';
  /**
   * รายชื่อภาษาที่รองรับ
   * ตามที่มีในโฟลเดอร์ language/
   * เริ่มต้นคือ en (ภาษาอังกฤษ)
   *
   * @var array
   */
  public $languages = array('th');
  /**
   * template ที่กำลังใช้งานอยู่ (ชื่อโฟลเดอร์)
   *
   * @var string
   */
  public $skin = 'default';
  /**
   * ชื่อเว็บไซต์
   *
   * @var string
   */
  public $web_title = 'Kotchasan PHP Framework';
  /**
   * คำอธิบายเกี่ยวกับเว็บไซต์
   *
   * @var string
   */
  public $web_description = 'PHP Framework พัฒนาโดยคนไทย';
  /**
   * กำหนดอายุของแคช (วินาที)
   * 0 หมายถึงไม่มีการใช้งานแคช
   *
   * @var int
   */
  public $cache_expire = 0;
  /**
   * ทีอยู่อีเมล์ใช้เป็นผู้ส่งจดหมาย สำหรับจดหมายที่ไม่ต้องการตอบกลับ เช่น no-reply@domain.tld
   *
   * @var string
   */
  public $noreply_email = 'no-reply@locahost';
  /**
   * ระบุรหัสภาษาของอีเมล์ที่ส่ง เช่น tis-620
   *
   * @var string
   */
  public $email_charset = 'utf-8';
  /**
   * เลือกโปรแกรมที่ใช้ในการส่งอีเมล์เป็น PHPMailer
   *
   * @var bool
   */
  public $email_use_phpMailer = true;
  /**
   * ชื่อของเมล์เซิร์ฟเวอร์ เช่น localhost หรือ smtp.gmail.com
   *
   * @var string
   */
  public $email_Host = 'localhost';
  /**
   * หมายเลขพอร์ตของเมล์เซิร์ฟเวอร์ (ค่าปกติคือ 25, สำหรับ gmail ใช้ 465, 587 สำหรับ DirectAdmin)
   *
   * @var int
   */
  public $email_Port = 25;
  /**
   * กำหนดวิธีการตรวจสอบผู้ใช้สำหรับเมล์เซิร์ฟเวอร์
   * ถ้ากำหนดเป็น true จะต้องระบุUser+Pasword ของ mailserver ด้วย
   *
   * @var bool
   */
  public $email_SMTPAuth = false;
  /**
   * โปรโตคอลการเข้ารหัส SSL สำหรับการส่งอีเมล์ เช่น ssl
   *
   * @var string
   */
  public $email_SMTPSecure = '';
  /**
   * ชื่อผู้ใช้ mailserver
   *
   * @var string
   */
  public $email_Username = '';
  /**
   * รหัสผ่าน mailserver
   *
   * @var string
   */
  public $email_Password = '';
  /**
   * คีย์สำหรับการเข้ารหัสข้อความ
   *
   * @var string
   */
  public $password_key = '1234567890';
  /**
   * default charset
   *
   * @var string
   */
  public $char_set = 'UTF-8';
  /**
   * รายชื่อฟิลด์จากตารางสมาชิก สำหรับตรวจสอบการ login
   *
   * @var array
   */
  public $login_fields = array('username');

  /**
   * เรียกใช้งาน Class แบบสามารถเรียกได้ครั้งเดียวเท่านั้น
   *
   * @return \static
   */
  protected function __construct()
  {
    if (is_file(ROOT_PATH.'settings/config.php')) {
      $config = include (ROOT_PATH.'settings/config.php');
      if (is_array($config)) {
        foreach ($config as $key => $value) {
          $this->$key = $value;
        }
      }
    }
    if (ROOT_PATH != APP_PATH && is_file(APP_PATH.'settings/config.php')) {
      $config = include (APP_PATH.'settings/config.php');
      if (is_array($config)) {
        foreach ($config as $key => $value) {
          $this->$key = $value;
        }
      }
    }
  }

  /**
   * เรียกใช้งาน Class แบบสามารถเรียกได้ครั้งเดียวเท่านั้น
   *
   * @return \static
   */
  public static function create()
  {
    if (null === self::$instance) {
      self::$instance = new static;
    }
    return self::$instance;
  }

  /**
   * อ่านค่าตัวแปร และ แปลงผลลัพท์ตามชนิดของตัวแปรตามที่กำหนดโดย $default เช่น
   * $default = 0 หรือ เลขจำนวนเต็ม ผลลัพท์จะถูกแปลงเป็น int
   * $default = 0.0 หรือตัวเลขมีจุดทศนิยม จำนวนเงิน ผลลัพท์จะถูกแปลงเป็น double
   * $default = true หรือ false ผลลัพท์จะถูกแปลงเป็น true หรือ false เท่านั้น
   *
   * @param string $key ชื่อตัวแปร
   * @param mixed $default (option) ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return mixed ค่าตัวแปร $key ถ้าไม่พบคืนค่า $default
   */
  public function get($key, $default = '')
  {
    if (isset($this->{$key})) {
      $result = $this->{$key};
      if (is_float($default)) {
        // จำนวนเงิน เช่น 0.0
        $result = (double)$result;
      } elseif (is_int($default)) {
        // เลขจำนวนเต็ม เช่น 0
        $result = (int)$result;
      } elseif (is_bool($default)) {
        // true, false
        $result = (bool)$result;
      }
    } else {
      $result = $default;
    }
    return $result;
  }

  /**
   * โหลดไฟล์ config
   *
   * @param string $file ไฟล์ config (fullpath)
   * @return Object
   */
  public static function load($file)
  {
    $config = array();
    if (is_file($file)) {
      $config = include ($file);
    }
    return (object)$config;
  }

  /**
   * บันทึกไฟล์ config ของโปรเจ็ค
   *
   * @param array $config
   * @param string $file ไฟล์ config (fullpath)
   * @return boolean คืนค่า true ถ้าสำเร็จ
   */
  public static function save($config, $file)
  {
    $f = @fopen($file, 'wb');
    if ($f !== false) {
      if (!preg_match('/^.*\/([^\/]+)\.php?/', $file, $match)) {
        $match[1] = 'config';
      }
      fwrite($f, "<"."?php\n/* $match[1].php */\nreturn ".var_export((array)$config, true).";");
      fclose($f);
      return true;
    } else {
      return false;
    }
  }
}
