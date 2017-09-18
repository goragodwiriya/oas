<?php
/**
 * @filesource modules/inventory/models/stock.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Stock;

use \Kotchasan\Database\Sql;

/**
 * ข้อมูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านรายการสินค้าในใบเสร็จ
   * ถ้าไมมีคืนค่ารายการว่าง 1 รายการ
   *
   * @param int $order_id
   * @param string $typ IN หรือ OUT
   * @return array
   */
  public static function get($order_id, $typ)
  {
    if ($order_id > 0) {
      $model = new \Kotchasan\Model;
      $result = $model->db()->createQuery()
        ->select('S.id', 'S.quantity', 'S.price', 'S.vat', 'S.discount', 'S.product_id', 'S.topic', 'P.unit')
        ->from('stock S')
        ->join('product P', 'LEFT', array(array('P.id', 'S.product_id')))
        ->where(array(
          array('S.order_id', $order_id),
          array('S.status', $typ),
        ))
        ->toArray()
        ->execute();
    }
    if (empty($result)) {
      // ถ้าไม่มีผลลัพท์ คืนค่ารายการเปล่าๆ 1 รายการ
      return array(
        0 => array(
          'id' => 0,
          'quantity' => 1,
          'price' => 0,
          'vat' => 0,
          'discount' => 0,
          'total' => 0,
          'product_id' => 0,
          'topic' => ''
        )
      );
    } else {
      return $result;
    }
  }

  /**
   * สรุปรายละเอียดของสินค้าคงคลัง (เข้า, ออก, คงเหลือ)
   * รายเดือน ตามปีที่เลือก
   *
   * @param int $id
   * @param int $year
   * @return array
   */
  public static function inventory($id, $year)
  {
    $model = new \Kotchasan\Model;
    $db = $model->db();
    $q1 = $db->createQuery()
      ->select(Sql::MONTH('S.create_date', 'm'), 'S.status', Sql::SUM('S.quantity', 'quantity'))
      ->from('stock S')
      ->join('orders O', 'LEFT', array('O.id', 'S.order_id'))
      ->where(array(
        array('S.product_id', $id),
        array(Sql::YEAR('S.create_date'), $year),
        Sql::create('(S.`order_id`=0 OR O.`status`=(CASE WHEN S.`status`="IN" THEN '.self::$cfg->instock_status.' ELSE '.self::$cfg->outstock_status.' END))')
      ))
      ->groupBy('m', 'S.status');
    $query = $db->createQuery()
      ->select('m', Sql::create("SUM(IF(`status`='IN', `quantity`, NULL)) AS `Buy`"), Sql::create("SUM(IF(`status`='OUT', `quantity`, NULL)) AS `Sell`"))
      ->from(array($q1, 'Q'))
      ->groupBy('m')
      ->toArray();
    $result = array();
    foreach ($query->execute() as $item) {
      $result['Sell'][$item['m']] = $item['Sell'];
      $result['Buy'][$item['m']] = $item['Buy'];
    }
    return $result;
  }

  /**
   * อ่านรายการปี ที่มีการทำรายการ สินค้าที่เลือก
   * สำหรับใส่ลงใน select
   *
   * @param int $id
   * @return array
   */
  public static function listYears($id)
  {
    $model = new \Kotchasan\Model;
    $query = $model->db()->createQuery()
      ->select(Sql::create('DISTINCT YEAR(`create_date`) AS `y`'))
      ->from('stock')
      ->where(array(
        array('product_id', $id),
      ))
      ->toArray();
    $year_offset = \Kotchasan\Language::get('YEAR_OFFSET');
    $result = array();
    foreach ($query->execute() as $item) {
      $result[$item['y']] = $item['y'] + $year_offset;
    }
    // ปีนี้
    $y = date('Y');
    $result[$y] = $y + $year_offset;
    return $result;
  }

  /**
   * อ่านข้อมูลสำหรับใส่ลงในตาราง
   *
   * @param int $id
   * @return array|null คืนค่า Object ของข้อมูล ไม่พบคืนค่า null
   */
  public static function toDataTable($id)
  {
    // query ข้อมูลที่เลือก
    $model = new \Kotchasan\Model;
    return $model->db()->createQuery()
        ->select('C.create_date', 'C.order_id', 'O.order_no', 'C.quantity', 'C.price', 'C.total', 'C.id', 'C.status')
        ->from('stock C')
        ->join('orders O', 'LEFT', array(
          array('O.id', 'C.order_id'),
        ))
        ->where(array(
          array('C.product_id', $id),
          Sql::create('(C.`order_id`=0 OR O.`status`=(CASE WHEN C.`status`="IN" THEN '.self::$cfg->instock_status.' ELSE '.self::$cfg->outstock_status.' END))')
    ));
  }
}