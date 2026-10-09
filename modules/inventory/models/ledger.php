<?php
/**
 * @filesource modules/inventory/models/ledger.php
 *
 * สมุดบัญชีสต๊อกและชั้นต้นทุน — "ความจริง" ของระบบสต๊อกทั้งระบบ
 *
 * ⚠️ ก่อนมีไฟล์นี้ ระบบมีสมุดบัญชีแต่ไม่มีทางเปิดดู : ยอดคงเหลือที่เห็นในทะเบียน
 * สินค้าเป็นแค่ cache ถ้ามันเพี้ยน ไม่มีใครหาสาเหตุได้เลยเพราะมองไม่เห็นรายการ
 * ที่ทำให้มันเพี้ยน — ตัวเลขที่ตรวจสอบไม่ได้ก็คือตัวเลขที่เชื่อไม่ได้
 *
 * หน้า /inventory-stock เดิมดูได้ทีละสินค้า ไฟล์นี้ทำให้ดูข้ามสินค้าได้
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Ledger;

use Inventory\Base\Model as Base;
use Kotchasan\Language;

/**
 * Model สมุดบัญชีสต๊อก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query ของสมุดบัญชีสต๊อก (ทุกสินค้า)
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function movements($params = [])
    {
        $where = [];
        foreach ([
            'inventory_id' => 'M.inventory_id',
            'movement_direction' => 'M.movement_direction',
            'movement_type' => 'M.movement_type',
            'reference_type' => 'M.reference_type'
        ] as $key => $column) {
            if (!empty($params[$key])) {
                $where[] = [$column, $params[$key]];
            }
        }
        // ช่วงวันที่ — ค่าเริ่มต้นของหน้าคือเดือนปัจจุบัน สมุดบัญชียาวเป็นแสนแถวได้
        if (!empty($params['from'])) {
            $where[] = ['M.occurred_at', '>=', $params['from'].' 00:00:00'];
        }
        if (!empty($params['to'])) {
            $where[] = ['M.occurred_at', '<=', $params['to'].' 23:59:59'];
        }

        $query = static::createQuery()
            ->select('M.id', 'M.inventory_id', 'M.inventory_item_id', 'M.sku',
                'M.movement_direction', 'M.movement_type', 'M.reference_type',
                'M.reference_id', 'M.reference_no', 'M.quantity', 'M.unit_cost',
                'M.total_cost', 'M.occurred_at', 'M.note')
            // ห้าม select V.topic ตรง ๆ คู่กับคอลัมน์ชื่อเดียวกัน — ตั้งชื่อใหม่เสมอ
            ->selectRaw('V.`topic` AS `product_topic`')
            ->from('inventory_stock_movement M')
            ->join('inventory V', ['V.id', 'M.inventory_id'], 'LEFT')
            ->where($where);

        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->whereNested(function ($q) use ($needle) {
                $q->where([['V.topic', 'LIKE', $needle]])
                    ->orWhere([['M.sku', 'LIKE', $needle]])
                    ->orWhere([['M.reference_no', 'LIKE', $needle]]);
            });
        }

        return $query;
    }

    /**
     * Query ของชั้นต้นทุน FIFO
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function costLayers($params = [])
    {
        $where = [];
        if (!empty($params['inventory_id'])) {
            $where[] = ['L.inventory_id', (int) $params['inventory_id']];
        }
        // ชั้นที่ตัดหมดแล้วยังต้องเก็บไว้ (เป็นหลักฐานของต้นทุนที่เคยตัดไป)
        // แต่คนดูส่วนใหญ่อยากเห็นเฉพาะชั้นที่ยังมีของ
        if (!empty($params['remaining']) && $params['remaining'] === 'open') {
            $where[] = ['L.remaining_qty', '>', 0];
        } elseif (!empty($params['remaining']) && $params['remaining'] === 'closed') {
            $where[] = ['L.remaining_qty', '<=', 0];
        }

        $query = static::createQuery()
            ->select('L.id', 'L.inventory_id', 'L.inventory_item_id', 'L.sku',
                'L.reference_type', 'L.reference_no', 'L.received_qty', 'L.remaining_qty',
                'L.unit_cost', 'L.currency', 'L.received_at')
            ->selectRaw('V.`topic` AS `product_topic`')
            ->selectRaw('L.`remaining_qty` * L.`unit_cost` AS `remaining_value`')
            ->from('inventory_cost_layer L')
            ->join('inventory V', ['V.id', 'L.inventory_id'], 'LEFT')
            ->where($where);

        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->whereNested(function ($q) use ($needle) {
                $q->where([['V.topic', 'LIKE', $needle]])
                    ->orWhere([['L.sku', 'LIKE', $needle]])
                    ->orWhere([['L.reference_no', 'LIKE', $needle]]);
            });
        }

        return $query;
    }

    /**
     * ตัวเลือก "ชนิดการเคลื่อนไหว"
     *
     * ⚠️ อ่านจากทะเบียนกลาง ไม่ใช่ SELECT DISTINCT จากข้อมูลที่มีอยู่ — ไซต์ที่ยัง
     * ไม่เคยมีรายการชนิดนั้นต้องเลือกกรองได้เหมือนกัน และชนิดที่หลุดทะเบียนต้อง
     * ไม่โผล่มาเป็นตัวเลือกให้เข้าใจผิดว่าเป็นของที่รองรับ
     *
     * @return array
     */
    public static function movementTypeOptions()
    {
        $options = [['value' => '', 'text' => '{LNG_All}']];
        foreach (Base::movementTypes() as $type => $direction) {
            $options[] = ['value' => $type, 'text' => self::movementTypeLabel($type)];
        }

        return $options;
    }

    /**
     * ชื่อภาษาคนของชนิดการเคลื่อนไหวหนึ่งชนิด
     *
     * ⚠️ ที่เดียวในระบบที่แปลชื่อชนิด — หน้ารายการต่อสินค้า (controllers/stocks.php)
     * กับสมุดบัญชีรวมเรียกที่นี่เหมือนกัน ถ้าแยกกันแปล สองหน้าจะเรียกของอย่างเดียวกัน
     * คนละชื่อ แล้วคนอ่านจะนึกว่าเป็นคนละเรื่อง
     *
     * @param string $type
     *
     * @return string
     */
    public static function movementTypeLabel($type)
    {
        $labels = [
            'opening' => 'Opening balance',
            'purchase' => 'Purchase',
            'sale' => 'Sale',
            'return_in' => 'Return in',
            'return_out' => 'Return out',
            'borrow' => 'Borrow',
            'borrow_return' => 'Borrow return',
            'repair_out' => 'Repair out',
            'repair_in' => 'Repair in',
            'adjust_in' => 'Adjust in',
            'adjust_out' => 'Adjust out'
        ];
        $direction = Base::movementDirection($type);

        // ชนิดที่ไม่อยู่ในทะเบียนต้องแสดงชื่อดิบ ไม่ใช่หายไปเฉย ๆ — ข้อมูลเก่าของ
        // ไซต์ที่อัปเกรดมาอาจมีชนิดที่เลิกใช้แล้วค้างอยู่ และมันต้องยังอ่านออก
        $text = isset($labels[$type]) ? Language::get($labels[$type]) : $type;

        return $text.' ('.($direction === 'out'
            ? Language::get('Stock out') : Language::get('Stock in')).')';
    }

    /**
     * ตัวเลือก "ที่มา" — อ่านจากข้อมูลจริง เพราะโมดูลอื่นเติมที่มาใหม่ได้เอง
     *
     * @return array
     */
    public static function referenceTypeOptions()
    {
        $rows = static::createQuery()
            ->select('reference_type')
            ->from('inventory_stock_movement')
            ->where([['reference_type', '!=', null], ['reference_type', '!=', '']])
            ->groupBy('reference_type')
            ->orderBy('reference_type')
            ->execute(null, 'array')
            ->fetchAll();

        $options = [['value' => '', 'text' => '{LNG_All}']];
        foreach ($rows as $row) {
            $options[] = ['value' => $row['reference_type'], 'text' => $row['reference_type']];
        }

        return $options;
    }

    /**
     * ตัวเลือก "ทิศทาง"
     *
     * @return array
     */
    public static function directionOptions()
    {
        return [
            ['value' => '', 'text' => '{LNG_All}'],
            ['value' => 'in', 'text' => '{LNG_Received}'],
            ['value' => 'out', 'text' => '{LNG_Issued}']
        ];
    }

    /**
     * ตัวเลือก "สินค้า"
     *
     * @return array
     */
    public static function productOptions()
    {
        $rows = static::createQuery()
            ->select('id', 'topic')
            ->from('inventory')
            ->where(['is_active', 1])
            ->orderBy('topic')
            ->execute(null, 'array')
            ->fetchAll();

        $options = [['value' => '', 'text' => '{LNG_All}']];
        foreach ($rows as $row) {
            $options[] = ['value' => (string) $row['id'], 'text' => $row['topic']];
        }

        return $options;
    }
}
