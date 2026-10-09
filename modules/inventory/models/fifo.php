<?php
/**
 * @filesource modules/inventory/models/fifo.php
 *
 * ต้นทุนแบบเข้าก่อนออกก่อน บนตาราง cost layer
 *
 * ⚠️ ของใหม่ทั้งหมด ไม่ใช่การย้ายของที่พิสูจน์แล้ว — ทั้ง oas และ oms ไม่เคยมี
 * ข้อมูลต้นทุนที่เดินจริงเลยสักแถว (oas cost_layer = 0, oms stock.used > 0 = 0)
 * ชุดทดสอบของโมดูลจึงต้องสร้างข้อมูลเองและเป็นด่านเดียวที่พิสูจน์ความถูกต้อง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Fifo;

use Inventory\Base\Model as Base;

/**
 * ตัวจัดการชั้นต้นทุน
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * สร้างชั้นต้นทุนใหม่จากการรับเข้าหนึ่งครั้ง
     *
     * @param array $movement แถวการเคลื่อนไหวที่เพิ่งบันทึก (ต้องมี id แล้ว)
     *
     * @return int id ของชั้นต้นทุนที่สร้าง
     */
    public static function receive(array $movement)
    {
        return (int) static::createDB()->insert(Base::table('inventory_cost_layer'), [
            'inventory_id' => $movement['inventory_id'],
            'inventory_item_id' => $movement['inventory_item_id'],
            'sku' => $movement['sku'],
            'movement_id' => $movement['id'],
            'reference_type' => $movement['reference_type'],
            'reference_id' => $movement['reference_id'],
            'reference_no' => $movement['reference_no'],
            'reference_item_id' => $movement['reference_item_id'],
            'received_qty' => $movement['quantity'],
            'remaining_qty' => $movement['quantity'],
            'unit_cost' => $movement['unit_cost'] === null ? 0 : $movement['unit_cost'],
            'currency' => 'THB',
            'note' => $movement['note'],
            'received_at' => $movement['occurred_at'],
            'created_by' => $movement['created_by'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * ตัดของออกจากชั้นต้นทุนที่เก่าที่สุดก่อน
     *
     * ของที่ตัดออกเกินกว่าที่มีชั้นต้นทุนรองรับ (สต๊อกติดลบ หรือยอดเปิดไม่ได้ตั้ง)
     * จะไม่มีต้นทุนให้คิด — คืนต้นทุนเท่าที่ตัดได้จริง และบันทึกส่วนที่ขาดไว้ใน
     * หมายเหตุของ allocation แถวสุดท้าย ไม่ใช่เงียบไปเฉย ๆ
     *
     * @param array $movement แถวการเคลื่อนไหวที่เพิ่งบันทึก (ต้องมี id แล้ว)
     *
     * @return array [ต้นทุนรวม, ต้นทุนต่อหน่วย, จำนวนที่ไม่มีชั้นต้นทุนรองรับ]
     */
    public static function consume(array $movement)
    {
        $db = static::createDB();
        $tableLayer = Base::table('inventory_cost_layer');
        $tableAllocation = Base::table('inventory_cost_allocation');
        $remain = (float) $movement['quantity'];
        $totalCost = 0.0;

        // ⚠️ ตัดจากชั้นของ "คลังนั้น" เท่านั้น — ต้นทุนต้องตามของจริง ของที่ซื้อ
        // เข้าคลัง ก ราคา 100 ไม่ควรถูกใช้เป็นต้นทุนของที่ขายออกจากคลัง ข
        // ที่ซื้อมา 120 ไม่งั้นกำไรของแต่ละสาขาจะเพี้ยนสลับกัน
        $layers = static::createQuery()
            ->select('id', 'unit_cost', 'remaining_qty')
            ->from('inventory_cost_layer')
            ->where([
                ['inventory_id', $movement['inventory_id']],
                ['inventory_item_id', $movement['inventory_item_id']],
                ['remaining_qty', '>', 0]
            ])
            ->orderBy('received_at')
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();

        foreach ($layers as $layer) {
            if ($remain <= 0) {
                break;
            }
            $take = min($remain, (float) $layer['remaining_qty']);
            $cost = $take * (float) $layer['unit_cost'];
            $db->insert($tableAllocation, [
                'layer_id' => $layer['id'],
                'inventory_id' => $movement['inventory_id'],
                'inventory_item_id' => $movement['inventory_item_id'],
                    'sku' => $movement['sku'],
                'movement_id' => $movement['id'],
                'reference_type' => $movement['reference_type'],
                'reference_id' => $movement['reference_id'],
                'reference_no' => $movement['reference_no'],
                'reference_item_id' => $movement['reference_item_id'],
                'quantity' => $take,
                'unit_cost' => $layer['unit_cost'],
                'total_cost' => $cost,
                'note' => $movement['note'],
                'created_by' => $movement['created_by'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $db->update($tableLayer, ['id', $layer['id']], [
                'remaining_qty' => (float) $layer['remaining_qty'] - $take
            ]);
            $totalCost += $cost;
            $remain -= $take;
        }

        $taken = (float) $movement['quantity'] - $remain;
        $unitCost = $taken > 0 ? $totalCost / $taken : 0.0;

        return [$totalCost, $unitCost, $remain];
    }

    /**
     * คืนของที่เคยตัดออกไปแล้ว กลับเข้าชั้นต้นทุนเดิม
     *
     * ใช้ตอนยกเลิกเอกสารที่ตัดสต๊อกไปแล้ว — ต้องคืนเข้าชั้นเดิมไม่ใช่สร้างชั้นใหม่
     * ไม่งั้นของชิ้นเดิมจะมีสองต้นทุนในระบบ และลำดับ FIFO ของที่เหลือจะเพี้ยน
     *
     * @param int $movementId การเคลื่อนไหวขาออกที่ต้องการกลับรายการ
     * @param int $newMovementId การเคลื่อนไหวขาเข้าที่สร้างขึ้นเพื่อกลับรายการ
     *
     * @return float ต้นทุนรวมที่คืนกลับ
     */
    public static function restore($movementId, $newMovementId)
    {
        $db = static::createDB();
        $tableLayer = Base::table('inventory_cost_layer');
        $tableAllocation = Base::table('inventory_cost_allocation');
        $total = 0.0;

        $allocations = static::createQuery()
            ->select('id', 'layer_id', 'inventory_id', 'inventory_item_id', 'sku',
                'quantity', 'unit_cost', 'reference_type', 'reference_id', 'reference_no', 'reference_item_id')
            ->from('inventory_cost_allocation')
            ->where([['movement_id', $movementId], ['quantity', '>', 0]])
            ->execute(null, 'array')
            ->fetchAll();

        foreach ($allocations as $allocation) {
            $layer = $db->first($tableLayer, ['id' => $allocation['layer_id']]);
            if (!$layer) {
                continue;
            }
            $db->update($tableLayer, ['id', $allocation['layer_id']], [
                'remaining_qty' => (float) $layer->remaining_qty + (float) $allocation['quantity']
            ]);
            // แถวหักล้างจำนวนติดลบ เพื่อให้ผลรวมของ allocation ยังบอกความจริงได้
            // และยังเห็นประวัติว่าเคยตัดไปแล้วคืนกลับเมื่อไร
            $db->insert($tableAllocation, [
                'layer_id' => $allocation['layer_id'],
                'inventory_id' => $allocation['inventory_id'],
                'inventory_item_id' => $allocation['inventory_item_id'],
                'sku' => $allocation['sku'],
                'movement_id' => $newMovementId,
                'source_allocation_id' => $allocation['id'],
                'reference_type' => $allocation['reference_type'],
                'reference_id' => $allocation['reference_id'],
                'reference_no' => $allocation['reference_no'],
                'reference_item_id' => $allocation['reference_item_id'],
                'quantity' => -1 * (float) $allocation['quantity'],
                'unit_cost' => $allocation['unit_cost'],
                'total_cost' => -1 * (float) $allocation['quantity'] * (float) $allocation['unit_cost'],
                'note' => 'กลับรายการของการเคลื่อนไหว '.(int) $movementId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $total += (float) $allocation['quantity'] * (float) $allocation['unit_cost'];
        }

        return $total;
    }

    /**
     * ถอนชั้นต้นทุนที่เกิดจากการรับเข้าครั้งหนึ่ง (ใช้ตอนยกเลิกใบรับสินค้า)
     *
     * ถ้าของในชั้นนั้นถูกตัดออกไปแล้วบางส่วน จะถอนได้เท่าที่ยังเหลือ ส่วนที่ขายไป
     * แล้วถอนไม่ได้ — คืนจำนวนที่ถอนไม่ได้กลับไปให้ผู้เรียกตัดสินใจ
     *
     * @param int $movementId การเคลื่อนไหวขาเข้าที่ต้องการกลับรายการ
     * @param float $quantity จำนวนที่ต้องการถอน
     *
     * @return float จำนวนที่ถอนไม่ได้ (0 = ถอนได้ครบ)
     */
    public static function withdraw($movementId, $quantity)
    {
        $db = static::createDB();
        $tableLayer = Base::table('inventory_cost_layer');
        $remain = (float) $quantity;

        $layers = static::createQuery()
            ->select('id', 'remaining_qty')
            ->from('inventory_cost_layer')
            ->where([['movement_id', $movementId], ['remaining_qty', '>', 0]])
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();

        foreach ($layers as $layer) {
            if ($remain <= 0) {
                break;
            }
            $take = min($remain, (float) $layer['remaining_qty']);
            $db->update($tableLayer, ['id', $layer['id']], [
                'remaining_qty' => (float) $layer['remaining_qty'] - $take
            ]);
            $remain -= $take;
        }

        return $remain;
    }

    /**
     * มูลค่าคงเหลือของสินค้าหนึ่ง คิดจากชั้นต้นทุนที่ยังไม่ถูกตัด
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     *
     * @return array [จำนวนคงเหลือ, มูลค่ารวม, ต้นทุนเฉลี่ยต่อหน่วย]
     */
    public static function value($inventoryId, $inventoryItemId = 0)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`remaining_qty`) AS `qty`')
            ->selectRaw('SUM(`remaining_qty` * `unit_cost`) AS `amount`')
            ->from('inventory_cost_layer')
            ->where([['inventory_id', $inventoryId], ['inventory_item_id', $inventoryItemId]])
            ->execute(null, 'array')
            ->fetchAll();

        $qty = empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
        $amount = empty($row) || $row[0]['amount'] === null ? 0.0 : (float) $row[0]['amount'];

        return [$qty, $amount, $qty > 0 ? $amount / $qty : 0.0];
    }

    /**
     * มูลค่าคงเหลือรวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย)
     *
     * ⚠️ value() กรองด้วย inventory_item_id เสมอ (ปริยาย 0) เรียกด้วย id ของ
     * สินค้าเฉย ๆ จะได้เฉพาะชั้นต้นทุนของหน่วยย่อย 0 ไม่ใช่ทั้งสินค้า
     *
     * @param int $inventoryId
     *
     * @return array [จำนวน, มูลค่า, ต้นทุนเฉลี่ยต่อหน่วย]
     */
    public static function totalValue($inventoryId)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`remaining_qty`) AS `qty`')
            ->selectRaw('SUM(`remaining_qty` * `unit_cost`) AS `amount`')
            ->from('inventory_cost_layer')
            ->where(['inventory_id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();

        $qty = empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
        $amount = empty($row) || $row[0]['amount'] === null ? 0.0 : (float) $row[0]['amount'];

        return [$qty, $amount, $qty > 0 ? $amount / $qty : 0.0];
    }
}
