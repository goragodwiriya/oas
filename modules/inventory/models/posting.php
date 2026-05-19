<?php
/**
 * @filesource modules/inventory/models/posting.php
 */

namespace Inventory\Posting;

class Model extends \Kotchasan\Model
{
    /**
     * @param float $value
     */
    private static function roundValue(float $value): float
    {
        return round($value, (int) (self::$cfg->value_decimals ?? 4));
    }

    /**
     * Purge inventory history for the given inventory IDs.
     *
     * @param \Kotchasan\DB $db
     * @param array $inventoryIds
     *
     * @return void
     */
    public static function purgeInventoryHistory(\Kotchasan\DB $db, array $inventoryIds): void
    {
        $inventoryIds = array_values(array_filter(array_map('intval', $inventoryIds)));
        if (empty($inventoryIds)) {
            return;
        }

        $db->delete('inventory_cost_allocation', ['inventory_id', $inventoryIds], 0);
        $db->delete('inventory_cost_layer', ['inventory_id', $inventoryIds], 0);
        $db->delete('inventory_stock_movement', ['inventory_id', $inventoryIds], 0);
        $db->delete('inventory_stock', ['inventory_id', $inventoryIds], 0);
    }

    /**
     * Record an inventory receipt.
     *
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     * @param string $sku
     * @param float $quantity
     * @param float $unitCost
     * @param string $movementType
     * @param string $referenceType
     * @param string $referenceNo
     * @param string $note
     * @param int $createdBy
     * @param string $timestamp
     * @param int $referenceId
     * @param null|int $referenceItemId
     * @param null|int $warehouseId
     * @param null|int $inventoryItemId
     *
     * @return int
     */
    public static function recordReceipt(
        \Kotchasan\DB $db,
        int $inventoryId,
        string $sku,
        float $quantity,
        float $unitCost,
        string $movementType,
        string $referenceType,
        string $referenceNo,
        string $note,
        ?int $createdBy,
        string $timestamp,
        ?int $referenceId = null,
        ?int $referenceItemId = null,
        ?int $warehouseId = null,
        ?int $inventoryItemId = null
    ): int {
        $sku = trim($sku);
        $quantity = self::roundValue(max(0, $quantity));
        $unitCost = self::roundValue(max(0, $unitCost));
        if ($inventoryId < 1 || $sku === '' || $quantity <= 0) {
            return 0;
        }

        $warehouseId = $warehouseId !== null && $warehouseId > 0 ? $warehouseId : 0;
        $totalCost = self::roundValue($quantity * $unitCost);
        $movementId = (int) $db->insert('inventory_stock_movement', [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'movement_direction' => 'in',
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_no' => $referenceNo,
            'reference_item_id' => $referenceItemId,
            'source_movement_id' => null,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'note' => $note,
            'occurred_at' => $timestamp,
            'created_by' => $createdBy,
            'created_at' => $timestamp
        ]);

        $db->insert('inventory_cost_layer', [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_no' => $referenceNo,
            'reference_item_id' => $referenceItemId,
            'source_allocation_id' => null,
            'received_qty' => $quantity,
            'remaining_qty' => $quantity,
            'unit_cost' => $unitCost,
            'currency' => 'THB',
            'note' => $note,
            'received_at' => $timestamp,
            'created_by' => $createdBy,
            'created_at' => $timestamp
        ]);

        self::updateInventoryStock($db, $inventoryId, $sku, $warehouseId, $quantity, $inventoryItemId);
        self::refreshInventoryCost($db, $inventoryId);

        return $movementId;
    }

