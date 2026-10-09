<?php
/**
 * @filesource modules/inventory/models/inventories.php
 *
 * ทะเบียนสินค้า — หน้าตั้งค่ารายการสินค้าทั้งหมด (รวมที่ปิดการใช้งานแล้ว)
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
     * ต่างจาก Inventory\Products\Model ตรงที่หน้านี้แสดง "สินค้า" หนึ่งบรรทัด
     * ต่อหนึ่งรายการเสมอ ไม่แตกตามหน่วยย่อย และแสดงของที่ปิดการใช้งานแล้วด้วย
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];
        if (isset($params['category_id']) && $params['category_id'] !== '') {
            $where[] = ['V.category_id', (string) $params['category_id']];
        }
        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $where[] = ['V.is_active', (int) $params['is_active']];
        }
        if (isset($params['count_stock']) && $params['count_stock'] !== '') {
            $where[] = ['V.count_stock', (int) $params['count_stock']];
        }

        $query = static::createQuery()
            ->select('V.id', 'V.product_code', 'V.topic', 'V.category_id', 'V.unit',
                'V.price', 'V.cost', 'V.stock', 'V.count_stock', 'V.is_active')
            ->selectRaw('COUNT(I.`id`) AS `items`')
            ->from('inventory V')
            ->join('inventory_items I', ['I.inventory_id', 'V.id'], 'LEFT')
            ->groupBy('V.id');
        if (!empty($where)) {
            $query->where($where);
        }

        return $query;
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
