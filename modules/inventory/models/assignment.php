<?php
/**
 * @filesource modules/inventory/models/assignment.php
 */

namespace Inventory\Assignment;

class Model extends \Kotchasan\Model
{
    /**
     * @param int $inventoryItemId
     * @param string $sku
     * @param float $quantity
     * @param \Kotchasan\DB $db
     *
     * @return void
     */
    public static function setCurrent(int $inventoryItemId, string $sku, float $quantity,  ? \Kotchasan\DB $db = null) : void
    {
        $db = $db ?: \Kotchasan\DB::create();
        $sku = trim($sku);

        if ($inventoryItemId > 0) {
            $db->delete('inventory_assignments', ['inventory_item_id', $inventoryItemId], 0);
        } elseif ($sku !== '') {
            $db->delete('inventory_assignments', ['sku', $sku], 0);
        }

        if ($inventoryItemId < 1 || $sku === '' || $quantity <= 0) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $db->insert('inventory_assignments', [
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'quantity' => round($quantity, 4),
            'assigned_at' => $timestamp,
            'returned_at' => null
        ]);
    }

    /**
     * @param array $inventoryItems
     * @return mixed
     */
    public static function getCurrentQuantitiesByItemIds(array $inventoryItems): array
    {
        $itemIds = array_values(array_filter(array_map('intval', array_keys($inventoryItems))));
        if (empty($itemIds)) {
            return [];
        }

        $quantities = [];
        foreach (static::createQuery()
            ->select('inventory_item_id', 'quantity', 'returned_at')
            ->from('inventory_assignments')
            ->where(['inventory_item_id', $itemIds])
            ->fetchAll() as $row) {
            if (!empty($row->returned_at)) {
                continue;
            }

            $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            if ($inventoryItemId < 1) {
                continue;
            }

            $quantities[$inventoryItemId] = round((float) ($quantities[$inventoryItemId] ?? 0) + (float) ($row->quantity ?? 0), 4);
        }

        return $quantities;
    }

    /**
     * @param int $inventoryId
     */
    public static function getActiveInventoryItemIdsByInventoryId(int $inventoryId): array
    {
        if ($inventoryId < 1) {
            return [];
        }

        $itemIds = [];
        foreach (static::createQuery()
            ->select('id')
            ->from('inventory_items')
            ->where(['inventory_id', $inventoryId])
            ->fetchAll() as $row) {
            $inventoryItemId = (int) ($row->id ?? 0);
            if ($inventoryItemId > 0) {
                $itemIds[] = $inventoryItemId;
            }
        }

        if (empty($itemIds)) {
            return [];
        }

        return array_keys(array_filter(self::getCurrentQuantitiesByItemIds(array_fill_keys($itemIds, '')), static function ($quantity) {
            return (float) $quantity > 0;
        }));
    }
}