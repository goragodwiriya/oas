<?php
/**
 * @filesource modules/inventory/models/products.php
 */

namespace Inventory\Products;

use Kotchasan\Database\Sql;

class Model extends \Kotchasan\Model
{
    /**
     * Query data for product table.
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['category_id'] !== '') {
            $where[] = ['V.category_id', $params['category_id']];
        }
        if ($params['inuse'] !== '') {
            $where[] = ['V.inuse', (int) $params['inuse']];
        }

        return static::createQuery()
            ->select(
                'V.id',
                'V.category_id',
                'V.product_code',
                'V.topic',
                'V.inuse',
                'V.cost',
                'V.stockable',
                Sql::create('MIN(I.`sku`) first_sku'),
                Sql::COUNT('I.id', 'item_count'),
                Sql::SUM('I.stock', 'total_stock')
            )
            ->from('inventory V')
            ->join('inventory_items I', ['I.inventory_id', 'V.id'], 'LEFT')
            ->where($where)
            ->groupBy('V.id');
    }
}
