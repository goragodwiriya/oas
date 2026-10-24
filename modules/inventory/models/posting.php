<?php
/**
 * @filesource modules/inventory/models/posting.php
 *
 * Posting API — ทางเดียวที่สต๊อกเปลี่ยนค่าได้
 *
 * กฎเหล็กของโมดูลกลาง : ห้ามโมดูลไหนไปแก้ inventory_stock.qty หรือ
 * inventory.stock / inventory_items.stock ตรง ๆ ทุกการเปลี่ยนต้องผ่านที่นี่
 * เพราะยอดคงเหลือคือ "ผลสรุปของสมุดบัญชี" ไม่ใช่ตัวเลขที่ใครก็เขียนทับได้
 *
 * ของเดิมของ oms แก้ inventory.stock ตรง ๆ จากหลายที่ ทำให้เมื่อยอดเพี้ยนแล้ว
 * ไม่มีทางรู้ว่าเพี้ยนตอนไหนเพราะไม่มีประวัติ — ตารางนี้แก้ปัญหานั้นโดยตรง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Posting;

use Inventory\Base\Model as Base;
use Inventory\Fifo\Model as Fifo;

/**
 * ตัวบันทึกการเคลื่อนไหวของสต๊อก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * บันทึกการเคลื่อนไหวหนึ่งรายการ
     *
     * @param array $movement {
     *   inventory_id      int    บังคับ
     *   inventory_item_id int    0 = ยอดระดับสินค้า (สินค้าที่นับรวม)
     *   warehouse_id      int    คลังที่รายการนี้เกิด (ว่าง = คลังตั้งต้นของไซต์)
     *   movement_type     string ต้องอยู่ในทะเบียนกลาง (Base::movementTypes)
     *   quantity          float  เป็นบวกเสมอ ทิศทางมาจากชนิดการเคลื่อนไหว
     *   unit_cost         float  ราคาทุนต่อหน่วย (ใช้เฉพาะขาเข้า)
     *   lot_no            string ขาเข้า = ล็อตของชั้นที่เปิด · ขาออก = ตัดจากล็อตนี้เท่านั้น (ว่าง = ตามวิธีตัด)
     *   expiry_date       string Y-m-d วันหมดอายุของชั้นที่เปิด (ขาเข้า · ว่างได้)
     *   from_movement_id  int    ขาออก = ตัดจากชั้นของการรับเข้าครั้งนี้เท่านั้น (คืนสินค้าไปยังผู้ขาย)
     *   reference_type    string order | borrow | repair | adjustment | opening
     *   reference_id      int
     *   reference_no      string
     *   reference_item_id int
     *   occurred_at       string เวลาที่เกิดรายการจริง (ใช้เรียง FIFO)
     *   created_by        int
     *   note              string
     * }
     *
     * @throws \Exception เมื่อข้อมูลไม่ครบ ชนิดไม่รู้จัก หรือสต๊อกจะติดลบทั้งที่ไม่อนุญาต
     *
     * @return int id ของแถวที่บันทึก (0 = สินค้านี้ไม่นับสต๊อก จึงไม่บันทึกอะไร)
     */
    public static function post(array $movement)
    {
        $movement = static::normalize($movement);
        $product = static::product($movement['inventory_id']);

        // สินค้าที่ไม่นับสต๊อก (ค่าบริการ ค่าแรง) ไม่มีอะไรให้เดิน — ไม่ใช่ข้อผิดพลาด
        if ((int) $product['count_stock'] === 0) {
            return 0;
        }

        // ⚠️ ปรับระดับก่อนทำอะไรทั้งสิ้น — ทั้งการตรวจสต๊อกพอ การเขียนสมุดบัญชี
        // และการตัดชั้นต้นทุน ต้องอ้างระดับเดียวกันหมด ไม่งั้นยอดคงเหลือกับ
        // สมุดบัญชีจะชี้คนละที่ (ดู stockLevel)
        //
        // `sku` ยังเก็บหน่วยขายที่ใช้จริงไว้บนแถวสมุดบัญชี เพื่อให้ตรวจย้อนหลังได้
        // ว่ารายการนั้นขายเป็นลังหรือเป็นชิ้น ถึงยอดจะรวมกองเดียว
        $movement['inventory_item_id'] = self::stockLevel($product, $movement['inventory_item_id']);

        $direction = Base::movementDirection($movement['movement_type']);
        $signed = $direction === 'in' ? $movement['quantity'] : -1 * $movement['quantity'];

        if ((int) $product['count_stock'] === 2) {
            static::assertPiece($product, $movement, $direction);
        }

        // ⚠️ ตรวจกับยอดของ "คลังนั้น" ไม่ใช่ยอดรวมทั้งระบบ — ของอยู่คลัง ก 100 ชิ้น
        // แล้วสั่งตัดจากคลัง ข ต้องถูกปฏิเสธ ไม่ใช่ปล่อยผ่านเพราะยอดรวมพอ
        // (ตัดผ่านไปแล้วยอดของคลัง ข จะติดลบ ส่วนของจริงในคลัง ก ยังอยู่ครบ)
        $balance = static::balance($movement['inventory_id'], $movement['inventory_item_id'],
            $movement['warehouse_id']);
        // ⚠️ ของที่ "จอง" ไว้ให้ใบสั่งขายที่ยังเปิดอยู่ ไม่ใช่ของที่ขายได้ — ลูกค้าออนไลน์
        // จ่ายเงินแล้วต้องได้ของ ไม่ใช่มาถึงคิวส่งแล้วพบว่าหน้าร้านขายไปแล้วเมื่อเช้า
        $reserved = $direction === 'out'
            ? static::reserved($movement['inventory_id'], $movement['inventory_item_id'], $movement['warehouse_id'])
            : 0.0;
        if ($direction === 'out' && ($balance - $reserved + $signed) < 0 && empty($product['allow_negative'])) {
            $where = static::warehouseName($movement['warehouse_id']);
            throw new \Exception(
                'สต๊อกของ '.$product['topic'].($where === '' ? '' : ' ที่'.$where)
                .' ไม่พอ — คงเหลือ '.static::format($balance)
                .($reserved > 0 ? ' (จองไว้แล้ว '.static::format($reserved).')' : '')
                .' แต่ต้องการตัดออก '.static::format($movement['quantity'])
            );
        }
        // ระบุล็อตมา = ตัดจากล็อตนั้นเท่านั้น (DESIGN.md ข้อ 1.3) — ล็อตที่ไม่มีอยู่จริงคือรหัสผิด
        // ไม่ใช่ให้ไปตัดล็อตอื่นแทนเงียบ ๆ แล้วต้นทุนกับวันหมดอายุของบรรทัดนั้นผิดตัว
        if ($direction === 'out' && $movement['lot_no'] !== null) {
            $lot = Fifo::lotRemaining($movement['inventory_id'], $movement['inventory_item_id'],
                $movement['warehouse_id'], $movement['lot_no']);
            $where = static::warehouseName($movement['warehouse_id']);
            if ($lot === null) {
                throw new \Exception('ไม่พบล็อต '.$movement['lot_no'].' ของ '.$product['topic']
                    .($where === '' ? '' : ' ที่'.$where));
            }
            if ($lot < $movement['quantity'] - 0.00001 && empty($product['allow_negative'])) {
                throw new \Exception('ล็อต '.$movement['lot_no'].' ของ '.$product['topic']
                    .' คงเหลือ '.static::format($lot).' แต่ต้องการตัดออก '.static::format($movement['quantity']));
            }
        }
        // คืนผู้ขาย : ของต้องออกจากใบรับที่มา ด้วยทุนของใบนั้น (TAS 2) — ไม่ใช่ชั้นที่วิธีตัดเลือกให้
        if ($direction === 'out' && !empty($movement['from_movement_id'])) {
            $left = Fifo::receiptRemaining($movement['inventory_id'], $movement['inventory_item_id'],
                $movement['warehouse_id'], $movement['from_movement_id']);
            if ($left === null) {
                throw new \Exception('ไม่พบการรับเข้าของ '.$product['topic'].' จากใบรับที่อ้าง');
            }
            if ($left < $movement['quantity'] - 0.00001) {
                throw new \Exception($product['topic'].' จากใบรับที่อ้างเหลือ '.static::format($left)
                    .' แต่ต้องการคืน '.static::format($movement['quantity']));
            }
        }

        $db = static::createDB();
        $movement['id'] = (int) $db->insert(Base::table('inventory_stock_movement'), [
            'inventory_id' => $movement['inventory_id'],
            'inventory_item_id' => $movement['inventory_item_id'],
            'warehouse_id' => $movement['warehouse_id'],
            'sku' => $movement['sku'],
            'movement_direction' => $direction,
            'movement_type' => $movement['movement_type'],
            'reference_type' => $movement['reference_type'],
            'reference_id' => $movement['reference_id'],
            'reference_no' => $movement['reference_no'],
            'reference_item_id' => $movement['reference_item_id'],
            'source_movement_id' => $movement['source_movement_id'],
            'quantity' => $movement['quantity'],
            'unit_cost' => $movement['unit_cost'],
            'total_cost' => $movement['unit_cost'] === null ? null : $movement['unit_cost'] * $movement['quantity'],
            'note' => $movement['note'],
            'occurred_at' => $movement['occurred_at'],
            'created_by' => $movement['created_by'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // ต้นทุน — ขาเข้าเปิดชั้นใหม่ ขาออกตัดจากชั้นที่เก่าที่สุดก่อน
        if ($direction === 'in') {
            Fifo::receive($movement);
        } else {
            list($totalCost, $unitCost) = Fifo::consume($movement);
            $db->update(Base::table('inventory_stock_movement'), ['id', $movement['id']], [
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost
            ]);
        }

        static::applyBalance($movement['inventory_id'], $movement['inventory_item_id'],
            $movement['warehouse_id'], $signed);

        return $movement['id'];
    }

    /**
     * สินค้านับรายชิ้น — หนึ่งรายการคือหนึ่งเครื่องที่ระบุตัวได้เสมอ (DESIGN.md ข้อ 1.3 · 5.2–5.3)
     *
     * บรรทัดเอกสารต้องรู้ก่อนมาถึงที่นี่ว่าเครื่องไหนเข้า/ออก (Items::addSerial ตอนรับ ·
     * Fifo::nextPiece ตอนยิงบาร์โค้ดรุ่น) — สมุดบัญชีไม่เลือกหรือออกเลขให้เอง เพราะบันทึก
     * เอกสารซ้ำแล้วจะได้คนละเครื่อง และบรรทัดเอกสารจะไม่รู้ว่าเครื่องไหนไปกับใบไหน
     *
     * @param array  $product
     * @param array  $movement  ผลจาก normalize() ที่ปรับระดับแล้ว
     * @param string $direction
     *
     * @throws \Exception
     */
    protected static function assertPiece(array $product, array $movement, $direction)
    {
        if (empty($movement['piece'])) {
            throw new \Exception($product['topic'].' นับสต๊อกรายชิ้น — ต้องระบุหมายเลขเครื่อง (serial) ที่'
                .($direction === 'in' ? 'รับเข้า' : 'ตัดออก'));
        }
        if (abs($movement['quantity'] - 1) > 0.00001) {
            throw new \Exception($product['topic'].' นับสต๊อกรายชิ้น — รับเข้าหรือตัดออกได้ทีละหนึ่งเครื่อง');
        }
        if ($direction === 'in') {
            // เครื่องหนึ่งอยู่ได้ที่เดียว — มีอยู่แล้วที่คลังไหนก็ตาม รับซ้ำคือข้อมูลผิด
            if (static::balance($movement['inventory_id'], $movement['inventory_item_id']) > 0.00001) {
                throw new \Exception('เครื่องหมายเลข '.$movement['sku'].' อยู่ในคลังแล้ว รับเข้าซ้ำไม่ได้');
            }
        } elseif (static::balance($movement['inventory_id'], $movement['inventory_item_id'],
            $movement['warehouse_id']) < 0.99999) {
            // ยอมติดลบไม่ใช้กับรายชิ้น — เครื่องที่ไม่อยู่ในคลังขายไม่ได้
            $where = static::warehouseName($movement['warehouse_id']);
            throw new \Exception('เครื่องหมายเลข '.$movement['sku'].' ไม่อยู่ในคลัง'.($where === '' ? '' : ' '.$where)
                .' — ขายไปแล้วหรือยังไม่ได้รับเข้า');
        }
    }

    /**
     * ระดับที่ยอดคงเหลือของสินค้าตัวนี้ถูกเก็บไว้
     *
     * ⚠️ นี่คือกติกาที่ทำให้ขายแบบ "ยกลัง / ยกโหล" ได้ถูกต้อง
     *
     * `count_stock` เป็นตัวตัดสิน ไม่ใช่ว่าบรรทัดเอกสารบังเอิญอ้างหน่วยย่อยหรือไม่
     *
     *   1 = นับรวม        → ของกองเดียวที่ระดับสินค้า (inventory_item_id = 0)
     *                       หน่วยย่อยเป็น "หน่วยขาย" ที่มีราคาและตัวคูณ `cut_stock` ของตัวเอง
     *                       ของตัวเอง เช่น "ยกลัง" = 12 ชิ้น — ขาย 2 ลังจึงตัด
     *                       24 ชิ้นออกจากกองเดียวกันกับที่ขายปลีกทีละชิ้น
     *   2 = นับแยกรายชิ้น → แต่ละหน่วยย่อยคือของคนละชิ้น มียอดของตัวเอง
     *
     * ถ้าไม่มีกติกานี้ การขาย 2 ลังจะไปหักจากยอดของ "ลัง" เอง แล้วฟ้องว่า
     * สต๊อกไม่พอทั้งที่ของในคลังมีครบ — และขายปลีกกับขายยกลังจะกลายเป็นของ
     * คนละกองที่ไม่รู้จักกัน
     *
     * @param array $product         ข้อมูลสินค้าจาก product()
     * @param int   $inventoryItemId หน่วยย่อยที่บรรทัดเอกสารอ้างถึง
     *
     * @return int ระดับที่ต้องลงบัญชีจริง
     */
    public static function stockLevel(array $product, $inventoryItemId)
    {
        return (int) $product['count_stock'] === 2 ? (int) $inventoryItemId : 0;
    }

    /**
     * รับเข้า — ทางลัดของ post() ที่อ่านง่ายกว่าตรงจุดเรียกใช้
     *
     * @param array $movement
     *
     * @return int
     */
    public static function receipt(array $movement)
    {
        if (empty($movement['movement_type'])) {
            $movement['movement_type'] = 'purchase';
        }

        return static::post($movement);
    }

    /**
     * ตัดออก — ทางลัดของ post()
     *
     * @param array $movement
     *
     * @return int
     */
    public static function issue(array $movement)
    {
        if (empty($movement['movement_type'])) {
            $movement['movement_type'] = 'sale';
        }

        return static::post($movement);
    }

    /**
     * ตั้งยอดยกมาตอนเริ่มใช้สมุดบัญชี
     *
     * ไซต์ที่อัปเกรดมาไม่มีประวัติการเดินสต๊อกให้สร้างย้อนหลัง (ระบบเดิมแก้ค่า
     * stock ตรง ๆ) ทำได้แค่ตั้งยอดเปิดเท่ากับยอดปัจจุบันแล้วเริ่มนับจากตรงนั้น
     * — ข้อจำกัดนี้ต้องเขียนในหมายเหตุรุ่นให้ผู้ดูแลไซต์รู้ตัวด้วย
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param float $quantity
     * @param float $unitCost
     * @param int   $warehouseId 0 = คลังตั้งต้น
     * @param string|null $occurredAt วันเวลาของยอดยกมา (กำหนดลำดับ FIFO ของชั้นแรก) · null = ตอนนี้
     *
     * @return int
     */
    public static function opening($inventoryId, $inventoryItemId, $quantity, $unitCost = 0, $warehouseId = 0, $occurredAt = null)
    {
        if ($quantity == 0) {
            return 0;
        }
        $movement = [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $quantity > 0 ? 'opening' : 'adjust_out',
            'quantity' => abs($quantity),
            'unit_cost' => $unitCost,
            'reference_type' => 'opening',
            'note' => 'ยอดยกมาตอนเริ่มใช้สมุดบัญชีสต๊อก'
        ];
        if (!empty($occurredAt)) {
            $movement['occurred_at'] = $occurredAt;
        }

        return static::post($movement);
    }

    /**
     * กลับรายการทั้งหมดของเอกสารหนึ่ง (ใช้ตอนยกเลิกหรือลบเอกสาร)
     *
     * ไม่ลบแถวเดิมทิ้ง — สร้างแถวตรงข้ามที่ชี้กลับไปหาแถวเดิม เพราะสมุดบัญชี
     * ที่ลบแถวได้ไม่ใช่สมุดบัญชี และผู้ตรวจต้องเห็นว่าเคยมีรายการนี้แล้วถูกยกเลิก
     *
     * @param string $referenceType
     * @param int    $referenceId
     * @param int    $createdBy
     *
     * @return int จำนวนรายการที่กลับรายการ
     */
    public static function reverse($referenceType, $referenceId, $createdBy = 0)
    {
        $rows = static::createQuery()
            ->select('id', 'inventory_id', 'inventory_item_id', 'warehouse_id', 'sku',
                'movement_direction', 'movement_type', 'reference_no', 'reference_item_id',
                'quantity', 'unit_cost')
            ->from('inventory_stock_movement')
            ->where([
                ['reference_type', $referenceType],
                ['reference_id', $referenceId],
                ['source_movement_id', null]
            ])
            ->execute(null, 'array')
            ->fetchAll();

        $db = static::createDB();
        $done = 0;
        foreach ($rows as $row) {
            // กลับรายการซ้ำไม่ได้ — ถ้ามีแถวที่ชี้กลับมาหาแถวนี้แล้วก็ข้ามไป
            if ($db->exists(Base::table('inventory_stock_movement'), ['source_movement_id' => $row['id']])) {
                continue;
            }
            $back = $row['movement_direction'] === 'in' ? 'adjust_out' : 'adjust_in';
            $movement = static::normalize([
                'inventory_id' => $row['inventory_id'],
                'inventory_item_id' => $row['inventory_item_id'],
                // ⚠️ กลับรายการต้องคืนของเข้าคลังเดิมเสมอ ไม่ใช่คลังตั้งต้น
                // ไม่งั้นยกเลิกใบที่ตัดจากคลังสาขา แล้วของไปโผล่ที่คลังหลัก
                'warehouse_id' => $row['warehouse_id'],
                'sku' => $row['sku'],
                'movement_type' => $back,
                'quantity' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_no' => $row['reference_no'],
                'reference_item_id' => $row['reference_item_id'],
                'source_movement_id' => $row['id'],
                'created_by' => $createdBy,
                'note' => 'กลับรายการของการเคลื่อนไหว '.(int) $row['id']
            ]);
            $direction = Base::movementDirection($movement['movement_type']);
            $movement['id'] = (int) $db->insert(Base::table('inventory_stock_movement'), [
                'inventory_id' => $movement['inventory_id'],
                'inventory_item_id' => $movement['inventory_item_id'],
                'warehouse_id' => $movement['warehouse_id'],
                'sku' => $movement['sku'],
                'movement_direction' => $direction,
                'movement_type' => $movement['movement_type'],
                'reference_type' => $movement['reference_type'],
                'reference_id' => $movement['reference_id'],
                'reference_no' => $movement['reference_no'],
                'reference_item_id' => $movement['reference_item_id'],
                'source_movement_id' => $movement['source_movement_id'],
                'quantity' => $movement['quantity'],
                'unit_cost' => $movement['unit_cost'],
                'total_cost' => $movement['unit_cost'] === null ? null : $movement['unit_cost'] * $movement['quantity'],
                'note' => $movement['note'],
                'occurred_at' => $movement['occurred_at'],
                'created_by' => $movement['created_by'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            if ($row['movement_direction'] === 'out') {
                // เคยตัดออกไป — คืนกลับเข้าชั้นต้นทุนเดิม ไม่ใช่เปิดชั้นใหม่
                Fifo::restore($row['id'], $movement['id']);
                $signed = $movement['quantity'];
            } else {
                // เคยรับเข้า — ถอนชั้นที่เปิดไว้ เท่าที่ยังไม่ถูกตัดออกไป
                Fifo::withdraw($row['id'], $movement['quantity']);
                $signed = -1 * $movement['quantity'];
            }
            static::applyBalance($movement['inventory_id'], $movement['inventory_item_id'],
                $movement['warehouse_id'], $signed);
            ++$done;
        }

        return $done;
    }

    /**
     * ยอดคงเหลือปัจจุบัน
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     * @param int $warehouseId     0 = รวมทุกคลัง (ผู้เรียกเดิมที่ไม่รู้เรื่องคลังได้ยอดเดิม)
     *
     * @return float
     */
    public static function balance($inventoryId, $inventoryItemId = 0, $warehouseId = 0)
    {
        // อ่านต้องใช้ระดับเดียวกับที่เขียน ไม่งั้นหน้าที่ถามยอดของหน่วยย่อยจะได้ 0
        // ทั้งที่ของมีอยู่ในกองรวม (ดู stockLevel)
        if ((int) $inventoryItemId > 0 && (int) $inventoryId > 0) {
            $inventoryItemId = self::stockLevel(static::product($inventoryId), $inventoryItemId);
        }

        $where = [
            ['inventory_id', (int) $inventoryId],
            ['inventory_item_id', (int) $inventoryItemId]
        ];
        if ((int) $warehouseId > 0) {
            $where[] = ['warehouse_id', (int) $warehouseId];
        }
        $row = static::createQuery()
            ->selectRaw('SUM(`qty`) AS `qty`')
            ->from('inventory_stock')
            ->where($where)
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * ยอดที่ถูกจองไว้ (ใบสั่งขายที่ยังเปิดอยู่)
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     * @param int $warehouseId     0 = รวมทุกคลัง
     *
     * @return float
     */
    public static function reserved($inventoryId, $inventoryItemId = 0, $warehouseId = 0)
    {
        $where = [
            ['inventory_id', (int) $inventoryId],
            ['inventory_item_id', (int) $inventoryItemId]
        ];
        if ((int) $warehouseId > 0) {
            $where[] = ['warehouse_id', (int) $warehouseId];
        }
        $row = static::createQuery()
            ->selectRaw('SUM(`reserved_qty`) AS `qty`')
            ->from('inventory_stock')
            ->where($where)
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * ยอดจองรวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย ทุกคลัง) — คู่กับ totalBalance()
     *
     * @param int $inventoryId
     *
     * @return float
     */
    public static function totalReserved($inventoryId)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`reserved_qty`) AS `qty`')
            ->from('inventory_stock')
            ->where(['inventory_id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * ยอดที่ขายได้รวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย ทุกคลัง)
     *
     * ⚠️ available() ถามที่ระดับเดียว (หน่วยย่อยหนึ่ง/กองรวม) — สินค้าที่นับแยกรายชิ้น
     * ถามด้วย id สินค้าเฉย ๆ จะได้ 0 ทั้งที่มีของ ต้องใช้ตัวนี้เมื่อต้องการยอดระดับสินค้า
     *
     * @param int $inventoryId
     *
     * @return float
     */
    public static function totalAvailable($inventoryId)
    {
        return round(static::totalBalance($inventoryId) - static::totalReserved($inventoryId), 4);
    }

    /**
     * ยอดที่ขายได้จริง = คงเหลือ − จองไว้
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     * @param int $warehouseId
     *
     * @return float
     */
    public static function available($inventoryId, $inventoryItemId = 0, $warehouseId = 0)
    {
        return round(static::balance($inventoryId, $inventoryItemId, $warehouseId)
            - static::reserved($inventoryId, $inventoryItemId, $warehouseId), 4);
    }

    /**
     * คำนวณยอดจองใหม่จากใบสั่งขายที่ยังเปิดอยู่ แล้วเขียนลง reserved_qty
     *
     * ⚠️ ยอดจองเป็น "ผลสรุป" เหมือนยอดคงเหลือ — ความจริงคือเอกสารที่จองของ
     * (แม่แบบ reserve_stock = 1) ที่ยังไม่ถูกยกเลิก และ **ยังไม่มีใบลูกที่ออกแล้ว**
     * (ใบสั่งขายที่กลายเป็นใบเสร็จไปแล้ว ไม่จองอีก เพราะของถูกตัดจริงไปแล้ว)
     * จึงไม่มีตารางการจองแยก ไม่มีสถานะที่ต้องคอยเปลี่ยน — คำนวณจากเอกสารเสมอ
     * ยอดที่เพี้ยนซ่อมได้ด้วยการเรียกเมธอดนี้ซ้ำ หรือ rebuild()
     *
     * @param int $inventoryId
     * @param int $inventoryItemId ระดับที่ลงบัญชี (ดู stockLevel)
     * @param int $warehouseId
     *
     * @return float ยอดจองที่เขียน
     */
    public static function syncReserved($inventoryId, $inventoryItemId, $warehouseId)
    {
        $inventoryId = (int) $inventoryId;
        $inventoryItemId = (int) $inventoryItemId;
        $warehouseId = \Inventory\Warehouse\Model::resolve($warehouseId);
        $types = static::reserveTypes();

        $reserved = 0.0;
        if (!empty($types)) {
            $product = static::product($inventoryId);
            $query = static::createQuery()
                ->select('O.id', 'O.root_document_id')
                ->selectRaw('SUM(T.`base_qty`) AS `qty`')
                ->from('order_items T')
                ->join('orders O', ['O.id', 'T.order_id'])
                ->where([
                    ['T.inventory_id', $inventoryId],
                    ['O.document_type', $types],
                    ['O.document_status', 'issued']
                ])
                // ใบที่ไม่ได้ระบุคลัง = คลังตั้งต้น (กติกาเดียวกับตอนตัดของ)
                // (ค่าเป็น int ที่ผ่าน cast แล้ว ใส่ตรง ๆ ได้ — ตัวสร้างคำสั่งใช้ named
                // parameter อยู่ ผสม ? แบบ positional เข้าไปแล้ว PDO ปฏิเสธทั้งคำสั่ง)
                ->whereRaw('IFNULL(O.`warehouse_id`, '.(int) \Inventory\Warehouse\Model::defaultId().') = '.(int) $warehouseId)
                ->groupBy(['O.id', 'O.root_document_id']);
            if ((int) $product['count_stock'] === 2) {
                // นับแยกรายชิ้น — จองที่หน่วยย่อยนั้น ๆ
                $query->where(['T.inventory_item_id', $inventoryItemId]);
            }
            $rows = $query->execute(null, 'array')->fetchAll();
            $released = static::releasedReservations($rows);
            foreach ($rows as $row) {
                if (!isset($released[(int) $row['id']])) {
                    $reserved += (float) $row['qty'];
                }
            }
        }

        static::writeReserved($inventoryId, $inventoryItemId, $warehouseId, $reserved);

        return $reserved;
    }

    /**
     * ใบจองที่ปล่อยการจองแล้ว — มีใบที่ต่อจากมันในสาย (ลูก หลาน …) ออกแล้วและตัดของจริง
     *
     * ใบแจ้งหนี้ที่แปลงจากใบสั่งขายไม่ปล่อยการจอง ใบเสร็จที่ต่อจากใบแจ้งหนี้นั้นปล่อย
     * (Document::applyStock) · ใบที่ตัดของถูกยกเลิก = ไม่นับ ใบจองกลับมาจองอีก
     * สายเอกสารอยู่ที่ source_document_id (ใบที่แปลงมา) — ไล่ภายในสายเดียวกัน (root_document_id)
     *
     * @param array $orders แถวใบจอง [['id', 'root_document_id'], ...]
     *
     * @return array [id ของใบจองที่ปล่อยแล้ว => true]
     */
    protected static function releasedReservations(array $orders)
    {
        $roots = [];
        foreach ($orders as $order) {
            $roots[empty($order['root_document_id']) ? (int) $order['id'] : (int) $order['root_document_id']] = true;
        }
        if (empty($roots)) {
            return [];
        }
        $cutTypes = [];
        foreach (\Inventory\Document\Model::templates() as $type => $template) {
            if (!empty($template['stock_out'])) {
                $cutTypes[$type] = true;
            }
        }
        // ทุกใบในสายเดียวกับใบจอง : ใบแม่ของแต่ละใบ และใบที่ออกแล้วซึ่งตัดของ
        $parent = [];
        $cuts = [];
        $rows = static::createQuery()
            ->select('id', 'source_document_id', 'document_type', 'document_status')
            ->from('orders')
            ->where(['root_document_id', array_keys($roots)])
            ->execute(null, 'array')
            ->fetchAll();
        foreach ($rows as $row) {
            $parent[(int) $row['id']] = empty($row['source_document_id']) ? 0 : (int) $row['source_document_id'];
            if ($row['document_status'] === 'issued' && isset($cutTypes[$row['document_type']])) {
                $cuts[] = (int) $row['id'];
            }
        }
        $released = [];
        foreach ($cuts as $id) {
            // กันวนไม่รู้จบเมื่อข้อมูลเสีย — สายเอกสารจริงไม่ยาวเกินนี้
            for ($n = 0, $up = isset($parent[$id]) ? $parent[$id] : 0; $up > 0 && $n < 50; ++$n) {
                $released[$up] = true;
                $up = isset($parent[$up]) ? $parent[$up] : 0;
            }
        }

        return $released;
    }

    /**
     * ชนิดเอกสารที่จองของ (แม่แบบ reserve_stock = 1)
     *
     * @return array
     */
    protected static function reserveTypes()
    {
        $types = [];
        foreach (\Inventory\Document\Model::templates() as $type => $template) {
            if (!empty($template['reserve_stock'])) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * คำนวณยอดจองใหม่ให้ทุกบรรทัดของเอกสารหนึ่งใบ (และของใบต้นทางถ้ามี)
     *
     * เรียกทุกครั้งที่เอกสารที่จองของถูกบันทึก/ยกเลิก/ลบ และเมื่อใบลูกของมันถูก
     * ออกหรือยกเลิก — เพราะทั้งสองเหตุการณ์เปลี่ยน "ใบไหนยังจองอยู่บ้าง"
     *
     * @param int $orderId
     */
    public static function syncReservedForDocument($orderId)
    {
        $orderId = (int) $orderId;
        $order = static::createDB()->first(Base::table('orders'), ['id' => $orderId]);
        if (!$order) {
            return;
        }
        $warehouseId = empty($order->warehouse_id) ? 0 : (int) $order->warehouse_id;
        $seen = [];
        foreach (\Inventory\Document\Model::items($orderId) as $line) {
            $product = static::product((int) $line['inventory_id']);
            if ((int) $product['count_stock'] === 0) {
                continue;
            }
            $level = self::stockLevel($product, (int) $line['inventory_item_id']);
            $key = $line['inventory_id'].'/'.$level;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            static::syncReserved((int) $line['inventory_id'], $level, $warehouseId);
        }
        if (!empty($order->source_document_id)) {
            static::syncReservedForDocument((int) $order->source_document_id);
        }
    }

    /**
     * เขียนยอดจองลงแถวยอดคงเหลือ (สร้างแถวถ้ายังไม่มี)
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param int   $warehouseId
     * @param float $reserved
     */
    protected static function writeReserved($inventoryId, $inventoryItemId, $warehouseId, $reserved)
    {
        $db = static::createDB();
        $table = Base::table('inventory_stock');
        $condition = [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => (int) $warehouseId
        ];
        if ($db->exists($table, $condition)) {
            $db->update($table, [
                ['inventory_id', $inventoryId],
                ['inventory_item_id', $inventoryItemId],
                ['warehouse_id', (int) $warehouseId]
            ], ['reserved_qty' => $reserved, 'updated_at' => date('Y-m-d H:i:s')]);
        } elseif ($reserved > 0) {
            $db->insert($table, [
                'inventory_id' => $inventoryId,
                'inventory_item_id' => $inventoryItemId,
                'warehouse_id' => (int) $warehouseId,
                'sku' => static::balanceSku($inventoryId, $inventoryItemId),
                'qty' => 0,
                'reserved_qty' => $reserved,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * ยอดคงเหลือแยกรายคลังของสินค้าหนึ่งตัว
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     *
     * @return array [['warehouse_id' => .., 'warehouse' => ชื่อ, 'qty' => ..], ...]
     */
    public static function balanceByWarehouse($inventoryId, $inventoryItemId = 0)
    {
        $rows = static::createQuery()
            ->select('S.warehouse_id', 'S.qty')
            ->selectRaw('W.`name` AS `warehouse`')
            ->from('inventory_stock S')
            ->join('inventory_warehouse W', ['W.id', 'S.warehouse_id'], 'LEFT')
            ->where([
                ['S.inventory_id', (int) $inventoryId],
                ['S.inventory_item_id', (int) $inventoryItemId]
            ])
            ->orderBy('W.sort_order')
            ->orderBy('S.warehouse_id')
            ->execute(null, 'array')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'warehouse_id' => (int) $row['warehouse_id'],
                'warehouse' => (string) $row['warehouse'],
                'qty' => (float) $row['qty']
            ];
        }

        return $result;
    }

    /**
     * ยอดคงเหลือรวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย)
     *
     * ⚠️ balance() คืนยอดของ "หน่วยย่อยเดียว" — เรียกด้วย id ของสินค้าเฉย ๆ จะได้
     * ยอดของแถว inventory_item_id = 0 เท่านั้น สินค้าที่นับสต๊อกแยกรายชิ้นจึงได้ 0
     * ทั้งที่มีของอยู่จริง ต้องใช้เมธอดนี้เมื่อต้องการยอดระดับสินค้า
     *
     * @param int $inventoryId
     *
     * @return float
     */
    public static function totalBalance($inventoryId)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`qty`) AS `qty`')
            ->from('inventory_stock')
            ->where(['inventory_id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * เขียน cache ทั้งหมดของสต๊อกใหม่จากข้อมูลดิบ แล้วรายงานแถวที่ cache เดิมไม่ตรง (DESIGN.md ข้อ 5.6)
     *
     * ข้อมูลดิบ = สมุดบัญชี · ชั้นต้นทุน (received_qty) · allocation · เอกสารที่จองของ · ทะเบียนสินค้า
     * cache = ยอดคงเหลือ/ยอดจองรายระดับรายคลัง · remaining_qty ของชั้น · ยอด ต้นทุน ชื่อของ variant ·
     * ยอดของแถวชิ้น — หน้าจอแสดงผิดให้เรียกเมธอดนี้ ไม่ใช่ไปแก้ตัวเลขที่ cache
     *
     * รายงานไม่ว่าง = มีทางเขียนที่ข้าม Posting หรือมีบั๊ก ชุดทดสอบเรียกท้ายกลุ่มแล้วต้องได้ []
     *
     * @param int $inventoryId 0 = ทุก variant
     *
     * @return array [['table', 'key', 'field', 'sku', 'cached', 'actual'(, 'note')], ...]
     */
    public static function rebuild($inventoryId = 0)
    {
        return Base::transaction(function () use ($inventoryId) {
            $query = static::createQuery()
                ->select('id', 'sku', 'stock', 'cost', 'topic')
                ->from('inventory');
            if ((int) $inventoryId > 0) {
                $query->where(['id', (int) $inventoryId]);
            }
            $diff = [];
            foreach ($query->execute(null, 'array')->fetchAll() as $variant) {
                $diff = array_merge($diff, static::rebuildVariant($variant));
            }

            return $diff;
        });
    }

    /**
     * rebuild() ของ variant หนึ่ง — ลำดับสำคัญ: ชั้นต้นทุน → ยอดรายระดับ → ยอดจอง → ค่าบน variant
     * (ต้นทุนถัดไปอ่านชั้นที่ซ่อมแล้ว) · ค่า cache เดิมอ่านไว้ก่อนแตะอะไร เพราะ writeBalance เขียนตาม
     *
     * @param array $variant แถวของ inventory (id, sku, stock, cost, topic)
     *
     * @return array
     */
    protected static function rebuildVariant(array $variant)
    {
        $db = static::createDB();
        $id = (int) $variant['id'];
        $sku = (string) $variant['sku'];
        $diff = [];
        $report = function ($table, $key, $field, $code, $cached, $actual) use (&$diff) {
            $diff[] = ['table' => $table, 'key' => $key, 'field' => $field, 'sku' => $code,
                'cached' => $cached, 'actual' => $actual];
        };
        $items = [];
        foreach (static::createQuery()->select('id', 'barcode', 'serial_no', 'stock')->from('inventory_items')
            ->where(['inventory_id', $id])->execute(null, 'array')->fetchAll() as $row) {
            $items[(int) $row['id']] = $row;
        }

        // 1. ชั้นต้นทุน = received − Σ allocation
        $diff = Fifo::rebuildRemaining($id);

        // 2. ยอดคงเหลือรายระดับ รายคลัง = Σ สมุดบัญชี · แถวที่ไม่มีการเคลื่อนไหวเลย = 0
        $actual = [];
        foreach (static::createQuery()
            ->select('inventory_item_id', 'warehouse_id')
            ->selectRaw("SUM(IF(`movement_direction` = 'in', `quantity`, -`quantity`)) AS `qty`")
            ->from('inventory_stock_movement')
            ->where(['inventory_id', $id])
            // ⚠️ groupBy() รับ "อาร์เรย์" หรือสตริงเดียว — ส่งหลายอาร์กิวเมนต์แล้วตัวหลังถูกทิ้งเงียบ ๆ
            ->groupBy(['inventory_item_id', 'warehouse_id'])
            ->execute(null, 'array')->fetchAll() as $row) {
            $actual[(int) $row['inventory_item_id'].'/'.(int) $row['warehouse_id']] = round((float) $row['qty'], 4);
        }
        $cached = [];
        foreach (static::createQuery()->select('inventory_item_id', 'warehouse_id', 'sku', 'qty')
            ->from('inventory_stock')->where(['inventory_id', $id])->execute(null, 'array')->fetchAll() as $row) {
            $cached[(int) $row['inventory_item_id'].'/'.(int) $row['warehouse_id']] = $row;
        }
        $levels = [];
        foreach ($actual + array_fill_keys(array_keys($cached), 0.0) as $key => $qty) {
            list($level, $warehouseId) = array_map('intval', explode('/', $key));
            $levels[$level] = (isset($levels[$level]) ? $levels[$level] : 0.0) + $qty;
            $code = static::balanceSku($id, $level);
            if (!isset($cached[$key])) {
                if (abs($qty) > 0.00005) {
                    static::writeBalance($id, $level, $warehouseId, $qty);
                    $report('inventory_stock', $id.'/'.$key, 'qty', $code, null, $qty);
                }
                continue;
            }
            $old = (float) $cached[$key]['qty'];
            if (abs($old - $qty) > 0.00005 || (string) $cached[$key]['sku'] !== $code) {
                static::writeBalance($id, $level, $warehouseId, $qty);
                if (abs($old - $qty) > 0.00005) {
                    $report('inventory_stock', $id.'/'.$key, 'qty', $code, $old, $qty);
                }
                if ((string) $cached[$key]['sku'] !== $code) {
                    $report('inventory_stock', $id.'/'.$key, 'sku', $code, (string) $cached[$key]['sku'], $code);
                }
            }
        }

        // 3. ยอดจอง = เอกสารที่จองของที่ยังเปิด — ทุกแถวยอด และทุกระดับ/คลังที่เอกสารจองถึง
        $keys = [];
        foreach (static::createQuery()->select('inventory_item_id', 'warehouse_id', 'reserved_qty')
            ->from('inventory_stock')->where(['inventory_id', $id])->execute(null, 'array')->fetchAll() as $row) {
            $keys[(int) $row['inventory_item_id'].'/'.(int) $row['warehouse_id']] = (float) $row['reserved_qty'];
        }
        $types = static::reserveTypes();
        if (!empty($types)) {
            $product = static::product($id);
            foreach (static::createQuery()
                ->select('T.inventory_item_id')
                ->selectRaw('IFNULL(O.`warehouse_id`, '.(int) \Inventory\Warehouse\Model::defaultId().') AS `warehouse_id`')
                ->from('order_items T')
                ->join('orders O', ['O.id', 'T.order_id'])
                ->where([['T.inventory_id', $id], ['O.document_type', $types], ['O.document_status', 'issued']])
                ->execute(null, 'array')->fetchAll() as $row) {
                $key = self::stockLevel($product, (int) $row['inventory_item_id']).'/'.(int) $row['warehouse_id'];
                if (!isset($keys[$key])) {
                    $keys[$key] = 0.0;
                }
            }
        }
        foreach ($keys as $key => $old) {
            list($level, $warehouseId) = array_map('intval', explode('/', $key));
            $reserved = round(static::syncReserved($id, $level, $warehouseId), 4);
            if (abs($old - $reserved) > 0.00005) {
                $report('inventory_stock', $id.'/'.$key, 'reserved_qty', static::balanceSku($id, $level), $old, $reserved);
            }
        }

        // 4. ค่าบน variant และแถวชิ้น
        $stock = round(array_sum($levels), 4);
        $cost = Fifo::nextCost($id);
        $update = [];
        if (abs((float) $variant['stock'] - $stock) > 0.00005) {
            $update['stock'] = $stock;
            $report('inventory', $id, 'stock', $sku, (float) $variant['stock'], $stock);
        }
        if ($cost !== null && abs((float) $variant['cost'] - $cost) > 0.00005) {
            $update['cost'] = $cost;
            $report('inventory', $id, 'cost', $sku, (float) $variant['cost'], $cost);
        }
        if (!empty($update)) {
            $db->update(Base::table('inventory'), ['id', $id], $update);
        }
        foreach ($items as $itemId => $item) {
            // แถวชิ้น = ยอดของเครื่องนั้นรวมทุกคลัง · แถวหน่วยขายไม่ถือยอด (ยอดอยู่ที่ variant)
            $qty = $item['serial_no'] === null ? 0.0 : round(isset($levels[$itemId]) ? $levels[$itemId] : 0.0, 4);
            if (abs((float) $item['stock'] - $qty) > 0.00005) {
                $db->update(Base::table('inventory_items'), ['id', $itemId], ['stock' => $qty]);
                $report('inventory_items', $itemId, 'stock',
                    (string) ($item['serial_no'] === null ? $item['barcode'] : $item['serial_no']), (float) $item['stock'], $qty);
            }
        }
        $topic = \Inventory\Product\Model::refreshTopic($id);
        if ($topic !== null && $topic !== (string) $variant['topic']) {
            $report('inventory', $id, 'topic', $sku, (string) $variant['topic'], $topic);
        }

        // 5. ทุนของบรรทัดเอกสาร = ทุนของการลงบัญชีที่ยังมีผลของบรรทัดนั้น · ไม่มี (ร่าง · ยกเลิก · ไม่นับสต๊อก) = NULL
        $active = [];
        foreach (static::createQuery()
            ->select('M.id', 'M.reference_item_id')
            ->from('inventory_stock_movement M')
            ->where([['M.inventory_id', $id], ['M.reference_type', 'order'], ['M.source_movement_id', null]])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM `'.Base::table('inventory_stock_movement').'` R'
                .' WHERE R.`source_movement_id` = M.`id`)')
            ->execute(null, 'array')->fetchAll() as $row) {
            $active[(int) $row['reference_item_id']] = (int) $row['id'];
        }
        foreach (static::createQuery()->select('id', 'code', 'cost_price')->from('order_items')
            ->where(['inventory_id', $id])->execute(null, 'array')->fetchAll() as $row) {
            $lineId = (int) $row['id'];
            $cost = isset($active[$lineId]) ? round(static::movementCost($active[$lineId]), 4) : null;
            $old = $row['cost_price'] === null ? null : (float) $row['cost_price'];
            if (($old === null) !== ($cost === null) || ($old !== null && abs($old - $cost) > 0.00005)) {
                $db->update(Base::table('order_items'), ['id', $lineId], ['cost_price' => $cost]);
                $report('order_items', $lineId, 'cost_price', (string) $row['code'], $old, $cost);
            }
        }

        return $diff;
    }

    /**
     * ทุนรวมของการเคลื่อนไหวหนึ่งรายการจากข้อมูลดิบ — ขาออก = Σ allocation · ขาเข้า = ทุนที่รับ
     *
     * ค่าที่บรรทัดเอกสารเก็บเป็น cost_price (DESIGN.md ข้อ 3.6) — ส่วนที่ตัดเกินชั้นต้นทุน
     * (สินค้ายอมติดลบ) ไม่มีทุนให้คิด จึงไม่อยู่ในผลรวม
     *
     * @param int $movementId
     *
     * @return float
     */
    public static function movementCost($movementId)
    {
        $movement = static::createDB()->first(Base::table('inventory_stock_movement'), ['id' => (int) $movementId]);
        if (!$movement) {
            return 0.0;
        }
        if ($movement->movement_direction === 'in') {
            return (float) $movement->total_cost;
        }
        $row = static::createQuery()
            ->selectRaw('SUM(`total_cost`) AS `cost`')
            ->from('inventory_cost_allocation')
            ->where(['movement_id', (int) $movementId])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['cost'] === null ? 0.0 : (float) $row[0]['cost'];
    }

    /**
     * เติมค่าที่ขาดและตรวจความถูกต้องของข้อมูลที่ส่งเข้ามา
     *
     * @param array $movement
     *
     * @throws \Exception
     *
     * @return array
     */
    protected static function normalize(array $movement)
    {
        $movement += [
            'inventory_item_id' => 0,
            'sku' => '',
            'movement_type' => '',
            'quantity' => 0,
            'unit_cost' => null,
            'lot_no' => null,
            'expiry_date' => null,
            'from_movement_id' => null,
            'reference_type' => null,
            'reference_id' => null,
            'reference_no' => null,
            'reference_item_id' => null,
            'source_movement_id' => null,
            'occurred_at' => date('Y-m-d H:i:s'),
            'created_by' => null,
            'note' => null
        ];
        $movement['inventory_id'] = isset($movement['inventory_id']) ? (int) $movement['inventory_id'] : 0;
        $movement['inventory_item_id'] = (int) $movement['inventory_item_id'];
        $movement['quantity'] = (float) $movement['quantity'];
        // ทุกรายการต้องมีคลังจริง — ผู้เรียกที่ไม่รู้เรื่องคลัง (โมดูลเก่า · ยอดยกมา)
        // ได้คลังตั้งต้นของไซต์ไปโดยไม่ต้องแก้อะไร
        $movement['warehouse_id'] = \Inventory\Warehouse\Model::resolve(
            isset($movement['warehouse_id']) ? $movement['warehouse_id'] : 0
        );

        if ($movement['inventory_id'] <= 0) {
            throw new \Exception('การเคลื่อนไหวของสต๊อกต้องระบุสินค้า');
        }
        if (Base::movementDirection($movement['movement_type']) === null) {
            throw new \Exception('ไม่รู้จักชนิดการเคลื่อนไหว "'.$movement['movement_type'].'"');
        }
        if ($movement['quantity'] <= 0) {
            // จำนวนติดลบต้องแสดงด้วยชนิดการเคลื่อนไหวที่เป็นขาออก ไม่ใช่ตัวเลขติดลบ
            // ไม่งั้นผลรวมของสมุดบัญชีจะขึ้นกับว่าใครใส่เครื่องหมายไว้ตรงไหน
            throw new \Exception('จำนวนในการเคลื่อนไหวของสต๊อกต้องมากกว่าศูนย์');
        }
        // หน่วยขาย/ชิ้นที่อ้างต้องเป็นของ variant นี้ — อ้างข้ามสินค้าแล้วยอดไปลงผิดกองเงียบ ๆ
        $movement['piece'] = false;
        if ($movement['inventory_item_id'] > 0) {
            $item = static::createDB()->first(Base::table('inventory_items'), ['id' => $movement['inventory_item_id']]);
            if (!$item || (int) $item->inventory_id !== $movement['inventory_id']) {
                throw new \Exception('หน่วยขาย/ชิ้นรหัส '.$movement['inventory_item_id'].' ไม่ใช่ของสินค้ารหัส '.$movement['inventory_id']);
            }
            $movement['piece'] = $item->serial_no !== null && $item->serial_no !== '';
            // รหัสที่ใช้จริงบนแถวสมุดบัญชี (ข้อมูลดิบ) — serial ของชิ้น · บาร์โค้ดของหน่วยขาย ·
            // ไม่ระบุหน่วย = sku ของ variant ยอดจะลงระดับไหนก็ตาม ยังตรวจย้อนได้ว่าขายเป็นลังหรือเป็นชิ้น
            if ($movement['sku'] === '') {
                $movement['sku'] = (string) ($movement['piece'] ? $item->serial_no : $item->barcode);
            }
        }
        if ($movement['sku'] === '') {
            $movement['sku'] = static::balanceSku($movement['inventory_id'], 0);
        }
        if ($movement['unit_cost'] !== null) {
            $movement['unit_cost'] = (float) $movement['unit_cost'];
        }
        // ล็อต (DESIGN.md ข้อ 3.4) — ว่าง = ไม่ระบุ
        foreach (['lot_no', 'expiry_date'] as $key) {
            $value = $movement[$key] === null ? '' : trim((string) $movement[$key]);
            $movement[$key] = $value === '' ? null : $value;
        }
        if ($movement['lot_no'] !== null && mb_strlen($movement['lot_no']) > 50) {
            throw new \Exception('เลขล็อตยาวได้ไม่เกิน 50 ตัวอักษร');
        }
        if ($movement['expiry_date'] !== null) {
            $date = \DateTime::createFromFormat('!Y-m-d', $movement['expiry_date']);
            if (!$date || $date->format('Y-m-d') !== $movement['expiry_date']) {
                throw new \Exception('วันหมดอายุ "'.$movement['expiry_date'].'" ไม่ถูกต้อง (ปปปป-ดด-วว)');
            }
        }

        return $movement;
    }

    /**
     * ข้อมูลสินค้าที่จำเป็นต่อการตัดสินใจ
     *
     * @param int $inventoryId
     *
     * @throws \Exception เมื่อไม่พบสินค้า
     *
     * @return array
     */
    protected static function product($inventoryId)
    {
        // บรรทัดที่ไม่มีสินค้า (เช่น หักเงินมัดจำ · accounting) = ไม่นับสต๊อก ทุกทางที่เดินสต๊อกข้ามเอง
        if ((int) $inventoryId <= 0) {
            return ['topic' => '', 'count_stock' => 0, 'allow_negative' => 0];
        }
        // วิธีนับและการยอมติดลบเป็นของสินค้า ใช้ร่วมกันทุก variant
        $rows = static::createQuery()
            ->select('V.topic', 'V.sku', 'P.count_stock', 'P.allow_negative')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            throw new \Exception('ไม่พบสินค้ารหัส '.(int) $inventoryId);
        }

        return [
            'topic' => (string) $rows[0]['topic'],
            'sku' => (string) $rows[0]['sku'],
            'count_stock' => (int) $rows[0]['count_stock'],
            'allow_negative' => (int) $rows[0]['allow_negative']
        ];
    }

    /**
     * บวก/ลบยอดคงเหลือ แล้วอัปเดตค่าที่ตารางเดิมเก็บไว้ให้ตรงกัน
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param int   $warehouseId
     * @param float $signed จำนวนพร้อมเครื่องหมาย
     */
    protected static function applyBalance($inventoryId, $inventoryItemId, $warehouseId, $signed)
    {
        static::writeBalance(
            $inventoryId,
            $inventoryItemId,
            $warehouseId,
            static::balance($inventoryId, $inventoryItemId, $warehouseId) + $signed
        );
    }

    /**
     * รหัสที่ควรอยู่บนแถวยอดคงเหลือ — ระดับ 0 = sku ของ variant · แถวชิ้น = serial ของเครื่อง
     *
     * ⚠️ แถวระดับ 0 เป็นยอดของ **ตัวสินค้า** ไม่ใช่ของหน่วยขายใดหน่วยขายหนึ่ง
     * ถ้าเขียนรหัสของหน่วยที่เพิ่งขายลงไปตรง ๆ สินค้าที่ขายหลายหน่วย (ยกลัง/ยกโหล)
     * จะได้แถวที่บอกว่า "1128 · WATER-600-B (ลัง)" ทั้งที่ยอดนั้นเป็นขวด
     * — ตัวเลขถูกแต่หน่วยผิด และผิดสลับไปมาตามบิลใบล่าสุด (รหัสที่ยิงจริงอยู่บนแถวสมุดบัญชี)
     *
     * @param int $inventoryId
     * @param int $inventoryItemId ระดับที่ลงบัญชี
     *
     * @return string
     */
    protected static function balanceSku($inventoryId, $inventoryItemId)
    {
        if ((int) $inventoryItemId > 0) {
            $item = static::createDB()->first(Base::table('inventory_items'), ['id' => (int) $inventoryItemId]);

            return $item ? (string) ($item->serial_no !== null && $item->serial_no !== '' ? $item->serial_no : $item->barcode) : '';
        }
        $variant = static::createDB()->first(Base::table('inventory'), ['id' => (int) $inventoryId]);

        return $variant ? (string) $variant->sku : '';
    }

    /**
     * เขียนยอดคงเหลือหนึ่งแถว พร้อมอัปเดต cache บน variant และแถวชิ้น
     *
     * inventory.stock / inventory.cost / inventory_items.stock มีคนอ่าน (POS · ร้านออนไลน์ ·
     * ทะเบียนสินค้า) จึงต้องเขียนตามให้ตรงเสมอ ไม่ใช่ปล่อยให้ค้างค่าเดิม
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param int   $warehouseId
     * @param float $qty
     */
    protected static function writeBalance($inventoryId, $inventoryItemId, $warehouseId, $qty)
    {
        $db = static::createDB();
        $table = Base::table('inventory_stock');
        $sku = static::balanceSku($inventoryId, $inventoryItemId);
        $condition = [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => (int) $warehouseId
        ];
        if ($db->exists($table, $condition)) {
            $db->update($table, [
                ['inventory_id', $inventoryId],
                ['inventory_item_id', $inventoryItemId],
                ['warehouse_id', (int) $warehouseId]
            ], [
                'qty' => $qty,
                'sku' => $sku,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $db->insert($table, [
                'inventory_id' => $inventoryId,
                'inventory_item_id' => $inventoryItemId,
                'warehouse_id' => (int) $warehouseId,
                'sku' => $sku,
                'qty' => $qty,
                'reserved_qty' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // cache ของตารางเดิม — ยอดรวมทั้งสินค้า และยอดของหน่วยย่อยรายตัว
        $row = static::createQuery()
            ->selectRaw('SUM(`qty`) AS `total`')
            ->from('inventory_stock')
            ->where(['inventory_id', $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();
        $total = empty($row) || $row[0]['total'] === null ? 0 : (float) $row[0]['total'];
        $cache = ['stock' => $total];
        // ต้นทุนของหน่วยถัดไปที่จะขาย (แสดงผล) — ไม่เคยรับของเข้าเลย คงค่าที่ผู้ใช้กรอกไว้
        $cost = Fifo::nextCost($inventoryId);
        if ($cost !== null) {
            $cache['cost'] = $cost;
        }
        $db->update(Base::table('inventory'), ['id', $inventoryId], $cache);
        if ($inventoryItemId > 0) {
            // ⚠️ ยอดของหน่วยย่อยก็ต้องรวมทุกคลัง — ถ้าเขียน $qty (ยอดของคลังที่เพิ่งแตะ)
            // ลงไป หน่วยย่อยที่มีของสองคลังจะโชว์แค่ยอดของคลังล่าสุด สลับไปมาตามบิล
            $db->update(Base::table('inventory_items'), ['id', $inventoryItemId], [
                'stock' => static::balance($inventoryId, $inventoryItemId)
            ]);
        }
    }

    /**
     * ชื่อคลังสำหรับข้อความผิดพลาด — ว่างเมื่อไซต์มีคลังเดียว (ไม่ต้องพูดถึง)
     *
     * @param int $warehouseId
     *
     * @return string
     */
    protected static function warehouseName($warehouseId)
    {
        if (!\Inventory\Warehouse\Model::isMultiple()) {
            return '';
        }
        $warehouse = \Inventory\Warehouse\Model::get($warehouseId);

        return $warehouse === null ? '' : (string) $warehouse['name'];
    }

    /**
     * จำนวนในรูปที่อ่านง่ายสำหรับข้อความแจ้งผู้ใช้
     *
     * @param float $value
     *
     * @return string
     */
    protected static function format($value)
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
    }
}
