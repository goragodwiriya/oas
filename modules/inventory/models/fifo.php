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
     * ชั้นต้นทุนของใบรับที่ถูกถอนไม่ครบ เพราะของถูกตัดออกไปแล้ว (Phase 2.6 · T1–T4)
     *
     * [กุญแจ (reference + สินค้า + คลัง) => [id ของชั้น, ...]] — รอการรับเข้าใหม่ของเอกสาร
     * เดียวกันมารับช่วง (receive()) ถ้าจบงานแล้วยังค้าง = แก้/ยกเลิกใบรับที่ของถูกตัดไปแล้ว (finish())
     *
     * @var array
     */
    protected static $carry = [];

    /**
     * เริ่มงานที่ถอนแล้วลงใหม่ (บันทึก/ยกเลิก/ลบเอกสาร) — ล้างของค้างจากงานก่อนที่ล้มกลางทาง
     */
    public static function begin()
    {
        self::$carry = [];
    }

    /**
     * จบงานที่ถอนแล้วลงใหม่ — ยังมีชั้นที่ถอนไม่ครบและไม่มีใครรับช่วง = ปฏิเสธทั้งงาน
     *
     * เช่น แก้ใบรับเปลี่ยนเป็นสินค้าอื่น หรือยกเลิกใบรับ ทั้งที่ของล็อตนั้นถูกขายไปแล้ว — ถ้ายอม
     * สมุดบัญชีจะติดลบเท่าที่ขายไป แต่ชั้นต้นทุนยังนับของที่ขายไปแล้วเป็นของในคลัง (มูลค่าคลังเกินจริง)
     * ผู้เรียกต้องอยู่ใน transaction เพื่อให้ทั้งงานถูกถอนกลับ
     *
     * @throws \RuntimeException
     */
    public static function finish()
    {
        $carry = self::$carry;
        self::$carry = [];
        foreach ($carry as $layerIds) {
            $consumed = self::consumed($layerIds);
            if ($consumed > 0.00001) {
                $layer = static::createDB()->first(Base::table('inventory_cost_layer'), ['id' => (int) $layerIds[0]]);
                throw new \RuntimeException(self::productName($layer ? (int) $layer->inventory_id : 0)
                    .' ในเอกสารนี้ถูกตัดออกไปแล้ว '.self::number($consumed)
                    .' — แก้เป็นสินค้าอื่น ลบบรรทัด หรือยกเลิกเอกสารรับเข้าที่ของถูกตัดไปแล้วไม่ได้ ต้องยกเลิกใบที่ตัดออกก่อน');
            }
        }
    }

    /**
     * กุญแจของของล็อตหนึ่งในเอกสารหนึ่ง (ไม่ใช้ reference_item_id — บรรทัดอาจถูกสร้างใหม่ได้
     * และสินค้าเดียวกันซ้ำสองบรรทัดในเอกสารเดียวไม่ได้อยู่แล้ว)
     *
     * @param array|object $row
     *
     * @return string
     */
    protected static function carryKey($row)
    {
        $row = (array) $row;

        return implode('|', [(string) $row['reference_type'], (int) $row['reference_id'], (int) $row['inventory_id'],
            (int) $row['inventory_item_id'], (int) $row['warehouse_id']]);
    }

    /**
     * จำนวนสุทธิที่ถูกตัดออกจากชั้นต้นทุนชุดหนึ่ง (allocation ขาออก หักแถวที่คืนกลับแล้ว)
     *
     * @param array $layerIds
     *
     * @return float
     */
    protected static function consumed(array $layerIds)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`quantity`) AS `qty`')
            ->from('inventory_cost_allocation')
            ->where([['layer_id', array_map('intval', $layerIds)]])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * @param int $inventoryId
     *
     * @return string
     */
    protected static function productName($inventoryId)
    {
        $product = static::createDB()->first(Base::table('inventory'), ['id' => (int) $inventoryId]);

        return $product ? 'สินค้า '.$product->topic : 'สินค้า';
    }

    /**
     * @param float $value
     *
     * @return string
     */
    protected static function number($value)
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
    }
    /**
     * สร้างชั้นต้นทุนใหม่จากการรับเข้าหนึ่งครั้ง
     *
     * @param array $movement แถวการเคลื่อนไหวที่เพิ่งบันทึก (ต้องมี id แล้ว)
     *
     * @return int id ของชั้นต้นทุนที่สร้าง
     */
    public static function receive(array $movement)
    {
        // รับช่วงจากชั้นเดิมของเอกสารเดียวกันที่ถอนไม่ครบ (บันทึกใบรับซ้ำหลังของถูกขายไปแล้ว)
        // ส่วนที่ขายไปแล้วยังนับเป็น "ถูกตัด" ของชั้นใหม่ และ allocation ย้ายมาชี้ชั้นใหม่
        // — ใบขายคงต้นทุนเดิม (unit_cost ของ allocation ไม่เปลี่ยน) ยกเลิกใบขายทีหลังของคืนเข้าชั้นนี้
        $key = self::carryKey($movement);
        $consumed = 0.0;
        $carried = isset(self::$carry[$key]) ? self::$carry[$key] : [];
        if (!empty($carried)) {
            unset(self::$carry[$key]);
            $consumed = self::consumed($carried);
            if ($consumed > (float) $movement['quantity'] + 0.00001) {
                throw new \RuntimeException(self::productName($movement['inventory_id'])
                    .' ถูกตัดออกไปแล้ว '.self::number($consumed)
                    .' — รับเข้า '.self::number($movement['quantity']).' น้อยกว่าที่ตัดออกไปไม่ได้');
            }
            $consumed = max(0.0, $consumed);
        }
        $layerId = (int) static::createDB()->insert(Base::table('inventory_cost_layer'), [
            'inventory_id' => $movement['inventory_id'],
            'inventory_item_id' => $movement['inventory_item_id'],
            'warehouse_id' => $movement['warehouse_id'],
            'sku' => $movement['sku'],
            'movement_id' => $movement['id'],
            'reference_type' => $movement['reference_type'],
            'reference_id' => $movement['reference_id'],
            'reference_no' => $movement['reference_no'],
            'reference_item_id' => $movement['reference_item_id'],
            'received_qty' => $movement['quantity'],
            'remaining_qty' => (float) $movement['quantity'] - $consumed,
            'unit_cost' => $movement['unit_cost'] === null ? 0 : $movement['unit_cost'],
            'currency' => 'THB',
            'lot_no' => isset($movement['lot_no']) ? $movement['lot_no'] : null,
            'expiry_date' => isset($movement['expiry_date']) ? $movement['expiry_date'] : null,
            'note' => $movement['note'],
            'received_at' => $movement['occurred_at'],
            'created_by' => $movement['created_by'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
        if (!empty($carried)) {
            static::createDB()->update(Base::table('inventory_cost_allocation'),
                [['layer_id', array_map('intval', $carried)]], ['layer_id' => $layerId]);
        }

        return $layerId;
    }

    /**
     * วิธีตัดของระบบ (ค่าตั้ง stock_method) — DESIGN.md ข้อ 5.1
     *
     * fifo = รับก่อนออกก่อน · fefo = หมดอายุก่อนออกก่อน (ชั้นที่ไม่มีวันหมดอายุไว้ท้าย)
     * ใช้เมื่อไม่รู้ตัวตนของของที่ออกเท่านั้น — ระบุล็อตหรือเครื่องมาแล้วตัดตัวนั้นเสมอ
     *
     * @return string fifo | fefo
     */
    public static function method()
    {
        return Base::config('stock_method', 'fifo') === 'fefo' ? 'fefo' : 'fifo';
    }

    /**
     * เรียงชั้นต้นทุนตามวิธีตัดของระบบ
     *
     * @param \Kotchasan\QueryBuilder\QueryBuilderInterface $query
     * @param string $alias ชื่อย่อของตารางชั้นต้นทุนในคำสั่ง (ว่าง = ไม่มี)
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected static function orderLayers($query, $alias = '')
    {
        $prefix = $alias === '' ? '' : $alias.'.';
        if (self::method() === 'fefo') {
            $query->orderBy('ISNULL('.$prefix.'`expiry_date`)')->orderBy($prefix.'expiry_date');
        }

        return $query->orderBy($prefix.'received_at')->orderBy($prefix.'id');
    }

    /**
     * จำนวนคงเหลือของชั้นที่เกิดจากการรับเข้าครั้งหนึ่ง ในคลังหนึ่ง (คืนผู้ขายจากใบรับที่ระบุ)
     *
     * @param int $inventoryId
     * @param int $inventoryItemId ระดับที่ลงบัญชี
     * @param int $warehouseId
     * @param int $movementId      การเคลื่อนไหวขาเข้าของใบรับ
     *
     * @return float|null null = การรับเข้าครั้งนั้นไม่ได้เปิดชั้นของสินค้านี้ในคลังนี้
     */
    public static function receiptRemaining($inventoryId, $inventoryItemId, $warehouseId, $movementId)
    {
        $row = static::createQuery()
            ->selectRaw('COUNT(*) AS `layers`')
            ->selectRaw('SUM(`remaining_qty`) AS `qty`')
            ->from('inventory_cost_layer')
            ->where([
                ['inventory_id', (int) $inventoryId],
                ['inventory_item_id', (int) $inventoryItemId],
                ['warehouse_id', (int) $warehouseId],
                ['movement_id', (int) $movementId]
            ])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || (int) $row[0]['layers'] === 0 ? null : (float) $row[0]['qty'];
    }

    /**
     * จำนวนคงเหลือของล็อตหนึ่ง ในคลังหนึ่ง
     *
     * @param int    $inventoryId
     * @param int    $inventoryItemId ระดับที่ลงบัญชี
     * @param int    $warehouseId
     * @param string $lotNo
     *
     * @return float|null null = ไม่เคยรับล็อตนี้เข้าคลังนี้เลย
     */
    public static function lotRemaining($inventoryId, $inventoryItemId, $warehouseId, $lotNo)
    {
        $row = static::createQuery()
            ->selectRaw('COUNT(*) AS `layers`')
            ->selectRaw('SUM(`remaining_qty`) AS `qty`')
            ->from('inventory_cost_layer')
            ->where([
                ['inventory_id', (int) $inventoryId],
                ['inventory_item_id', (int) $inventoryItemId],
                ['warehouse_id', (int) $warehouseId],
                ['lot_no', (string) $lotNo]
            ])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || (int) $row[0]['layers'] === 0 ? null : (float) $row[0]['qty'];
    }

    /**
     * ตัดของออกจากชั้นต้นทุน — ล็อตที่ระบุ หรือตามวิธีตัดของระบบ
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
        $where = [
            ['inventory_id', $movement['inventory_id']],
            ['inventory_item_id', $movement['inventory_item_id']],
            ['warehouse_id', $movement['warehouse_id']],
            ['remaining_qty', '>', 0]
        ];
        // รู้ตัวตน ใช้ของตัวตนนั้น (DESIGN.md ข้อ 1.3) — ระบุล็อตมา ตัดจากล็อตนั้นเท่านั้น
        // (ชิ้นของสินค้านับรายชิ้นมีชั้นของตัวเองอยู่แล้ว เพราะ inventory_item_id คือเครื่องนั้น)
        if (isset($movement['lot_no']) && $movement['lot_no'] !== null) {
            $where[] = ['lot_no', $movement['lot_no']];
        }
        // ระบุใบรับที่มา (คืนผู้ขาย) — ตัดจากชั้นของการรับเข้าครั้งนั้นเท่านั้น
        if (!empty($movement['from_movement_id'])) {
            $where[] = ['movement_id', (int) $movement['from_movement_id']];
        }
        $layers = self::orderLayers(static::createQuery()
            ->select('id', 'unit_cost', 'remaining_qty', 'received_qty', 'movement_id')
            ->from('inventory_cost_layer')
            ->where($where))
            ->execute(null, 'array')
            ->fetchAll();

        foreach ($layers as $layer) {
            if ($remain <= 0) {
                break;
            }
            $take = min($remain, (float) $layer['remaining_qty']);
            $cost = $take * (float) $layer['unit_cost'];
            // ชิ้นสุดท้ายของชั้นรับเศษ — ทุนต่อหน่วยเก็บ 4 ตำแหน่ง (200 ÷ 60 = 3.3333) ตัดตรง ๆ ครบชั้น
            // ได้ 199.998 · ทุนที่ตัดออกรวมของชั้นต้องเท่าทุนที่รับเข้าพอดี
            if ((float) $layer['remaining_qty'] - $take <= 0.00001) {
                $cost = self::closingCost($layer, $take, $cost);
            }
            $db->insert($tableAllocation, [
                'layer_id' => $layer['id'],
                'inventory_id' => $movement['inventory_id'],
                'inventory_item_id' => $movement['inventory_item_id'],
                'warehouse_id' => $movement['warehouse_id'],
                'sku' => $movement['sku'],
                'movement_id' => $movement['id'],
                'reference_type' => $movement['reference_type'],
                'reference_id' => $movement['reference_id'],
                'reference_no' => $movement['reference_no'],
                'reference_item_id' => $movement['reference_item_id'],
                'quantity' => $take,
                'unit_cost' => $take > 0 ? $cost / $take : $layer['unit_cost'],
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
     * ทุนของการตัดที่ทำให้ชั้นหมด = ทุนที่รับเข้าทั้งชั้น − ทุนที่ตัดออกไปแล้ว (สุทธิการคืน)
     *
     * ใช้เฉพาะเมื่อส่วนต่างเป็นเศษจากการปัดทุนต่อหน่วย (ไม่เกิน 0.0001 ต่อหน่วยที่รับ + 0.01) —
     * ส่วนต่างที่ใหญ่กว่านั้นไม่ใช่เศษ (เช่น ชั้นรับช่วงที่ allocation เดิมคิดด้วยราคาก่อนแก้ใบรับ)
     * จึงคงทุนตามราคาต่อหน่วย ไม่โยนส่วนต่างทั้งก้อนไปที่ชิ้นสุดท้าย
     *
     * @param array $layer ['id', 'unit_cost', 'received_qty', 'movement_id']
     * @param float $take
     * @param float $cost  ทุนตามราคาต่อหน่วย
     *
     * @return float
     */
    protected static function closingCost(array $layer, $take, $cost)
    {
        $received = null;
        if (!empty($layer['movement_id'])) {
            $movement = static::createDB()->first(Base::table('inventory_stock_movement'), ['id' => (int) $layer['movement_id']]);
            if ($movement && $movement->total_cost !== null) {
                $received = (float) $movement->total_cost;
            }
        }
        if ($received === null) {
            $received = (float) $layer['received_qty'] * (float) $layer['unit_cost'];
        }
        $row = static::createQuery()
            ->selectRaw('SUM(`total_cost`) AS `cost`')
            ->from('inventory_cost_allocation')
            ->where(['layer_id', (int) $layer['id']])
            ->execute(null, 'array')
            ->fetchAll();
        $allocated = empty($row) || $row[0]['cost'] === null ? 0.0 : (float) $row[0]['cost'];
        $closing = round($received - $allocated, 4);

        return abs($closing - $cost) <= 0.0001 * (float) $layer['received_qty'] + 0.01 ? $closing : $cost;
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
            ->select('id', 'layer_id', 'inventory_id', 'inventory_item_id', 'warehouse_id', 'sku',
                'quantity', 'unit_cost', 'total_cost', 'reference_type', 'reference_id', 'reference_no', 'reference_item_id')
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
                'warehouse_id' => $allocation['warehouse_id'],
                'sku' => $allocation['sku'],
                'movement_id' => $newMovementId,
                'source_allocation_id' => $allocation['id'],
                'reference_type' => $allocation['reference_type'],
                'reference_id' => $allocation['reference_id'],
                'reference_no' => $allocation['reference_no'],
                'reference_item_id' => $allocation['reference_item_id'],
                'quantity' => -1 * (float) $allocation['quantity'],
                'unit_cost' => $allocation['unit_cost'],
                // หักล้างด้วยยอดเดิมตรงตัว — คูณใหม่จากทุนต่อหน่วยที่ปัดแล้วได้ยอดเพี้ยนเป็นเศษ
                'total_cost' => -1 * (float) $allocation['total_cost'],
                'note' => 'กลับรายการของการเคลื่อนไหว '.(int) $movementId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $total += (float) $allocation['total_cost'];
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
        // ถอนไม่ครบ = ของล็อตนี้ถูกตัดออกไปแล้ว จำไว้ให้การรับเข้าใหม่ของเอกสารเดียวกันรับช่วง
        // (receive) หรือให้ finish() ปฏิเสธ ถ้าไม่มีใครรับ
        if ($remain > 0.00001) {
            foreach (static::createQuery()->select('id', 'reference_type', 'reference_id', 'inventory_id',
                'inventory_item_id', 'warehouse_id')->from('inventory_cost_layer')
                ->where(['movement_id', $movementId])->execute(null, 'array')->fetchAll() as $layer) {
                self::$carry[self::carryKey($layer)][] = (int) $layer['id'];
            }
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

    /**
     * เครื่องถัดไปที่ควรขาย ของสินค้านับรายชิ้นที่ยิงบาร์โค้ดรุ่นโดยไม่ระบุเครื่อง
     *
     * เลือกตามวิธีตัดของระบบจากชั้นต้นทุนของแต่ละเครื่อง เฉพาะเครื่องที่อยู่ในคลังและไม่ถูกจอง
     * ⚠️ ผู้เรียกต้องบันทึกเครื่องที่ได้ลงบรรทัดเอกสาร (DESIGN.md ข้อ 4) — สมุดบัญชีไม่เลือกให้เอง
     * ตอนลงบัญชี ไม่งั้นบันทึกเอกสารซ้ำแล้วอาจได้คนละเครื่อง และบรรทัดไม่รู้ว่าเครื่องไหนออกไป
     *
     * @param int   $inventoryId
     * @param int   $warehouseId 0 = ทุกคลัง
     * @param array $exclude     id ของเครื่องที่ถูกเลือกไปแล้ว (บรรทัดอื่นในเอกสารเดียวกัน)
     *
     * @return array|null ['id', 'serial_no', 'warehouse_id'] · null = ไม่มีเครื่องในคลัง
     */
    public static function nextPiece($inventoryId, $warehouseId = 0, array $exclude = [])
    {
        $where = [
            ['S.inventory_id', (int) $inventoryId],
            ['S.inventory_item_id', '>', 0],
            ['I.is_active', 1]
        ];
        if ((int) $warehouseId > 0) {
            $where[] = ['S.warehouse_id', (int) $warehouseId];
        }
        if (!empty($exclude)) {
            $where[] = ['S.inventory_item_id', 'NOT IN', array_map('intval', $exclude)];
        }
        $rows = self::orderLayers(static::createQuery()
            ->select('S.inventory_item_id', 'S.warehouse_id', 'I.serial_no')
            ->from('inventory_stock S')
            ->join('inventory_items I', ['I.id', 'S.inventory_item_id'], 'INNER')
            ->join('inventory_cost_layer L', [['L.inventory_id', 'S.inventory_id'], ['L.inventory_item_id', 'S.inventory_item_id'],
                ['L.warehouse_id', 'S.warehouse_id'], ['L.remaining_qty', '>', 0]], 'LEFT')
            ->where($where)
            ->whereRaw('S.`qty` - S.`reserved_qty` >= 1')
            ->whereRaw('I.`serial_no` IS NOT NULL'), 'L')
            ->orderBy('S.inventory_item_id')
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : [
            'id' => (int) $rows[0]['inventory_item_id'],
            'serial_no' => (string) $rows[0]['serial_no'],
            'warehouse_id' => (int) $rows[0]['warehouse_id']
        ];
    }

    /**
     * ต้นทุนของหน่วยถัดไปที่จะขาย — ค่าที่เก็บไว้แสดงใน inventory.cost (DESIGN.md ข้อ 5.6)
     *
     * ชั้นแรกตามวิธีตัดที่ยังมีของเหลือ · ไม่มีของเหลือ = ชั้นที่รับเข้าล่าสุด (ไม่นับใบรับที่ถูกยกเลิก)
     * ใช้แสดงผลเท่านั้น — ต้นทุนขายจริงมาจาก allocation เสมอ
     *
     * @param int $inventoryId
     *
     * @return float|null null = ไม่เคยรับของเข้าเลย (คงต้นทุนที่ผู้ใช้กรอกไว้)
     */
    public static function nextCost($inventoryId)
    {
        $rows = self::orderLayers(static::createQuery()
            ->select('unit_cost')
            ->from('inventory_cost_layer')
            ->where([['inventory_id', (int) $inventoryId], ['remaining_qty', '>', 0]]))
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            $rows = static::createQuery()
                ->select('L.unit_cost')
                ->from('inventory_cost_layer L')
                ->where(['L.inventory_id', (int) $inventoryId])
                ->whereRaw('NOT EXISTS (SELECT 1 FROM `'.Base::table('inventory_stock_movement').'` M'
                    .' WHERE M.`source_movement_id` = L.`movement_id`)')
                ->orderBy('L.received_at', 'DESC')
                ->orderBy('L.id', 'DESC')
                ->limit(1)
                ->execute(null, 'array')
                ->fetchAll();
        }

        return empty($rows) ? null : (float) $rows[0]['unit_cost'];
    }

    /**
     * คำนวณ remaining_qty ของทุกชั้นของสินค้าหนึ่งใหม่จากข้อมูลดิบ เขียนทับเฉพาะแถวที่ไม่ตรง
     *
     * - ชั้นของใบรับที่ถูกกลับรายการแล้ว = 0 — withdraw() ถอนส่วนที่เหลือออกโดยไม่มี allocation
     *   และส่วนที่ถูกตัดไปแล้วย้ายไปอยู่ชั้นใหม่ของเอกสารเดียวกัน (receive())
     * - ชั้นอื่น = received_qty − Σ allocation (สุทธิการคืน)
     *
     * ชั้นของใบรับที่ยกเลิกแล้วแต่ยังมี allocation สุทธิค้าง = ของที่ขายไปแล้วอ้างต้นทุนของใบที่ไม่มีอยู่
     * (สิ่งที่ finish() กันไว้) ซ่อมอัตโนมัติไม่ได้ — รายงานไว้ให้คนตรวจ
     *
     * @param int $inventoryId
     *
     * @return array แถวที่ไม่ตรง [['table', 'key', 'field', 'sku', 'cached', 'actual'], ...]
     */
    public static function rebuildRemaining($inventoryId)
    {
        $rows = static::createQuery()
            ->select('L.id', 'L.sku', 'L.received_qty', 'L.remaining_qty')
            ->selectRaw('(SELECT IFNULL(SUM(A.`quantity`), 0) FROM `'.Base::table('inventory_cost_allocation').'` A'
                .' WHERE A.`layer_id` = L.`id`) AS `allocated`')
            ->selectRaw('EXISTS (SELECT 1 FROM `'.Base::table('inventory_stock_movement').'` M'
                .' WHERE M.`source_movement_id` = L.`movement_id`) AS `reversed`')
            ->from('inventory_cost_layer L')
            ->where(['L.inventory_id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();

        $db = static::createDB();
        $diff = [];
        foreach ($rows as $row) {
            $allocated = (float) $row['allocated'];
            if ($row['reversed'] && abs($allocated) > 0.00001) {
                $diff[] = ['table' => 'inventory_cost_allocation', 'key' => (int) $row['id'], 'field' => 'layer_id',
                    'sku' => (string) $row['sku'], 'cached' => $allocated, 'actual' => 0.0,
                    'note' => 'ชั้นของใบรับที่ยกเลิกแล้วยังมีของถูกตัดค้าง — ต้องตรวจเอกสารที่ตัดของออก'];
            }
            $actual = $row['reversed'] ? 0.0 : round((float) $row['received_qty'] - $allocated, 4);
            if (abs($actual - (float) $row['remaining_qty']) > 0.00005) {
                $db->update(Base::table('inventory_cost_layer'), ['id', (int) $row['id']], ['remaining_qty' => $actual]);
                $diff[] = ['table' => 'inventory_cost_layer', 'key' => (int) $row['id'], 'field' => 'remaining_qty',
                    'sku' => (string) $row['sku'], 'cached' => (float) $row['remaining_qty'], 'actual' => $actual];
            }
        }

        return $diff;
    }
}
