<?php
/**
 * @filesource modules/inventory/models/autocomplete.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Autocomplete;

use \Kotchasan\Http\Request;
use \Gcms\Login;

/**
 * ค้นหาสมาชิก สำหรับ autocomplete
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * ค้นหาสินค้า สำหรับ autocomplete
   * คืนค่าเป็น JSON
   *
   * @param Request $request
   */
  public function findProduct(Request $request)
  {
    if ($request->initSession() && $request->isReferer() && Login::isMember()) {
      $search = $request->post('name')->topic();
      $where = array();
      $select = array('id', 'name', 'email');
      $order = array();
      foreach (explode(',', $request->post('from', 'product_no,topic')->filter('a-z_,')) as $item) {
        if ($item == 'product_no') {
          if ($search != '') {
            $where[] = array('product_no', 'LIKE', "%$search%");
          }
          $order[] = 'product_no';
        }
        if ($item == 'topic') {
          if ($search != '') {
            $where[] = array('topic', 'LIKE', "%$search%");
          }
          $order[] = 'topic';
        }
      }
      $query = $this->db()->createQuery()
        ->select('id', 'product_no', 'topic')
        ->from('product')
        ->order($order)
        ->limit($request->post('count')->toInt())
        ->toArray();
      if (!empty($where)) {
        $query->andWhere($where, 'OR');
      }
      $result = array();
      foreach ($query->execute() as $item) {
        $result[$item['id']] = $item;
      }
      // คืนค่า JSON
      if (!empty($result)) {
        echo json_encode($result);
      }
    }
  }
}