<?php
/**
 * @filesource modules/inventory/models/products.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Products;

use Kotchasan\Database\Sql;

/**
 * model=inventory-products
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
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = array(
            array('V.count_stock', array(0, 1)),
            array('V.inuse', 1)
        );
        if ($params['category_id'] > 0) {
            $where[] = array('V.category_id', $params['category_id']);
        }
        $q1 = static::createQuery()
            ->select('V.id', Sql::CONCAT(array('V.topic', 'I.topic'), 'topic', ', '), 'I.product_no', 'V.category_id', 'I.price', 'V.stock', 'I.unit', 'V.count_stock')
            ->from('inventory V')
            ->join('inventory_items I', 'LEFT', array(array('I.inventory_id', 'V.id')))
            ->where($where);
        $where[0] = array('V.count_stock', 2);
        $q2 = static::createQuery()
            ->select('V.id', 'V.topic', 'I.product_no', 'V.category_id', 'I.price', 'V.stock', 'I.unit', 'V.count_stock')
            ->from('inventory V')
            ->join('inventory_items I', 'LEFT', array('I.inventory_id', 'V.id'))
            ->where($where)
            ->groupBy('V.id');
        return static::createQuery()
            ->union($q1, $q2);
    }
}
