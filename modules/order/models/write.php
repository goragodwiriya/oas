<?php
/**
 * @filesource modules/order/models/write.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Write;

use Kotchasan\Text;

/**
 * Order Write Model — CRUD for orders
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * @return int
     */
    private static function getOrderValueDecimals(): int
    {
        return \Order\Helper\Model::getValueDecimals();
    }

    /**
     * @return float
     */
    private static function getOrderMinimumQuantity(): float
    {
        return \Order\Helper\Model::getMinimumQuantity();
    }

    /**
     * @param mixed $value
     */
    private static function roundOrderValue($value): float
    {
        return round(Text::toDouble((string) $value), self::getOrderValueDecimals());
    }

    /**
     * @param mixed $value
     */
    private static function normalizeOrderAmount($value): float
    {
        return self::roundOrderValue(max(0, Text::toDouble((string) $value)));
    }

    /**
     * @param mixed $value
     */
    private static function normalizeOrderQuantity($value, float $fallback = 1.0, ?float $minimum = null): float
    {
        $quantity = is_numeric($value) ? Text::toDouble((string) $value) : $fallback;

        return self::roundOrderValue(max($minimum ?? self::getOrderMinimumQuantity(), $quantity));
    }

    /**
     * Normalize document type from input.
     *
     * @param string|null $documentType
     * @param string      $default
     *
     * @return string
     */
    public static function normalizeDocumentType(?string $documentType, string $default = 'QT'): string
    {
        return \Order\Helper\Model::normalizeDocumentType($documentType, $default);
    }

    /**
     * Normalize document status from input.
     *
     * @param string|null $documentStatus
     * @param string      $default
     *
     * @return string
     */
    public static function normalizeDocumentStatus(?string $documentStatus, string $default = 'issued'): string
    {
        return \Order\Helper\Model::normalizeDocumentStatus($documentStatus, $default);
    }

    /**
     * @param string|null $documentType
     *
     * @return string
     */
    public static function getDocumentTypeText(?string $documentType): string
    {
        return \Order\Helper\Model::getDocumentTypeText($documentType);
    }

    /**
     * @param string|null $documentType
     *
     * @return string[]
     */
    public static function getAllowedTargetDocumentTypes(?string $documentType): array
    {
        $documentType = self::normalizeDocumentType($documentType, 'QT');
        $map = [
            'QT' => ['SO'],
            'SO' => ['DN'],
            'DN' => ['INV'],
            'INV' => ['RCP'],
            'PR' => ['PO'],
            'PO' => ['GR'],
            'GR' => ['PINV']
        ];

        return $map[$documentType] ?? [];
    }

    /**
     * @param string|null $documentType
     *
     * @return array<int,array<string,string>>
     */
    public static function getAllowedTargetDocumentTypeOptions(?string $documentType): array
    {
        $options = [];
        foreach (self::getAllowedTargetDocumentTypes($documentType) as $value) {
            $options[] = [
                'value' => $value,
                'text' => self::getDocumentTypeText($value)
            ];
        }

        return $options;
    }

    /**
     * @param int $documentId
     *
     * @return bool
     */
    public static function hasChildDocuments(int $documentId): bool
    {
        if ($documentId < 1) {
            return false;
        }

        return static::createQuery()
            ->select('id')
            ->from('order')
            ->where(['source_document_id', $documentId])
            ->limit(1)
            ->first() !== null;
    }

    /**
     * Prepare document-derived view state.
     *
     * @param object $document
     *
     * @return object
     */
    private static function hydrateDocumentState(object $document): object
    {
        $document->document_type = self::normalizeDocumentType($document->document_type ?? 'QT', 'QT');
        $document->document_status = self::normalizeDocumentStatus($document->document_status ?? 'issued', 'issued');
        $document->document_type_text = self::getDocumentTypeText($document->document_type);

        return $document;
    }

    /**
     * @return int|null
     */
    private static function getDefaultWarehouseId(): ?int
    {
        $warehouse = \Inventory\Warehouse\Model::getDefault();

        return $warehouse === null || (int) ($warehouse->id ?? 0) < 1 ? null : (int) $warehouse->id;
    }

    /**
     * @param string $documentType
     *
     * @return array{prefix:string,number:string}
     */
    private static function getConfiguredOrderNumberSetting(string $documentType): array
    {
        $settings = self::$cfg->order_no[$documentType] ?? [];
        $prefix = trim((string) ($settings['prefix'] ?? $documentType.'%Y%M-'));
        $number = trim((string) ($settings['no'] ?? '%04d'));

        $prefix = preg_replace('/[^A-Za-z0-9%\/_\-]/', '', $prefix);
        $number = preg_replace('/[^A-Za-z0-9%\/_\-]/', '', $number);

        if ($prefix === '') {
            $prefix = $documentType.'%Y%M-';
        }
        if ($number === '') {
            $number = '%04d';
        }

        return [
            'prefix' => $prefix,
            'number' => $number
        ];
    }

    /**
     * @param string $prefixTemplate
     * @param int $year
     * @param int $month
     *
     * @return string
     */
    private static function resolveOrderNumberPrefix(string $prefixTemplate, int $year, int $month): string
    {
        return str_replace(
            ['%Y', '%y', '%M', '%m'],
            [substr((string) $year, -2), substr((string) $year, -2), str_pad((string) $month, 2, '0', STR_PAD_LEFT), str_pad((string) $month, 2, '0', STR_PAD_LEFT)],
            $prefixTemplate
        );
    }

    /**
     * @param string $prefix
     *
     * @return int
     */
    private static function getLastOrderNumberForPrefix(string $prefix): int
    {
        if ($prefix === '') {
            return 0;
        }

        $last = static::createQuery()
            ->select('order_no')
            ->from('order')
            ->where(['order_no', 'LIKE', $prefix.'%'])
            ->orderBy('order_no', 'DESC')
            ->limit(1)
            ->first();

        if ($last === null || empty($last->order_no)) {
            return 0;
        }

        if (preg_match('/(\d+)$/', (string) $last->order_no, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    /**
     * Get order details with items, payments, status log
     *
     * @param int $id Order ID
     *
     * @return object|null
     */
    public static function get($id, ?string $defaultDocumentType = 'QT')
    {
        if (empty($id)) {
            $defaultDocumentType = self::normalizeDocumentType($defaultDocumentType, 'QT');
            // Return empty template for new order
            return self::hydrateDocumentState((object) [
                'id' => 0,
                'order_no' => '',
                'document_type' => $defaultDocumentType,
                'document_status' => 'issued',
                'payment_status' => 'paid',
                'customer_id' => 0,
                'customer_name' => '',
                'customer_phone' => '',
                'created_at' => date('Y-m-d H:i'),
                'items' => []
            ]);
        }

        // Get order
        $order = static::createQuery()
            ->select()
            ->from('order')
            ->where(['id', $id])
            ->first();

        if (!$order) {
            return null;
        }

        $order->customer = ['value' => (int) $order->customer_id, 'text' => (string) $order->customer_name];

        /*

        if (!empty($order->customer = ['value' => '', 'text' => ''];d)) {
            $customer = \Customer\Customer\Model::get((int) $order->customer_id, '');
            if ($customer !== null) {
                $order->customer = \Customer\Autocomplete\Model::buildCustomerOption($customer);
                if (empty($order->customer_phone)) {
                    $order->customer_phone = (string) ($customer->phone ?? '');
                }
                if (empty($order->customer_tax_id)) {
                    $order->customer_tax_id = (string) ($customer->tax_id ?? '');
                }
            } elseif (!empty($order->customer_name)) {
                $order->customer = [
                    'value' => (int) $order->customer_id,
                    'text' => (string) $order->customer_name
                ];
            }
        }
        */

        // Get order items
        $order->items = static::createQuery()
            ->select(
                'OI.id',
                'OI.product_id',
                'OI.inventory_item_id',
                'OI.item_id',
                'OI.product_code',
                'OI.name',
                'OI.quantity',
                'OI.unit_price',
                'OI.unit',
                'OI.cost_price',
                'OI.discount_amount',
                'OI.tax_amount',
                'OI.subtotal',
                'OI.note'
            )
            ->from('order_item OI')
            ->where(['OI.order_id', $id])
            ->fetchAll();
        foreach ($order->items as $item) {
            $item->product_code = (string) ($item->product_code ?? '');
            $item->name = (string) ($item->name ?? '');
            $item->unit = (string) ($item->unit ?? '');
            $item->qty = self::roundOrderValue((float) ($item->quantity ?? 0));
            $item->price = self::normalizeOrderAmount($item->unit_price ?? 0);
            $item->cost_price = self::normalizeOrderAmount($item->cost_price ?? 0);
            $item->discount_amount = self::normalizeOrderAmount($item->discount_amount ?? 0);
            $item->tax_amount = self::normalizeOrderAmount($item->tax_amount ?? 0);
            $item->total = self::normalizeOrderAmount($item->subtotal ?? 0);
            $item->note = (string) ($item->note ?? '');
        }

        return self::hydrateDocumentState($order);
    }

    /**
     * Ensure the sequence row for a document type exists.
     *
     * @param string $documentType
     * @param int    $year
     * @param int    $month
     *
     * @return object
     */
    private static function ensureDocumentSequence(string $documentType, int $year, int $month)
    {
        $db = \Kotchasan\DB::create();
        $sequence = $db->first('document_sequence', [
            ['type', $documentType],
            ['year', $year],
            ['month', $month]
        ]);

        if ($sequence) {
            return $sequence;
        }

        $settings = self::getConfiguredOrderNumberSetting($documentType);
        $prefix = self::resolveOrderNumberPrefix($settings['prefix'], $year, $month);
        $nextId = $db->nextId('document_sequence');
        $row = [
            'id' => $nextId,
            'type' => $documentType,
            'prefix' => $prefix,
            'year' => $year,
            'month' => $month,
            'last_number' => self::getLastOrderNumberForPrefix($prefix),
            'format' => $settings['number']
        ];
        $db->insert('document_sequence', $row);

        return (object) $row;
    }

    /**
     * Generate next document number for a document type.
     *
     * @param array $order
     *
     * @return string
     */
    public static function generateOrderNo($order)
    {
        if (isset(self::$cfg->order_no[$order['document_type']])) {
            $order_no = self::$cfg->order_no[$order['document_type']];
        } else {
            $order_no = [
                'no' => '%04d',
                'prefix' => $order['document_type']

            ];
        }
        return \Index\Number\Model::get($order['id'], $order_no['no'], 'order', 'order_no', $order_no['prefix']);

        $documentType = self::normalizeDocumentType((string) $documentType, 'QT');
        $year = (int) date('Y') + 543;
        $month = (int) date('n');
        $db = \Kotchasan\DB::create();
        $sequence = self::ensureDocumentSequence($documentType, $year, $month);
        $nextNumber = (int) $sequence->last_number + 1;
        $prefix = (string) $sequence->prefix;
        $format = (string) ($sequence->format ?? '%04d');

        if (strpos($format, '%') !== false) {
            $documentNo = $prefix.sprintf($format, $nextNumber);
        } else {
            $documentNo = strtr($format, [
                '{PREFIX}' => $prefix,
                '{TYPE}' => $documentType,
                '{YY}' => substr((string) $year, -2),
                '{YYYY}' => (string) $year,
                '{MM}' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                '{NNNN}' => str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                '{NNNNN}' => str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT),
                '{SEQ4}' => str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                '{SEQ5}' => str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT)
            ]);
        }

        $db->update('document_sequence', ['id', (int) $sequence->id], [
            'last_number' => $nextNumber
        ]);

        return $documentNo;
    }

    /**
     * Load order items for a document.
     *
     * @param int                $orderId
     * @param \Kotchasan\DB|null $db
     *
     * @return array<int,object>
     */
    private static function getOrderItems(int $orderId,  ? \Kotchasan\DB $db = null) : array
    {
        if ($orderId < 1) {
            return [];
        }

        if ($db !== null) {
            return $db->select('order_item', ['order_id', $orderId]);
        }

        return static::createQuery()
            ->select()
            ->from('order_item')
            ->where(['order_id', $orderId])
            ->fetchAll();
    }

    /**
     * Remaining quantity available for a source item after descendants consume it.
     *
     * @param int $sourceItemId
     * @param int $excludeOrderId
     *
     * @return float
     */
    private static function getAvailableQuantityForSourceItem(int $sourceItemId, int $excludeOrderId = 0): float
    {
        $db = \Kotchasan\DB::create();
        $sourceItem = $db->first('order_item', ['id', $sourceItemId]);
        if (!$sourceItem) {
            return 0;
        }

        $used = 0;
        foreach (static::createQuery()
            ->select('order_id', 'quantity')
            ->from('order_item')
            ->where(['source_item_id', $sourceItemId])
            ->fetchAll() as $item) {
            if ((int) $item->order_id === $excludeOrderId) {
                continue;
            }

            $document = $db->first('order', ['id', (int) $item->order_id]);
            if ($document && !in_array(self::normalizeDocumentStatus($document->document_status ?? 'draft'), ['cancelled', 'voided'], true)) {
                $used += (float) $item->quantity;
            }
        }

        return self::roundOrderValue(max(0, (float) $sourceItem->quantity - (float) $used));
    }

    /**
     * Build child document items from a source document using remaining quantities.
     *
     * @param int $sourceOrderId
     *
     * @return array<int,array<string,mixed>>
     */
    private static function buildDerivedItems(int $sourceOrderId): array
    {
        $items = [];
        foreach (self::getOrderItems($sourceOrderId) as $item) {
            $availableQty = self::getAvailableQuantityForSourceItem((int) $item->id);
            if ($availableQty < self::getOrderMinimumQuantity()) {
                continue;
            }

            $unitPrice = self::normalizeOrderAmount($item->unit_price ?? 0);
            $lineBase = self::roundOrderValue($availableQty * $unitPrice);
            $discountAmount = self::roundOrderValue(min($lineBase, self::normalizeOrderAmount($item->discount_amount ?? 0)));

            $items[] = [
                'product_id' => (int) ($item->product_id ?? 0),
                'inventory_item_id' => (int) ($item->inventory_item_id ?? 0),
                'item_id' => (int) ($item->item_id ?? 0),
                'product_code' => (string) ($item->product_code ?? ''),
                'name' => (string) ($item->name ?? ''),
                'quantity' => $availableQty,
                'unit' => (string) ($item->unit ?? ''),
                'unit_price' => $unitPrice,
                'cost_price' => self::normalizeOrderAmount($item->cost_price ?? 0),
                'discount_amount' => $discountAmount,
                'tax_amount' => self::normalizeOrderAmount($item->tax_amount ?? 0),
                'subtotal' => self::roundOrderValue(max(0, $lineBase - $discountAmount)),
                'note' => (string) ($item->note ?? ''),
                'source_item_id' => (int) $item->id,
                'root_item_id' => !empty($item->root_item_id) ? (int) $item->root_item_id : (int) $item->id
            ];
        }

        return $items;
    }

    /**
     * Build order item snapshots from selected parts.
     *
     * @param array $items
     * @param array $existingProducts
     * @param float $taxRate
     *
     * @return array
     */
    public static function buildItemsFromParts(array $items, array $existingProducts = [], float $taxRate = 0): array
    {
        $db = \Kotchasan\DB::create();
        $normalized = [];
        $normalizedIndex = [];

        foreach ($items as $item) {
            $productCode = trim((string) ($item['product_code'] ?? $item['sku'] ?? $item['part_no'] ?? ''));
            if ($productCode === '') {
                continue;
            }

            $quantity = self::normalizeOrderQuantity($item['qty'] ?? $item['quantity'] ?? 1);
            $inventoryItemId = isset($item['inventory_item_id']) ? (int) $item['inventory_item_id'] : 0;
            $candidateKeys = [];
            if ($inventoryItemId > 0) {
                $candidateKeys[] = 'id:'.$inventoryItemId;
            }
            $candidateKeys[] = 'code:'.strtoupper($productCode);

            $normalizedKey = null;
            foreach ($candidateKeys as $candidateKey) {
                if (isset($normalizedIndex[$candidateKey])) {
                    $normalizedKey = $normalizedIndex[$candidateKey];
                    break;
                }
            }

            if ($normalizedKey === null) {
                $normalizedKey = $candidateKeys[0];
            }

            if (!isset($normalized[$normalizedKey])) {
                $normalized[$normalizedKey] = [
                    'item_id' => isset($item['item_id']) ? (int) $item['item_id'] : 0,
                    'inventory_item_id' => $inventoryItemId,
                    'product_code' => $productCode,
                    'name' => trim((string) ($item['name'] ?? '')),
                    'quantity' => 0.0,
                    'unit' => trim((string) ($item['unit'] ?? '')),
                    'unit_price' => isset($item['unit_price']) ? Text::toDouble($item['unit_price']) : (isset($item['price']) ? Text::toDouble($item['price']) : null),
                    'cost_price' => isset($item['cost_price']) ? Text::toDouble($item['cost_price']) : null,
                    'discount_amount' => 0.0,
                    'note' => trim((string) ($item['note'] ?? $item['notes'] ?? ''))
                ];
            }

            foreach ($candidateKeys as $candidateKey) {
                $normalizedIndex[$candidateKey] = $normalizedKey;
            }

            $normalized[$normalizedKey]['quantity'] = self::roundOrderValue($normalized[$normalizedKey]['quantity'] + $quantity);
            if ($inventoryItemId > 0) {
                $normalized[$normalizedKey]['inventory_item_id'] = $inventoryItemId;
            }
            if (!empty($item['name'])) {
                $normalized[$normalizedKey]['name'] = trim((string) $item['name']);
            }
            if (!empty($item['unit'])) {
                $normalized[$normalizedKey]['unit'] = trim((string) $item['unit']);
            }
            if (isset($item['unit_price'])) {
                $normalized[$normalizedKey]['unit_price'] = self::normalizeOrderAmount($item['unit_price']);
            } elseif (isset($item['price'])) {
                $normalized[$normalizedKey]['unit_price'] = self::normalizeOrderAmount($item['price']);
            }
            if (isset($item['cost_price'])) {
                $normalized[$normalizedKey]['cost_price'] = self::normalizeOrderAmount($item['cost_price']);
            }
            if (isset($item['discount_amount'])) {
                $normalized[$normalizedKey]['discount_amount'] = self::roundOrderValue($normalized[$normalizedKey]['discount_amount'] + self::normalizeOrderAmount($item['discount_amount']));
            }
            if (!empty($item['note']) || !empty($item['notes'])) {
                $normalized[$normalizedKey]['note'] = trim((string) ($item['note'] ?? $item['notes']));
            }

            foreach ($candidateKeys as $candidateKey) {
                $normalizedIndex[$candidateKey] = $normalizedKey;
            }
        }

        $result = [];
        foreach ($normalized as $item) {
            $inventoryItemId = (int) ($item['inventory_item_id'] ?? 0);
            $inventoryItem = $inventoryItemId > 0
                ? \Inventory\Item\Model::resolve($inventoryItemId)
                : \Inventory\Item\Model::resolve(0, (string) $item['product_code']);
            if (!$inventoryItem) {
                continue;
            }
            $inventory = $db->first('inventory', ['id', (int) $inventoryItem->inventory_id]);

            $unitPrice = $item['unit_price'] !== null ? self::normalizeOrderAmount($item['unit_price']) : self::normalizeOrderAmount($inventoryItem->price ?? 0);
            $costPrice = $item['cost_price'] !== null ? self::normalizeOrderAmount($item['cost_price']) : self::normalizeOrderAmount($inventory->cost ?? 0);
            $lineBase = self::roundOrderValue($item['quantity'] * $unitPrice);
            $discountAmount = self::roundOrderValue(min($lineBase, self::normalizeOrderAmount($item['discount_amount'])));
            $subtotal = self::roundOrderValue($lineBase - $discountAmount);
            $taxAmount = self::roundOrderValue($subtotal * max(0, $taxRate) / 100);

            $result[] = [
                'product_id' => (int) $inventoryItem->inventory_id,
                'inventory_item_id' => (int) ($inventoryItem->inventory_item_id ?? 0),
                'item_id' => (int) ($item['item_id'] ?? 0),
                'product_code' => (string) $inventoryItem->sku,
                'name' => $item['name'] !== '' ? $item['name'] : (string) ($inventory->topic ?? $inventoryItem->sku),
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'unit' => $item['unit'] !== '' ? $item['unit'] : (string) ($inventoryItem->unit ?? ''),
                'cost_price' => $costPrice,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'subtotal' => $subtotal,
                'note' => $item['note']
            ];
        }

        return $result;
    }

    /**
     * Save order (create or update)
     *
     * @param array  $data      Order data
     * @param array  $items     Order items
     * @param int    $changedBy Who made the change
     *
     * @return int Order ID
     */
    public static function save($data, $items = null, $changedBy = null)
    {
        $db = \Kotchasan\DB::create();
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $now = date('Y-m-d H:i:s');
        $previousOrder = $id > 0 ? $db->first('order', ['id', $id]) : null;

        $requestedDocumentType = trim((string) ($data['document_type'] ?? ''));
        $data['document_type'] = self::normalizeDocumentType(
            $requestedDocumentType !== '' ? $requestedDocumentType : ($previousOrder->document_type ?? 'QT'),
            'QT'
        );
        $data['document_status'] = self::normalizeDocumentStatus($data['document_status'] ?? 'issued', 'issued');
        if (empty($data['issued_at'])) {
            $data['issued_at'] = $now;
        }

        $data['payment_status'] = 'paid';
        $data['paid_at'] = $now;

        // Remove items from data
        unset($data['items']);

        $db->beginTransaction();

        try {
            if (empty($id)) {
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                $data['member_id'] = $changedBy;
                $data['id'] = $db->nextId('order');
                // New order
                if (empty($data['order_no'])) {
                    $data['order_no'] = self::generateOrderNo($data);
                }
                if (empty($data['root_document_id'])) {
                    $data['root_document_id'] = $data['id'];
                }
                $db->insert('order', $data);
                $id = $data['id'];
            } else {
                // Update order
                $data['updated_at'] = $now;
                if ($previousOrder) {
                    $previousOrder = self::hydrateDocumentState($previousOrder);
                }

                if ($previousOrder && $previousOrder->document_type !== $data['document_type']) {
                    $data['reference_document_no'] = $previousOrder->order_no;
                    $data['order_no'] = self::generateOrderNo($data);
                } else {
                    $data['order_no'] = $previousOrder->order_no ?? ($data['order_no'] ?? null);
                }

                if ($previousOrder && empty($data['root_document_id'])) {
                    $data['root_document_id'] = !empty($previousOrder->root_document_id) ? (int) $previousOrder->root_document_id : $id;
                }

                unset($data['id'], $data['created_at']);
                $db->update('order', ['id', $id], $data);
            }

            // Save items if provided
            if ($items !== null) {
                $db->delete('order_item', ['order_id', $id]);

                foreach ($items as $item) {
                    $item['order_id'] = $id;
                    $db->insert('order_item', $item);
                }
            }

            self::syncInventoryForOrder($id, $changedBy, $db);

            if ($id > 0 && empty($previousOrder) && empty($data['root_document_id'])) {
                $db->update('order', ['id', $id], ['root_document_id' => $id]);
            }

            $db->commit();

            return $id;
        } catch (\Throwable $e) {
            $db->rollback();

            throw $e;
        }
    }

    /**
     * Rebuild inventory posting for the current order contents.
     *
     * @param int                $orderId
     * @param int|null           $changedBy
     * @param \Kotchasan\DB|null $db
     *
     * @return void
     */
    private static function syncInventoryForOrder(int $orderId, $changedBy = null,  ? \Kotchasan\DB $db = null) : void
    {
        if ($orderId < 1) {
            return;
        }

        $db = $db ?: \Kotchasan\DB::create();
        $order = $db->first('order', ['id', $orderId]);
        if (!$order) {
            return;
        }

        self::reverseInventoryForOrder($db, $orderId);

        $direction = \Order\Helper\Model::getStockDirection($order->document_type ?? '');
        if ($direction === '') {
            return;
        }
        if (!self::shouldPostInventoryForOrder($db, $order, $direction)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $referenceNo = (string) ($order->order_no ?? 'ORDER-'.$orderId);
        $movementType = $direction === 'out' ? 'sale' : 'purchase';

        foreach (self::getOrderItems($orderId, $db) as $item) {
            $inventoryId = (int) (is_array($item) ? ($item['product_id'] ?? 0) : ($item->product_id ?? 0));
            $inventoryItemId = (int) (is_array($item) ? ($item['inventory_item_id'] ?? 0) : ($item->inventory_item_id ?? 0));
            $sku = trim((string) (is_array($item) ? ($item['product_code'] ?? '') : ($item->product_code ?? '')));
            $quantity = self::roundOrderValue(max(0, (float) (is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0))));
            $referenceItemId = (int) (is_array($item) ? ($item['id'] ?? 0) : ($item->id ?? 0));
            if ($inventoryId < 1 || $sku === '' || $quantity <= 0) {
                continue;
            }

            self::updateInventoryItemStock($db, $inventoryId, $sku, $direction === 'out' ? -$quantity : $quantity, $inventoryItemId);

            if ($direction === 'out') {
                \Inventory\Posting\Model::recordIssue(
                    $db,
                    $inventoryId,
                    $sku,
                    $quantity,
                    $movementType,
                    'order',
                    $referenceNo,
                    'Order '.$referenceNo,
                    $changedBy !== null ? (int) $changedBy : null,
                    $timestamp,
                    $orderId,
                    $referenceItemId > 0 ? $referenceItemId : null,
                    0,
                    $inventoryItemId > 0 ? $inventoryItemId : null
                );
            } else {
                \Inventory\Posting\Model::recordReceipt(
                    $db,
                    $inventoryId,
                    $sku,
                    $quantity,
                    (float) ($item->cost_price ?? $item->unit_price ?? 0),
                    $movementType,
                    'order',
                    $referenceNo,
                    'Order '.$referenceNo,
                    $changedBy !== null ? (int) $changedBy : null,
                    $timestamp,
                    $orderId,
                    $referenceItemId > 0 ? $referenceItemId : null,
                    0,
                    $inventoryItemId > 0 ? $inventoryItemId : null
                );
            }
        }
    }

    /**
     * Post stock only for the first active document in a chain with the same stock direction.
     *
     * @param \Kotchasan\DB $db
     * @param object        $order
     * @param string        $direction
     *
     * @return bool
     */
    private static function shouldPostInventoryForOrder(\Kotchasan\DB $db, object $order, string $direction): bool
    {
        if (self::normalizeDocumentStatus($order->document_status ?? 'issued', 'issued') === 'cancelled') {
            return false;
        }

        $seen = [];
        $sourceId = (int) ($order->source_document_id ?? 0);
        while ($sourceId > 0 && !isset($seen[$sourceId])) {
            $seen[$sourceId] = true;
            $source = $db->first('order', ['id', $sourceId]);
            if (!$source) {
                break;
            }

            if (
                self::normalizeDocumentStatus($source->document_status ?? 'issued', 'issued') !== 'cancelled'
                && \Order\Helper\Model::getStockDirection($source->document_type ?? '') === $direction
            ) {
                return false;
            }

            $sourceId = (int) ($source->source_document_id ?? 0);
        }

        return true;
    }

    /**
     * Reverse existing order inventory movement before rebuilding it.
     *
     * @param \Kotchasan\DB $db
     * @param int           $orderId
     *
     * @return void
     */
    private static function reverseInventoryForOrder(\Kotchasan\DB $db, int $orderId): void
    {
        $movements = static::createQuery()
            ->select('inventory_id', 'inventory_item_id', 'sku', 'movement_direction', 'quantity')
            ->from('inventory_stock_movement')
            ->where([
                ['reference_type', 'order'],
                ['reference_id', $orderId]
            ])
            ->fetchAll();

        foreach ($movements as $movement) {
            $direction = (string) ($movement->movement_direction ?? '');
            $quantity = (float) ($movement->quantity ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            self::updateInventoryItemStock(
                $db,
                (int) ($movement->inventory_id ?? 0),
                (string) ($movement->sku ?? ''),
                $direction === 'out' ? $quantity : -$quantity,
                (int) ($movement->inventory_item_id ?? 0)
            );
            self::updateInventoryStockAggregate(
                $db,
                (int) ($movement->inventory_id ?? 0),
                (string) ($movement->sku ?? ''),
                0,
                $direction === 'out' ? $quantity : -$quantity,
                (int) ($movement->inventory_item_id ?? 0)
            );
        }

        $allocations = static::createQuery()
            ->select('layer_id', 'quantity')
            ->from('inventory_cost_allocation')
            ->where([
                ['reference_type', 'order'],
                ['reference_id', $orderId]
            ])
            ->fetchAll();

        foreach ($allocations as $allocation) {
            $layerId = (int) ($allocation->layer_id ?? 0);
            $quantity = (float) ($allocation->quantity ?? 0);
            if ($layerId < 1 || $quantity <= 0) {
                continue;
            }
            $layer = $db->first('inventory_cost_layer', ['id', $layerId]);
            if ($layer) {
                $db->update('inventory_cost_layer', ['id', $layerId], [
                    'remaining_qty' => self::roundOrderValue((float) ($layer->remaining_qty ?? 0) + $quantity)
                ]);
            }
        }

        $blockedLayers = static::createQuery()
            ->select('A.id')
            ->from('inventory_cost_allocation A')
            ->join('inventory_cost_layer L', ['L.id', 'A.layer_id'], 'INNER')
            ->where([
                ['L.reference_type', 'order'],
                ['L.reference_id', $orderId],
                ['A.reference_type', '!=', 'order']
            ])
            ->limit(1)
            ->first();
        if ($blockedLayers) {
            throw new \RuntimeException('This order receipt has stock layers already consumed by another document');
        }

        $db->delete('inventory_cost_allocation', [
            ['reference_type', 'order'],
            ['reference_id', $orderId]
        ], 0);
        $db->delete('inventory_cost_layer', [
            ['reference_type', 'order'],
            ['reference_id', $orderId]
        ], 0);
        $db->delete('inventory_stock_movement', [
            ['reference_type', 'order'],
            ['reference_id', $orderId]
        ], 0);
    }

    /**
     * @param \Kotchasan\DB $db
     * @param int           $inventoryId
     * @param string        $sku
     * @param float         $delta
     *
     * @return void
     */
    private static function updateInventoryItemStock(\Kotchasan\DB $db, int $inventoryId, string $sku, float $delta, int $inventoryItemId = 0): void
    {
        $sku = trim($sku);
        if ($inventoryId < 1 || $sku === '' || abs($delta) < 0.00005) {
            return;
        }

        $where = $inventoryItemId > 0
            ? ['id', $inventoryItemId]
            : [
            ['inventory_id', $inventoryId],
            ['sku', $sku]
        ];

        $item = $db->first('inventory_items', $where);
        if ($item) {
            $db->update('inventory_items', $where, [
                'stock' => self::roundOrderValue((float) ($item->stock ?? 0) + $delta)
            ]);
        }
    }

    /**
     * @param \Kotchasan\DB $db
     * @param int $inventoryId
     * @param string $sku
     * @param int $warehouseId
     * @param float $delta
     *
     * @return void
     */
    private static function updateInventoryStockAggregate(\Kotchasan\DB $db, int $inventoryId, string $sku, int $warehouseId, float $delta, int $inventoryItemId = 0): void
    {
        $sku = trim($sku);
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
                'qty' => self::roundOrderValue((float) ($stock->qty ?? 0) + $delta)
            ]);

            return;
        }

        $db->insert('inventory_stock', [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId > 0 ? $inventoryItemId : null,
            'sku' => $sku,
            'qty' => self::roundOrderValue($delta),
            'reserved_qty' => 0
        ]);
    }

    /**
     * Reverse current stock posting for a refunded sales order.
     *
     * @param \Kotchasan\DB $db
     * @param object        $order
     *
     * @return void
     */
    private static function refundInventoryForOrder(\Kotchasan\DB $db, object $order): void
    {
        $orderId = (int) ($order->id ?? 0);
        if ($orderId < 1) {
            return;
        }

        if (\Order\Helper\Model::getStockDirection($order->document_type ?? '') !== 'out') {
            return;
        }

        self::reverseInventoryForOrder($db, $orderId);
    }

    /**
     * Update payment status
     *
     * @param int    $orderId
     * @param string $newStatus
     *
     * @return bool
     */
    public static function updatePaymentStatus($orderId, $newStatus)
    {
        $db = \Kotchasan\DB::create();
        $order = $db->first('order', ['id', $orderId]);
        if (!$order) {
            return false;
        }

        $update = [
            'payment_status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($newStatus === 'paid') {
            $update['paid_at'] = date('Y-m-d H:i:s');
            $update['paid_amount'] = $order->total;
        }

        $db->update('order', ['id', $orderId], $update);

        return true;
    }

    /**
     * Calculate order totals from items
     *
     * @param int                $orderId
     * @param \Kotchasan\DB|null $db
     *
     * @return array totals
     */
    public static function recalculateTotals($orderId,  ? \Kotchasan\DB $db = null)
    {
        if ($db === null) {
            $items = static::createQuery()
                ->select('quantity', 'unit_price', 'discount_amount', 'tax_amount', 'subtotal')
                ->from('order_item')
                ->where(['order_id', $orderId])
                ->fetchAll();
            $db = \Kotchasan\DB::create();
        } else {
            $items = $db->select('order_item', ['order_id', $orderId], [], ['quantity', 'unit_price', 'discount_amount', 'tax_amount', 'subtotal']);
        }

        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $itemSubtotal = (float) (is_array($item) ? ($item['subtotal'] ?? 0) : ($item->subtotal ?? 0));
            $itemDiscount = (float) (is_array($item) ? ($item['discount_amount'] ?? 0) : ($item->discount_amount ?? 0));
            $itemTax = (float) (is_array($item) ? ($item['tax_amount'] ?? 0) : ($item->tax_amount ?? 0));
            $subtotal = self::roundOrderValue($subtotal + $itemSubtotal);
            $totalDiscount = self::roundOrderValue($totalDiscount + $itemDiscount);
            $totalTax = self::roundOrderValue($totalTax + $itemTax);
        }

        $order = $db->first('order', ['id', $orderId]);

        $shippingCost = $order ? self::normalizeOrderAmount($order->shipping_cost) : 0.0;
        $orderDiscount = $order ? self::normalizeOrderAmount($order->discount_amount) : 0.0;
        $total = self::roundOrderValue(max(0, $subtotal - $orderDiscount + $totalTax + $shippingCost));

        $db->update('order', ['id', $orderId], [
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'total' => $total,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => self::roundOrderValue($orderDiscount + $totalDiscount),
            'tax_amount' => $totalTax,
            'shipping_cost' => $shippingCost,
            'total' => $total
        ];
    }
}