    /**
     * Record an inventory issue.
     *
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     * @param string $sku
     * @param float $quantity
     * @param string $movementType
     * @param string $referenceType
     * @param string $referenceNo
     * @param string $note
     * @param int $createdBy
     * @param string $timestamp
     * @param int $referenceId
     * @param null|int $referenceItemId
     * @param null|int $warehouseId
     * @param null|int $inventoryItemId
     *
     * @return mixed
     */
    public static function recordIssue(
        \Kotchasan\DB $db,
        int $inventoryId,
        string $sku,
        float $quantity,
        string $movementType,
        string $referenceType,
        string $referenceNo,
        string $note,
        ?int $createdBy,
        string $timestamp,
        ?int $referenceId = null,
        ?int $referenceItemId = null,
        ?int $warehouseId = null,
        ?int $inventoryItemId = null
    ): int {
        $sku = trim($sku);
        $quantity = self::roundValue(max(0, $quantity));
        if ($inventoryId < 1 || $sku === '' || $quantity <= 0) {
            return 0;
        }

        $warehouseId = $warehouseId !== null && $warehouseId > 0 ? $warehouseId : 0;
        $movementId = (int) $db->insert('inventory_stock_movement', [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'movement_direction' => 'out',
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_no' => $referenceNo,
            'reference_item_id' => $referenceItemId,
            'source_movement_id' => null,
            'quantity' => $quantity,
            'unit_cost' => 0,
            'total_cost' => 0,
            'note' => $note,
            'occurred_at' => $timestamp,
            'created_by' => $createdBy,
            'created_at' => $timestamp
        ]);

        $remaining = $quantity;
        $totalCost = 0.0;
        foreach (self::getOpenCostLayers($inventoryId, $sku, $inventoryItemId) as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $available = self::roundValue((float) ($layer->remaining_qty ?? 0));
            if ($available <= 0) {
                continue;
            }

            $allocatedQty = min($remaining, $available);
            $unitCost = self::roundValue((float) ($layer->unit_cost ?? 0));
            $allocationCost = self::roundValue($allocatedQty * $unitCost);

            $db->insert('inventory_cost_allocation', [
                'layer_id' => (int) $layer->id,
                'inventory_id' => $inventoryId,
                'inventory_item_id' => $inventoryItemId,
                'sku' => $sku,
                'movement_id' => $movementId,
                'source_allocation_id' => null,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_no' => $referenceNo,
                'reference_item_id' => $referenceItemId,
                'quantity' => $allocatedQty,
                'unit_cost' => $unitCost,
                'total_cost' => $allocationCost,
                'note' => $note,
                'created_by' => $createdBy,
                'created_at' => $timestamp
            ]);

            $db->update('inventory_cost_layer', ['id', (int) $layer->id], [
                'remaining_qty' => self::roundValue($available - $allocatedQty)
            ]);

            $remaining = self::roundValue($remaining - $allocatedQty);
            $totalCost = self::roundValue($totalCost + $allocationCost);
        }

        if ($remaining > 0) {
            $inventory = $db->first('inventory', ['id', $inventoryId]);
            $fallbackUnitCost = self::roundValue((float) ($inventory->cost ?? 0));
            $totalCost = self::roundValue($totalCost + ($remaining * $fallbackUnitCost));
        }

        $unitCost = $quantity > 0 ? self::roundValue($totalCost / $quantity) : 0;
        $db->update('inventory_stock_movement', ['id', $movementId], [
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost
        ]);

        self::updateInventoryStock($db, $inventoryId, $sku, $warehouseId, -$quantity, $inventoryItemId);
        self::refreshInventoryCost($db, $inventoryId);

        return $movementId;
    }

    /**
     * Get open cost layers for the given inventory ID and SKU, optionally filtered by inventory item ID.
     *
     * @param int $inventoryId
     * @param string $sku
     * @param null|int $inventoryItemId
     * @return array|null
     */
    private static function getOpenCostLayers(int $inventoryId, string $sku, ?int $inventoryItemId = null): array
    {
        $query = static::createQuery()
            ->select('id', 'remaining_qty', 'unit_cost')
            ->from('inventory_cost_layer')
            ->where(['inventory_id', $inventoryId])
            ->where(['sku', $sku])
            ->where(['remaining_qty', '>', 0])
            ->orderBy('received_at')
            ->orderBy('id');

        if ($inventoryItemId !== null && $inventoryItemId > 0) {
            $query->where(['inventory_item_id', $inventoryItemId]);
        }

        return $query->fetchAll();
    }

    /**
     * Record an inventory receipt.
     *
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     * @param string $sku
     * @param null|int $warehouseId
     * @param float $delta
     * @param null|int $inventoryItemId
     *
     * @return void
     */
    private static function updateInventoryStock(\Kotchasan\DB $db, int $inventoryId, string $sku, ?int $warehouseId = null, float $delta, ?int $inventoryItemId = null): void
    {
        if ($inventoryId < 1 || $sku === '' || abs($delta) < 0.00005) {
            return;
        }

        $where = [
            ['inventory_id', $inventoryId],
            ['sku', $sku]
        ];
        $stock = $db->first('inventory_stock', $where);
        if ($stock) {
            $db->update('inventory_stock', $where, [
                'qty' => self::roundValue((float) ($stock->qty ?? 0) + $delta)
            ]);

            return;
        }

        $db->insert('inventory_stock', [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'qty' => self::roundValue($delta),
            'reserved_qty' => 0
        ]);
    }

    /**
     * Refresh the inventory cost for the given inventory ID.
     *
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     *
     * @return void
     */
    private static function refreshInventoryCost(\Kotchasan\DB $db, int $inventoryId): void
    {
        if ($inventoryId < 1) {
            return;
        }

        $totalQty = 0.0;
        $totalCost = 0.0;
        foreach (static::createQuery()
            ->select('remaining_qty', 'unit_cost')
            ->from('inventory_cost_layer')
            ->where(['inventory_id', $inventoryId])
            ->where(['remaining_qty', '>', 0])
            ->fetchAll() as $layer) {
            $remainingQty = (float) ($layer->remaining_qty ?? 0);
            $unitCost = (float) ($layer->unit_cost ?? 0);
            $totalQty += $remainingQty;
            $totalCost += $remainingQty * $unitCost;
        }

        if ($totalQty <= 0) {
            return;
        }

        $db->update('inventory', ['id', $inventoryId], [
            'cost' => self::roundValue($totalCost / $totalQty)
        ]);
    }
}