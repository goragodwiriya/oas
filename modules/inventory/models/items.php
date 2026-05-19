<?php
/**
 * @filesource modules/inventory/models/items.php
 */

namespace Inventory\Items;

use Inventory\Helper\Controller as InventoryHelper;

class Model extends \Kotchasan\Model
{
    /**
     * Load item-row editor payload for an product.
     *
     * @param int $inventoryId
     *
     * @return object|null
     */
    public static function get(int $inventoryId)
    {
        $product = \Inventory\Product\Model::getRecord($inventoryId);
        if ($product === null) {
            return null;
        }

        $product->item_rows = [
            'columns' => self::getColumns(),
            'data' => self::getRows($inventoryId),
            'options' => [
                'unit' => \Inventory\Category\Controller::init()->toOptions('unit', false, null, ['' => '{LNG_Please select}'])
            ]
        ];

        return $product;
    }

    /**
     * Current item rows keyed by inventory_item_id.
     *
     * @param int $inventoryId
     *
     * @return array<int,object>
     */
    public static function getCurrentRowsById(int $inventoryId): array
    {
        $rows = [];
        foreach (\Inventory\Product\Model::getItemRows($inventoryId) as $row) {
            $row = \Inventory\Item\Model::hydrate($row);
            $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            if ($inventoryItemId > 0) {
                $rows[$inventoryItemId] = $row;
            }
        }

        return $rows;
    }

    /**
     * Editing policies per inventory item row.
     *
     * @param int $inventoryId
     * @param array|null $sourceRows
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getRowPolicies(int $inventoryId, ?array $sourceRows = null): array
    {
        $sourceRows = $sourceRows ?? \Inventory\Product\Model::getItemRows($inventoryId);
        $inventoryItems = [];
        $stocks = [];

        foreach ($sourceRows as $row) {
            $inventoryItemId = (int) ($row->inventory_item_id ?? $row->id ?? 0);
            if ($inventoryItemId > 0) {
                $inventoryItems[$inventoryItemId] = (string) ($row->sku ?? '');
                $stocks[$inventoryItemId] = InventoryHelper::roundValue(max(0, (float) ($row->stock ?? 0)));
            }
        }

        if (empty($inventoryItems)) {
            return [];
        }

        $historyMap = self::getHistoryInventoryItemIds($inventoryItems);

        $policies = [];
        foreach ($inventoryItems as $inventoryItemId => $sku) {
            $hasHistory = !empty($historyMap[$inventoryItemId]);
            $stock = (float) ($stocks[$inventoryItemId] ?? 0);
            $unitLocked = $hasHistory;
            $canDelete = !$hasHistory && $stock <= 0;

            $deleteReasons = [];
            if ($stock > 0) {
                $deleteReasons[] = 'stock is not zero';
            }
            if ($hasHistory) {
                $deleteReasons[] = 'transaction history exists';
            }

            $policies[$inventoryItemId] = [
                'has_history' => $hasHistory,
                'unit_locked' => $unitLocked,
                'stock_locked' => true,
                'can_delete' => $canDelete,
                'delete_reason' => empty($deleteReasons) ? '' : 'This item row cannot be removed because '.implode(', ', $deleteReasons)
            ];
        }

        return $policies;
    }

    /**
     * Column metadata for the editable item rows table.
     *
     * @return array
     */
    public static function getColumns(): array
    {
        return [
            [
                'field' => 'inventory_item_id',
                'label' => 'ID',
                'cellElement' => 'number',
                'hidden' => true
            ],
            [
                'field' => 'sku',
                'label' => 'SKU',
                'cellElement' => 'text',
                'i18n' => true
            ],
            [
                'field' => 'barcode',
                'label' => '{LNG_Barcode}',
                'cellElement' => 'text',
                'i18n' => true
            ],
            [
                'field' => 'stock',
                'label' => '{LNG_Stock}',
                'class' => 'center',
                'cellClass' => 'center',
                'i18n' => true
            ],
            [
                'field' => 'unit',
                'label' => '{LNG_Unit}',
                'cellElement' => 'select',
                'optionsKey' => 'unit',
                'class' => 'center',
                'cellClass' => 'center',
                'i18n' => true
            ],
            [
                'field' => 'price',
                'label' => '{LNG_Price}',
                'cellElement' => 'currency',
                'min' => 0,
                'decimals' => InventoryHelper::getValueDecimals(),
                'step' => InventoryHelper::getStepValue(),
                'size' => 5,
                'class' => 'center',
                'cellClass' => 'center',
                'i18n' => true
            ]
        ];
    }

