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
         *   movement_type     string ต้องอยู่ในทะเบียนกลาง (Base::movementTypes)
     *   quantity          float  เป็นบวกเสมอ ทิศทางมาจากชนิดการเคลื่อนไหว
     *   unit_cost         float  ราคาทุนต่อหน่วย (ใช้เฉพาะขาเข้า)
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

        $balance = static::balance($movement['inventory_id'], $movement['inventory_item_id']);
        // ⚠️ ของที่ "จอง" ไว้ให้ใบสั่งขายที่ยังเปิดอยู่ ไม่ใช่ของที่ขายได้ — ลูกค้าออนไลน์
        // จ่ายเงินแล้วต้องได้ของ ไม่ใช่มาถึงคิวส่งแล้วพบว่าหน้าร้านขายไปแล้วเมื่อเช้า
        $reserved = $direction === 'out'
            ? static::reserved($movement['inventory_id'], $movement['inventory_item_id'])
            : 0.0;
        if ($direction === 'out' && ($balance - $reserved + $signed) < 0 && empty($product['allow_negative'])) {
            throw new \Exception(
                'สต๊อกของ '.$product['topic']
                .' ไม่พอ — คงเหลือ '.static::format($balance)
                .($reserved > 0 ? ' (จองไว้แล้ว '.static::format($reserved).')' : '')
                .' แต่ต้องการตัดออก '.static::format($movement['quantity'])
            );
        }

        $db = static::createDB();
        $movement['id'] = (int) $db->insert(Base::table('inventory_stock_movement'), [
            'inventory_id' => $movement['inventory_id'],
            'inventory_item_id' => $movement['inventory_item_id'],
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
            $movement['sku'], $signed);

        return $movement['id'];
    }

    /**
     * ระดับที่ยอดคงเหลือของสินค้าตัวนี้ถูกเก็บไว้
     *
     * ⚠️ นี่คือกติกาที่ทำให้ขายแบบ "ยกลัง / ยกโหล" ได้ถูกต้อง
     *
     * `count_stock` เป็นตัวตัดสิน ไม่ใช่ว่าบรรทัดเอกสารบังเอิญอ้างหน่วยย่อยหรือไม่
     *
     *   1 = นับรวม        → ของกองเดียวที่ระดับสินค้า (inventory_item_id = 0)
     *                       หน่วยย่อยเป็น "หน่วยขาย" ที่มีราคาและตัวคูณ `cut_stock`
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
     *
     * @return int
     */
    public static function opening($inventoryId, $inventoryItemId, $quantity, $unitCost = 0)
    {
        if ($quantity == 0) {
            return 0;
        }

        return static::post([
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'movement_type' => $quantity > 0 ? 'opening' : 'adjust_out',
            'quantity' => abs($quantity),
            'unit_cost' => $unitCost,
            'reference_type' => 'opening',
            'note' => 'ยอดยกมาตอนเริ่มใช้สมุดบัญชีสต๊อก'
        ]);
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
            ->select('id', 'inventory_id', 'inventory_item_id', 'sku',
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
            $movement['sku'], $signed);
            ++$done;
        }

        return $done;
    }

    /**
     * ยอดคงเหลือปัจจุบัน
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     *
     * @return float
     */
    public static function balance($inventoryId, $inventoryItemId = 0)
    {
        // อ่านต้องใช้ระดับเดียวกับที่เขียน ไม่งั้นหน้าที่ถามยอดของหน่วยย่อยจะได้ 0
        // ทั้งที่ของมีอยู่ในกองรวม (ดู stockLevel)
        if ((int) $inventoryItemId > 0) {
            $product = static::createDB()->first(Base::table('inventory'), ['id' => (int) $inventoryId]);
            if ($product) {
                $inventoryItemId = self::stockLevel(['count_stock' => (int) $product->count_stock], $inventoryItemId);
            }
        }

        $row = static::createQuery()
            ->selectRaw('SUM(`qty`) AS `qty`')
            ->from('inventory_stock')
            ->where([
                ['inventory_id', (int) $inventoryId],
                ['inventory_item_id', (int) $inventoryItemId]
            ])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * ยอดที่ถูกจองไว้ (ใบสั่งขายที่ยังเปิดอยู่)
     *
     * @param int $inventoryId
     * @param int $inventoryItemId
     *
     * @return float
     */
    public static function reserved($inventoryId, $inventoryItemId = 0)
    {
        $row = static::createQuery()
            ->selectRaw('SUM(`reserved_qty`) AS `qty`')
            ->from('inventory_stock')
            ->where([
                ['inventory_id', (int) $inventoryId],
                ['inventory_item_id', (int) $inventoryItemId]
            ])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($row) || $row[0]['qty'] === null ? 0.0 : (float) $row[0]['qty'];
    }

    /**
     * ยอดจองรวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย) — คู่กับ totalBalance()
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
     * ยอดที่ขายได้รวมของสินค้าหนึ่งตัว (รวมทุกหน่วยย่อย)
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
     *
     * @return float
     */
    public static function available($inventoryId, $inventoryItemId = 0)
    {
        return round(static::balance($inventoryId, $inventoryItemId)
            - static::reserved($inventoryId, $inventoryItemId), 4);
    }

    /**
     * คำนวณยอดจองใหม่จากใบสั่งขายที่ยังเปิดอยู่ แล้วเขียนลง reserved_qty
     *
     * ⚠️ ยอดจองเป็น "ผลสรุป" เหมือนยอดคงเหลือ — ความจริงคือเอกสารที่จองของ
     * (แม่แบบ reserve_stock = 1) ที่ยังไม่ถูกยกเลิก และ **ยังไม่มีใบลูกที่ออกแล้ว**
     * (ใบสั่งขายที่กลายเป็นใบเสร็จไปแล้ว ไม่จองอีก เพราะของถูกตัดจริงไปแล้ว)
     * จึงไม่มีตารางการจองแยก ไม่มีสถานะที่ต้องคอยเปลี่ยน — คำนวณจากเอกสารเสมอ
     * ยอดที่เพี้ยนซ่อมได้ด้วยการเรียกเมธอดนี้ซ้ำ เหมือน recalculate()
     *
     * @param int $inventoryId
     * @param int $inventoryItemId ระดับที่ลงบัญชี (ดู stockLevel)
     *
     * @return float ยอดจองที่เขียน
     */
    public static function syncReserved($inventoryId, $inventoryItemId)
    {
        $inventoryId = (int) $inventoryId;
        $inventoryItemId = (int) $inventoryItemId;

        $types = [];
        foreach (\Inventory\Document\Model::templates() as $type => $template) {
            if (!empty($template['reserve_stock'])) {
                $types[] = $type;
            }
        }

        $reserved = 0.0;
        if (!empty($types)) {
            $product = static::product($inventoryId);
            $ordersTable = Base::table('orders');
            $query = static::createQuery()
                ->selectRaw('SUM(IF(T.`cut_stock` > 0, T.`cut_stock`, T.`quantity`)) AS `qty`')
                ->from('order_items T')
                ->join('orders O', ['O.id', 'T.order_id'])
                ->where([
                    ['T.inventory_id', $inventoryId],
                    ['O.document_type', $types],
                    ['O.document_status', 'issued']
                ])
                // ใบที่มีใบลูกออกแล้ว (ถูกแปลงเป็นใบเสร็จ) ไม่จองอีก — ของถูกตัดจริงไปแล้ว
                ->whereRaw('NOT EXISTS (SELECT 1 FROM `'.$ordersTable.'` C'
                    ." WHERE C.`source_document_id` = O.`id` AND C.`document_status` = 'issued')");
            if ((int) $product['count_stock'] === 2) {
                // นับแยกรายชิ้น — จองที่หน่วยย่อยนั้น ๆ
                $query->where(['T.inventory_item_id', $inventoryItemId]);
            }
            $rows = $query->execute(null, 'array')->fetchAll();
            $reserved = empty($rows) || $rows[0]['qty'] === null ? 0.0 : (float) $rows[0]['qty'];
        }

        static::writeReserved($inventoryId, $inventoryItemId, $reserved);

        return $reserved;
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
            static::syncReserved((int) $line['inventory_id'], $level);
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
     * @param float $reserved
     */
    protected static function writeReserved($inventoryId, $inventoryItemId, $reserved)
    {
        $db = static::createDB();
        $table = Base::table('inventory_stock');
        $condition = [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId
        ];
        if ($db->exists($table, $condition)) {
            $db->update($table, [
                ['inventory_id', $inventoryId],
                ['inventory_item_id', $inventoryItemId]
            ], ['reserved_qty' => $reserved, 'updated_at' => date('Y-m-d H:i:s')]);
        } elseif ($reserved > 0) {
            $db->insert($table, [
                'inventory_id' => $inventoryId,
                'inventory_item_id' => $inventoryItemId,
                'sku' => static::balanceSku($inventoryId, $inventoryItemId, ''),
                'qty' => 0,
                'reserved_qty' => $reserved,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
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
     * คำนวณยอดคงเหลือใหม่จากสมุดบัญชีทั้งเล่ม แล้วเขียนทับยอดที่เก็บไว้
     *
     * สมุดบัญชีคือความจริง ยอดคงเหลือเป็นแค่ผลสรุปที่เก็บไว้ให้อ่านเร็ว
     * เมธอดนี้จึงเป็นเครื่องมือ "ซ่อมยอดที่เพี้ยน" ที่ตอบได้เสมอว่ายอดที่ถูกคืออะไร
     *
     * @param int $inventoryId 0 = คำนวณใหม่ทั้งระบบ
     *
     * @return int จำนวนแถวยอดคงเหลือที่เขียน
     */
    public static function recalculate($inventoryId = 0)
    {
        $query = static::createQuery()
            ->select('inventory_id', 'inventory_item_id')
            ->selectRaw("SUM(IF(`movement_direction` = 'in', `quantity`, -`quantity`)) AS `qty`")
            ->selectRaw('MAX(`sku`) AS `sku`')
            ->from('inventory_stock_movement');
        if ($inventoryId > 0) {
            $query->where(['inventory_id', $inventoryId]);
        }
        // ⚠️ groupBy() รับ "อาร์เรย์" หรือสตริงเดียว — ส่งหลายอาร์กิวเมนต์แล้วตัวหลัง
        // ถูกทิ้งเงียบ ๆ (ของเดิมจัดกลุ่มแค่ inventory_id หน่วยย่อยจึงเคยถูกรวมกอง)
        $rows = $query->groupBy(['inventory_id', 'inventory_item_id'])
            ->execute(null, 'array')->fetchAll();

        $written = 0;
        foreach ($rows as $row) {
            static::writeBalance(
                (int) $row['inventory_id'],
                (int) $row['inventory_item_id'],
                (string) $row['sku'],
                (float) $row['qty']
            );
            ++$written;
        }

        return $written;
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
        if ($movement['sku'] === '' && $movement['inventory_item_id'] > 0) {
            $item = static::createDB()->first(Base::table('inventory_items'), ['id' => $movement['inventory_item_id']]);
            $movement['sku'] = $item ? (string) $item->sku : '';
        }
        if ($movement['unit_cost'] !== null) {
            $movement['unit_cost'] = (float) $movement['unit_cost'];
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
        $row = static::createDB()->first(Base::table('inventory'), ['id' => $inventoryId]);
        if (!$row) {
            throw new \Exception('ไม่พบสินค้ารหัส '.(int) $inventoryId);
        }

        return [
            'topic' => (string) $row->topic,
            'count_stock' => (int) $row->count_stock,
            'allow_negative' => (int) $row->allow_negative
        ];
    }

    /**
     * บวก/ลบยอดคงเหลือ แล้วอัปเดตค่าที่ตารางเดิมเก็บไว้ให้ตรงกัน
     *
     * @param int    $inventoryId
     * @param int    $inventoryItemId
     * @param string $sku
     * @param float  $signed จำนวนพร้อมเครื่องหมาย
     */
    protected static function applyBalance($inventoryId, $inventoryItemId, $sku, $signed)
    {
        static::writeBalance(
            $inventoryId,
            $inventoryItemId,
            $sku,
            static::balance($inventoryId, $inventoryItemId) + $signed
        );
    }

    /**
     * รหัสที่ควรอยู่บนแถวยอดคงเหลือ
     *
     * ⚠️ แถวระดับ 0 เป็นยอดของ **ตัวสินค้า** ไม่ใช่ของหน่วยขายใดหน่วยขายหนึ่ง
     * ถ้าเขียน sku ของหน่วยที่เพิ่งขายลงไปตรง ๆ สินค้าที่ขายหลายหน่วย (ยกลัง/ยกโหล)
     * จะได้แถวที่บอกว่า "1128 · WATER-600-B (ลัง)" ทั้งที่ยอดนั้นเป็นขวด
     * — ตัวเลขถูกแต่หน่วยผิด และผิดสลับไปมาตามบิลใบล่าสุด
     *
     * @param int    $inventoryId
     * @param int    $inventoryItemId
     * @param string $sku sku ของหน่วยที่เคลื่อนไหว
     *
     * @return string
     */
    protected static function balanceSku($inventoryId, $inventoryItemId, $sku)
    {
        if ((int) $inventoryItemId > 0) {
            return (string) $sku;
        }
        $product = static::createDB()->first(Base::table('inventory'), ['id' => (int) $inventoryId], ['product_code']);

        return $product && $product->product_code !== '' ? (string) $product->product_code : (string) $sku;
    }

    /**
     * เขียนยอดคงเหลือหนึ่งแถว พร้อมอัปเดต cache ของตารางเดิม
     *
     * ตาราง inventory.stock (oms) และ inventory_items.stock (inventory/borrow/oas)
     * ยังมีอยู่และยังมีคนอ่าน จึงต้องเขียนตามให้ตรงเสมอ ไม่ใช่ปล่อยให้ค้างค่าเดิม
     *
     * @param int    $inventoryId
     * @param int    $inventoryItemId
     * @param string $sku
     * @param float  $qty
     */
    protected static function writeBalance($inventoryId, $inventoryItemId, $sku, $qty)
    {
        $db = static::createDB();
        $table = Base::table('inventory_stock');
        $sku = static::balanceSku($inventoryId, $inventoryItemId, $sku);
        $condition = [
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId
        ];
        if ($db->exists($table, $condition)) {
            $db->update($table, [
                ['inventory_id', $inventoryId],
                ['inventory_item_id', $inventoryItemId]
            ], [
                'qty' => $qty,
                'sku' => $sku,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $db->insert($table, [
                'inventory_id' => $inventoryId,
                'inventory_item_id' => $inventoryItemId,
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
        $db->update(Base::table('inventory'), ['id', $inventoryId], ['stock' => $total]);
        if ($inventoryItemId > 0) {
            $db->update(Base::table('inventory_items'), ['id', $inventoryItemId], [
                'stock' => $qty,
                'instock' => $qty > 0 ? 1 : 0
            ]);
        }
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
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }
}
