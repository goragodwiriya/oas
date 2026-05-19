<?php
/**
 * @filesource modules/order/models/setup.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Setup;

/**
 * Order List Model — DataTable queries
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Build the DataTable query for orders
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];

        if ($params['document_type'] !== '') {
            $where[] = ['O.document_type', $params['document_type']];
        }
        // Date range filter
        if (!empty($params['date_from'])) {
            $where[] = ['O.created_at', '>=', $params['date_from'].' 00:00:00'];
        }
        if (!empty($params['date_to'])) {
            $where[] = ['O.created_at', '<=', $params['date_to'].' 23:59:59'];
        }

        $query = static::createQuery()
            ->select(
                'O.id',
                'O.order_no',
                'O.document_type',
                'O.customer_name',
                'O.customer_phone',
                'O.subtotal',
                'O.discount_amount',
                'O.tax_amount',
                'O.total',
                'O.created_at',
                'O.updated_at'
            )
            ->from('order O')
            ->where($where);

        // Search
        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['O.order_no', 'LIKE', $search],
                ['O.customer_name', 'LIKE', $search],
                ['O.customer_phone', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }

    /**
     * Delete orders by IDs
     * Removes order and all related data
     *
     * @param array $ids
     *
     * @return int Number deleted
     */
    public static function remove($ids)
    {
        if (empty($ids)) {
            return 0;
        }

        $db = \Kotchasan\DB::create();
        $removed = 0;

        foreach ($ids as $id) {
            $order = $db->first('order', ['id', $id]);
            if (!$order) {
                continue;
            }

            // Delete related data
            $db->delete('order_item', ['order_id', $id]);

            // Delete order
            $db->delete('order', ['id', $id]);
            $removed++;
        }

        return $removed;
    }

    /**
     * Update order status
     *
     * @param int    $id
     * @param string $field
     * @param mixed  $value
     *
     * @return bool
     */
    public static function updateField($id, $field, $value)
    {
        $db = \Kotchasan\DB::create();
        $order = $db->first('order', ['id', $id]);
        if (!$order) {
            return false;
        }

        $db->update('order', ['id', $id], [
            $field => $value,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }
}
