<?php
/**
 * @filesource modules/customer/models/customers.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Customer\Customers;

/**
 * API Customers Model
 *
 * Customers with comprehensive metrics
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query data to send to DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        // Filters (AND conditions)
        $where = [];
        if (!empty($params['type'])) {
            $where[] = ['C.type', $params['type']];
        }

        // Default query
        $query = static::createQuery()
            ->select('C.id', 'C.code', 'C.name', 'C.contact', 'C.phone', 'C.email', 'C.is_active')
            ->from('customer C')
            ->where($where);

        if (!empty($params['search'])) {
            $s = '%'.$params['search'].'%';
            $query->where(function ($q) use ($s) {
                $q->where(['C.code', 'LIKE', $s])
                    ->orWhere(['C.name', 'LIKE', $s])
                    ->orWhere(['C.contact', 'LIKE', $s])
                    ->orWhere(['C.email', 'LIKE', $s])
                    ->orWhere(['C.phone', 'LIKE', $s]);
            });
        }

        return $query;
    }

    /**
     * Get recent CRM Customers data
     *
     * @param int $limit Number of recent customers to retrieve
     *
     * @return array Customers data
     */
    public static function recent($limit = 5)
    {
        return static::createQuery()
            ->select('name', 'phone', 'type', 'created_at')
            ->from('customer')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->cacheOn()
            ->fetchAll();
    }

    /**
     * Search customers for autocomplete controls.
     *
     * @param string $search
     * @param int $limit
     */
    public static function search($search, $limit = 20, $type = ''): array
    {
        $query = static::createQuery()
            ->select('id', 'code', 'name', 'phone', 'company', 'contact', 'email')
            ->from('customer');

        if ($type === 'customer' || $type === 'supplier') {
            $query->where(['type', $type]);
        }

        if (strlen((string) $search) > 1) {
            $s = '%'.$search.'%';
            $query->where(function ($q) use ($s) {
                $q->where(['code', 'LIKE', $s])
                    ->orWhere(['name', 'LIKE', $s])
                    ->orWhere(['phone', 'LIKE', $s])
                    ->orWhere(['company', 'LIKE', $s])
                    ->orWhere(['contact', 'LIKE', $s])
                    ->orWhere(['email', 'LIKE', $s]);
            });
        }

        $result = [];
        foreach ($query->orderBy('name')->limit($limit)->fetchAll() as $customer) {
            $label = trim($customer->name);
            if ($customer->code !== '') {
                $label = trim($customer->code.' '.$label);
            }
            $meta = [];
            if (!empty($customer->phone)) {
                $meta[] = $customer->phone;
            }
            if (!empty($customer->company)) {
                $meta[] = $customer->company;
            } elseif (!empty($customer->contact)) {
                $meta[] = $customer->contact;
            } elseif (!empty($customer->email)) {
                $meta[] = $customer->email;
            }
            if (!empty($meta)) {
                $label .= ' ('.implode(', ', $meta).')';
            }
            $result[] = [
                'value' => (string) $customer->id,
                'text' => $label
            ];
        }

        return $result;
    }

    /**
     * Save customer
     *
     * @param int $id 0 = insert, > 0 = update
     * @param array $save
     *
     * @return mixed
     */
    public static function save($id, $save)
    {
        if ($id === 0) {
            return \Kotchasan\DB::create()->insert('customer', $save);
        } else {
            return \Kotchasan\DB::create()->update('customer', ['id', $id], $save);
        }
    }

    /**
     * Delete customer
     *
     * @param int|array $ids
     *
     * @return int
     */
    public static function remove($ids)
    {
        $db = \Kotchasan\DB::create();
        return $db->delete('customer', ['id', $ids], 0);
    }
}
