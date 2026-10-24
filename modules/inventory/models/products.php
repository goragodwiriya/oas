<?php
/**
 * @filesource modules/inventory/models/products.php
 *
 * เลือกสินค้าใส่เอกสาร — ตาราง ช่องค้นหา และการยิงบาร์โค้ด
 *
 * สิ่งที่ขายคือ "หน่วยขาย" ของ variant (ฟอง · โหล · แผง) — ตารางเลือกสินค้าจึงแสดง
 * บรรทัดละหน่วยขาย ส่วนชิ้นของสินค้านับรายชิ้นไม่แสดง (ยิงหมายเลขเครื่องได้โดยตรง)
 * modules/inventory/DESIGN.md ข้อ 4
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Products;

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
     * Query สำหรับตารางเลือกสินค้าใส่เอกสาร — บรรทัดละหน่วยขายที่ใช้งานอยู่
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [['P.is_active', 1], ['V.is_active', 1], ['I.is_active', 1]];
        if (!empty($params['category_id'])) {
            $where[] = ['P.category_id', (string) $params['category_id']];
        }

        $query = static::createQuery()
            ->select('V.id', 'V.sku', 'V.stock', 'P.product_code', 'P.category_id', 'P.count_stock', 'I.barcode', 'I.unit', 'I.cut_stock')
            ->selectRaw('I.`id` AS `inventory_item_id`')
            ->selectRaw('V.`topic` AS `topic`')
            // ราคาของหน่วยขาย — ไม่ได้ตั้งไว้ = ราคาต่อหน่วยฐาน × ตัวคูณ (สูตรเดียวกับ detail())
            ->selectRaw('IFNULL(I.`price`, V.`price` * I.`cut_stock`) AS `price`')
            ->selectRaw('V.`price` AS `base_price`')
            ->selectRaw('P.`unit` AS `base_unit`')
            ->selectRaw('P.`vat` AS `vat`')
            ->selectRaw('IF(P.`count_stock` > 0, 1, 0) AS `stockable`')
            ->selectRaw('P.`allow_negative` AS `allow_negative`')
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'INNER')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where($where)
            ->whereRaw('I.`serial_no` IS NULL');

        // ⚠️ ต้องครอบเป็นวงเล็บเดียวก่อนแล้วค่อย AND กับเงื่อนไขอื่น ไม่งั้น OR จะ
        // กระจายออกมาแล้วสินค้าที่ปิดการใช้งานจะโผล่ในผลค้นหา
        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $query->whereNested(function ($q) use ($search) {
                $q->where([['V.topic', 'LIKE', "%$search%"]])
                    ->orWhere([['P.product_code', 'LIKE', "%$search%"]])
                    ->orWhere([['V.sku', 'LIKE', "%$search%"]])
                    ->orWhere([['I.barcode', 'LIKE', "%$search%"]]);
            });
        }

        return $query;
    }

    /**
     * ค้นหาสินค้าสำหรับช่อง autocomplete ในเอกสาร
     *
     * ⚠️ `value` ต้องเป็นรหัสที่ detail() ค้นได้ (บาร์โค้ดของหน่วยขาย) ไม่ใช่ id —
     * ไม่งั้นบรรทัดที่เลือกจะหาข้อมูลไม่เจอแล้วไม่ถูกเพิ่มเงียบ ๆ
     *
     * @param string $q
     * @param int    $limit
     *
     * @return array [['value' => บาร์โค้ด, 'text' => ชื่อ]]
     */
    public static function search($q, $limit = 20)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return [];
        }

        $rows = static::toDataTable(['search' => $q])
            ->orderBy('V.topic')
            ->orderBy('I.cut_stock')
            ->limit((int) $limit)
            ->execute(null, 'array')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $unit = abs((float) $row['cut_stock'] - 1) < 0.00001 ? '' : ' · '.$row['unit'];
            $result[] = ['value' => $row['barcode'], 'text' => $row['topic'].$unit.' ('.$row['barcode'].')'];
        }

        return $result;
    }

    /**
     * ข้อมูลหนึ่งบรรทัดสำหรับใส่ในเอกสาร จากรหัสที่ยิงหรือพิมพ์
     *
     * ลำดับค้นหา (เจอที่ไหนหยุดที่นั่น) :
     *   1. serial_no    → ชิ้นนั้น
     *   2. barcode      → หน่วยขายนั้น (สินค้านับรายชิ้น = ระบบเลือกเครื่องตามวิธีตัดให้ เมื่อ $pickPiece)
     *   3. sku          → variant ด้วยหน่วยขายฐาน (×1)
     *   4. product_code → สินค้าที่มี variant เดียว (หลายแบบต้องเลือกเอง = null)
     *
     * คีย์ที่คืนต้องตรงกับ data-field ของตารางรายการในเทมเพลตทุกตัว
     *
     * @param string $code
     * @param bool   $pickPiece สินค้านับรายชิ้นที่ยิงบาร์โค้ดรุ่น ให้ระบบเลือกเครื่องในคลังให้ไหม
     *                          (เอกสารรับเข้า = ไม่ — เครื่องที่รับยังไม่มีในทะเบียน บรรทัดคงเป็นหน่วยขาย
     *                          แล้วเอกสารออกเลขเครื่องให้ตอนบันทึก)
     *
     * @return array|null
     */
    public static function detail($code, $pickPiece = true)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        $db = static::createDB();
        $item = $db->first(\Inventory\Base\Model::table('inventory_items'), ['serial_no' => $code, 'is_active' => 1]);
        if (!$item) {
            $item = $db->first(\Inventory\Base\Model::table('inventory_items'), ['barcode' => $code, 'is_active' => 1]);
        }
        if (!$item) {
            $variantId = 0;
            $variant = $db->first(\Inventory\Base\Model::table('inventory'), ['sku' => $code, 'is_active' => 1]);
            if ($variant) {
                $variantId = (int) $variant->id;
            } else {
                $product = $db->first(\Inventory\Base\Model::table('inventory_product'), ['product_code' => $code, 'is_active' => 1]);
                if ($product) {
                    $ids = $db->select(\Inventory\Base\Model::table('inventory'), [['product_id', (int) $product->id], ['is_active', 1]]);
                    $variantId = count($ids) === 1 ? (int) $ids[0]->id : 0;
                }
            }
            if ($variantId > 0) {
                $item = static::basePack($variantId);
            }
        }
        if (!$item) {
            return null;
        }

        return static::line((int) $item->id, $pickPiece);
    }

    /**
     * บรรทัดเอกสารจากแถวหน่วยขาย/ชิ้น
     *
     * @param int  $itemId
     * @param bool $pickPiece
     *
     * @return array|null
     */
    protected static function line($itemId, $pickPiece)
    {
        $rows = static::createQuery()
            ->select('I.id', 'I.inventory_id', 'I.barcode', 'I.serial_no', 'I.unit', 'I.cut_stock', 'V.sku', 'V.topic', 'V.stock', 'P.count_stock', 'P.vat')
            ->selectRaw('IFNULL(I.`price`, V.`price` * I.`cut_stock`) AS `price`')
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'INNER')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where([['I.id', (int) $itemId], ['V.is_active', 1], ['P.is_active', 1]])
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        $itemId = (int) $row['id'];
        $code = $row['serial_no'] !== null ? (string) $row['serial_no'] : (string) $row['barcode'];
        // สินค้านับรายชิ้นที่ยิงบาร์โค้ดรุ่น — ระบบเลือกเครื่องตามวิธีตัด (Fifo::nextPiece) แล้วบรรทัด
        // บันทึกเครื่องที่เลือก (ตรวจย้อนได้ว่าเครื่องไหนไปกับใบไหน)
        if ($pickPiece && (int) $row['count_stock'] === 2 && $row['serial_no'] === null) {
            $serial = \Inventory\Fifo\Model::nextPiece((int) $row['inventory_id']);
            if ($serial !== null) {
                $itemId = $serial['id'];
                $code = $serial['serial_no'];
            }
        }
        $price = (float) $row['price'];

        return [
            'inventory_id' => (int) $row['inventory_id'],
            'inventory_item_id' => $itemId,
            'sku' => (string) $row['sku'],
            'code' => $code,
            'topic' => (string) $row['topic'],
            'unit' => (string) $row['unit'],
            'price' => $price,
            'quantity' => 1,
            'discount' => 0,
            'vat' => (int) $row['vat'] > 0 ? 1 : 0,
            'total' => $price,
            // หน่วยฐานต่อ 1 หน่วยขายของบรรทัดนี้ (ตัวคูณของหน่วยขาย ณ วันออกเอกสาร)
            // (เครื่องของสินค้ารายชิ้นและหน่วยขายของมันเป็น 1 เสมอ — Items::savePack บังคับ)
            'unit_factor' => (float) $row['cut_stock'],
            'stock' => (float) $row['stock'],
            'count_stock' => (int) $row['count_stock']
        ];
    }

    /**
     * หน่วยขายฐาน (×1) ที่ใช้งานอยู่ของ variant — ไม่มี = หน่วยขายแรกที่ใช้งานอยู่
     *
     * @param int $variantId
     *
     * @return object|null
     */
    protected static function basePack($variantId)
    {
        $rows = static::createQuery()
            ->select('id')
            ->from('inventory_items')
            ->where([['inventory_id', (int) $variantId], ['is_active', 1]])
            ->whereRaw('`serial_no` IS NULL')
            ->orderBy('cut_stock')
            ->orderBy('id')
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : (object) $rows[0];
    }
}
