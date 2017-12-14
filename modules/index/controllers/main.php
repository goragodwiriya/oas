<?php
/**
 * @filesource modules/index/controllers/main.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Main;

use \Kotchasan\Http\Request;
use \Kotchasan\Template;

/**
 * Controller หลัก สำหรับแสดงหน้าเว็บไซต์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * ฟังก์ชั่นแปลงชื่อโมดูลที่ส่งมาเป็น Controller Class และโหลดคลาสไว้ เช่น
   * home = Index\Home\Controller
   * person-index = Person\Index\Controller
   *
   * @param Request $request
   * @param string $default ถ้าไม่ระบุจะคืนค่า Error Controller
   * @return string|null คืนค่าชื่อคลาส ถ้าไม่พบจะคืนค่า null
   */
  public static function parseModule($request, $default = null)
  {
    if (preg_match('/^([a-z]+)([\/\-]([a-z]+))?$/i', $request->request('module')->toString(), $match)) {
      if (empty($match[3])) {
        if (is_file(APP_PATH.'modules/'.$match[1].'/controllers/index.php')) {
          $owner = $match[1];
          $module = 'index';
        } else {
          $owner = 'index';
          $module = $match[1];
        }
      } else {
        $owner = $match[1];
        $module = $match[3];
      }
    } elseif (preg_match('/^([a-z]+)([\/\-]([a-z]+))?$/i', $default, $match)) {
      // ถ้าไม่ระบุ module มาแสดงหน้า $default
      if (empty($match[3])) {
        if (is_file(APP_PATH.'modules/'.$match[1].'/controllers/index.php')) {
          $owner = $match[1];
          $module = 'index';
        } else {
          $owner = 'index';
          $module = $match[1];
        }
      } else {
        $owner = $match[1];
        $module = $match[3];
      }
    } else {
      // ไม่มีเมนู
      return null;
    }
    // ตรวจสอบหน้าที่เรียก
    if (is_file(APP_PATH.'modules/'.$owner.'/controllers/'.$module.'.php')) {
      // โหลดคลาส ถ้าพบโมดูลที่เรียก
      include APP_PATH.'modules/'.$owner.'/controllers/'.$module.'.php';
      return ucfirst($owner).'\\'.ucfirst($module).'\Controller';
    }
    return null;
  }

  /**
   * หน้าหลักเว็บไซต์
   *
   * @param Request $request
   * @return string
   */
  public function execute(Request $request)
  {
    // โมดูลจาก URL ถ้าไม่มีใช้เมนูรายการแรก
    $className = self::parseModule($request, self::$menus->home());
    if ($className) {
      // create Class
      $controller = new $className;
      // tempalate
      $template = Template::create('', '', 'main');
      $template->add(array(
        '/{CONTENT}/' => $controller->render($request)
      ));
      // ข้อความ title bar
      $this->title = $controller->title();
      // เมนูที่เลือก
      $this->menu = $controller->menu();
      return $template->render();
    }
    // ไม่พบหน้าที่เรียก
    return \Index\Error\Controller::page404();
  }
}