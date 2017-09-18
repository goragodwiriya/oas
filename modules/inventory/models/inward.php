<?php
/**
 * @filesource modules/inventory/models/inward.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Inward;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;
use \Kotchasan\Database\Sql;

/**
 * โมเดลสำหรับ (setup.php)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * Query ข้อมูลสำหรับส่งให้กับ DataTable
   *
   * @param object $owner
   * @return \static
   */
  public static function toDataTable($owner)
  {
    $model = new static;
    return $model->db()->createQuery()
        ->select('O.order_date', 'O.order_no', 'C.company', Sql::create('(O.`total`+O.`vat`-O.`tax`) AS `total`'), 'O.id', 'O.customer_id')
        ->from('orders O')
        ->join('customer C', 'LEFT', array(
          array('C.id', 'O.customer_id')
        ))
        ->where(array(
          array('O.status', $owner->status),
          array('O.stock_status', 'IN')
    ));
  }

  /**
   * รับค่าจาก action
   *
   * @param Request $request
   */
  public function action(Request $request)
  {
    $ret = array();
    // session, referer, can_manage_inventory
    if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
      // รับค่าจากการ POST
      $action = $request->post('action')->toString();
      // id ที่ส่งมา
      if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->toString(), $match)) {
        // Model
        $model = new \Kotchasan\Model;
        // ตาราง user
        $table = $model->getTableName('product');
        if ($action === 'delete') {
          // ลบ order
          $model->db()->delete($model->getTableName('orders'), array('id', $match[1]), 0);
          // ลบ stock
          $model->db()->delete($model->getTableName('stock'), array('order_id', $match[1]), 0);
          // reload
          $ret['location'] = 'reload';
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่า JSON
    echo json_encode($ret);
  }
}