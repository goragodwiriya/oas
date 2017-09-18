<?php
/**
 * @filesource modules/inventory/models/home.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Home;

use \Kotchasan\Database\Sql;

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
   * อ่านจำนวนใบสั่งซื้อที่ยังไม่ได้อนุมัติ
   *
   * @param array $login
   * @return int
   */
  public static function getCardData($login)
  {
    $model = new static;
    $sql1 = array($model->db()->createQuery()->selectCount()->from('customer'), 'customers');
    $sql2 = array($model->db()->createQuery()->selectCount()->from('product'), 'products');
    $sql3 = array($model->db()->createQuery()->selectCount()->from('orders')->where(array(
        array('stock_status', 'IN'),
        array('status', 1),
        array(Sql::YEAR('order_date'), date('Y')),
        array(Sql::MONTH('order_date'), date('m'))
      )), 'purcashe_order');
    $sql4 = array($model->db()->createQuery()->selectCount()->from('orders')->where(array(
        array('stock_status', 'OUT'),
        array('status', self::$cfg->outstock_status),
        array('order_date', date('Y-m-d')),
      )), 'receipt');
    return $model->db()->createQuery()->first($sql1, $sql2, $sql3, $sql4);
  }
}