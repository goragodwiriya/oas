<?php
/**
 * @filesource modules/inventory/models/order.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Order;

/**
 * คลาสสำหรับจัดการ Order
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูล order
   *
   * @param int $id 0 คืนค่ารายการใหม่, > 0 คืนค่ารายการที่เลือก
   * @param string $typ IN หรือ OUT
   * @param int $order_status สถานะ order default 3
   * @return object|null ไม่พบคืนค่า null
   */
  public static function get($id, $typ = 'IN', $order_status = 3)
  {
    if ($id > 0) {
      $model = new \Kotchasan\Model;
      return $model->db()->createQuery()
          ->from('orders O')
          ->join('customer U', 'LEFT', array(
            array('U.id', 'O.customer_id')
          ))
          ->where(array(
            array('O.id', $id)
          ))
          ->first('O.*', 'U.name customer', 'U.company', 'U.branch', 'U.address', 'U.province', 'U.zipcode', 'U.country', 'U.phone', 'U.email', 'U.tax_id');
    } else {
      return (object)array(
          'id' => 0,
          'customer_id' => 0,
          'customer' => '',
          'order_no' => '',
          'order_date' => date('Y-m-d H:i:s'),
          'discount' => 0,
          'vat' => 0,
          'tax' => 0,
          'comment' => '',
          'status' => $order_status,
          'tax_status' => 0,
          'vat_status' => self::$request->cookie($typ == 'IN' ? 'buy_vat_status' : 'sell_vat_status')->toInt(),
          'discount_percent' => 0,
          'due_date' => date('Y-m-d'),
      );
    }
  }

  /**
   * อ่านข้อมูล order จาก $order
   *
   * @param string $order
   * @return object|null ไม่พบคืนค่า null
   */
  public static function getOrder($order)
  {
    if (preg_match('/[0-9a-zA-Z]{32,32}/', $order)) {
      $model = new \Kotchasan\Model;
      return $model->db()->createQuery()
          ->from('orders O')
          ->join('customer U', 'LEFT', array(
            array('U.id', 'O.customer_id')
          ))
          ->where(array('order', $order))
          ->first('O.*', 'U.name customer', 'U.company', 'U.branch', 'U.address', 'U.province', 'U.zipcode', 'U.country', 'U.phone', 'U.email');
    }
    return null;
  }

  /**
   * คืนค่ารายละเอียดของ order
   *
   * @param int $id
   * @return object
   */
  public static function getOrderDetails($id)
  {
    $model = new \Kotchasan\Model;
    return $model->db()->createQuery()
        ->from('orders O')
        ->join('customer U', 'LEFT', array('U.id', 'O.customer_id'))
        ->where(array(
          array('O.id', $id),
        ))
        ->first('O.*', 'U.name customer', 'U.company', 'U.branch', 'U.idcard', 'U.tax_id', 'U.phone', 'U.fax', 'U.email', 'U.address', 'U.provinceID', 'U.province', 'U.zipcode', 'U.country');
  }
}