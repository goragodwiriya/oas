<?php
/**
 * @filesource Kotchasan/Login.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\LoginInterface;
use \Kotchasan\Password;
use \Kotchasan\Language;
use \Kotchasan\Http\Request;
use \Kotchasan\Text;

/**
 * คลาสสำหรับตรวจสอบการ Login
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Login extends \Kotchasan\KBase implements LoginInterface
{
  /**
   * ข้อความจาก Login Class
   *
   * @var string
   */
  public static $login_message;
  /**
   * ชื่อ Input ที่ต้องการให้ active
   * login_username หรือ login_password
   *
   * @var string
   */
  public static $login_input;
  /**
   * ตัวแปรเก็บข้อมูลที่ส่งมา
   * เช่น username, password
   *
   * @var array
   */
  public static $login_params = array();
  /**
   * ตัวแปรบอกว่ามาจากการ submit
   * true มาจากการ submit
   * false default
   *
   * @var bool
   */
  public static $from_submit = false;

  /**
   * ตรวจสอบการ login เมื่อมีการเรียกใช้ class new Login
   * action=logout ออกจากระบบ
   * มาจากการ submit ตรวจสอบการ login
   * ถ้าไม่มีทั้งสองส่วนด้านบน จะตรวจสอบการ login จาก session และ cookie ตามลำดับ
   *
   * @return \static
   */
  public static function create()
  {
    // create class
    $login = new static;
    // การเข้ารหัส
    $pw = new Password(self::$cfg->password_key);
    // ชื่อฟิลด์สำหรับการรับค่าเป็นรายการแรกของ login_fields
    $field_name = reset(self::$cfg->login_fields);
    // อ่านข้อมูลจากฟอร์ม login ฟิลด์ login_username
    self::$login_params['username'] = self::$request->post('login_username', null)->toString();
    if (self::$login_params['username'] === null) {
      if (isset($_SESSION['login']) && isset($_SESSION['login'][$field_name])) {
        // from session
        self::$login_params['username'] = $_SESSION['login'][$field_name];
      } else {
        // from cookie
        $datas = self::$request->getCookieParams();
        self::$login_params['username'] = isset($datas['login_username']) ? $pw->decode($datas['login_username']) : null;
      }
      self::$from_submit = false;
    } else {
      self::$from_submit = true;
    }
    self::$login_params['username'] = Text::username(self::$login_params['username']);
    self::$login_params['password'] = self::get('password', $pw);
    $login_remember = self::get('remember') == 1 ? 1 : 0;
    // ตรวจสอบการ login
    if (self::$request->get('action')->toString() === 'logout' && !self::$from_submit) {
      // logout ลบ session และ cookie
      unset($_SESSION['login']);
      $time = time();
      setCookie('login_username', '', $time, '/');
      setCookie('login_password', '', $time, '/');
      self::$login_message = Language::get('Logout successful');
    } elseif (self::$request->post('action')->toString() === 'forgot') {
      // ขอรหัสผ่านใหม่
      return $login->forgot(self::$request);
    } elseif (!self::$from_submit && isset($_SESSION['login']) && is_array($_SESSION['login'])) {
      // login อยู่แล้ว
      return $login;
    } else {
      // ตรวจสอบค่าที่ส่งมา
      if (self::$login_params['username'] == '') {
        if (self::$from_submit) {
          self::$login_message = Language::get('Please fill in');
          self::$login_input = 'login_username';
        }
      } elseif (self::$login_params['password'] == '') {
        if (self::$from_submit) {
          self::$login_message = Language::get('Please fill in');
          self::$login_input = 'login_password';
        }
      } elseif (!self::$from_submit || (self::$from_submit && self::$request->isReferer())) {
        // ตรวจสอบการ login กับฐานข้อมูล
        $login_result = $login->checkLogin(self::$login_params);
        if (is_string($login_result)) {
          // ข้อความผิดพลาด
          self::$login_input = self::$login_input == 'password' ? 'login_password' : 'login_username';
          self::$login_message = Language::get($login_result);
          // logout ลบ session และ cookie
          unset($_SESSION['login']);
          $time = time();
          setCookie('login_username', '', $time, '/');
          setCookie('login_password', '', $time, '/');
        } else {
          // save login session
          $login_result['password'] = self::$login_params['password'];
          $_SESSION['login'] = $login_result;
          // save login cookie
          $time = time() + 2592000;
          if ($login_remember == 1) {
            setcookie('login_username', $pw->encode(self::$login_params['username']), $time, '/');
            setcookie('login_password', $pw->encode(self::$login_params['password']), $time, '/');
            setcookie('login_remember', $login_remember, $time, '/');
          }
          setcookie('login_id', $login_result['id'], $time, '/');
        }
      }
      return $login;
    }
  }

  /**
   * อ่านข้อมูลจาก POST, SESSION และ COOKIE ตามลำดับ
   * เจออันไหนก่อนใช้อันนั้น
   *
   * @param string $name
   * @param null|Password $pw
   * @return string|null คืนค่าข้อความ ไม่พบคืนค่า null
   */
  protected static function get($name, $pw = null)
  {
    $datas = self::$request->getParsedBody();
    if (isset($datas['login_'.$name])) {
      self::$from_submit = true;
      return (string)$datas['login_'.$name];
    } elseif (isset($_SESSION['login']) && isset($_SESSION['login'][$name])) {
      return (string)$_SESSION['login'][$name];
    }
    $datas = self::$request->getCookieParams();
    if ($pw instanceof Password) {
      return isset($datas['login_'.$name]) ? $pw->decode($datas['login_'.$name]) : null;
    } else {
      return isset($datas['login_'.$name]) ? $datas['login_'.$name] : null;
    }
  }

  /**
   * ฟังก์ชั่นตรวจสอบการ login
   *
   * @param array $params ข้อมูลการ login ที่ส่งมา $params = array('username' => '', 'password' => '');
   * @return string|array เข้าระบบสำเร็จคืนค่าแอเรย์ข้อมูลสมาชิก, ไม่สำเร็จ คืนค่าข้อความผิดพลาด
   */
  public function checkLogin($params)
  {
    $field_name = reset(self::$cfg->login_fields);
    if ($params['username'] !== self::$cfg->get($field_name)) {
      self::$login_input = $field_name;
      return 'not a registered user';
    } elseif ($params['password'] !== self::$cfg->get('password')) {
      self::$login_input = 'password';
      return 'password incorrect';
    } else {
      return array(
        'id' => 1,
        $field_name => $params['username'],
        'password' => $params['password'],
        'status' => 1
      );
    }
    return 'not a registered user';
  }

  /**
   * ฟังก์ชั่นส่งอีเมล์ลืมรหัสผ่าน
   */
  public function forgot(Request $request)
  {
    return $this;
  }

  /**
   * ฟังก์ชั่นตรวจสอบการเข้าระบบ
   *
   * @return array|null คืนค่าข้อมูลสมาชิก (แอเรย์) ถ้าเป็นสมาชิกและเข้าระบบแล้ว ไม่ใช่คืนค่า null
   */
  public static function isMember()
  {
    return empty($_SESSION['login']) ? null : $_SESSION['login'];
  }

  /**
   * ฟังก์ชั่นตรวจสอบสถานะแอดมิน
   *
   * @return array|null คืนค่าข้อมูลสมาชิก (แอเรย์) ถ้าเป็นผู้ดูแลระบบและเข้าระบบแล้ว ไม่ใช่คืนค่า null
   */
  public static function isAdmin()
  {
    $login = self::isMember();
    return isset($login['status']) && $login['status'] == 1 ? $login : null;
  }
}
