<?php
/**
 * @filesource modules/index/controllers/export.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Export;

use \Kotchasan\Http\Request;
use \Kotchasan\Template;

/**
 * Controller สำหรับการ Export หรือ Print
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{

  /**
   * export.php
   *
   * @param Request $request
   */
  public function index(Request $request)
  {
    // ตัวแปรป้องกันการเรียกหน้าเพจโดยตรง
    define('MAIN_INIT', 'export');
    // session cookie
    $request->initSession();
    // กำหนด skin ให้กับ template
    Template::init(self::$cfg->skin);
    // ตรวจสอบโมดูลที่เรียก
    $className = \Index\Main\Controller::parseModule($request);
    if ($className && method_exists($className, 'execute')) {
      // create Class
      createClass($className)->execute($request);
    } else {
      // ไม่พบโมดูล
      new \Kotchasan\Http\NotFound();
    }
  }
}