    /**
     * Load item rows for editing.
     *
     * @param int $inventoryId
     *
     * @return array
     */
    public static function getRows(int $inventoryId): array
    {
        $sourceRows = \Inventory\Product\Model::getItemRows($inventoryId);
        $policies = self::getRowPolicies($inventoryId, $sourceRows);

        $rows = [];
        $index = 0;
        foreach ($sourceRows as $row) {
            ++$index;
            $row = \Inventory\Item\Model::hydrate($row);
            $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            $policy = $policies[$inventoryItemId] ?? [
                'unit_locked' => false,
                'can_delete' => true,
                'delete_reason' => ''
            ];
            $stockText = number_format((float) $row->stock + 0, InventoryHelper::getValueDecimals(), '.', '');
            $displayOnlyFields = [
                'stock' => true
            ];
            if (!empty($policy['unit_locked'])) {
                $displayOnlyFields['unit'] = true;
            }
            $rows[] = [
                'id' => 'row_'.$index,
                'inventory_item_id' => $inventoryItemId,
                'sku' => (string) ($row->sku ?? ''),
                'barcode' => (string) ($row->barcode ?? ''),
                'unit' => (string) ($row->unit ?? ''),
                'stock' => (float) $row->stock + 0,
                'price' => (float) ($row->price ?? 0),
                '_displayOnlyFields' => $displayOnlyFields,
                '_displayValues' => [
                    'stock' => $stockText,
                    'unit' => (string) ($row->unit ?? '')
                ],
                '_rowDeleteDisabled' => empty($policy['can_delete']) ? true : false,
                '_rowDeleteDisabledReason' => (string) ($policy['delete_reason'] ?? ''),
                '_copyResetData' => [
                    'inventory_item_id' => 0,
                    'sku' => '',
                    'barcode' => '',
                    'stock' => 0,
                    '_displayOnlyFields' => [
                        'stock' => true
                    ],
                    '_displayValues' => [
                        'stock' => number_format(0, InventoryHelper::getValueDecimals(), '.', ''),
                        'unit' => (string) ($row->unit ?? '')
                    ],
                    '_rowDeleteDisabled' => false,
                    '_rowDeleteDisabledReason' => ''
                ]
            ];
        }

        return $rows;
    }

    /**
     * Replace all item rows for an product.
     *
     * @param int $inventoryId
     * @param array $rows
     *
     * @return void
     */
    public static function replace(int $inventoryId, array $rows, ?int $createdBy = null,  ? \Kotchasan\DB $db = null, string $movementType = 'adjustment', string $referenceType = 'item_rows') : void
    {
        $currentRows = self::getCurrentRowsById($inventoryId);
        $policies = self::getRowPolicies($inventoryId, array_values($currentRows));
        $normalizedRows = array_map(static function ($row) use ($inventoryId) {
            $normalized = \Inventory\Item\Model::normalizeRow(is_array($row) ? $row : (array) $row);
            $normalized['inventory_id'] = $inventoryId;

            return $normalized;
        }, $rows);
        $ownsTransaction = $db === null;
        $db = $db ?: \Kotchasan\DB::create();

        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $submittedIds = [];
            foreach ($normalizedRows as $row) {
                $inventoryItemId = (int) ($row['inventory_item_id'] ?? 0);
                $currentRow = $inventoryItemId > 0 ? ($currentRows[$inventoryItemId] ?? null) : null;
                $policy = $inventoryItemId > 0 ? ($policies[$inventoryItemId] ?? null) : null;
                $save = [
                    'inventory_id' => $inventoryId,
                    'sku' => $row['sku'],
                    'barcode' => $row['barcode'] !== '' ? $row['barcode'] : null,
                    'unit' => !empty($policy['unit_locked']) && $currentRow !== null ? (string) ($currentRow->unit ?? '') : $row['unit'],
                    'stock' => $currentRow !== null ? (float) ($currentRow->stock ?? 0) : 0,
                    'price' => isset($row['price']) ? (float) $row['price'] : 0
                ];

                if ($inventoryItemId > 0) {
                    $submittedIds[] = $inventoryItemId;
                    $db->update('inventory_items', ['id', $inventoryItemId], $save);
                } else {
                    $submittedIds[] = (int) $db->insert('inventory_items', $save);
                }
            }

            $existingIds = array_values(array_keys($currentRows));
            $removeIds = array_values(array_diff($existingIds, $submittedIds));
            if (!empty($removeIds)) {
                $db->delete('inventory_items', ['id', $removeIds], 0);
            }

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $db->rollback();
            }

            throw $e;
        }
    }

    /**
     * Inventory item ids that already have stock movement history.
     *
     * @param array<int,string> $inventoryItems
     *
     * @return array<int,bool>
     */
    private static function getHistoryInventoryItemIds(array $inventoryItems): array
    {
        if (empty($inventoryItems)) {
            return [];
        }

        $history = [];
        $rows = static::createQuery()
            ->select('inventory_item_id')
            ->from('inventory_stock_movement')
            ->where(['inventory_item_id', array_keys($inventoryItems)])
            ->groupBy('inventory_item_id')
            ->fetchAll();

        foreach ($rows as $row) {
            $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            if ($inventoryItemId > 0) {
                $history[$inventoryItemId] = true;
            }
        }

        $missingItems = array_diff(array_keys($inventoryItems), array_keys($history));
        if (!empty($missingItems)) {
            $legacySkuMap = [];
            foreach ($missingItems as $inventoryItemId) {
                $sku = trim((string) ($inventoryItems[$inventoryItemId] ?? ''));
                if ($sku !== '') {
                    $legacySkuMap[$sku][] = (int) $inventoryItemId;
                }
            }

            if (!empty($legacySkuMap)) {
                $legacyRows = [];
                foreach ([null, 0] as $legacyInventoryItemId) {
                    $legacyRows = array_merge($legacyRows, static::createQuery()
                            ->select('sku')
                            ->from('inventory_stock_movement')
                            ->where([
                                ['sku', array_keys($legacySkuMap)],
                                ['inventory_item_id', $legacyInventoryItemId]
                            ])
                            ->groupBy('sku')
                            ->fetchAll());
                }

                foreach ($legacyRows as $row) {
                    $sku = trim((string) ($row->sku ?? ''));
                    if (!isset($legacySkuMap[$sku])) {
                        continue;
                    }
                    foreach ($legacySkuMap[$sku] as $inventoryItemId) {
                        $history[$inventoryItemId] = true;
                    }
                }
            }
        }

        return $history;
    }
}