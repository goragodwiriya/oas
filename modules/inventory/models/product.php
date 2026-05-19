<?php
/**
 * @filesource modules/inventory/models/product.php
 */

namespace Inventory\Product;

use Inventory\Helper\Controller as InventoryHelper;
use Inventory\Posting\Model as PostingModel;

class Model extends \Kotchasan\Model
{
    /**
     * Get product for editing.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function get(int $id)
    {
        $record = (object) [
            'id' => 0,
            'allow_negative' => 0,
            'barcode' => '',
            'category_id' => '',
            'description' => '',
            'inuse' => 1,
            'inventory_item_id' => 0,
            'location' => '',
            'cost' => '',
            'price' => '',
            'product_code' => '',
            'sku' => '',
            'stock' => 0,
            'stockable' => 1,
            'topic' => '',
            'unit' => ''
        ];

        if ($id > 0) {
            $product = static::createQuery()
                ->select()
                ->from('inventory')
                ->where(['id', $id])
                ->first();

            if (!$product) {
                return null;
            }

            $item = \Inventory\Item\Model::resolve(0, '', $id);

            $record = (object) array_merge(
                (array) $record,
                (array) $product,
                self::getMetaValues($id),
                $item ? (array) $item : []
            );
            $record->inventory = \Download\Index\Controller::getAttachments($id, 'inventory', self::$cfg->img_typies);
        }

        return $record;
    }

    /**
     * Save product.
     *
     * @param int $id
     * @param array $save
     * @param array $meta
     * @param array $item
     *
     * @return int
     */
    public static function save(int $id, array $save, array $meta, array $item, ?int $createdBy = null): int
    {
        $db = \Kotchasan\DB::create();
        $item = \Inventory\Item\Model::normalizeRow($item);
        $itemCode = trim((string) ($item['sku'] ?? ''));

        if ($id === 0 && $itemCode === '') {
            $itemCode = \Index\Number\Model::get(0, (string) (self::$cfg->inventory_sku_no ?? 'SKU%04d'), 'inventory_items', 'sku');
        }
        $item['sku'] = $itemCode;

        if (trim((string) ($save['product_code'] ?? '')) === '') {
            $save['product_code'] = $itemCode;
        }

        if ($id === 0) {
            $db->beginTransaction();

            try {
                $id = (int) $db->insert('inventory', $save);
                $item['inventory_id'] = $id;

                $inventoryItemId = (int) $db->insert('inventory_items', [
                    'inventory_id' => $id,
                    'sku' => $itemCode,
                    'barcode' => $item['barcode'] !== '' ? $item['barcode'] : null,
                    'unit' => $item['unit'],
                    'stock' => 0,
                    'price' => (float) ($item['price'] ?? 0)
                ]);
                $item['inventory_item_id'] = $inventoryItemId;

                self::saveMeta($id, $meta, $db);

                if ((float) ($item['stock'] ?? 0) > 0) {
                    self::createOpeningReceipt($db, $id, $save, $item, $createdBy);
                }

                /*
                AssignmentModel::setCurrent(
                    $inventoryItemId,
                    $itemCode,
                    (float) $item['stock']
                );
*/
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();

                throw $e;
            }
        } else {
            $db->update('inventory', ['id', $id], $save);
            self::saveMeta($id, $meta, $db);
        }

        return $id;
    }

    /**
     * Remove products.
     *
     * @param array $ids
     *
     * @return int
     */
    public static function remove(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $db = \Kotchasan\DB::create();
        $items = static::createQuery()
            ->select('id inventory_item_id', 'sku')
            ->from('inventory_items')
            ->where(['inventory_id', $ids])
            ->fetchAll();

        $inventoryItemIds = array_values(array_filter(array_map(static function ($row) {
            return (int) ($row->inventory_item_id ?? 0);
        }, $items)));
        $skus = array_values(array_filter(array_map(static function ($row) {
            return trim((string) ($row->sku ?? ''));
        }, $items)));
/*
        if (!empty($inventoryItemIds)) {
            $db->delete('inventory_assignments', ['inventory_item_id', $inventoryItemIds], 0);
        }

        if (!empty($skus)) {
            $db->delete('inventory_assignments', ['sku', $skus], 0);
        }
*/
        $db->delete('inventory_meta', ['inventory_id', $ids], 0);
        PostingModel::purgeInventoryHistory($db, $ids);
        $db->delete('inventory_items', ['inventory_id', $ids], 0);
        $removed = $db->delete('inventory', ['id', $ids], 0);

        foreach ($ids as $id) {
            \Kotchasan\File::removeDirectory(ROOT_PATH.DATA_FOLDER.'inventory/'.$id.'/');
        }

        return (int) $removed;
    }

    /**
     * Toggle inuse flag.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function toggleInuse(int $id)
    {
        $db = \Kotchasan\DB::create();
        $product = $db->first('inventory', ['id', $id]);
        if (!$product) {
            return null;
        }

        $inuse = (int) $product->inuse === 1 ? 0 : 1;
        $db->update('inventory', ['id', $id], ['inuse' => $inuse]);
        $product->inuse = $inuse;

        return $product;
    }

    /**
     * Raw record.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function getRecord(int $id)
    {
        return static::createQuery()
            ->select('id', 'topic', 'inuse')
            ->from('inventory')
            ->where(['id', $id])
            ->first();
    }

    /**
     * Load inventory item rows for an product.
     *
     * @param int $id
     *
     * @return array
     */
    public static function getItemRows(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        return static::createQuery()
            ->select('id inventory_item_id', 'inventory_id', 'sku', 'barcode', 'unit', 'stock', 'price')
            ->from('inventory_items')
            ->where(['inventory_id', $id])
            ->orderBy('sku')
            ->fetchAll();
    }

