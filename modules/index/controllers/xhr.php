<?php
/**
 * @filesource modules/index/controllers/xhr.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Xhr;

use \Kotchasan\Http\Request;
use \Kotchasan\Template;

/**
 * Controller สำหรับโหลดข้อมูลด้วย Ajax
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{

  /**
   * มาจากการเรียกด้วย Ajax
   *
   * @param Request $request
   */
  public function index(Request $request)
  {
    // ตรวจสอบ Referer
    if ($request->initSession() && $request->isReferer()) {
      // ตัวแปรป้องกันการเรียกหน้าเพจโดยตรง
      define('MAIN_INIT', 'xhr');
      // กำหนด skin ให้กับ template
      Template::init(self::$cfg->skin);
      // ค่าจาก POST
      $query_string = $request->getParsedBody();
      // เรียก Class ที่กำหนด
      if (!empty($query_string['class']) &&
        preg_match('/^[a-zA-Z0-9]+$/', $query_string['method']) &&
        class_exists($query_string['class']) &&
        method_exists($query_string['class'], $query_string['method'])) {
        $method = $query_string['method'];
        createClass($query_string['class'])->$method($request->withQueryParams($query_string));
      }
    }
  }
}