<?php
/**
 * @filesource modules/inventory/models/customers.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Customers;

use \Kotchasan\Http\Request;
use \Gcms\Login;

/**
 * module=inventory-customers
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูลสำหรับใส่ลงในตาราง
   *
   * @return array
   */
  public static function toDataTable()
  {
    $model = new \Kotchasan\Model;
    return $model->db()->createQuery()
        ->select('company', 'branch', 'phone', 'email', 'id')
        ->from('customer');
  }

  /**
   * รับค่าจาก action
   *
   * @param Request $request
   */
  public function action(Request $request)
  {
    // session, referer, สามารถขายได้, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
      if (Login::checkPermission($login, array('can_buy', 'can_sell', 'can_manage_inventory')) && Login::notDemoMode($login)) {
        // id ที่ส่งมา
        if ($request->post('action')->toString() === 'delete' && preg_match_all('/,?([0-9]+),?/', $request->post('id')->toString(), $match)) {
          // ลบลูกค้ายังไม่เคยทำรายการสั่งซื้อสินค้า
          $model = new \Kotchasan\Model;
          $model->db()->createQuery()
            ->delete('customer', array(
              array('id', $match[1]),
            ))
            ->notExists('orders', array(
              array('customer_id', $match[1]),
            ))
            ->execute();
        }
      }
    }
  }
}