<?php
/**
 * @filesource modules/inventory/models/products.php
 *
 * รายการสินค้า — ทั้งหน้าทะเบียนสินค้าและช่องเลือกสินค้าในเอกสาร
 *
 * ยอดคงเหลือในรายการอ่านจากคอลัมน์ `stock` ซึ่งเป็น cache ที่ Posting API
 * เขียนให้ทุกครั้งที่สต๊อกเปลี่ยน (ความจริงอยู่ที่ inventory_stock + สมุดบัญชี)
 * ที่ใช้ cache ตรงนี้เพราะเป็นการแสดงผลรายการยาว ๆ ที่ต้องเรียงและกรองได้
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Products;

use Inventory\Base\Model as Base;

/**
 * Model รายการสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query สำหรับตารางเลือกสินค้าใส่เอกสาร
     *
     * สินค้าสองแบบแสดงคนละอย่าง
     *  - ไม่นับสต๊อก / นับรวม (count_stock 0, 1) : บรรทัดละหน่วยย่อย และต่อชื่อ
     *    หน่วยย่อยท้ายชื่อสินค้า เช่น "กระดาษ A4 รีม"
     *  - นับแยกรายชิ้น (count_stock 2)           : รวมเป็นบรรทัดเดียวต่อสินค้า
     *
     * ⚠️ ระบบเดิมทำด้วย UNION ของสองคิวรี แต่ตารางของเฟรมเวิร์กเติม ORDER BY/LIMIT
     * ต่อท้ายคิวรีที่ได้ ซึ่ง MariaDB ไม่ยอมถ้าเป็น UNION ที่ไม่ได้ครอบวงเล็บ
     * จึงรวมเป็นคิวรีเดียวโดยใช้คีย์การจัดกลุ่มแบบมีเงื่อนไข ให้ผลเท่ากันเป๊ะ
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [['V.is_active', 1]];
        if (!empty($params['category_id'])) {
            $where[] = ['V.category_id', (string) $params['category_id']];
        }

        $query = static::createQuery()
            ->select('V.id', 'V.product_code', 'V.category_id', 'V.stock', 'V.count_stock', 'I.sku', 'I.price', 'I.unit')
            ->selectRaw('I.`id` AS `inventory_item_id`')
            ->selectRaw('I.`barcode` AS `barcode`')
            // ราคา/หน่วยของหน่วยย่อยเป็น NULL ได้ (สินค้าที่ไม่มีหน่วยย่อย) ผู้เรียกที่
            // ต้องแสดงราคาจึงต้องมีค่าตั้งต้นของสินค้าให้ถอยไปใช้ — สูตรเดียวกับ detail()
            ->selectRaw('V.`price` AS `base_price`')
            ->selectRaw('V.`unit` AS `base_unit`')
            ->selectRaw('V.`vat` AS `vat`')
            ->selectRaw('V.`stockable` AS `stockable`')
            ->selectRaw('V.`allow_negative` AS `allow_negative`')
            ->selectRaw("CASE WHEN V.`count_stock` = 2 THEN V.`topic`"
                ." ELSE TRIM(CONCAT_WS(' ', V.`topic`, I.`topic`)) END AS `topic`")
            ->from('inventory V')
            ->join('inventory_items I', ['I.inventory_id', 'V.id'], 'LEFT')
            ->where($where);

        // ค้นหาด้วยชื่อ/รหัสสินค้า/รหัสหน่วยย่อย/บาร์โค้ด
        //
        // ⚠️ ต้องครอบเป็นวงเล็บเดียวก่อนแล้วค่อย AND กับเงื่อนไขอื่น ไม่งั้น OR จะ
        // กระจายออกมาแล้วสินค้าที่ปิดการใช้งานจะโผล่ในผลค้นหา (บกพร่องแบบเดียวกับ search())
        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $query->whereNested(function ($q) use ($search) {
                $q->where([['V.topic', 'LIKE', "%$search%"]])
                    ->orWhere([['V.product_code', 'LIKE', "%$search%"]])
                    ->orWhere([['I.sku', 'LIKE', "%$search%"]])
                    ->orWhere([['I.barcode', 'LIKE', "%$search%"]]);
            });
        }

        return $query
            ->groupBy('V.id')
            ->groupBy("CASE WHEN V.`count_stock` = 2 THEN '' ELSE IFNULL(I.`sku`, '') END");
    }

    /**
     * ค้นหาสินค้าสำหรับช่อง autocomplete ในเอกสาร
     *
     * ⚠️ ค่าที่คืนใน `value` ต้องเป็นค่าที่ detail() ใช้ค้นได้ (รหัสสินค้า/รหัสหน่วยย่อย)
     * ไม่ใช่ id — ไม่งั้นบรรทัดที่เลือกจะหาข้อมูลไม่เจอแล้วไม่ถูกเพิ่มเงียบ ๆ
     *
     * @param string $q
     * @param int    $limit
     *
     * @return array [['value' => รหัส, 'text' => ชื่อ]]
     */
    public static function search($q, $limit = 20)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return [];
        }

        $rows = static::createQuery()
            // ห้าม select `I.topic` ตรง ๆ คู่กับ `V.topic` — ชื่อคอลัมน์ซ้ำกัน ตัวหลังทับตัวหน้า
            // ในแถวที่ fetch มา และ inventory_items.topic มักเป็น NULL
            // ช่องค้นหาจึงแสดงแค่ " (รหัส)" โดยไม่มีชื่อสินค้า
            ->select('V.id', 'V.topic', 'V.product_code', 'V.count_stock', 'I.sku')
            ->selectRaw('I.`topic` AS `item_topic`')
            ->from('inventory V')
            ->join('inventory_items I', ['I.inventory_id', 'V.id'], 'LEFT')
            ->where(['V.is_active', 1])
            // เงื่อนไขค้นหาต้องรวมเป็นวงเล็บเดียวก่อน แล้วค่อย AND กับเงื่อนไขอื่น
            // ถ้าปล่อยให้ OR กระจายออกมา สินค้าที่ปิดการใช้งานแล้วจะโผล่ในผลค้นหาด้วย
            ->whereNested(function ($query) use ($q) {
                $query->where([['V.topic', 'LIKE', "%$q%"]])
                    ->orWhere([['V.product_code', 'LIKE', "%$q%"]])
                    ->orWhere([['I.sku', 'LIKE', "%$q%"]]);
            })
            ->orderBy('V.topic')
            ->limit((int) $limit)
            ->execute(null, 'array')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $code = $row['sku'] !== null && $row['sku'] !== '' ? $row['sku'] : $row['product_code'];
            if ($code === null || $code === '') {
                continue;
            }
            $text = (int) $row['count_stock'] === 2
                ? $row['topic']
                : trim($row['topic'].' '.(string) $row['item_topic']);
            $result[$code] = ['value' => $code, 'text' => $text.' ('.$code.')'];
        }

        return array_values($result);
    }

    /**
     * ข้อมูลสินค้าหนึ่งบรรทัดสำหรับใส่ในเอกสาร
     *
     * คีย์ที่คืนต้องตรงกับ data-field ของตารางรายการในเทมเพลตทุกตัว
     *
     * @param string $code รหัสหน่วยย่อย (sku) หรือรหัสสินค้า (product_code)
     *
     * @return array|null
     */
    public static function detail($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        $rows = static::createQuery()
            ->select('V.id', 'V.count_stock', 'V.price', 'V.vat', 'V.stock')
            ->selectRaw('V.`topic` AS `product_topic`')
            ->selectRaw('I.`id` AS `inventory_item_id`')
            ->selectRaw('I.`sku` AS `sku`')
            ->selectRaw('I.`topic` AS `item_topic`')
            ->selectRaw('I.`price` AS `item_price`')
            ->selectRaw('I.`unit` AS `unit`')
            ->selectRaw('I.`cut_stock` AS `cut_stock`')
            ->from('inventory V')
            ->join('inventory_items I', ['I.inventory_id', 'V.id'], 'LEFT')
            ->whereNested(function ($query) use ($code) {
                $query->where([['I.sku', $code]])->orWhere([['V.product_code', $code]]);
            })
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();

        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        $price = $row['item_price'] !== null ? (float) $row['item_price'] : (float) $row['price'];

        return [
            'inventory_id' => (int) $row['id'],
            'inventory_item_id' => (int) $row['inventory_item_id'],
            'product_code' => $row['sku'] !== null && $row['sku'] !== '' ? $row['sku'] : $code,
            'product_no' => $row['sku'] !== null && $row['sku'] !== '' ? $row['sku'] : $code,
            'topic' => (int) $row['count_stock'] === 2
                ? $row['product_topic']
                : trim($row['product_topic'].' '.(string) $row['item_topic']),
            'unit' => $row['unit'],
            'price' => $price,
            'quantity' => 1,
            'discount' => 0,
            'vat' => (float) $row['vat'] > 0 ? 1 : 0,
            'total' => $price,
            // จำนวนที่ตัดจากสต๊อกหลักต่อ 1 หน่วยของรายการนี้
            'cut_stock' => $row['cut_stock'] === null ? 1 : (float) $row['cut_stock'],
            'stock' => (float) $row['stock'],
            'count_stock' => (int) $row['count_stock']
        ];
    }

    /**
     * รหัสสินค้าถัดไป ตามรูปแบบที่ไซต์ตั้งไว้
     *
     * ⚠️ ระบบเดิมของ oms มีการสร้างรหัสให้เมื่อผู้ใช้เว้นว่าง แต่ส่งชื่อฟิลด์
     * `'product_no'` เข้าไปในช่องรูปแบบ (modules/inventory/models/write.php)
     * Number::printf() จึงคืนคำว่า "product_no" กลับมาตรง ๆ ทุกครั้งเพราะไม่มี
     * token ให้แทน — ค่า product_no ที่ตั้งไว้ในหน้าตั้งค่าไม่เคยถูกใช้เลย
     * และสินค้าตัวที่สองจะชนคีย์ไม่ซ้ำทันที ที่นี่ส่ง "รูปแบบ" เข้าไปให้ถูกช่อง
     * แบบเดียวกับ Customers::nextNumber()
     *
     * @return string
     */
    public static function nextNumber()
    {
        return \Index\Number\Model::get(
            0,
            Base::config('product_no', 'P%04d'),
            'inventory_items',
            'sku'
        );
    }
}
