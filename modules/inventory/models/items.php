<?php
/**
 * @filesource modules/inventory/models/items.php
 *
 * หน่วยย่อยของสินค้า — รหัสสินค้า/บาร์โค้ดหลายรหัสต่อสินค้าหนึ่งตัว
 * ระบบเดิมของ oms คือ module=inventory-write&tab=items
 *
 * ⚠️ ต่างจากรุ่นของ oms ตรงที่ "ไม่ลบแล้วแทรกใหม่ทั้งชุด" อีกต่อไป
 *    สมุดบัญชีสต๊อกอ้าง inventory_items.id ถ้า id เปลี่ยนทุกครั้งที่กดบันทึก
 *    การเคลื่อนไหวเดิมจะชี้ไปหาหน่วยย่อยที่ไม่มีอยู่แล้วเงียบ ๆ
 *    จึงเปลี่ยนเป็นอัปเดตทับตามรหัส (id คงที่) แล้วค่อยลบเฉพาะรหัสที่หายไปจริง
 *
 * ⚠️ และไม่เขียนตาราง stock/order_items ตรง ๆ อีกต่อไป — สต๊อกทุกเม็ดต้องผ่าน
 *    Posting API ที่เดียวตามสถาปัตยกรรมของโมดูลกลาง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Items;

use Inventory\Base\Model as Base;
use Inventory\Posting\Model as Posting;
use Kotchasan\Language;

/**
 * Model หน่วยย่อย/บาร์โค้ดของสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * รายการหน่วยย่อยของสินค้าสำหรับใส่ในตารางแก้ไข
     *
     * ระบบเดิมคืนแถวว่างหนึ่งแถวเมื่อยังไม่มีข้อมูล เพื่อให้ผู้ใช้พิมพ์ได้เลย
     * ที่นี่ทำเหมือนกัน
     *
     * @param array $product ข้อมูลสินค้า (ต้องมี id, count_stock)
     *
     * @return array
     */
    public static function toDataTable(array $product)
    {
        $rows = static::createQuery()
            ->select('I.id', 'I.sku', 'I.product_no', 'I.topic', 'I.price', 'I.unit', 'I.cut_stock')
            ->from('inventory_items I')
            ->where([
                ['I.inventory_id', (int) $product['id']],
                ['I.instock', 1]
            ])
            ->orderBy('I.sku')
            ->execute(null, 'array')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = self::formatRow($row, $product);
        }

        if (empty($result)) {
            $result[] = self::formatRow([
                'product_no' => '',
                'sku' => '',
                'topic' => '',
                'price' => '',
                'unit' => '',
                'cut_stock' => 1
            ], $product);
        }

        return $result;
    }

    /**
     * จัดรูปแบบหนึ่งแถวให้ตรงกับคอลัมน์ของตารางแก้ไข
     *
     * ⚠️ ตัวเลขจาก MariaDB เป็นสตริง DECIMAL ("0.00") ต้องแปลงเป็นตัวเลขจริง
     * ก่อนส่งออก ไม่งั้นช่องตัวเลขในตารางจะเพี้ยน
     *
     * @param array $row
     * @param array $product
     *
     * @return array
     */
    protected static function formatRow(array $row, array $product)
    {
        $code = self::codeOf($row);
        $item = [
            'product_no' => $code,
            'barcode' => self::barcodeImage($code),
            'topic' => (string) $row['topic'],
            'price' => $row['price'] === '' || $row['price'] === null ? '' : (float) $row['price'],
            'unit' => (string) $row['unit']
        ];

        // สินค้านับสต๊อกแยกรายชิ้น (count_stock = 2) ตัดสต๊อกชิ้นละ 1 เสมอ
        // ระบบเดิมจึงไม่แสดงคอลัมน์นี้ให้แก้
        if ((int) $product['count_stock'] !== 2) {
            $item['cut_stock'] = $row['cut_stock'] === null ? 1 : (float) $row['cut_stock'];
        }

        return $item;
    }

    /**
     * รหัสของหน่วยย่อยหนึ่งแถว
     *
     * สคีมากลางใช้ `sku` เป็นชื่อหลัก ส่วน `product_no` คงไว้เพราะระบบเดิมของ
     * oms/inventory อ่านคอลัมน์นั้น แถวที่ย้ายมาจากระบบเดิมอาจมีแค่ product_no
     *
     * @param array $row
     *
     * @return string
     */
    protected static function codeOf(array $row)
    {
        if (isset($row['sku']) && trim((string) $row['sku']) !== '') {
            return trim((string) $row['sku']);
        }

        return isset($row['product_no']) ? trim((string) $row['product_no']) : '';
    }

    /**
     * รูปบาร์โค้ดเป็น data URI
     *
     * ระบบเดิมวาดบาร์โค้ดฝั่ง PHP แล้วฝังเป็น base64 ในตาราง ที่นี่ทำแบบเดียวกัน
     * จะได้ไม่ต้องมี endpoint รูปภาพเพิ่มและไม่ต้องใช้ไลบรารีฝั่งเบราว์เซอร์
     *
     * @param string $productNo
     *
     * @return string ค่าว่างถ้าไม่มีรหัส
     */
    public static function barcodeImage($productNo)
    {
        if ($productNo === '') {
            return '';
        }

        // ไม่ใส่ label ในรูป (fontSize 0) เพราะฟอนต์ที่ Barcode ใช้ไม่มีในโปรเจ็คนี้
        // รหัสแสดงอยู่ในช่องข้อความข้าง ๆ อยู่แล้ว ผู้ใช้จึงเห็นครบเหมือนเดิม
        return 'data:image/png;base64,'.base64_encode(
            \Kotchasan\Barcode::create($productNo, 30)->toPng()
        );
    }

    /**
     * บันทึกหน่วยย่อยทั้งชุด
     *
     * คงพฤติกรรมที่ผู้ใช้เห็นไว้ครบ
     *  - รหัสซ้ำภายในชุดเดียวกัน และซ้ำกับสินค้าตัวอื่น = ไม่ผ่าน
     *  - รหัสที่ขายไปแล้ว (instock = 0) ห้ามลบทิ้ง เพราะเอกสารเดิมอ้างอิงอยู่
     *  - สินค้านับสต๊อกแยกรายชิ้น (count_stock = 2) หนึ่งรหัส = หนึ่งชิ้นในสต๊อก
     *
     * @param array $product ข้อมูลสินค้า
     * @param array $rows    แถวจากฟอร์ม [['product_no'=>..,'topic'=>..,'price'=>..,'unit'=>..,'cut_stock'=>..], ...]
     * @param int   $memberId
     *
     * @return array errors รายคีย์ ว่าง = สำเร็จ
     */
    public static function save(array $product, array $rows, $memberId)
    {
        $db = static::createDB();
        $tableItems = Base::table('inventory_items');
        $id = (int) $product['id'];
        $countStock = (int) $product['count_stock'];

        $duplicated = Language::replace('This :name already exist', [
            ':name' => Language::get('Product code')
        ]);

        // แถวที่มีอยู่ตอนนี้ของสินค้าตัวนี้ — เก็บ id ไว้เพื่ออัปเดตทับ ไม่ใช่ลบทิ้ง
        $existing = [];
        foreach ($db->select($tableItems, ['inventory_id', $id]) as $row) {
            $row = (array) $row;
            $existing[self::codeOf($row)] = $row;
        }

        $errors = [];
        $items = [];
        foreach ($rows as $index => $row) {
            $code = isset($row['product_no']) ? trim((string) $row['product_no']) : '';
            if ($code === '') {
                // แถวที่ไม่ได้กรอกอะไรเลยคือแถวเปล่าที่ตารางแก้ไขแถมมาให้ ข้ามไป
                // แต่แถวที่กรอกรายละเอียดไว้แล้วเว้นแต่รหัส = ขอให้ระบบออกรหัสให้
                // (ระบบเดิมก็ออกให้ แต่ส่งชื่อฟิลด์เข้าไปแทนรูปแบบ ทุกตัวจึงได้
                //  รหัสว่า "product_no" เหมือนกันหมดแล้วชนคีย์ไม่ซ้ำตั้งแต่ตัวที่สอง)
                $_filled = trim((string) (isset($row['topic']) ? $row['topic'] : ''))
                    .trim((string) (isset($row['unit']) ? $row['unit'] : ''));
                if ($_filled === '' && empty($row['price'])) {
                    continue;
                }
                $code = \Inventory\Products\Model::nextNumber();
            }
            if (isset($items[$code])) {
                $errors['product_no_'.$index] = $duplicated;
                continue;
            }

            // รหัสนี้เป็นของสินค้าตัวอื่นอยู่ = ใช้ซ้ำไม่ได้
            if (!isset($existing[$code])) {
                $found = $db->first($tableItems, ['sku', $code]);
                if (!$found) {
                    $found = $db->first($tableItems, ['product_no', $code]);
                }
                if ($found && (int) $found->inventory_id !== $id) {
                    $errors['product_no_'.$index] = $duplicated;
                    continue;
                }
            } elseif ($countStock === 2 && (int) $existing[$code]['instock'] === 0) {
                // รหัสนี้ถูกขายออกไปแล้ว นำกลับมาใช้ซ้ำไม่ได้
                $errors['product_no_'.$index] = $duplicated;
                continue;
            }

            $items[$code] = [
                // เขียนชื่อกลางกับชื่อเดิมคู่กันเสมอ ระบบเดิมยังอ่าน product_no อยู่
                'sku' => $code,
                'product_no' => $code,
                'inventory_id' => $id,
                'topic' => isset($row['topic']) ? (string) $row['topic'] : '',
                'unit' => isset($row['unit']) ? (string) $row['unit'] : '',
                'price' => isset($row['price']) ? (float) $row['price'] : 0,
                'cut_stock' => $countStock === 2 ? 1 : (isset($row['cut_stock']) && $row['cut_stock'] > 0
                    ? (float) $row['cut_stock'] : 1),
                'instock' => 1,
                'last_update' => time()
            ];
        }

        if (!empty($errors)) {
            return $errors;
        }

        // ---- อัปเดตทับ/เพิ่มใหม่ โดยคง id เดิมไว้ ----
        $ids = [];
        foreach ($items as $code => $item) {
            if (isset($existing[$code])) {
                $itemId = (int) $existing[$code]['id'];
                $db->update($tableItems, ['id', $itemId], $item);
            } else {
                $itemId = (int) $db->insert($tableItems, $item);
            }
            $ids[$code] = $itemId;
        }

        // ---- รหัสที่หายไปจากฟอร์ม ----
        foreach ($existing as $code => $row) {
            if (isset($items[$code])) {
                continue;
            }
            // รหัสที่ขายไปแล้วต้องคงไว้ เอกสารเดิมอ้างอิงอยู่
            if ((int) $row['instock'] === 0) {
                continue;
            }
            $itemId = (int) $row['id'];
            if ($countStock === 2) {
                // ล้างยอดคงเหลือของหน่วยย่อยนี้ก่อน ไม่งั้นยอดรวมของสินค้าจะค้าง
                $balance = Posting::balance($id, $itemId);
                if ($balance != 0) {
                    Posting::opening($id, $itemId, -$balance, (float) $product['cost']);
                }
            }
            $db->delete($tableItems, ['id', $itemId]);
        }

        // ---- สินค้านับสต๊อกแยกรายชิ้น : หนึ่งรหัส = หนึ่งชิ้น ----
        //
        // ปรับด้วย "ส่วนต่าง" ไม่ใช่เขียนทับยอด กดบันทึกซ้ำจึงไม่นับซ้ำ
        // และการเคลื่อนไหวเดิมยังอยู่ในสมุดครบ
        if ($countStock === 2) {
            foreach ($ids as $itemId) {
                $balance = Posting::balance($id, $itemId);
                if ($balance != 1) {
                    Posting::opening($id, $itemId, 1 - $balance, (float) $product['cost']);
                }
            }
        }

        return [];
    }
}
