<?php
/**
 * @filesource modules/inventory/models/inventory.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Inventory;

use Kotchasan\Database\Sql;

/**
 * module=inventory-write&tab=inventory
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
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = array(
            array('S.inventory_id', $params['id']),
            Sql::create('(S.`order_id`=0 OR O.`status`=S.`status`)')
        );
        if ($params['status'] != '') {
            $where[] = array('S.status', $params['status']);
        }
        if ($params['year'] > 0) {
            $where[] = array(Sql::YEAR('S.create_date'), $params['year']);
        }
        if ($params['month'] > 0) {
            $where[] = array(Sql::MONTH('S.create_date'), $params['month']);
        }
        return static::createQuery()
            ->select('S.create_date', 'S.order_id', 'O.order_no', 'S.product_no', 'S.quantity', 'S.unit', 'S.cut_stock', 'S.price', 'S.vat', 'S.total', 'S.id', 'S.status')
            ->from('stock S')
            ->join('orders O', 'LEFT', array('O.id', 'S.order_id'))
            ->where($where);
    }
}
