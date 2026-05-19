<?php
/**
 * @filesource modules/order/models/search.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Search;

use Kotchasan\Currency;
use Order\Helper\Model as OrderHelper;

/**
 * Order Search Model
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Search parts by keyword and/or spec filters
     *
     * @param string $search
     * @param int    $limit
     *
     * @return array
     */
    public static function search($search, $limit = 20): array
    {
        $query = static::createQuery()
            ->select('I.id inventory_item_id', 'I.inventory_id product_id', 'I.sku', 'I.barcode', 'I.unit', 'I.price', 'V.product_code', 'V.topic')
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id']);

        if (strlen($search) > 1) {
            $search = '%'.$search.'%';
            $query->where([
                ['I.sku', 'LIKE', $search],
                ['I.barcode', 'LIKE', $search],
                ['V.product_code', 'LIKE', $search],
                ['V.topic', 'LIKE', $search]
            ], 'OR');
        }

        $query->orderBy('V.topic')
            ->orderBy('I.sku')
            ->limit($limit);

        $services = [];
        foreach ($query->fetchAll() as $product) {
            $services[] = [
                'value' => (string) ($product->sku ?? ''),
                'text' => (string) ($product->sku ?? '').' - '.$product->topic.' ('.Currency::format($product->price, OrderHelper::getValueDecimals()).')',
                'inventory_item_id' => (int) ($product->inventory_item_id ?? 0),
                'product_id' => (int) ($product->product_id ?? 0),
                'product_code' => (string) ($product->product_code ?? ''),
                'sku' => (string) ($product->sku ?? ''),
                'barcode' => (string) ($product->barcode ?? ''),
                'unit' => (string) ($product->unit ?? ''),
                'price' => (float) ($product->price ?? 0)
            ];
        }

        return $services;
    }

    /**
     * Get single part item by code
     *
     * @param string $code
     *
     * @return array|null

     */
    public static function get($code)
    {
        $part = \Inventory\Item\Model::resolve(0, (string) $code);

        if (!$part) {
            return null;
        }

        return [
            'inventory_item_id' => (int) ($part->inventory_item_id ?? 0),
            'product_id' => (int) ($part->inventory_id ?? 0),
            'product_code' => (string) ($part->sku ?? ''),
            'name' => (string) ($part->topic ?? ''),
            'qty' => 1,
            'unit' => (string) ($part->unit ?? ''),
            'price' => (float) ($part->price ?? 0),
            'discount_amount' => 0,
            'total' => (float) ($part->price ?? 0),
            'note' => ''
        ];
    }
}
