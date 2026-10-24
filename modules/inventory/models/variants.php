<?php
/**
 * @filesource modules/inventory/models/variants.php
 *
 * แบบของสินค้าหนึ่งตัว (variant = กองสต๊อก) — ตารางในหน้าสินค้า
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Variants;

/**
 * Model ตารางแบบสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query สำหรับตารางแบบของสินค้าเดียวกัน (รวมแบบที่ปิดการใช้งานแล้ว)
     *
     * @param array $params product_id
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        return static::createQuery()
            ->select('V.id', 'V.sku', 'V.option1', 'V.option2', 'V.option3', 'V.topic', 'V.price', 'V.cost',
                'V.stock', 'V.is_active', 'V.sort_order', 'P.count_stock', 'P.unit')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.product_id', (int) $params['product_id']]);
    }

    /**
     * id ของสินค้าที่ variant หนึ่งสังกัด
     *
     * @param int $variantId
     *
     * @return int 0 = ไม่พบ
     */
    public static function productId($variantId)
    {
        $row = static::createDB()->first(\Inventory\Base\Model::table('inventory'), ['id' => (int) $variantId]);

        return $row ? (int) $row->product_id : 0;
    }
}
