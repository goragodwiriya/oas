<?php
/**
 * @filesource modules/index/controllers/api.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Api;

use \Kotchasan\Http\Request;

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
    $action = $request->get('action')->filter('a-z');
    if (method_exists('Index\Api\Model', $action)) {
      $result = \Index\Api\Model::$action($request);
    }
    if (!isset($result)) {
      $result = array(
        'error' => array(
          'code' => 400,
          'message' => 'bad request'
        )
      );
    }
    // Response
    $response = new \Kotchasan\Http\Response;
    $response->withHeaders(array(
        'Content-type' => 'application/json; charset=UTF-8'
      ))
      ->withContent(json_encode($result))
      ->send();
  }
}