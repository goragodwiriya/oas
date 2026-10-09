<?php
/**
 * @filesource modules/inventory/models/stocks.php
 *
 * ความเคลื่อนไหวสต๊อกของสินค้าหนึ่งตัว — หน้า /inventory-stock และการ์ดในหน้า
 * /inventory-overview  (ระบบเดิมของ oms คือ module=inventory-write&tab=stock)
 *
 * ⚠️ ต่างจากรุ่นของ oms ทั้งชั้น : รุ่นนั้นอ่านตาราง `stock` (บรรทัดของเอกสาร)
 *    แล้วเดา "ทิศทาง" เอาจากชนิดเอกสารผ่าน directions() และคิด FIFO เองในไฟล์นี้
 *    รุ่นนี้อ่านจากสมุดบัญชี `inventory_stock_movement` ซึ่งบันทึกทิศทางไว้ในตัวเอง
 *    (`movement_direction`) และคิดต้นทุนจากชั้นต้นทุนที่ Posting/Fifo เขียนไว้
 *
 *    เหตุผล : บรรทัดเอกสาร ≠ การเดินสต๊อก ใบเสนอราคามีบรรทัดแต่ไม่แตะสต๊อก
 *    ส่วนยอดยกมาและการปรับยอดแตะสต๊อกโดยไม่มีเอกสารเลย การนับจากบรรทัดเอกสาร
 *    จึงได้ตัวเลขที่ไม่ตรงกับยอดคงเหลือจริงเสมอ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Stocks;

use Inventory\Base\Model as Base;
use Inventory\Fifo\Model as Fifo;
use Inventory\Posting\Model as Posting;
use Kotchasan\Database\Sql;

/**
 * Model ความเคลื่อนไหวสต๊อกของสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชนิดการเคลื่อนไหวแยกตามทิศทาง สำหรับใส่ในตัวกรอง
     *
     * ทิศทางอ่านจากทะเบียนกลาง (Base::movementTypes) ไม่ใช่เดาจากชนิดเอกสาร
     *
     * @return array ['in' => [...], 'out' => [...]]
     */
    public static function directions()
    {
        $in = [];
        $out = [];
        foreach (Base::movementTypes() as $type => $direction) {
            if ($direction === 'in') {
                $in[] = $type;
            } else {
                $out[] = $type;
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /**
     * Query รายการเคลื่อนไหวสต๊อกสำหรับตาราง
     *
     * ⚠️ ห้ามใส่ orderBy() ที่นี่ — Gcms\Table ห่อ query นี้เป็น COUNT(*) subquery
     * การเรียงลำดับเป็นหน้าที่ของ executeDataTable()
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\SelectBuilder
     */
    public static function toDataTable(array $params)
    {
        $where = [
            ['M.inventory_id', (int) $params['inventory_id']]
        ];

        if (!empty($params['status'])) {
            $where[] = ['M.movement_type', $params['status']];
        }
        if (!empty($params['direction'])) {
            $where[] = ['M.movement_direction', $params['direction']];
        }
        if (!empty($params['year'])) {
            $where[] = [Sql::YEAR('M.occurred_at'), (int) $params['year']];
        }
        if (!empty($params['month'])) {
            $where[] = [Sql::MONTH('M.occurred_at'), (int) $params['month']];
        }

        // ชื่อคอลัมน์ฝั่งขาออกคงชื่อเดิมของหน้าเว็บไว้ (create_date/status/price/total)
        // เทมเพลตและตัวจัดรูปแบบฝั่ง JS อ้างชื่อพวกนี้อยู่ เปลี่ยนชื่อ = ตารางว่าง
        // ⚠️ คอลัมน์ธรรมดาทุกตัวต้องอยู่ใน select() ครั้งเดียวนี้เท่านั้น
        // QueryBuilder::select() **เขียนทับ** $this->columns ทั้งชุด ส่วน selectRaw()
        // ต่อท้าย (Kotchasan/QueryBuilder/QueryBuilder.php:270 กับ :367) เรียก select()
        // คั่นหลัง selectRaw() เมื่อไร คอลัมน์ที่สะสมไว้ก่อนหน้าหายหมดทันที
        // และตารางจะคืนค่าว่างโดยไม่มีข้อผิดพลาดให้เห็น
        return static::createQuery()
            ->select('M.id', 'M.movement_direction', 'M.quantity', 'M.reference_type')
            // หน้าเว็บอ้างชื่อ order_id เดิม (ทำลิงก์ไปหน้าเอกสาร) จึงคงชื่อไว้
            ->selectRaw('IFNULL(M.`reference_id`, 0) AS `order_id`')
            ->selectRaw('M.`occurred_at` AS `create_date`')
            ->selectRaw('M.`movement_type` AS `status`')
            ->selectRaw('M.`unit_cost` AS `price`')
            ->selectRaw('M.`total_cost` AS `total`')
            ->selectRaw('M.`sku` AS `product_no`')
            ->selectRaw('M.`note` AS `topic`')
            ->selectRaw('IFNULL(O.`order_no`, M.`reference_no`) AS `order_no`')
            ->from('inventory_stock_movement M')
            ->join('orders O', ['O.id', 'M.reference_id'], 'LEFT')
            ->where($where);
    }

    /**
     * ปีที่มีการเคลื่อนไหวของสินค้า สำหรับใส่ในตัวกรอง
     *
     * ระบบเดิมเติมปีปัจจุบันเข้าไปเสมอ เพื่อให้เลือกปีนี้ได้แม้ยังไม่มีรายการ
     *
     * @param int $id
     *
     * @return array [['value' => 2026, 'text' => '2569'], ...]
     */
    public static function listYears($id)
    {
        $rows = static::createQuery()
            ->selectRaw('DISTINCT YEAR(`occurred_at`) AS `y`')
            ->from('inventory_stock_movement')
            ->where(['inventory_id', (int) $id])
            ->execute(null, 'array')
            ->fetchAll();

        // YEAR_OFFSET มีเฉพาะภาษาไทย (พ.ศ.) ภาษาอื่นคืนชื่อคีย์กลับมา ต้องกันไว้
        $offset = \Kotchasan\Language::get('YEAR_OFFSET', 0);
        $offset = is_numeric($offset) ? (int) $offset : 0;

        $years = [];
        foreach ($rows as $row) {
            if (!empty($row['y'])) {
                $years[(int) $row['y']] = true;
            }
        }
        $years[(int) date('Y')] = true;
        krsort($years);

        $result = [];
        foreach (array_keys($years) as $y) {
            $result[] = ['value' => $y, 'text' => (string) ($y + $offset)];
        }

        return $result;
    }

    /**
     * ตัวเลขสรุปของสินค้าหนึ่งตัว
     *
     * buy/sell         จำนวนที่รับเข้า/จ่ายออกสะสม (จากสมุดบัญชี)
     * circulation      มูลค่าที่ขายไป (ราคาขาย ไม่รวมภาษี)
     * cost             ต้นทุนของที่ขายไปแล้ว (จากชั้นต้นทุนที่ถูกตัดจริง)
     * instock          ยอดคงเหลือปัจจุบัน
     * balances         มูลค่าคงเหลือตามต้นทุน FIFO
     *
     * @param int $id
     *
     * @return array
     */
    public static function summary($id)
    {
        $id = (int) $id;

        $rows = static::createQuery()
            ->select('M.movement_direction')
            ->selectRaw('SUM(M.`quantity`) AS `quantity`')
            ->selectRaw('SUM(IFNULL(M.`total_cost`, 0)) AS `total_cost`')
            ->from('inventory_stock_movement M')
            ->where(['M.inventory_id', $id])
            ->groupBy('M.movement_direction')
            ->execute(null, 'array')
            ->fetchAll();

        $result = [
            'buy' => 0,
            'sell' => 0,
            'circulation' => 0,
            'cost' => 0,
            'instock' => 0,
            'balances' => 0
        ];

        foreach ($rows as $row) {
            if ($row['movement_direction'] === 'in') {
                $result['buy'] += (float) $row['quantity'];
            } else {
                $result['sell'] += (float) $row['quantity'];
                // total_cost ของขาออกคือ "ต้นทุนของที่จ่ายออกไป" ที่ Fifo คิดให้แล้ว
                $result['cost'] += (float) $row['total_cost'];
            }
        }

        // ยอดขายอ่านจากบรรทัดเอกสารที่ผูกกับการเคลื่อนไหวขาออก เพราะสมุดบัญชี
        // เก็บ "ต้นทุน" ไม่ได้เก็บ "ราคาขาย" — สองอย่างนี้คนละตัวเลขกัน
        $sold = static::createQuery()
            ->selectRaw('SUM(I.`quantity` * I.`price` - I.`discount`) AS `amount`')
            ->from('inventory_stock_movement M')
            ->join('order_items I', ['I.id', 'M.reference_item_id'], 'INNER')
            ->where([
                ['M.inventory_id', $id],
                ['M.movement_direction', 'out'],
                ['M.reference_type', 'order'],
                ['M.source_movement_id', null]
            ])
            ->execute(null, 'array')
            ->fetchAll();
        if (!empty($sold)) {
            $result['circulation'] = (float) $sold[0]['amount'];
        }

        // ⚠️ ต้องเป็นยอด "ระดับสินค้า" ไม่ใช่ระดับหน่วยย่อย — balance()/value() กรอง
        // ด้วย inventory_item_id เสมอ (ปริยาย 0) สินค้าที่นับสต๊อกแยกรายชิ้นจึงได้ 0
        $result['instock'] = Posting::totalBalance($id);
        list(, $value) = Fifo::totalValue($id);
        $result['balances'] = $value;

        return $result;
    }

    /**
     * สรุปจำนวนรับเข้า/จ่ายออกรายเดือนของปีที่เลือก (สำหรับกราฟ)
     *
     * @param int $id
     * @param int $year
     *
     * @return array [['month' => 1, 'label' => 'ม.ค.', 'buy' => 0, 'sell' => 0], ...]
     */
    public static function monthlyReport($id, $year)
    {
        $rows = static::createQuery()
            ->selectRaw('MONTH(M.`occurred_at`) AS `m`')
            ->selectRaw('M.`movement_direction` AS `d`')
            ->selectRaw('SUM(M.`quantity`) AS `quantity`')
            ->from('inventory_stock_movement M')
            ->where([
                ['M.inventory_id', (int) $id],
                [Sql::YEAR('M.occurred_at'), (int) $year]
            ])
            ->groupBy(['m', 'd'])
            ->execute(null, 'array')
            ->fetchAll();

        $months = \Kotchasan\Language::get('MONTH_SHORT');
        $result = [];
        for ($m = 1; $m <= 12; ++$m) {
            $result[$m] = [
                'month' => $m,
                'label' => isset($months[$m]) ? $months[$m] : (string) $m,
                'buy' => 0,
                'sell' => 0
            ];
        }

        foreach ($rows as $row) {
            $m = (int) $row['m'];
            if (!isset($result[$m])) {
                continue;
            }
            if ($row['d'] === 'in') {
                $result[$m]['buy'] += (float) $row['quantity'];
            } else {
                $result[$m]['sell'] += (float) $row['quantity'];
            }
        }

        return array_values($result);
    }

    /**
     * ปรับยอดคงเหลือให้ตรงกับที่นับได้จริง
     *
     * ⚠️ รับ "ยอดใหม่" ไม่ใช่ "ส่วนต่าง" แต่หายอดปัจจุบันเองตอนบันทึกเสมอ —
     * ห้ามเชื่อยอดที่ฟอร์มส่งมา เพราะคนสองคนที่เปิดฟอร์มพร้อมกันจะคำนวณ
     * ส่วนต่างจากยอดเดียวกัน แล้วคนที่บันทึกทีหลังจะกลืนการปรับของคนแรกทิ้ง
     *
     * การปรับยอดลงบัญชีผ่าน Posting API เหมือนทุกอย่างที่แตะสต๊อก จึงมีประวัติ
     * ว่าใครปรับ ปรับเมื่อไร เพราะอะไร — ต่างจากระบบเดิมที่เขียนทับค่า stock
     * ตรง ๆ แล้วไม่เหลือร่องรอยว่ายอดเคยเป็นเท่าไร
     *
     * @param int    $inventoryId
     * @param int    $inventoryItemId 0 = ยอดระดับสินค้า
     * @param float  $quantity        ยอดที่นับได้จริง
     * @param string $note            เหตุผล (บังคับ)
     * @param int    $memberId
     *
     * @throws \Exception เมื่อยอดใหม่เท่ายอดเดิม ไม่มีเหตุผล หรือ Posting ปฏิเสธ
     *
     * @return array ['movement_id' => int, 'difference' => float, 'balance' => float]
     */
    public static function adjust($inventoryId, $inventoryItemId, $quantity, $note, $memberId = 0)
    {
        $inventoryId = (int) $inventoryId;
        $inventoryItemId = (int) $inventoryItemId;
        $quantity = (float) $quantity;
        $note = trim((string) $note);
        if ($note === '') {
            // เหตุผลคือสิ่งเดียวที่อธิบายได้ว่าทำไมยอดถึงกระโดด บังคับกรอกเหมือนระบบเดิม
            throw new \Exception(\Kotchasan\Language::get('Please fill in'));
        }

        $current = Posting::balance($inventoryId, $inventoryItemId);
        if (abs($quantity - $current) < 0.0000001) {
            throw new \Exception(
                \Kotchasan\Language::get('New Stock').' '.\Kotchasan\Language::get('equal to')
                .' '.\Kotchasan\Language::get('Quantity On Hand')
            );
        }

        $difference = $quantity - $current;
        $movementId = Posting::post([
            'inventory_id' => $inventoryId,
            'inventory_item_id' => $inventoryItemId,
            'movement_type' => $difference > 0 ? 'adjust_in' : 'adjust_out',
            'quantity' => abs($difference),
            'reference_type' => 'adjustment',
            'created_by' => (int) $memberId,
            'note' => $note
        ]);

        return [
            'movement_id' => (int) $movementId,
            'difference' => $difference,
            'balance' => Posting::balance($inventoryId, $inventoryItemId)
        ];
    }

    /**
     * หน่วยย่อยนี้เป็นของสินค้าตัวนี้จริงหรือไม่
     *
     * ตัวเลขที่ฟอร์มส่งมาเป็นกุญแจของตาราง ไม่ใช่ลำดับในกล่องเลือก ถ้าไม่ตรวจ
     * ผู้ใช้แก้ค่าในฟอร์มแล้วปรับยอดของสินค้าตัวอื่นได้
     *
     * @param int $inventoryId
     * @param int $itemId
     *
     * @return bool
     */
    public static function ownsItem($inventoryId, $itemId)
    {
        if ((int) $itemId <= 0) {
            return true;
        }

        $row = static::createDB()->first(Base::table('inventory_items'), [
            'id' => (int) $itemId,
            'inventory_id' => (int) $inventoryId
        ]);

        return $row ? true : false;
    }

    /**
     * ตัวเลือกหน่วยย่อยของสินค้า พร้อมยอดคงเหลือของแต่ละหน่วย
     *
     * มีเฉพาะสินค้าที่นับสต๊อกแยกรายชิ้น (count_stock = 2) — สินค้าที่นับรวม
     * มียอดเดียวที่ inventory_item_id = 0 จึงไม่มีอะไรให้เลือก
     *
     * ยอดคงเหลืออยู่ในข้อความของตัวเลือกเอง เพราะเลือกหน่วยไหนยอดก็เปลี่ยนตาม
     * ช่อง "ยอดคงเหลือปัจจุบัน" ช่องเดียวจึงบอกความจริงของทุกหน่วยไม่ได้
     *
     * @param int $inventoryId
     * @param int $countStock
     *
     * @return array
     */
    public static function unitOptions($inventoryId, $countStock)
    {
        if ((int) $countStock !== 2) {
            return [];
        }

        $options = [];
        foreach (\Inventory\Product\Model::items((int) $inventoryId) as $item) {
            $code = empty($item['sku']) ? (string) $item['product_no'] : (string) $item['sku'];
            $label = empty($item['topic']) ? $code : $code.' — '.$item['topic'];
            $balance = Posting::balance((int) $inventoryId, (int) $item['id']);
            $options[] = [
                'value' => (string) (int) $item['id'],
                'text' => $label.' ('.\Kotchasan\Language::get('Balance').' '
                    .rtrim(rtrim(number_format($balance, 2, '.', ''), '0'), '.').')'
            ];
        }

        return $options;
    }

    /**
     * ข้อความ "ที่มา" ของแถวหนึ่งในบัญชีเดินสต๊อก
     *
     * ⚠️ ยอดยกมากับการปรับยอดเป็นคนละเรื่อง แต่ทั้งคู่ไม่มี reference_id การเช็ค
     * order_id === 0 อย่างเดียวจึงป้ายว่า "ยอดยกมา" ให้ทุกการปรับยอด — ระบบเดิม
     * แยกด้วย order_id 0 กับ -1 ส่วนสมุดบัญชีแยกด้วย reference_type ซึ่งตรงกว่า
     *
     * @param string|null $orderNo       เลขที่เอกสาร (ว่างเมื่อแถวไม่ได้มาจากเอกสาร)
     * @param int         $orderId
     * @param string|null $referenceType
     * @param string|null $note
     *
     * @return array ['order_no' => string, 'order_url' => string, 'note' => string]
     */
    public static function describeSource($orderNo, $orderId, $referenceType, $note)
    {
        $orderId = (int) $orderId;
        $orderNo = $orderNo === null ? '' : (string) $orderNo;
        $note = $note === null ? '' : (string) $note;

        if ($orderNo === '') {
            if ($referenceType === 'adjustment') {
                $orderNo = \Kotchasan\Language::get('Inventory Adjust');
            } elseif ($orderId === 0) {
                $orderNo = \Kotchasan\Language::get('Beginning Inventory');
            } else {
                // เอกสารเก่าที่ไม่มีเลขที่ ให้แสดงรายละเอียดของบรรทัดแทนช่องว่าง
                $orderNo = $note === '' ? '#'.$orderId : $note;
            }
        }

        return [
            'order_no' => $orderNo,
            'order_url' => $orderId > 0 ? '/inventory-order?id='.$orderId : '',
            // เหตุผลมีค่าเฉพาะตอนที่เป็นข้อความที่คนเขียนไว้จริง แถวที่มาจากเอกสาร
            // เก็บเลขที่เอกสารไว้ในช่องเดียวกัน ซึ่งซ้ำกับคอลัมน์เลขที่เอกสารอยู่แล้ว
            'note' => $referenceType === 'adjustment' ? $note : ''
        ];
    }
}
