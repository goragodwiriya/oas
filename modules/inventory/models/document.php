<?php
/**
 * @filesource modules/inventory/models/document.php
 *
 * ชั้นเอกสาร — บันทึกหัวเอกสารกับบรรทัดรายการ แล้วสั่งเดินสต๊อกผ่าน Posting API
 *
 * สิ่งที่ต่างจากระบบเดิมของ oms อย่างมีนัยสำคัญ
 *   เดิม  บรรทัดเอกสารกับการเดินสต๊อกเป็นแถวเดียวกันในตาราง stock แล้วใช้ธง
 *         cut_stock แยกความหมาย ผลคือแก้เอกสารทีหนึ่งสต๊อกก็เปลี่ยนตามไปเงียบ ๆ
 *         และถ้ายอดเพี้ยนก็ไม่มีทางรู้ว่าเพี้ยนตอนไหนเพราะไม่มีประวัติ
 *   ใหม่  บรรทัดเอกสารอยู่ที่ order_items · การเดินสต๊อกอยู่ที่สมุดบัญชี
 *         การบันทึกเอกสารซ้ำ = กลับรายการของเดิมทั้งหมด แล้วลงใหม่ตามบรรทัดล่าสุด
 *         จึงได้ผลเหมือนกันทุกครั้งไม่ว่าจะกดบันทึกกี่รอบ และเห็นประวัติครบ
 *
 * บรรทัด (DESIGN.md ข้อ 3.6) : quantity = จำนวนหน่วยขาย · unit_factor = หน่วยฐานต่อหน่วยขาย ·
 * base_qty = หน่วยฐานที่รับ/ตัด · sku/code = รหัสของ variant / รหัสที่ยิงจริง ณ วันออกเอกสาร
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Document;

use Inventory\Base\Model as Base;
use Inventory\Fifo\Model as Fifo;
use Inventory\Posting\Model as Posting;

/**
 * Model ของเอกสารหนึ่งใบ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * แม่แบบเอกสารที่อ่านมาแล้ว คีย์คือชื่อตาราง (จึงมี prefix ของผู้เช่าติดอยู่)
     *
     * ⚠️ ห้ามเป็นแคชที่ไม่มีคีย์ — เมื่อถึงวันที่ทำระบบผู้เช่า คำขอของผู้เช่า ก
     * จะได้แม่แบบของผู้เช่า ข ทันทีที่สองคำขอใช้โพรเซสเดียวกัน (ข้อบังคับ 4.5)
     *
     * @var array
     */
    private static $templates = [];

    /**
     * แม่แบบเอกสารทั้งหมด คีย์คือ document_type
     *
     * @return array
     */
    public static function templates()
    {
        $table = Base::table('inventory_template');
        if (!isset(self::$templates[$table])) {
            $rows = static::createQuery()
                // ⚠️ หน้าพิมพ์อ่าน comment · due_date · signature_* จากแถวนี้ และฟอร์มเอกสาร
                // อ่าน due_date เพื่อซ่อนช่องครบกำหนด ขาดคอลัมน์ไหน ค่านั้นว่างเงียบ ๆ
                ->select('id', 'document_type', 'topic', 'mode', 'comment', 'due_date',
                    'signature_1', 'signature_2', 'signature_3', 'stock_in', 'stock_out', 'reserve_stock',
                    'movement_type', 'published', 'prefix', 'number_format', 'columns')
                ->from('inventory_template')
                ->execute(null, 'array')
                ->fetchAll();
            $templates = [];
            foreach ($rows as $row) {
                if ($row['document_type'] !== null && $row['document_type'] !== '') {
                    $templates[$row['document_type']] = $row;
                }
            }
            self::$templates[$table] = $templates;
        }

        return self::$templates[$table];
    }

    /**
     * แม่แบบของชนิดเอกสารหนึ่ง
     *
     * @param string $documentType
     *
     * @return array|null null = ไม่มีแม่แบบของชนิดนี้
     */
    public static function template($documentType)
    {
        $templates = self::templates();

        return isset($templates[$documentType]) ? $templates[$documentType] : null;
    }

    /**
     * ล้างแคชแม่แบบ (ใช้หลังแก้ไขแม่แบบ และในชุดทดสอบ)
     */
    public static function clearCache()
    {
        self::$templates = [];
    }

    /**
     * บันทึกเอกสารหนึ่งใบพร้อมบรรทัดรายการ แล้วเดินสต๊อกตามแม่แบบ
     *
     * @param array $header  ข้อมูลหัวเอกสาร ต้องมี document_type
     * @param array $lines   บรรทัดรายการ แต่ละแถวมี inventory_id, quantity, price ...
     * @param int   $orderId 0 = เอกสารใหม่
     * @param int   $memberId ผู้บันทึก
     *
     * @throws \Exception เมื่อชนิดเอกสารไม่มีแม่แบบ หรือสต๊อกไม่พอ
     *
     * @return int id ของเอกสาร
     */
    public static function save(array $header, array $lines, $orderId = 0, $memberId = 0)
    {
        $documentType = isset($header['document_type']) ? $header['document_type'] : '';
        $template = self::template($documentType);
        if ($template === null) {
            throw new \Exception('ไม่พบแม่แบบของเอกสารชนิด "'.$documentType.'" กรุณาตั้งค่าแม่แบบเอกสารก่อน');
        }

        // ⚠️ หัวเอกสาร + บรรทัด + การเดินสต๊อก ต้องเป็นก้อนเดียว — ถ้าเดินสต๊อกล้ม
        // (ของไม่พอ) แล้วปล่อยหัวกับบรรทัดค้างไว้ จะได้เอกสาร "ออกแล้ว" ที่ไม่มี
        // การเคลื่อนไหวในสมุดบัญชี ซึ่งดูเหมือนขายไปแล้วแต่ของยังอยู่ (เจอจริงในชุดทดสอบ 2026-09-12)
        return Base::transaction(function () use ($header, $lines, $orderId, $memberId, $template, $documentType) {
            // ถอนแล้วลงใหม่ — ใบรับที่ของถูกตัดไปแล้วต้องรับช่วงได้ครบ ไม่งั้นทั้งงานถูกปฏิเสธ (Fifo::finish)
            Fifo::begin();
            $savedId = self::write($header, $lines, $orderId, $memberId, $template, $documentType);
            Fifo::finish();

            return $savedId;
        });
    }

    /**
     * ตัวเขียนจริงของ save() — เรียกภายใน transaction เท่านั้น
     *
     * @param array  $header
     * @param array  $lines
     * @param int    $orderId
     * @param int    $memberId
     * @param array  $template
     * @param string $documentType
     *
     * @return int
     */
    protected static function write(array $header, array $lines, $orderId, $memberId, array $template, $documentType)
    {
        $db = static::createDB();
        $tableOrders = Base::table('orders');
        $tableItems = Base::table('order_items');
        $orderId = (int) $orderId;

        // ชื่อเดิมของชนิดเอกสารยังต้องเขียนคู่กันไว้ ระบบเดิมและรายงานเก่ายังอ่านอยู่
        $header['status'] = mb_substr($documentType, 0, 3);
        if (empty($header['order_date'])) {
            $header['order_date'] = date('Y-m-d H:i:s');
        }
        $header['updated_at'] = date('Y-m-d H:i:s');

        if ($orderId > 0) {
            // แก้ไขเอกสาร = ถอนผลของเดิมออกจากสมุดบัญชีก่อนเสมอ แล้วค่อยลงใหม่
            // ถ้าไม่ถอนก่อน การกดบันทึกซ้ำจะตัดสต๊อกซ้ำทุกครั้ง
            Posting::reverse('order', $orderId, $memberId);
            $db->update($tableOrders, ['id', $orderId], $header);
        } else {
            $header['member_id'] = $memberId;
            $header['created_at'] = date('Y-m-d H:i:s');
            $orderId = (int) $db->insert($tableOrders, $header);
        }
        $order = $db->first($tableOrders, ['id' => $orderId]);

        // ระบุเครื่องของสินค้านับรายชิ้นหลังถอนของเดิมแล้ว — เครื่องที่ใบนี้เคยขายกลับเข้าคลังก่อน
        // บรรทัดที่ระบุเครื่องไว้แล้วได้เครื่องเดิม (บันทึกซ้ำกี่รอบก็ไม่เปลี่ยนเครื่อง)
        $lines = self::prepareLines($lines, $order->document_status === 'draft' ? null : self::direction($template),
            empty($order->warehouse_id) ? 0 : (int) $order->warehouse_id);

        // สายของบรรทัด (บรรทัดต้นทางที่แปลงมา) ต้องอยู่รอดการบันทึกซ้ำ — ฟอร์มส่ง id ของบรรทัดเดิมกลับมา
        // แต่ไม่ได้ส่งสาย และบรรทัดถูกเขียนใหม่ทั้งชุด (ใบคืนต้องหาบรรทัดขาย/รับที่มาเจอเสมอ)
        $chain = [];
        foreach (self::lineChains($orderId) as $lineId => $link) {
            $chain[$lineId] = $link;
        }

        // บรรทัดรายการเขียนใหม่ทั้งชุด — ที่ทำได้เพราะการเดินสต๊อกไม่ได้อยู่ในแถวนี้แล้ว
        $db->delete($tableItems, ['order_id', $orderId], 0);
        foreach ($lines as $line) {
            $lineId = isset($line['id']) ? (int) $line['id'] : 0;
            if (empty($line['source_item_id']) && isset($chain[$lineId])) {
                $line['source_item_id'] = $chain[$lineId]['source_item_id'];
                $line['root_item_id'] = $chain[$lineId]['root_item_id'];
            }
            // ทุนของบรรทัดมาจากสมุดบัญชีตอนลงบัญชี (applyStock) ไม่ใช่ค่าที่ส่งมา
            unset($line['id'], $line['cost_price']);
            $line['order_id'] = $orderId;
            if (empty($line['create_date'])) {
                $line['create_date'] = $header['order_date'];
            }
            if (empty($line['member_id'])) {
                $line['member_id'] = $memberId;
            }
            $db->insert($tableItems, $line);
        }

        self::applyStock($orderId, $template, $memberId);

        return $orderId;
    }

    /**
     * ยกเลิกเอกสาร — ถอนผลออกจากสมุดบัญชีแต่เก็บเอกสารไว้
     *
     * ใบที่ยกเลิกแล้วยังต้องเปิดดูและพิมพ์ได้ เพราะเคยส่งให้ลูกค้าไปแล้ว
     *
     * @param int $orderId
     * @param int $memberId
     *
     * @return bool
     */
    public static function cancel($orderId, $memberId = 0)
    {
        $orderId = (int) $orderId;
        $db = static::createDB();
        $row = $db->first(Base::table('orders'), ['id' => $orderId]);
        if (!$row) {
            return false;
        }

        return Base::transaction(function () use ($db, $orderId, $memberId) {
            Fifo::begin();
            $done = self::doCancel($db, $orderId, $memberId);
            // ยกเลิกใบรับที่ของถูกตัดไปแล้วไม่ได้ — ต้องยกเลิกใบที่ตัดออกก่อน
            Fifo::finish();

            return $done;
        });
    }

    /**
     * @param \Kotchasan\DB $db
     * @param int           $orderId
     * @param int           $memberId
     *
     * @return bool
     */
    protected static function doCancel($db, $orderId, $memberId)
    {
        Posting::reverse('order', $orderId, $memberId);
        $db->update(Base::table('orders'), ['id', $orderId], [
            'document_status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        // ทุนของบรรทัด = ทุนของการลงบัญชีที่ยังมีผลอยู่ — ใบที่ยกเลิกแล้วไม่มี (ประวัติยังอยู่ในสมุดบัญชี)
        $db->update(Base::table('order_items'), ['order_id', $orderId], ['cost_price' => null]);

        return true;
    }

    /**
     * ลบเอกสารทิ้งทั้งใบ
     *
     * ถอนผลออกจากสมุดบัญชีก่อนเสมอ ไม่งั้นยอดคงเหลือจะค้างผลของเอกสารที่ไม่มีแล้ว
     * — เป็นข้อบกพร่องที่ระบบเดิมมีจริง (ลบเอกสารใบสุดท้ายแล้ว inventory.stock ค้างค่าเดิม)
     *
     * @param int $orderId
     * @param int $memberId
     *
     * @return bool
     */
    public static function remove($orderId, $memberId = 0)
    {
        $orderId = (int) $orderId;
        $db = static::createDB();
        if (!$db->exists(Base::table('orders'), ['id' => $orderId])) {
            return false;
        }

        return Base::transaction(function () use ($db, $orderId, $memberId) {
            Fifo::begin();
            $done = self::doRemove($db, $orderId, $memberId);
            Fifo::finish();

            return $done;
        });
    }

    /**
     * @param \Kotchasan\DB $db
     * @param int           $orderId
     * @param int           $memberId
     *
     * @return bool
     */
    protected static function doRemove($db, $orderId, $memberId)
    {
        Posting::reverse('order', $orderId, $memberId);

        $db->delete(Base::table('order_items'), ['order_id', $orderId], 0);
        $db->delete(Base::table('orders'), ['id', $orderId]);

        // ⚠️ โมดูลอื่นที่แขวนข้อมูลไว้กับเอกสารใบนี้ (บรรทัดการชำระของ payment · ใบจัดส่ง)
        // ต้องได้เก็บของของตัวเอง — โมดูลนี้ไม่รู้จักโมดูลไหนเป็นพิเศษ จึงประกาศเป็น
        // hook กลางแบบเดียวกับเมนู : โมดูลไหนมี onDocumentRemove ใน controllers/init.php
        // ก็ถูกเรียก ถอดโมดูลนั้นออกแล้วไม่มีอะไรล้ม
        // (ก่อน 2026-09-12 บรรทัดการชำระถูกทิ้งไว้เป็นแถวกำพร้าที่ชี้ไปเอกสารที่ไม่มีแล้ว)
        $hookParams = ['id' => $orderId, 'member_id' => (int) $memberId];
        \Gcms\Controller::initModule([], 'onDocumentRemove', null, $hookParams);

        // ⚠️ ตัดสายของแถวในสมุดบัญชีที่ชี้มาที่เอกสารที่เพิ่งลบไป
        //
        // แถวเก่ายังต้องอยู่ (สมุดบัญชีที่ลบแถวได้ไม่ใช่สมุดบัญชี) แต่ **id ของ
        // เอกสารถูกนำกลับมาใช้ใหม่ได้** — MariaDB คืนเลข AUTO_INCREMENT ให้เมื่อ
        // แถวท้ายสุดถูกลบ เอกสารใบถัดไปจึงได้ id เดียวกับใบที่ลบไปแล้ว
        //
        // ถ้าไม่ตัดสาย จะเกิดสองเรื่องพร้อมกัน และทั้งคู่หาสาเหตุยากมาก
        //   1. Posting::reverse('order', id ใหม่) จะไปกลับรายการของ "ใบเก่าที่ลบแล้ว"
        //      ด้วย → สต๊อกเพี้ยนโดยไม่มีอะไรฟ้อง
        //   2. ตารางความเคลื่อนไหว join orders ด้วย reference_id → แถวของใบเก่า
        //      จะแสดงเลขที่ของใบใหม่ อ่านแล้วเข้าใจผิดทั้งหน้า
        //
        // เลขที่เอกสารเดิมยังอยู่ในคอลัมน์ reference_no จึงไม่เสียร่องรอย
        $db->raw(
            'UPDATE `'.Base::table('inventory_stock_movement').'`'
            ." SET `reference_type` = 'order_removed', `reference_id` = NULL"
            ." WHERE `reference_type` = 'order' AND `reference_id` = ?",
            [$orderId]
        );

        return true;
    }

    /**
     * แปลงเอกสารเป็นใบใหม่อีกชนิดหนึ่ง (ใบเสนอราคา → ใบเสร็จ · ใบเสร็จ → ใบคืนสินค้า)
     *
     * ใบเดิมยังอยู่ครบ ใบใหม่ชี้กลับไปหาใบเดิมด้วย source_document_id — นี่คือหัวใจ
     * ของสายเอกสาร (ใบคืนสินค้าหาทุนของบรรทัดขายที่มาจากสายนี้)
     *
     * @param int    $orderId      ใบต้นทาง
     * @param string $documentType ชนิดของใบใหม่
     * @param int    $memberId
     * @param array  $override     ค่าหัวเอกสารที่ต้องการทับ (เช่น payment_status)
     *
     * @throws \Exception เมื่อไม่พบใบต้นทาง หรือชนิดใบใหม่ไม่มีแม่แบบ
     *
     * @return int id ของใบใหม่
     */
    public static function convert($orderId, $documentType, $memberId = 0, array $override = [])
    {
        $orderId = (int) $orderId;
        $db = static::createDB();
        $source = $db->first(Base::table('orders'), ['id' => $orderId]);
        if (!$source) {
            throw new \Exception('ไม่พบเอกสารต้นทางรหัส '.$orderId);
        }
        if (self::template($documentType) === null) {
            throw new \Exception('ไม่พบแม่แบบของเอกสารชนิด "'.$documentType.'"');
        }

        // หัวเอกสาร — ยกทุกอย่างมา ยกเว้นสิ่งที่เป็นของใบเดิมโดยเฉพาะ
        $header = (array) $source;
        foreach (['id', 'order_no', 'status', 'document_type', 'document_status', 'payment_status',
            'paid', 'change_amount', 'payment_date', 'payment_method', 'payment_ref',
            'completed_at', 'cancelled_at', 'created_at', 'updated_at', 'member_id'] as $key) {
            unset($header[$key]);
        }
        $header['document_type'] = $documentType;
        $header['document_status'] = 'issued';
        $header['payment_status'] = 'unpaid';
        $header['paid'] = 0;
        $header['order_date'] = date('Y-m-d H:i:s');
        $header['source_document_id'] = $orderId;
        $header['root_document_id'] = empty($source->root_document_id) ? $orderId : (int) $source->root_document_id;
        $header['reference_document_no'] = $source->order_no;
        $header['order_no'] = self::nextNumber($documentType);
        foreach ($override as $key => $value) {
            $header[$key] = $value;
        }

        $lines = [];
        $chain = self::lineChains($orderId);
        foreach (self::items($orderId) as $line) {
            // บรรทัดของใบใหม่ชี้กลับบรรทัดต้นทาง — ใบคืนหาทุนของบรรทัดขาย/ใบรับที่มาได้จากสายนี้
            $line['source_item_id'] = (int) $line['id'];
            $line['root_item_id'] = empty($chain[(int) $line['id']]['root_item_id'])
                ? (int) $line['id'] : $chain[(int) $line['id']]['root_item_id'];
            unset($line['id'], $line['order_id'], $line['create_date'], $line['member_id']);
            $lines[] = $line;
        }

        $childId = self::save($header, $lines, 0, $memberId);

        // ⚠️ หน้าที่จัดส่งย้ายไปอยู่กับใบใหม่แล้ว — ถ้าใบเดิมยังถือ shipping_status = pending
        // คิวจัดส่งจะเห็นคำสั่งซื้อเดียวกันสองใบ แล้วมีโอกาสส่งของสองรอบ
        if (class_exists('\\Shipping\\Methods\\Model') && isset($source->shipping_status) && $source->shipping_status !== null) {
            $db->update(Base::table('orders'), ['id', $orderId], ['shipping_status' => null]);
        }

        return $childId;
    }

    /**
     * เลขที่เอกสารถัดไปของชนิดหนึ่ง ตามรูปแบบที่ตั้งไว้ในแม่แบบของชนิดนั้น
     *
     * รูปแบบอยู่ในแถวแม่แบบ ไม่ใช่ในไฟล์ค่ากำหนด — ชนิดเอกสารเป็นข้อมูลที่ไซต์
     * เพิ่มเองได้ ถ้ารูปแบบยังอยู่ในไฟล์ ชนิดที่เพิ่มใหม่จะไม่มีใครไปเขียนคีย์ให้
     * แล้วได้เลขที่ผิดรูปเงียบ ๆ
     *
     * \Index\Number\Model::get() แทน token ของ prefix (%Y %M ...) และวนหาเลขที่ยังไม่ถูกใช้
     * ในตารางปลายทางให้ด้วย จึงไม่ต้องตรวจซ้ำเอง
     *
     * @param string $documentType
     *
     * @return string
     */
    public static function nextNumber($documentType)
    {
        $template = self::template($documentType);

        return \Index\Number\Model::get(
            0,
            empty($template['number_format']) ? '%04d' : $template['number_format'],
            'orders',
            'order_no',
            empty($template['prefix']) ? $documentType.'%Y%M-' : $template['prefix']
        );
    }

    /**
     * บรรทัดรายการของเอกสาร
     *
     * @param int $orderId
     *
     * @return array
     */
    public static function items($orderId)
    {
        if (empty($orderId)) {
            return [];
        }

        $rows = static::createQuery()
            ->select('id', 'order_id', 'source_item_id', 'root_item_id', 'inventory_id', 'inventory_item_id', 'sku', 'code', 'lot_no', 'expiry_date',
                'topic', 'quantity', 'unit', 'unit_factor', 'base_qty', 'price', 'cost_price', 'discount', 'vat', 'total',
                'note', 'member_id', 'create_date')
            ->from('order_items')
            ->where(['order_id', (int) $orderId])
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();

        // คอลัมน์ DECIMAL ของ MariaDB คืนมาเป็นสตริง ("0.00") ซึ่งไม่ใช่ค่าว่างใน
        // สายตา empty() และไม่ตรงกับ <option value="1"> ของหน้าเว็บ ต้องแปลงเป็น
        // ตัวเลขก่อนส่งออกเสมอ ไม่งั้นธง VAT ของบรรทัดจะติ๊กผิดทุกครั้งที่เปิดเอกสาร
        foreach ($rows as $i => $row) {
            $rows[$i]['vat'] = (float) $row['vat'] > 0 ? 1 : 0;
            foreach (['quantity', 'unit_factor', 'base_qty', 'price', 'discount', 'total'] as $column) {
                $rows[$i][$column] = (float) $row[$column];
            }
            $rows[$i]['cost_price'] = $row['cost_price'] === null ? null : (float) $row['cost_price'];
        }

        return $rows;
    }

    /**
     * สายของบรรทัดในเอกสารหนึ่ง
     *
     * @param int $orderId
     *
     * @return array [id ของบรรทัด => ['source_item_id', 'root_item_id']]
     */
    public static function lineChains($orderId)
    {
        $chain = [];
        if ((int) $orderId <= 0) {
            return $chain;
        }
        foreach (static::createQuery()->select('id', 'source_item_id', 'root_item_id')->from('order_items')
            ->where(['order_id', (int) $orderId])->execute(null, 'array')->fetchAll() as $row) {
            $chain[(int) $row['id']] = [
                'source_item_id' => $row['source_item_id'] === null ? null : (int) $row['source_item_id'],
                'root_item_id' => $row['root_item_id'] === null ? null : (int) $row['root_item_id']
            ];
        }

        return $chain;
    }

    /**
     * ทิศทางสต๊อกของแม่แบบ
     *
     * @param array $template
     *
     * @return string|null in | out · null = เอกสารที่ไม่แตะสต๊อก
     */
    public static function direction(array $template)
    {
        if (!empty($template['stock_in'])) {
            return 'in';
        }

        return !empty($template['stock_out']) ? 'out' : null;
    }

    /**
     * เติมบรรทัดจากทะเบียนสินค้า และแตกบรรทัดของสินค้านับรายชิ้นเป็นเครื่องละบรรทัด
     *
     * จุดเดียวที่ sku · code · unit_factor · base_qty ถูกคำนวณ — ฟอร์ม ตะกร้า และการแปลง
     * เอกสารส่งมาแค่ว่าอ้างแถวไหน กี่หน่วย จึงไม่มีทางที่แต่ละทางจะคิดหน่วยฐานต่างกัน
     *
     * นับรายชิ้น (DESIGN.md ข้อ 4 · 5.2–5.3) : บรรทัด n เครื่องกลายเป็น n บรรทัดบรรทัดละเครื่อง
     * เครื่องที่ระบุไว้ใช้เครื่องนั้น ที่เหลือ ขาเข้า = ออกเลขเครื่องใหม่ (Items::addSerial) ·
     * ขาออก = เลือกตามวิธีตัด (Fifo::nextPiece) แล้วเก็บเครื่องที่ได้ไว้บนบรรทัด
     *
     * @param array       $lines
     * @param string|null $direction   in | out · null = ยังไม่ต้องระบุเครื่อง (ร่าง · เอกสารที่ไม่แตะสต๊อก)
     * @param int         $warehouseId คลังของเอกสาร (0 = คลังตั้งต้น)
     *
     * @throws \Exception
     *
     * @return array
     */
    protected static function prepareLines(array $lines, $direction, $warehouseId)
    {
        $described = [];
        // เครื่องที่บรรทัดระบุมาเอง — กันไม่ให้ระบบเลือกซ้ำให้บรรทัดอื่น
        $taken = [];
        foreach ($lines as $line) {
            $line = self::describeLine($line);
            if ($line['piece']) {
                if (isset($taken[$line['inventory_item_id']])) {
                    throw new \Exception('เครื่องหมายเลข '.$line['code'].' อยู่ในเอกสารนี้มากกว่าหนึ่งบรรทัด');
                }
                $taken[$line['inventory_item_id']] = true;
            }
            $described[] = $line;
        }

        $result = [];
        foreach ($described as $line) {
            $countStock = $line['count_stock'];
            $piece = $line['piece'];
            unset($line['count_stock'], $line['piece']);
            if ($direction === null || $countStock !== 2) {
                $result[] = $line;
                continue;
            }
            $quantity = (float) $line['quantity'];
            if ($quantity < 1 || abs($quantity - round($quantity)) > 0.00001) {
                throw new \Exception($line['topic'].' นับสต๊อกรายชิ้น — จำนวนต้องเป็นจำนวนเต็มเครื่อง');
            }
            $count = (int) round($quantity);
            $total = (float) $line['total'];
            $share = round($total / $count, 2);
            for ($n = 0; $n < $count; ++$n) {
                if ($n === 0 && $piece) {
                    $itemId = (int) $line['inventory_item_id'];
                } elseif ($direction === 'in') {
                    $itemId = \Inventory\Items\Model::addSerial((int) $line['inventory_id']);
                } else {
                    $next = Fifo::nextPiece((int) $line['inventory_id'], $warehouseId, array_keys($taken));
                    if ($next === null) {
                        throw new \Exception('สต๊อกของ '.$line['topic'].' ไม่พอ — มีเครื่องพร้อมขายไม่ถึง '.$count.' เครื่อง');
                    }
                    $itemId = $next['id'];
                }
                $taken[$itemId] = true;
                $item = static::createDB()->first(Base::table('inventory_items'), ['id' => $itemId]);
                $result[] = array_merge($line, [
                    'inventory_item_id' => $itemId,
                    'code' => (string) $item->serial_no,
                    'quantity' => 1,
                    'unit_factor' => 1,
                    'base_qty' => 1,
                    // ยอดเงินแบ่งเท่ากัน เศษไปอยู่บรรทัดสุดท้าย — รวมแล้วเท่าบรรทัดเดิมพอดี
                    'total' => $n === $count - 1 ? round($total - $share * ($count - 1), 2) : $share
                ]);
            }
        }

        return $result;
    }

    /**
     * ทุนของแต่ละบรรทัดในใบรับซื้อ ตามมาตรฐานการบัญชีเรื่องสินค้าคงเหลือ (TAS 2 / IAS 2)
     *
     * ต้นทุนการซื้อ = ราคาซื้อ หักส่วนลดการค้า · ภาษีที่ขอคืนได้ไม่ใช่ต้นทุน (DESIGN.md ข้อ 5.2)
     *   1. ยอดบรรทัด (หลังส่วนลดของบรรทัด) หักส่วนลดท้ายบิลที่เฉลี่ยตามสัดส่วนยอด — ทุกบรรทัดของใบรับเดียวกัน
     *      (บรรทัดที่ไม่นับสต๊อกได้ส่วนแบ่งด้วย ส่วนลดของบิลจึงไม่ไปกองอยู่ที่สินค้าเกินส่วน)
     *   2. ภาษีซื้อของบรรทัดที่คิด VAT = ยอดภาษีของใบ เฉลี่ยตามฐานภาษีหลังส่วนลด — ใช้ยอดที่บันทึกบนใบ
     *      ไม่คำนวณจากอัตราใหม่ ผลรวมทุนจึงตรงกับเอกสารเสมอ
     *   3. ค่าตั้ง input_vat_creditable : เครดิตภาษีซื้อได้ = ทุนไม่รวมภาษี · ไม่ได้ = ภาษีซื้อเป็นส่วนหนึ่งของทุน
     *
     * @param object $order แถวหัวเอกสาร (discount = จำนวนเงิน · vat = ยอดภาษี · vat_status 1 แยก / 2 รวม)
     * @param array  $lines บรรทัดจาก items()
     *
     * @return array [id ของบรรทัด => ทุนรวมของบรรทัด]
     */
    public static function purchaseCosts($order, array $lines)
    {
        $vatStatus = (int) $order->vat_status;
        $creditable = (int) Base::config('input_vat_creditable', 1) === 1;
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['total'];
        }
        $discount = $subtotal > 0 ? min(max(0.0, (float) $order->discount), $subtotal) : 0.0;
        $after = [];
        $vatBase = 0.0;
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $after[$id] = $subtotal > 0 ? (float) $line['total'] - $discount * (float) $line['total'] / $subtotal : 0.0;
            if (!empty($line['vat']) && in_array($vatStatus, [1, 2], true)) {
                $vatBase += $after[$id];
            }
        }
        $costs = [];
        $goods = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $vat = !empty($line['vat']) && in_array($vatStatus, [1, 2], true) && $vatBase > 0
                ? (float) $order->vat * $after[$id] / $vatBase : 0.0;
            // ราคารวมภาษี (2) : ยอดบรรทัดมีภาษีอยู่แล้ว · แยกภาษี (1) : ภาษีบวกเพิ่มจากยอดบรรทัด
            $excluded = $vatStatus === 2 ? $after[$id] - $vat : $after[$id];
            $costs[$id] = $creditable ? $excluded : $excluded + $vat;
            if ((int) $line['inventory_id'] > 0 && \Inventory\Product\Model::countStock((int) $line['inventory_id']) > 0) {
                $goods[$id] = $excluded;
            }
        }
        // ค่าใช้จ่ายในการซื้อ (ค่าขนส่งเข้า อากร — shipping_cost ของใบรับ) เป็นต้นทุนของสินค้า (TAS 2 ย่อหน้า 11)
        // เฉลี่ยลงบรรทัดสินค้าที่นับสต๊อกตามมูลค่าหลังส่วนลดก่อนภาษี — บรรทัดบริการไม่รับ
        $freight = isset($order->shipping_cost) ? (float) $order->shipping_cost : 0.0;
        $goodsValue = array_sum($goods);
        if ($freight > 0 && $goodsValue > 0) {
            foreach ($goods as $id => $value) {
                $costs[$id] += $freight * $value / $goodsValue;
            }
        }

        return $costs;
    }

    /**
     * ทุนของบรรทัดในใบคืนสินค้าจากลูกค้า — ของกลับเข้าคลังด้วยทุนเดิม ไม่ใช่ราคาขาย (TAS 2 · DESIGN.md ข้อ 5.4)
     *
     * ลำดับ (รู้ตัวตน ใช้ของตัวตนนั้น) :
     *   1. เครื่อง (serial) — ทุนของการขายเครื่องนั้นครั้งล่าสุด
     *   2. บรรทัดที่แปลงมาจากบรรทัดขาย (source_item_id) — ทุนต่อหน่วยฐานของบรรทัดขายนั้น
     *   3. ไม่รู้ที่มา — ทุนต่อหน่วยของการขายสินค้านั้นครั้งล่าสุดก่อนวันที่คืน
     *   4. ไม่เคยขาย — ทุนถัดไปของสินค้า (inventory.cost) · ไม่มีเลย = 0
     *
     * @param object $order
     * @param array  $lines บรรทัดจาก items()
     *
     * @return array [id ของบรรทัด => ทุนรวมของบรรทัด]
     */
    public static function returnCosts($order, array $lines)
    {
        $db = static::createDB();
        $tableMovement = Base::table('inventory_stock_movement');
        $active = 'NOT EXISTS (SELECT 1 FROM `'.$tableMovement.'` R WHERE R.`source_movement_id` = M.`id`)';
        $costs = [];
        foreach ($lines as $line) {
            $inventoryId = (int) $line['inventory_id'];
            if ($inventoryId <= 0 || $line['base_qty'] <= 0) {
                continue;
            }
            $unit = null;
            $item = empty($line['inventory_item_id']) ? null
                : $db->first(Base::table('inventory_items'), ['id' => (int) $line['inventory_item_id']]);
            if ($item && $item->serial_no !== null) {
                $rows = static::createQuery()->select('M.id', 'M.quantity')->from('inventory_stock_movement M')
                    ->where([['M.inventory_item_id', (int) $item->id], ['M.movement_direction', 'out'], ['M.source_movement_id', null]])
                    ->whereRaw($active)->orderBy('M.occurred_at', 'DESC')->orderBy('M.id', 'DESC')->limit(1)
                    ->execute(null, 'array')->fetchAll();
                if (!empty($rows) && (float) $rows[0]['quantity'] > 0) {
                    $unit = Posting::movementCost((int) $rows[0]['id']) / (float) $rows[0]['quantity'];
                }
            }
            if ($unit === null && !empty($line['source_item_id'])) {
                $source = $db->first(Base::table('order_items'), ['id' => (int) $line['source_item_id']]);
                if ($source && $source->cost_price !== null && (float) $source->base_qty > 0) {
                    $unit = (float) $source->cost_price / (float) $source->base_qty;
                }
            }
            if ($unit === null) {
                $rows = static::createQuery()->select('M.id', 'M.quantity')->from('inventory_stock_movement M')
                    ->where([['M.inventory_id', $inventoryId], ['M.movement_type', 'sale'], ['M.source_movement_id', null],
                        ['M.occurred_at', '<=', $order->order_date]])
                    ->whereRaw($active)->orderBy('M.occurred_at', 'DESC')->orderBy('M.id', 'DESC')->limit(1)
                    ->execute(null, 'array')->fetchAll();
                if (!empty($rows) && (float) $rows[0]['quantity'] > 0) {
                    $unit = Posting::movementCost((int) $rows[0]['id']) / (float) $rows[0]['quantity'];
                }
            }
            if ($unit === null) {
                $unit = (float) Fifo::nextCost($inventoryId);
            }
            $costs[(int) $line['id']] = $unit * (float) $line['base_qty'];
        }

        return $costs;
    }

    /**
     * บรรทัดหนึ่งพร้อมค่าจากทะเบียน (sku · code · unit_factor · base_qty) และวิธีนับของสินค้า
     *
     * @param array $line
     *
     * @throws \Exception เมื่ออ้างสินค้าหรือหน่วยขายที่ไม่มี หรือหน่วยขายไม่ใช่ของสินค้านั้น
     *
     * @return array บรรทัด + count_stock + piece (แถวที่อ้างเป็นชิ้น)
     */
    protected static function describeLine(array $line)
    {
        $line += ['inventory_id' => 0, 'inventory_item_id' => null, 'sku' => '', 'code' => '', 'lot_no' => null,
            'expiry_date' => null, 'topic' => '', 'quantity' => 0, 'unit_factor' => 1, 'total' => 0];
        $db = static::createDB();
        $inventoryId = (int) $line['inventory_id'];
        $itemId = (int) $line['inventory_item_id'];
        $line['count_stock'] = 0;
        $line['piece'] = false;
        if ($inventoryId > 0) {
            $rows = static::createQuery()
                ->select('V.sku', 'P.count_stock')
                ->from('inventory V')
                ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
                ->where(['V.id', $inventoryId])
                ->execute(null, 'array')
                ->fetchAll();
            if (empty($rows)) {
                throw new \Exception('ไม่พบสินค้ารหัส '.$inventoryId.' ('.$line['topic'].')');
            }
            // sku ณ วันออกเอกสาร — บรรทัดที่มีอยู่แล้วคงค่าเดิมแม้ภายหลังจะเปลี่ยน sku ของ variant
            if ((string) $line['sku'] === '') {
                $line['sku'] = (string) $rows[0]['sku'];
            }
            $line['count_stock'] = (int) $rows[0]['count_stock'];
        }
        if ($itemId > 0) {
            $item = $db->first(Base::table('inventory_items'), ['id' => $itemId]);
            if (!$item || (int) $item->inventory_id !== $inventoryId) {
                throw new \Exception('หน่วยขายของ '.$line['topic'].' ไม่ใช่ของสินค้านี้');
            }
            $line['piece'] = $item->serial_no !== null && $item->serial_no !== '';
            // รหัสและตัวคูณของแถวที่ถูกอ้างแล้วเปลี่ยนไม่ได้ (Items::savePack) ค่าจากทะเบียนจึงเท่าเดิมเสมอ
            $line['code'] = (string) ($line['piece'] ? $item->serial_no : $item->barcode);
            $line['unit_factor'] = $line['piece'] ? 1.0 : (float) $item->cut_stock;
        } else {
            $line['inventory_item_id'] = null;
            $line['unit_factor'] = (float) $line['unit_factor'] > 0 ? (float) $line['unit_factor'] : 1.0;
            if ((string) $line['code'] === '') {
                $line['code'] = (string) $line['sku'];
            }
        }
        $line['quantity'] = (float) $line['quantity'];
        $line['base_qty'] = round($line['quantity'] * $line['unit_factor'], 4);
        foreach (['lot_no', 'expiry_date'] as $key) {
            $value = $line[$key] === null ? '' : trim((string) $line[$key]);
            $line[$key] = $value === '' ? null : $value;
        }
        if ($line['expiry_date'] !== null) {
            $date = \DateTime::createFromFormat('!Y-m-d', $line['expiry_date']);
            if (!$date || $date->format('Y-m-d') !== $line['expiry_date']) {
                throw new \Exception('วันหมดอายุของ '.$line['topic'].' "'.$line['expiry_date'].'" ไม่ถูกต้อง (ปปปป-ดด-วว)');
            }
        }

        return $line;
    }

    /**
     * เดินสต๊อกของเอกสารตามแม่แบบ
     *
     * เอกสารที่แม่แบบไม่ได้ตั้ง stock_in หรือ stock_out (ใบเสนอราคา ใบแจ้งหนี้
     * ใบกำกับภาษี) ไม่แตะสมุดบัญชีเลยแม้แต่แถวเดียว — ซึ่งเป็นเหตุผลที่ต้องแยก
     * บรรทัดเอกสารออกจากการเดินสต๊อกตั้งแต่แรก
     *
     * @param int   $orderId
     * @param array $template
     * @param int   $memberId
     */
    protected static function applyStock($orderId, array $template, $memberId)
    {
        $order = static::createDB()->first(Base::table('orders'), ['id' => (int) $orderId]);

        // oas ไม่มีเอกสารจองของ (ใบสั่งขาย) และใบคืนสินค้าไปยังผู้ขาย — มีเฉพาะ oms.in.th
        $direction = self::direction($template);
        if ($direction === null) {
            return;
        }

        $movementType = empty($template['movement_type'])
            ? ($direction === 'in' ? 'purchase' : 'sale')
            : $template['movement_type'];
        // แม่แบบที่ตั้งชนิดการเคลื่อนไหวไว้ขัดกับทิศทางของตัวเอง เป็นการตั้งค่าที่ผิด
        // ต้องหยุดตั้งแต่ตรงนี้ ไม่ใช่ปล่อยให้ยอดเดินผิดทางแล้วมาไล่หาทีหลัง
        if (Base::movementDirection($movementType) !== $direction) {
            throw new \Exception(
                'แม่แบบเอกสาร "'.$template['topic'].'" ตั้งชนิดการเคลื่อนไหวเป็น "'.$movementType
                .'" ซึ่งขัดกับทิศทางของเอกสาร กรุณาแก้ที่หน้าตั้งค่าแม่แบบเอกสาร'
            );
        }

        // ⚠️ เอกสารร่างยังไม่เกิดขึ้นจริง จึงต้องไม่แตะสต๊อก
        //
        // ตะกร้าที่พักไว้ที่หน้าร้าน (POS) เก็บเป็นเอกสารร่าง ถ้าร่างตัดสต๊อกด้วย
        // ของจะหายจากคลังทั้งที่ยังไม่ได้ขาย และพอลูกค้าเปลี่ยนใจทิ้งตะกร้าไป
        // ยอดก็ค้างผิดถาวร · พอร่างถูกเปลี่ยนเป็น "ออกแล้ว" save() จะถอนผลเดิม
        // (Posting::reverse) แล้วลงใหม่ให้เองอยู่แล้ว จึงได้ยอดถูกต้องพอดี
        if (isset($order->document_status) && $order->document_status === 'draft') {
            return;
        }

        $db = static::createDB();
        $lines = self::items($orderId);
        // ทุนขาเข้าตามมาตรฐานการบัญชี (TAS 2 · DESIGN.md ข้อ 5.2)
        //   ใบรับซื้อ = ราคาซื้อ หักส่วนลด ± ภาษีซื้อ + ค่าใช้จ่ายในการซื้อ (purchaseCosts)
        //   ใบคืนสินค้าจากลูกค้า = ทุนเดิมของของที่ขายไป ไม่ใช่ราคาขาย (returnCosts)
        $costs = [];
        if ($order && $movementType === 'purchase') {
            $costs = self::purchaseCosts($order, $lines);
        } elseif ($order && $movementType === 'return_in') {
            $costs = self::returnCosts($order, $lines);
        }
        foreach ($lines as $line) {
            if ($line['base_qty'] <= 0 || (int) $line['inventory_id'] <= 0) {
                continue;
            }
            $lineCost = isset($costs[(int) $line['id']]) ? $costs[(int) $line['id']] : (float) $line['total'];
            $movementId = Posting::post([
                'inventory_id' => $line['inventory_id'],
                'inventory_item_id' => $line['inventory_item_id'],
                'warehouse_id' => $order && !empty($order->warehouse_id) ? (int) $order->warehouse_id : 0,
                'movement_type' => $movementType,
                'quantity' => $line['base_qty'],
                // ทุนขาเข้าต่อหน่วยฐาน = ทุนของบรรทัด ÷ หน่วยฐานที่รับ — ราคาต่อหน่วยขายตรง ๆ ผิดทั้งเมื่อ
                // หน่วยขายมีตัวคูณ (แผงละ 30 ฟอง) และเมื่อมีส่วนลด · ส่วนขาออกวิธีตัดคิดให้เอง
                'unit_cost' => $direction === 'in' ? $lineCost / $line['base_qty'] : null,
                'lot_no' => $line['lot_no'],
                'expiry_date' => $direction === 'in' ? $line['expiry_date'] : null,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'reference_no' => $order ? $order->order_no : null,
                'reference_item_id' => $line['id'],
                'occurred_at' => $order ? $order->order_date : date('Y-m-d H:i:s'),
                'created_by' => $memberId,
                'note' => $order ? $order->order_no : null
            ]);
            if ($movementId > 0) {
                $db->update(Base::table('order_items'), ['id', (int) $line['id']],
                    ['cost_price' => Posting::movementCost($movementId)]);
            }
        }
    }
}