    /**
     * Inventory item options for serial autocomplete.
     *
     * @param string|null $includeSku
     *
     * @return array
     */
    public static function getItemOptions(?string $includeSku = null): array
    {
        return \Inventory\Item\Model::getOptions($includeSku);
    }

    /**
     * Inventory master options for select filters.
     *
     * @return array
     */
    public static function getInventoryOptions(): array
    {
        return static::createQuery()
            ->select('id value', 'topic text')
            ->from('inventory')
            ->where(['topic', '!=', ''])
            ->orderBy('topic')
            ->fetchAll();
    }

    /**
     * Look for duplicate serial number.
     *
     * @param string $sku
     * @param int $inventoryId
     *
     * @return object|null
     */
    public static function findDuplicateSKU(string $sku, int $inventoryId = 0, int $excludeItemId = 0)
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $query = static::createQuery()
            ->select('id inventory_item_id', 'sku', 'barcode', 'inventory_id')
            ->from('inventory_items')
            ->where(['sku', $sku]);

        if ($inventoryId > 0) {
            $query->where(['inventory_id', '!=', $inventoryId]);
        }
        if ($excludeItemId > 0) {
            $query->where(['id', '!=', $excludeItemId]);
        }

        return $query->first();
    }

    /**
     * Look for duplicate master product code.
     *
     * @param string $productCode
     * @param int $excludeInventoryId
     *
     * @return object|null
     */
    public static function findDuplicateProductCode(string $productCode, int $excludeInventoryId = 0)
    {
        $productCode = trim($productCode);
        if ($productCode === '') {
            return null;
        }

        $query = static::createQuery()
            ->select('id', 'product_code')
            ->from('inventory')
            ->where(['product_code', $productCode]);

        if ($excludeInventoryId > 0) {
            $query->where(['id', '!=', $excludeInventoryId]);
        }

        return $query->first();
    }

    /**
     * Look for duplicate barcode.
     *
     * @param string $barcode
     * @param int $excludeItemId
     *
     * @return object|null
     */
    public static function findDuplicateBarcode(string $barcode, int $excludeItemId = 0)
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $query = static::createQuery()
            ->select('id inventory_item_id', 'sku', 'barcode', 'inventory_id')
            ->from('inventory_items')
            ->where(['barcode', $barcode]);

        if ($excludeItemId > 0) {
            $query->where(['id', '!=', $excludeItemId]);
        }

        return $query->first();
    }

    /**
     * Load meta fields into flat array.
     *
     * @param int $id
     *
     * @return array
     */
    public static function getMetaValues(int $id): array
    {
        $rows = static::createQuery()
            ->select('name', 'value')
            ->from('inventory_meta')
            ->where(['inventory_id', $id])
            ->fetchAll();

        $meta = [];
        foreach ($rows as $row) {
            $meta[$row->name] = $row->value;
        }

        return $meta;
    }

    /**
     * Save meta values.
     *
     * @param int $id
     * @param array $meta
     *
     * @return void
     */
    public static function saveMeta(int $id, array $meta,  ? \Kotchasan\DB $db = null) : void
    {
        $db = $db ?: \Kotchasan\DB::create();
        $db->delete('inventory_meta', ['inventory_id', $id], 0);

        foreach ($meta as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $db->insert('inventory_meta', [
                'inventory_id' => $id,
                'name' => $name,
                'value' => $value
            ]);
        }
    }

    /**
     * Create the initial goods receipt and downstream posting for a newly added product.
     *
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     * @param array $save
     * @param array $item
     * @param int|null $createdBy
     *
     * @return void
     */
    private static function createOpeningReceipt(\Kotchasan\DB $db, int $inventoryId, array $save, array $item, ?int $createdBy = null): void
    {
        $inventoryItemId = (int) ($item['inventory_item_id'] ?? 0);
        $sku = trim((string) ($item['sku'] ?? ''));
        $quantity = InventoryHelper::roundValue(max(0, (float) ($item['stock'] ?? 0)));
        $unitCost = InventoryHelper::roundValue(max(0, (float) ($save['cost'] ?? 0)));
        //$warehouse = WarehouseModel::getDefault();
        //$warehouseId = $warehouse === null ? null : (int) ($warehouse->id ?? 0);

        if ($inventoryId < 1 || $inventoryItemId < 1 || $sku === '' || $quantity <= 0) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $referenceNo = 'GR-OPEN-'.$inventoryId;
        $note = 'Auto goods receipt from product creation for '.trim((string) ($save['topic'] ?? $sku));

        /*
        $receiptId = (int) $db->insert('inventory_goods_receipt', [
            'reference_no' => $referenceNo,
            'supplier_id' => null,
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'quantity' => $quantity,
            'unit' => (string) ($item['unit'] ?? ''),
            'unit_cost' => $unitCost,
            'currency' => 'THB',
            'occurred_at' => $timestamp,
            'note' => $note,
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp
        ]);

        $receiptItemId = (int) $db->insert('inventory_goods_receipt_item', [
            'receipt_id' => $receiptId,
            'line_no' => 1,
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $sku,
            'quantity' => $quantity,
            'unit' => (string) ($item['unit'] ?? ''),
            'unit_cost' => $unitCost,
            'line_total' => InventoryHelper::roundValue($quantity * $unitCost)
        ]);
        */

        $db->update('inventory_items', ['id', $inventoryItemId], [
            'stock' => $quantity
        ]);

        PostingModel::recordReceipt(
            $db,
            $inventoryId,
            $sku,
            $quantity,
            $unitCost,
            'receipt',
            'goods_receipt',
            $referenceNo,
            $note,
            $createdBy,
            $timestamp,
            0,
            0,
            0,
            $inventoryItemId
        );
    }
}