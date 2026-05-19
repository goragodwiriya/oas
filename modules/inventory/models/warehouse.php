<?php
/**
 * @filesource modules/inventory/models/warehouse.php
 */

namespace Inventory\Warehouse;

class Model extends \Kotchasan\Model
{
    /**
     * @param int $id
     */
    public static function get(int $id)
    {
        if ($id < 1) {
            return null;
        }

        return static::createQuery()
            ->select()
            ->from('inventory_warehouse')
            ->where(['id', $id])
            ->limit(1)
            ->first();
    }

    public static function getDefault()
    {
        return static::createQuery()
            ->select()
            ->from('inventory_warehouse')
            ->where(['is_active', 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('id')
            ->limit(1)
            ->first();
    }

    /**
     * @return mixed
     */
    public static function toOptions(): array
    {
        $options = [
            ['value' => '', 'text' => '{LNG_Please select}']
        ];

        foreach (static::createQuery()
            ->select('id', 'name')
            ->from('inventory_warehouse')
            ->where(['is_active', 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('name')
            ->fetchAll() as $warehouse) {
            $options[] = [
                'value' => (string) ($warehouse->id ?? ''),
                'text' => (string) ($warehouse->name ?? '')
            ];
        }

        return $options;
    }
}