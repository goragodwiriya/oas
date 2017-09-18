<?php
/**
 * @filesource Gcms/View.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Gcms;

use \Kotchasan\Language;

/**
 * View base class สำหรับ GCMS.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Kotchasan\View
{

  /**
   * ouput เป็น HTML.
   *
   * @param string|null $template HTML Template ถ้าไม่กำหนด (null) จะใช้ index.html
   * @return string
   */
  public function renderHTML($template = null)
  {
    // เนื้อหา
    parent::setContents(array(
      // url สำหรับกลับไปหน้าก่อนหน้า
      '/{BACKURL(\?([a-zA-Z0-9=&\-_@\.]+))?}/e' => '\Gcms\View::back',
      /* ภาษา */
      '/{LNG_([^}]+)}/e' => '\Kotchasan\Language::parse(array(1=>"$1"))',
      /* ภาษา ที่ใช้งานอยู่ */
      '/{LANGUAGE}/' => Language::name(),
    ));
    return parent::renderHTML($template);
  }

  /**
   * ฟังก์ชั่น แทนที่ query string ด้วยข้อมูลจาก GET และ POST สำหรับส่งต่อไปยัง URL ถัดไป
   * โดยการรับค่าจาก preg_replace
   *
   * @param array $f รับค่าจากตัวแปรที่ส่งมาจาก preg_replace มาสร้าง query string
   * @return string คืนค่า URL
   */
  public static function back($f)
  {
    $query_url = array();
    foreach (self::$request->getQueryParams() as $key => $value) {
      if ($value != '' && $key != 'module') {
        $key = ltrim($key, '_');
        $query_url[$key] = $key.'='.$value;
      }
    }
    foreach (self::$request->getParsedBody() as $key => $value) {
      if ($value != '' && $key != 'module') {
        $key = ltrim($key, '_');
        $query_url[$key] = $key.'='.$value;
      }
    }
    if (isset($f[2])) {
      foreach (explode('&', $f[2]) as $item) {
        if (preg_match('/^(.*)=([^$]{1,})$/', $item, $match)) {
          if ($match[2] === '0') {
            unset($query_url[$match[1]]);
          } else {
            $query_url[$match[1]] = $item;
          }
        }
      }
    }
    return WEB_URL.'index.php?'.implode('&amp;', $query_url);
  }

  /**
   * คืนค่าลิงค์รูปแบบโทรศัพท์
   *
   * @param string $phone_number
   * @return string
   */
  public static function showPhone($phone_number)
  {
    if (preg_match('/^([0-9\-\s]{9,})(.*)$/', $phone_number, $match)) {
      return '<a href="tel:'.trim($match[1]).'">'.$phone_number.'</a>';
    }
    return $phone_number;
  }
}
