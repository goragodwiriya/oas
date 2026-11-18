<?php
/**
 * @filesource modules/inventory/models/inventories.php
 *
 * ทะเบียนสินค้า — บรรทัดละสินค้า (รวมที่ปิดการใช้งานแล้ว) · แบบของสินค้าอยู่ในหน้าสินค้า
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Inventories;

/**
 * Model ทะเบียนสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query สำหรับตารางทะเบียนสินค้า
     *
     * บรรทัดละสินค้า แบบ e-commerce : จำนวนแบบ · ช่วงราคา · ยอดรวมทุกแบบ
     * ต่างจาก Inventory\Products\Model (รายการขาย) ที่แตกตามหน่วยขาย และแสดงของที่ปิดแล้วด้วย
     * แถวมี variant_id = แบบแรกของสินค้า ให้ปุ่มแก้ไขเปิดหน้าสินค้า (หน้าย่อยทุกหน้าใช้ id ของแบบ)
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];
        if (isset($params['category_id']) && $params['category_id'] !== '') {
            $where[] = ['P.category_id', (string) $params['category_id']];
        }
        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $where[] = ['P.is_active', (int) $params['is_active']];
        }
        if (isset($params['count_stock']) && $params['count_stock'] !== '') {
            $where[] = ['P.count_stock', (int) $params['count_stock']];
        }

        $query = static::createQuery()
            ->select('P.id', 'P.product_code', 'P.topic', 'P.category_id', 'P.unit', 'P.count_stock', 'P.is_active')
            ->selectRaw('MIN(V.`id`) AS `variant_id`')
            ->selectRaw('COUNT(V.`id`) AS `variants`')
            ->selectRaw('MIN(V.`price`) AS `price`')
            ->selectRaw('MAX(V.`price`) AS `price_max`')
            ->selectRaw('SUM(V.`stock`) AS `stock`')
            ->from('inventory_product P')
            ->join('inventory V', ['V.product_id', 'P.id'], 'LEFT')
            ->where($where);

        // ค้นได้ทั้งชื่อ รหัสสินค้า และ sku ของแบบใดแบบหนึ่ง
        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['P.topic', 'LIKE', $search],
                ['P.product_code', 'LIKE', $search],
                ['V.sku', 'LIKE', $search]
            ], 'OR');
        }

        return $query->groupBy('P.id');
    }

    /**
     * โหมดการนับสต๊อกทั้งสามแบบ
     *
     * ประกาศไว้ที่เดียว ทั้งฟอร์มสินค้า ตัวกรองของตาราง และคำอธิบายใช้ชุดนี้
     *
     * @return array
     */
    public static function countStockModes()
    {
        return [
            0 => '{LNG_Not counted}',
            1 => '{LNG_Counted together}',
            2 => '{LNG_Counted separately}'
        ];
    }
}
