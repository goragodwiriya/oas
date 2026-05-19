<?php
/**
 * @filesource modules/inventory/models/stockmovements.php
 */

namespace Inventory\Stockmovements;

class Model extends \Kotchasan\Model
{
    /**
     * Query data for stock movement ledger.
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable(array $params)
    {
        $where = [];
        if ($params['inventory_id'] > 0) {
            $where[] = ['M.inventory_id', $params['inventory_id']];
        }
        if (($params['inventory_item_id'] ?? 0) > 0) {
            $where[] = ['M.inventory_item_id', (int) $params['inventory_item_id']];
        } elseif ($params['sku'] !== '') {
            $where[] = ['M.sku', $params['sku']];
        }
        if ($params['movement_direction'] !== '') {
            $where[] = ['M.movement_direction', $params['movement_direction']];
        }
        if ($params['movement_type'] !== '') {
            $where[] = ['M.movement_type', $params['movement_type']];
        }
        if ($params['reference_type'] !== '') {
            $where[] = ['M.reference_type', $params['reference_type']];
        }

        $query = static::createQuery()
            ->select(
                'M.id',
                'M.inventory_id',
                'M.inventory_item_id',
                'V.topic',
                'M.sku',
                'M.movement_direction',
                'M.movement_type',
                'M.reference_type',
                'M.reference_no',
                'M.quantity',
                'M.unit_cost',
                'M.total_cost',
                'M.occurred_at'
            )
            ->from('inventory_stock_movement M')
            ->join('inventory V', ['V.id', 'M.inventory_id'], 'LEFT')
            ->where($where);

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['V.topic', 'LIKE', $search],
                ['M.sku', 'LIKE', $search],
                ['M.reference_no', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }

    /**
     * @return array
     */
    public static function getDirectionOptions(): array
    {
        return [
            ['value' => '', 'text' => '{LNG_All items}'],
            ['value' => 'in', 'text' => 'IN'],
            ['value' => 'out', 'text' => 'OUT']
        ];
    }

    /**
     * @return array
     */
    public static function getMovementTypeOptions(): array
    {
        return self::distinctOptions('movement_type');
    }

    /**
     * @return array
     */
    public static function getReferenceTypeOptions(): array
    {
        return self::distinctOptions('reference_type');
    }

    /**
     * @return array
     */
    public static function getInventoryOptions(): array
    {
        return array_merge([
            ['value' => '', 'text' => '{LNG_All items}']
        ], \Inventory\Helper\Controller::getInventoryOptions());
    }

    /**
     * @return array
     */
    public static function getProductOptions(): array
    {
        $options = [
            ['value' => '', 'text' => '{LNG_All items}']
        ];

        foreach (\Inventory\Product\Model::getItemOptions() as $option) {
            $inventoryItemId = (int) ($option['inventory_item_id'] ?? 0);
            if ($inventoryItemId < 1) {
                continue;
            }
            $options[] = [
                'value' => $inventoryItemId,
                'text' => (string) ($option['text'] ?? $option['sku'] ?? '')
            ];
        }

        return $options;
    }

    /**
     * @param string $column
     *
     * @return array
     */
    private static function distinctOptions(string $column): array
    {
        $rows = static::createQuery()
            ->select($column)
            ->from('inventory_stock_movement')
            ->where([[$column, '!=', null]])
            ->groupBy($column)
            ->orderBy($column)
            ->fetchAll();

        $options = [
            ['value' => '', 'text' => '{LNG_All items}']
        ];
        foreach ($rows as $row) {
            $value = trim((string) ($row->{$column} ?? ''));
            if ($value === '') {
                continue;
            }
            $options[] = [
                'value' => $value,
                'text' => self::humanizeToken($value)
            ];
        }

        return $options;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public static function humanizeToken(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}