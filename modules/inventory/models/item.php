<?php
/**
 * @filesource modules/inventory/models/item.php
 */

namespace Inventory\Item;

use Inventory\Helper\Controller as InventoryHelper;

class Model extends \Kotchasan\Model
{
    /**
     * Resolve one inventory item by item id, SKU, or first row under an inventory master.
     *
     * @param int      $inventoryItemId
     * @param string   $sku
     * @param int|null $inventoryId
     *
     * @return object|null
     */
    public static function resolve(int $inventoryItemId = 0, string $sku = '', ?int $inventoryId = null)
    {
        $query = static::createQuery()
            ->select(
                'I.id inventory_item_id',
                'I.inventory_id',
                'I.sku',
                'I.barcode',
                'I.unit',
                'I.stock',
                'I.price',
                'V.product_code',
                'V.topic'
            )
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'LEFT');

        if ($inventoryItemId > 0) {
            $query->where(['I.id', $inventoryItemId]);
        } else {
            $sku = trim($sku);
            if ($inventoryId !== null && $inventoryId > 0) {
                $query->where(['I.inventory_id', $inventoryId]);
                if ($sku !== '') {
                    $query->where(['I.sku', $sku]);
                }
            } else {
                if ($sku === '') {
                    return null;
                }
                $query->where(['I.sku', $sku]);
            }
        }

        if ($inventoryItemId <= 0 && $inventoryId !== null && $inventoryId > 0) {
            $query->orderBy('I.id');
        }

        $item = $query->first();

        return $item === null ? null : self::hydrate($item);
    }

    /**
     * Build autocomplete options for inventory SKU rows.
     *
     * @param string|null $includeSku
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getOptions(?string $includeSku = null): array
    {
        $query = static::createQuery()
            ->select(
                'I.id inventory_item_id',
                'I.inventory_id',
                'I.sku',
                'I.barcode',
                'I.unit',
                'I.stock',
                'I.price',
                'V.product_code',
                'V.topic'
            )
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'LEFT')
            ->orderBy('I.sku');

        $rows = array_map(static function ($row) {
            return self::hydrate($row);
        }, $query->fetchAll());

        $options = array_map(static function ($row) {
            return [
                'value' => (string) $row->sku,
                'text' => trim((string) $row->sku.' - '.(string) $row->topic),
                'inventory_item_id' => (int) $row->inventory_item_id,
                'inventory_id' => (int) $row->inventory_id,
                'product_code' => (string) ($row->product_code ?? ''),
                'sku' => (string) $row->sku,
                'barcode' => (string) ($row->barcode ?? ''),
                'topic' => (string) ($row->topic ?? ''),
                'unit' => (string) ($row->unit ?? ''),
                'stock' => (float) ($row->stock ?? 0),
                'price' => (float) ($row->price ?? 0)
            ];
        }, $rows);

        $includeSku = trim((string) $includeSku);
        if ($includeSku !== '') {
            foreach ($options as $option) {
                if ($option['sku'] === $includeSku) {
                    return $options;
                }
            }

            $item = self::resolve(0, $includeSku);
            if ($item !== null) {
                array_unshift($options, [
                    'value' => (string) $item->sku,
                    'text' => trim((string) $item->sku.' - '.(string) $item->topic),
                    'inventory_item_id' => (int) $item->inventory_item_id,
                    'inventory_id' => (int) $item->inventory_id,
                    'product_code' => (string) ($item->product_code ?? ''),
                    'sku' => (string) $item->sku,
                    'barcode' => (string) ($item->barcode ?? ''),
                    'topic' => (string) ($item->topic ?? ''),
                    'unit' => (string) ($item->unit ?? ''),
                    'stock' => (float) ($item->stock ?? 0),
                    'price' => (float) ($item->price ?? 0)
                ]);
            }
        }

        return $options;
    }

    /**
     * Normalize an incoming SKU row payload.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function normalizeRow(array $row): array
    {
        $sku = trim((string) ($row['sku'] ?? ''));

        return [
            'inventory_item_id' => (int) ($row['inventory_item_id'] ?? 0),
            'inventory_id' => (int) ($row['inventory_id'] ?? 0),
            'sku' => $sku,
            'barcode' => trim((string) ($row['barcode'] ?? '')),
            'unit' => trim((string) ($row['unit'] ?? '')),
            'stock' => InventoryHelper::roundValue(max(0, (float) ($row['stock'] ?? 0))),
            'price' => InventoryHelper::roundValue(max(0, (float) ($row['price'] ?? 0)))
        ];
    }

    /**
     * Hydrate compatibility fields on an inventory item row.
     *
     * @param object $item
     *
     * @return object
     */
    public static function hydrate(object $item): object
    {
        $item->inventory_item_id = (int) ($item->inventory_item_id ?? $item->id ?? 0);
        $item->sku = trim((string) ($item->sku ?? ''));
        $item->barcode = trim((string) ($item->barcode ?? ''));

        return $item;
    }
}