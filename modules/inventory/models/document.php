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
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Document;

use Inventory\Base\Model as Base;
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
                    'signature_1', 'signature_2', 'signature_3', 'in_stock', 'cut_stock', 'reserve_stock',
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
        // การเคลื่อนไหวในสมุดบัญชี ซึ่งดูเหมือนขายไปแล้วแต่ของยังอยู่ และไปกันการจอง
        // ของใบสั่งขายต้นทางค้างไว้ตลอดกาล (เจอจริงในชุดทดสอบ 2026-09-12)
        return Base::transaction(function () use ($header, $lines, $orderId, $memberId, $template, $documentType) {
            return self::write($header, $lines, $orderId, $memberId, $template, $documentType);
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

        // บรรทัดรายการเขียนใหม่ทั้งชุด — ที่ทำได้เพราะการเดินสต๊อกไม่ได้อยู่ในแถวนี้แล้ว
        $db->delete($tableItems, ['order_id', $orderId], 0);
        foreach ($lines as $line) {
            unset($line['id']);
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
            return self::doCancel($db, $orderId, $memberId);
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
        // ใบที่จองของถูกยกเลิก → ของถูกปล่อย · ใบเสร็จที่แปลงจากใบสั่งขายถูกยกเลิก →
        // ใบสั่งขายกลับมาจองของอีกครั้ง (ลูกค้ายังสั่งอยู่ แค่ใบเสร็จผิด)
        Posting::syncReservedForDocument($orderId);

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
            return self::doRemove($db, $orderId, $memberId);
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
        // จำสิ่งที่ต้อง sync ไว้ก่อนลบ — ลบแล้วอ่านบรรทัดรายการไม่ได้อีก
        $row = $db->first(Base::table('orders'), ['id' => $orderId]);
        $lines = self::items($orderId);
        $sourceId = $row && !empty($row->source_document_id) ? (int) $row->source_document_id : 0;

        $db->delete(Base::table('order_items'), ['order_id', $orderId], 0);
        $db->delete(Base::table('orders'), ['id', $orderId]);

        // ⚠️ โมดูลอื่นที่แขวนข้อมูลไว้กับเอกสารใบนี้ (บรรทัดการชำระของ payment · ใบจัดส่ง)
        // ต้องได้เก็บของของตัวเอง — โมดูลนี้ไม่รู้จักโมดูลไหนเป็นพิเศษ จึงประกาศเป็น
        // hook กลางแบบเดียวกับเมนู : โมดูลไหนมี onDocumentRemove ใน controllers/init.php
        // ก็ถูกเรียก ถอดโมดูลนั้นออกแล้วไม่มีอะไรล้ม
        // (ก่อน 2026-09-12 บรรทัดการชำระถูกทิ้งไว้เป็นแถวกำพร้าที่ชี้ไปเอกสารที่ไม่มีแล้ว)
        $hookParams = ['id' => $orderId, 'member_id' => (int) $memberId];
        \Gcms\Controller::initModule([], 'onDocumentRemove', null, $hookParams);

        // ปล่อยของที่ใบนี้จองไว้ และให้ใบแม่ (ถ้ามี) คำนวณการจองใหม่
        foreach ($lines as $line) {
            $product = $db->first(Base::table('inventory'), ['id' => (int) $line['inventory_id']]);
            if ($product && (int) $product->count_stock > 0) {
                Posting::syncReserved((int) $line['inventory_id'],
                    Posting::stockLevel(['count_stock' => (int) $product->count_stock], (int) $line['inventory_item_id']));
            }
        }
        if ($sourceId > 0) {
            Posting::syncReservedForDocument($sourceId);
        }

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
     * แปลงเอกสารเป็นใบใหม่อีกชนิดหนึ่ง (ใบสั่งขาย → ใบเสร็จ · ใบเสนอราคา → ใบแจ้งหนี้)
     *
     * ใบเดิมยังอยู่ครบ ใบใหม่ชี้กลับไปหาใบเดิมด้วย source_document_id — นี่คือหัวใจ
     * ของสายเอกสาร และเป็นสิ่งที่ปล่อยการจองของใบสั่งขายเมื่อใบเสร็จออก
     *
     * ⚠️ ที่เดียวในระบบที่แปลงเอกสาร — หน้าเอกสาร (บันทึกและสร้างใหม่) · การรับเงิน
     * บนใบสั่งขาย · ร้านค้าออนไลน์ เรียกที่นี่ทั้งหมด ถ้าแยกกันทำ วันหนึ่งสายเอกสาร
     * ของสามทางจะเดินไม่เหมือนกัน
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
        foreach (self::items($orderId) as $line) {
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
     * ชนิดเอกสารที่ "ตัดของจริง" ของโหมดหนึ่ง — ปลายทางเมื่อใบที่จองของถูกชำระ
     *
     * @param string $mode sell | buy
     *
     * @return string|null null = ไซต์นี้ไม่มีแม่แบบที่ตัดของในโหมดนั้น
     */
    public static function cutStockType($mode = 'sell')
    {
        foreach (self::templates() as $type => $template) {
            if ($template['mode'] === $mode && !empty($template['published'])
                && ($mode === 'sell' ? !empty($template['cut_stock']) : !empty($template['in_stock']))) {
                return $type;
            }
        }

        return null;
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
            ->select('id', 'order_id', 'inventory_id', 'inventory_item_id', 'product_code', 'product_no',
                'topic', 'quantity', 'unit', 'price', 'cost_price', 'discount', 'vat', 'total',
                'cut_stock', 'note', 'member_id', 'create_date')
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
            foreach (['quantity', 'price', 'discount', 'total', 'cut_stock'] as $column) {
                $rows[$i][$column] = (float) $row[$column];
            }
        }

        return $rows;
    }

    /**
     * เดินสต๊อกของเอกสารตามแม่แบบ
     *
     * เอกสารที่แม่แบบไม่ได้ตั้ง in_stock หรือ cut_stock (ใบเสนอราคา ใบแจ้งหนี้
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

        // เอกสารที่ "จอง" ของ (ใบสั่งขาย) ไม่แตะสมุดบัญชี — แค่ทำให้ยอดจองตรงกับใบที่เปิดอยู่
        // ของยังอยู่ในคลังแต่ถูกกันไว้ ใบอื่นตัดไม่ได้จนกว่าใบนี้จะถูกยกเลิกหรือกลายเป็นใบเสร็จ
        if (!empty($template['reserve_stock'])) {
            Posting::syncReservedForDocument($orderId);

            return;
        }

        $direction = null;
        if (!empty($template['in_stock'])) {
            $direction = 'in';
        } elseif (!empty($template['cut_stock'])) {
            $direction = 'out';
        }
        if ($direction === null) {
            // ใบที่ไม่เดินสต๊อกแต่แปลงมาจากใบที่จองของ (เช่น ใบแจ้งหนี้จากใบสั่งขาย)
            // ไม่ทำให้การจองหลุด — ใบสั่งขายยังจองต่อจนกว่าจะมีใบที่ตัดของจริง
            return;
        }

        // ⚠️ ใบนี้แปลงมาจากใบที่จองของ (ใบสั่งขาย → ใบเสร็จ) ต้องปล่อยการจองของใบแม่
        // "ก่อน" ตัดของ ไม่งั้นการตัดจะชนกับการจองของตัวเองแล้วฟ้องว่าของไม่พอ
        // — ใบนี้ถูกบันทึกเป็น issued ไปแล้วตอน save() ใบแม่จึงไม่นับเป็นจองอีก
        if ($order && !empty($order->source_document_id)
            && isset($order->document_status) && $order->document_status !== 'draft') {
            Posting::syncReservedForDocument((int) $order->source_document_id);
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

        foreach (self::items($orderId) as $line) {
            $quantity = $line['cut_stock'] > 0 ? $line['cut_stock'] : $line['quantity'];
            if ($quantity <= 0) {
                continue;
            }
            Posting::post([
                'inventory_id' => $line['inventory_id'],
                'inventory_item_id' => $line['inventory_item_id'],
                'movement_type' => $movementType,
                'quantity' => $quantity,
                // ต้นทุนของขาเข้ามาจากราคาในใบรับสินค้า ส่วนขาออก FIFO คิดให้เอง
                'unit_cost' => $direction === 'in' ? $line['price'] : null,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'reference_no' => $order ? $order->order_no : null,
                'reference_item_id' => $line['id'],
                'occurred_at' => $order ? $order->order_date : date('Y-m-d H:i:s'),
                'created_by' => $memberId,
                'note' => $order ? $order->order_no : null
            ]);
        }
    }
}
