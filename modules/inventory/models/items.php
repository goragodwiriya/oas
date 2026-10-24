<?php
/**
 * @filesource modules/inventory/models/items.php
 *
 * หน่วยขายและชิ้นของ variant — ทุกอย่างที่ยิงได้ (modules/inventory/DESIGN.md ข้อ 3.3)
 *
 *   หน่วยขาย : barcode (ห้ามว่าง) · serial_no NULL · cut_stock = หน่วยฐานต่อการยิง 1 ครั้ง
 *   ชิ้น     : serial_no (ห้ามว่าง) · barcode NULL · cut_stock = 1 · มีเฉพาะสินค้านับรายชิ้น
 *
 * แถวที่ถูกอ้างในเอกสารหรือสมุดบัญชีแล้ว ห้ามลบ ห้ามเปลี่ยนรหัส/ตัวคูณ — ปิดด้วย
 * is_active = 0 · บรรทัดเอกสารเก่าต้องอ่านความหมายเดิมของมันได้เสมอ
 *
 * ชั้นนี้ไม่แตะยอดคงเหลือ — ชิ้นที่ลงทะเบียนแล้วยังไม่มีของจนกว่าจะรับเข้าผ่าน Posting
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Items;

use Inventory\Base\Model as Base;

/**
 * Model หน่วยขายและชิ้น
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * เพิ่มหรือแก้ไขหน่วยขาย
     *
     * @param int   $inventoryId variant
     * @param int   $itemId      0 = เพิ่มใหม่
     * @param array $data        barcode (ว่าง = ระบบออกให้) · unit · cut_stock · price (ว่าง = ราคา variant × ตัวคูณ) · is_active
     *
     * @throws \Exception
     *
     * @return int id ของหน่วยขาย
     */
    public static function savePack($inventoryId, $itemId, array $data)
    {
        $variant = self::variant($inventoryId);
        $itemId = (int) $itemId;
        $db = static::createDB();
        $table = Base::table('inventory_items');
        $current = null;
        if ($itemId > 0) {
            $current = $db->first($table, ['id' => $itemId]);
            if (!$current || (int) $current->inventory_id !== (int) $variant['id'] || $current->serial_no !== null) {
                throw new \Exception('ไม่พบหน่วยขายของสินค้านี้');
            }
        }

        $cut = array_key_exists('cut_stock', $data) ? (float) $data['cut_stock'] : ($current ? (float) $current->cut_stock : 1.0);
        if ($cut <= 0) {
            throw new \Exception('ตัวคูณของหน่วยขายต้องมากกว่าศูนย์');
        }
        if ($variant['count_stock'] === 2 && abs($cut - 1) > 0.00001) {
            throw new \Exception('สินค้านับรายชิ้นขายได้ทีละหนึ่งชิ้นเท่านั้น (ตัวคูณต้องเป็น 1)');
        }
        $barcode = array_key_exists('barcode', $data) ? trim((string) $data['barcode']) : ($current ? (string) $current->barcode : '');
        if ($barcode === '') {
            $barcode = $current ? (string) $current->barcode : self::newBarcode($variant['sku'], $cut);
        }
        self::assertCodeFree($barcode, $itemId);

        $unit = array_key_exists('unit', $data) ? trim((string) $data['unit']) : ($current ? (string) $current->unit : '');
        if ($unit === '') {
            if (abs($cut - 1) > 0.00001) {
                throw new \Exception('กรุณากรอกชื่อหน่วยขาย (เช่น โหล แพ็ค ลัง)');
            }
            $unit = $variant['unit'];
        }

        if ($current && self::isReferenced($itemId)
            && ($barcode !== (string) $current->barcode || abs($cut - (float) $current->cut_stock) > 0.00001)) {
            throw new \Exception('หน่วยขาย '.$current->barcode.' ถูกใช้ในเอกสารหรือสมุดบัญชีแล้ว'
                .' เปลี่ยนบาร์โค้ดหรือตัวคูณไม่ได้ ให้ปิดการใช้งานแล้วเพิ่มหน่วยขายใหม่แทน');
        }

        $row = [
            'inventory_id' => (int) $variant['id'],
            'barcode' => $barcode,
            'serial_no' => null,
            'unit' => $unit,
            'cut_stock' => $cut
        ];
        if (array_key_exists('price', $data)) {
            $row['price'] = $data['price'] === '' || $data['price'] === null ? null : (float) $data['price'];
        }
        if (array_key_exists('is_active', $data)) {
            $row['is_active'] = empty($data['is_active']) ? 0 : 1;
        }
        if ($itemId > 0) {
            $db->update($table, ['id', $itemId], $row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $itemId = (int) $db->insert($table, $row);
        }
        \Inventory\Product\Model::changed((int) $variant['id']);

        return $itemId;
    }

    /**
     * ลงทะเบียนชิ้นหนึ่งชิ้นของสินค้านับรายชิ้น
     *
     * ยังไม่มีของในคลังจนกว่าจะรับเข้าผ่านสมุดบัญชี (stock = 0)
     *
     * @param int    $inventoryId variant
     * @param string $serialNo    ว่าง = ระบบออกเลข {sku}-0001
     *
     * @throws \Exception
     *
     * @return int id ของชิ้น
     */
    public static function addSerial($inventoryId, $serialNo = '')
    {
        $variant = self::variant($inventoryId);
        if ($variant['count_stock'] !== 2) {
            throw new \Exception('สินค้านี้ไม่ได้นับรายชิ้น จึงไม่มีหมายเลขเครื่อง');
        }
        $serialNo = trim((string) $serialNo);
        if ($serialNo === '') {
            $serialNo = self::newSerial($variant);
        }
        self::assertCodeFree($serialNo, 0);

        $itemId = (int) static::createDB()->insert(Base::table('inventory_items'), [
            'inventory_id' => (int) $variant['id'],
            'barcode' => null,
            'serial_no' => $serialNo,
            'unit' => $variant['unit'],
            'cut_stock' => 1,
            'stock' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        \Inventory\Product\Model::changed((int) $variant['id']);

        return $itemId;
    }

    /**
     * แก้หมายเลขเครื่อง หรือเปิด/ปิดการใช้งานเครื่อง
     *
     * เครื่องที่ถูกอ้างในเอกสารหรือสมุดบัญชีแล้วเปลี่ยนหมายเลขไม่ได้ — เอกสารเก่าต้องอ่านเครื่องเดิมได้เสมอ
     *
     * @param int   $inventoryId variant
     * @param int   $itemId
     * @param array $data        serial_no · is_active
     *
     * @throws \Exception
     *
     * @return int
     */
    public static function saveSerial($inventoryId, $itemId, array $data)
    {
        $variant = self::variant($inventoryId);
        $db = static::createDB();
        $table = Base::table('inventory_items');
        $current = $db->first($table, ['id' => (int) $itemId]);
        if (!$current || (int) $current->inventory_id !== (int) $variant['id'] || $current->serial_no === null) {
            throw new \Exception('ไม่พบเครื่องของสินค้านี้');
        }
        $row = [];
        if (array_key_exists('serial_no', $data)) {
            $serialNo = trim((string) $data['serial_no']);
            if ($serialNo === '') {
                throw new \Exception('กรุณากรอกหมายเลขเครื่อง');
            }
            if ($serialNo !== (string) $current->serial_no) {
                if (self::isReferenced((int) $itemId)) {
                    throw new \Exception('เครื่อง '.$current->serial_no.' ถูกใช้ในเอกสารหรือสมุดบัญชีแล้ว เปลี่ยนหมายเลขไม่ได้');
                }
                self::assertCodeFree($serialNo, (int) $itemId);
                $row['serial_no'] = $serialNo;
            }
        }
        if (array_key_exists('is_active', $data)) {
            $row['is_active'] = empty($data['is_active']) ? 0 : 1;
        }
        if (!empty($row)) {
            $db->update($table, ['id', (int) $itemId], $row);
            \Inventory\Product\Model::changed((int) $variant['id']);
        }

        return (int) $itemId;
    }

    /**
     * ลบหน่วยขาย/ชิ้นที่ยังไม่เคยถูกใช้
     *
     * @param int $itemId
     *
     * @throws \Exception เมื่อถูกใช้แล้ว
     *
     * @return bool
     */
    public static function remove($itemId)
    {
        $db = static::createDB();
        $item = $db->first(Base::table('inventory_items'), ['id' => (int) $itemId]);
        if (!$item) {
            return false;
        }
        if (self::isReferenced((int) $itemId)) {
            throw new \Exception('รหัส '.($item->serial_no !== null ? $item->serial_no : $item->barcode)
                .' ถูกใช้ในเอกสารหรือสมุดบัญชีแล้ว จึงลบไม่ได้ ให้ปิดการใช้งานแทน');
        }
        $db->delete(Base::table('inventory_items'), ['id', (int) $itemId]);
        \Inventory\Product\Model::changed((int) $item->inventory_id);

        return true;
    }

    /**
     * หน่วยขาย/ชิ้นนี้ถูกใช้ในเอกสารหรือสมุดบัญชีแล้วหรือยัง
     *
     * หน่วยขายของสินค้านับจำนวนลงบัญชีที่ระดับ variant (inventory_item_id = 0)
     * แต่แถวสมุดบัญชีเก็บรหัสที่ใช้จริงไว้ใน sku — จึงต้องดูทั้งสองทาง
     *
     * @param int $itemId
     *
     * @return bool
     */
    public static function isReferenced($itemId)
    {
        $db = static::createDB();
        $item = $db->first(Base::table('inventory_items'), ['id' => (int) $itemId]);
        if (!$item) {
            return false;
        }
        if ($db->first(Base::table('order_items'), ['inventory_item_id' => (int) $itemId])
            || $db->first(Base::table('inventory_stock_movement'), ['inventory_item_id' => (int) $itemId])) {
            return true;
        }
        $code = $item->serial_no !== null ? (string) $item->serial_no : (string) $item->barcode;

        return $db->first(Base::table('inventory_stock_movement'), [
            'inventory_id' => (int) $item->inventory_id,
            'sku' => $code
        ]) ? true : false;
    }

    /**
     * รูปบาร์โค้ดเป็น data URI สำหรับพิมพ์ฉลาก
     *
     * @param string $code
     *
     * @return string ค่าว่างถ้าไม่มีรหัส
     */
    public static function barcodeImage($code)
    {
        if ((string) $code === '') {
            return '';
        }

        // ไม่ใส่ label ในรูป (fontSize 0) เพราะฟอนต์ที่ Barcode ใช้ไม่มีในโปรเจ็คนี้
        return 'data:image/png;base64,'.base64_encode(\Kotchasan\Barcode::create((string) $code, 30)->toPng());
    }

    /**
     * variant พร้อมวิธีนับและหน่วยฐานของสินค้า
     *
     * @param int $inventoryId
     *
     * @throws \Exception เมื่อไม่พบ
     *
     * @return array id · sku · count_stock · unit
     */
    protected static function variant($inventoryId)
    {
        $rows = static::createQuery()
            ->select('V.id', 'V.sku', 'P.count_stock', 'P.unit')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            throw new \Exception('ไม่พบสินค้า');
        }

        return [
            'id' => (int) $rows[0]['id'],
            'sku' => (string) $rows[0]['sku'],
            'count_stock' => (int) $rows[0]['count_stock'],
            'unit' => (string) $rows[0]['unit']
        ];
    }

    /**
     * รหัสนี้ยังว่างอยู่หรือไม่ — บาร์โค้ดกับ serial ต้องไม่ชนกันด้วย
     * เพราะตอนยิงค้น serial ก่อน บาร์โค้ดที่ซ้ำกับ serial จะยิงไม่ติดตลอดไป
     *
     * @param string $code
     * @param int    $exceptId
     *
     * @throws \Exception เมื่อถูกใช้แล้ว
     */
    protected static function assertCodeFree($code, $exceptId)
    {
        $db = static::createDB();
        $table = Base::table('inventory_items');
        foreach (['barcode', 'serial_no'] as $column) {
            $found = $db->first($table, [$column => $code]);
            if ($found && (int) $found->id !== (int) $exceptId) {
                throw new \Exception('รหัส '.$code.' ถูกใช้ไปแล้ว');
            }
        }
    }

    /**
     * บาร์โค้ดที่ระบบออกให้หน่วยขายที่ไม่มีบาร์โค้ดจากผู้ผลิต
     *
     * ×1 = sku · อื่น ๆ = sku-ตัวคูณ (เช่น EGG-2-12) · ชนแล้วต่อท้ายลำดับ
     *
     * @param string $sku
     * @param float  $cut
     *
     * @return string
     */
    protected static function newBarcode($sku, $cut)
    {
        $base = abs($cut - 1) < 0.00001 ? $sku : $sku.'-'.rtrim(rtrim(number_format($cut, 4, '.', ''), '0'), '.');
        $code = $base;
        for ($n = 2; self::codeTaken($code); ++$n) {
            $code = $base.'-'.$n;
        }

        return $code;
    }

    /**
     * หมายเลขเครื่องที่ระบบออกให้ {sku}-0001 เรียงต่อจากที่มีอยู่
     *
     * @param array $variant
     *
     * @return string
     */
    protected static function newSerial(array $variant)
    {
        $rows = static::createQuery()
            ->selectRaw('COUNT(*) AS `c`')
            ->from('inventory_items')
            ->where(['inventory_id', $variant['id']])
            ->whereRaw('`serial_no` IS NOT NULL')
            ->execute(null, 'array')
            ->fetchAll();
        $n = empty($rows) ? 1 : (int) $rows[0]['c'] + 1;
        do {
            $code = $variant['sku'].'-'.sprintf('%04d', $n++);
        } while (self::codeTaken($code));

        return $code;
    }

    /**
     * @param string $code
     *
     * @return bool
     */
    protected static function codeTaken($code)
    {
        $db = static::createDB();
        $table = Base::table('inventory_items');

        return $db->first($table, ['barcode' => $code]) || $db->first($table, ['serial_no' => $code]);
    }
}
