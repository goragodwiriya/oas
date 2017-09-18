<?php
/**
 * @filesource modules/index/controllers/welcome.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Welcome;

use \Kotchasan\Http\Request;

/**
 * Controller สำหรับการเข้าระบบ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * forgot, login
   *
   * @param Request $request
   * @return string
   */
  public function execute(Request $request)
  {
    // action ที่เลือก
    $action = $request->get('action')->toString();
    // ตรวจสอบ method ที่กำหนดไว่เท่านั้น
    $action = $action == 'forgot' ? 'forgot' : 'login';
    // เรียก method ที่ส่งมา
    $view = \Index\Welcome\View::$action($request);
    // คืนค่า
    $this->title = $view->title;
    return $view->content;
  }
}
