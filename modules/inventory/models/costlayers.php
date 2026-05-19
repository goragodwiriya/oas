<?php
/**
 * @filesource modules/inventory/models/costlayers.php
 */

namespace Inventory\Costlayers;

class Model extends \Kotchasan\Model
{
    /**
     * Query data for cost layers ledger.
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable(array $params)
    {
        $where = [];
        if ($params['inventory_id'] > 0) {
            $where[] = ['L.inventory_id', $params['inventory_id']];
        }
        if (($params['inventory_item_id'] ?? 0) > 0) {
            $where[] = ['L.inventory_item_id', (int) $params['inventory_item_id']];
        } elseif ($params['sku'] !== '') {
            $where[] = ['L.sku', $params['sku']];
        }
        if ($params['reference_type'] !== '') {
            $where[] = ['L.reference_type', $params['reference_type']];
        }
        if ($params['layer_state'] === 'open') {
            $where[] = ['L.remaining_qty', '>', 0];
        } elseif ($params['layer_state'] === 'closed') {
            $where[] = ['L.remaining_qty', '<=', 0];
        }

        $query = static::createQuery()
            ->select(
                'L.id',
                'L.inventory_id',
                'L.inventory_item_id',
                'V.topic',
                'L.sku',
                'L.reference_type',
                'L.reference_no',
                'L.received_qty',
                'L.remaining_qty',
                'L.unit_cost',
                'L.currency',
                'L.received_at'
            )
            ->from('inventory_cost_layer L')
            ->join('inventory V', ['V.id', 'L.inventory_id'], 'LEFT')
            ->where($where);

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['V.topic', 'LIKE', $search],
                ['L.sku', 'LIKE', $search],
                ['L.reference_no', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }

    /**
     * @return array
     */
    public static function getReferenceTypeOptions(): array
    {
        $rows = static::createQuery()
            ->select('reference_type')
            ->from('inventory_cost_layer')
            ->where([['reference_type', '!=', null]])
            ->groupBy('reference_type')
            ->orderBy('reference_type')
            ->fetchAll();

        $options = [
            ['value' => '', 'text' => '{LNG_All items}']
        ];
        foreach ($rows as $row) {
            $value = trim((string) ($row->reference_type ?? ''));
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
     * @return array
     */
    public static function getLayerStateOptions(): array
    {
        return [
            ['value' => '', 'text' => '{LNG_All items}'],
            ['value' => 'open', 'text' => 'Open'],
            ['value' => 'closed', 'text' => 'Closed']
        ];
    }

    /**
     * @return array
     */
    public static function getInventoryOptions(): array
    {
        return array_merge([
            ['value' => '', 'text' => '{LNG_All items}']
        ], \Inventory\Product\Model::getInventoryOptions());
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
     * @param string $value
     *
     * @return string
     */
    public static function humanizeToken(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}