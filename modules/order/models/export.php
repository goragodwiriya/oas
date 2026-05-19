<?php
/**
 * @filesource modules/order/models/export.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Export;

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
        return round((float) $value, self::getOrderValueDecimals());
    }

    /**
     * @param mixed $value
     */
    private static function normalizeOrderAmount($value): float
    {
        return self::roundOrderValue(max(0, (float) $value));
    }

    /**
     * Get order details with items, payments, status log
     *
     * @param int $id Order ID
     *
     * @return object|null
     */
    public static function get($id)
    {
        if (empty($id)) {
            // Return empty template for new order
            return (object) [
                'id' => 0,
                'order_no' => '',
                'payment_status' => 'paid',
                'customer_id' => null,
                'customer' => ['value' => '', 'text' => ''],
                'member_id' => null,
                'customer_name' => '',
                'customer_phone' => '',
                'customer_tax_id' => '',
                'subtotal' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'tax_rate' => 7,
                'total' => 0,
                'paid_amount' => 0,
                'change_amount' => 0,
                'currency' => 'THB',
                'payment_method' => '',
                'note' => '',
                'internal_note' => '',
                'items' => []
            ];
        }

        // Get order
        $order = static::createQuery()
            ->select('O.*', 'U.name AS salesperson_name')
            ->from('order O')
            ->join('user U', ['U.id', 'O.member_id'], 'LEFT')
            ->where(['O.id', $id])
            ->first();

        if (!$order) {
            return null;
        }

        $order->salesperson_name = (string) ($order->salesperson_name ?? '');

        // Get order items
        $order->items = static::createQuery()
            ->select(
                'OI.id',
                'OI.product_id',
                'OI.item_id',
                'OI.product_code',
                'OI.name',
                'OI.quantity',
                'OI.unit',
                'OI.unit_price',
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
            $item->qty = self::roundOrderValue($item->quantity ?? 0);
            $item->price = self::normalizeOrderAmount($item->unit_price ?? 0);
            $item->total = self::normalizeOrderAmount($item->subtotal ?? 0);
        }

        return $order;
    }

    /**
     * @param object|null $record
     *
     * @return string
     */
    private static function formatAddress($record): string
    {
        if (!is_object($record)) {
            return '';
        }

        $lines = [];
        $address = trim((string) ($record->address ?? ''));
        if ($address !== '') {
            $lines[] = $address;
        }

        $country = strtoupper(trim((string) ($record->country ?? 'TH')));
        $locality = trim(implode(' ', array_filter([
            trim((string) ($record->province ?? '')),
            trim((string) ($record->zipcode ?? '')),
            $country === 'TH' ? '' : $country
        ])));
        if ($locality !== '') {
            $lines[] = $locality;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array $values
     */
    private static function firstText(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
