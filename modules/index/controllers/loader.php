<?php
/**
 * @filesource modules/index/controllers/loader.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Loader;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Template;

/**
 * Controller สำหรับโหลดหน้าเว็บด้วย GLoader
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * มาจากการเรียกด้วย GLoader
   * ให้ผลลัพท์เป็น JSON String
   *
   * @param Request $request
   */
  public function index(Request $request)
  {
    // session, referer
    if ($request->initSession() && $request->isReferer()) {
      // ตัวแปรป้องกันการเรียกหน้าเพจโดยตรง
      define('MAIN_INIT', 'indexhtml');
      // ตรวจสอบการ login
      Login::create();
      // template ที่กำลังใช้งานอยู่
      Template::init(self::$cfg->skin);
      // View
      self::$view = new \Gcms\View;
      // โมดูลจาก URL ถ้าไม่มีใช้ default (home)
      $className = \Index\Main\Controller::parseModule($request, 'home');
      if ($className === null) {
        // ถ้าไม่พบหน้าที่เรียก แสดงหน้า 404
        include APP_PATH.'modules/index/controllers/error.php';
        $className = 'Index\Error\Controller';
      }
      // create Controller
      $controller = new $className;
      // เนื้อหา
      self::$view->setContents(array(
        '/{CONTENT}/' => $controller->render($request)
      ));
      // output เป็น HTML
      $ret = array(
        'detail' => self::$view->renderHTML(Template::load('', '', 'loader')),
        'menu' => $controller->menu(),
        'topic' => $controller->title(),
        'to' => $request->post('to', 'scroll-to')->filter('a-z0-9_')
      );
      // คืนค่า JSON
      echo json_encode($ret);
    }
  }
}
