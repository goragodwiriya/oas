<?php
/**
 * @filesource modules/inventory/models/search.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Search;

use \Kotchasan\Http\Request;
use \Gcms\Login;

/**
 * ค้นหาสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * ค้นหาสินค้าจาก product_no
   * คืนค่าเป็น JSON เพียงรายการเดียว
   *
   * @param Request $request
   */
  public function fromProductno(Request $request)
  {
    if ($request->initSession() && $request->isReferer() && Login::isMember()) {
      $product_no = $request->post('product_no')->topic();
      if ($product_no != '') {
        $query = $this->db()->createQuery()
          ->from('product P')
          ->where(array(
            array('P.product_no', $product_no)
          ))
          ->toArray();
        if ($request->post('typ')->toString() == 'buy') {
          $search = $query->join('stock S', 'LEFT', array(
              array('S.product_id', 'P.id'),
              array('S.status', 'IN')
            ))
            ->first('P.id', 'P.product_no', 'P.topic', 'P.description', 'S.price', 'P.unit', 'S.vat');
        } else {
          $search = $query->first('P.id', 'P.product_no', 'P.topic', 'P.description', 'P.price', 'P.unit', 'P.vat');
        }
      }
      // คืนค่า JSON
      if ($search) {
        echo json_encode($search);
      }
    }
  }
}