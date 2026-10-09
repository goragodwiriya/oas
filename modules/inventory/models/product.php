<?php
/**
 * @filesource modules/inventory/models/product.php
 *
 * Product Master — ทะเบียนสินค้าและหน่วยย่อย
 *
 * ชั้นนี้ไม่แตะยอดคงเหลือเลยแม้แต่ที่เดียว การเปลี่ยนสต๊อกทุกกรณีต้องผ่าน
 * Inventory\Posting\Model — รวมถึงตอนตั้งยอดเปิดให้สินค้าที่เพิ่งสร้าง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Product;

use Inventory\Base\Model as Base;
use Inventory\Posting\Model as Posting;

/**
 * Model ของสินค้าหนึ่งรายการ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านสินค้าหนึ่งรายการพร้อมยอดคงเหลือจริงจากสมุดบัญชี
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function get($id)
    {
        $row = static::createDB()->first(Base::table('inventory'), ['id' => (int) $id]);
        if (!$row) {
            return null;
        }
        $product = (array) $row;
        $product['balance'] = Posting::balance((int) $id, 0);
        $product['items'] = self::items((int) $id);

        return $product;
    }

    /**
     * หน่วยย่อยของสินค้า
     *
     * @param int $inventoryId
     *
     * @return array
     */
    public static function items($inventoryId)
    {
        return static::createQuery()
            ->select('id', 'sku', 'product_no', 'barcode', 'topic', 'unit', 'price', 'cut_stock', 'stock', 'instock')
            ->from('inventory_items')
            ->where(['inventory_id', (int) $inventoryId])
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();
    }

    /**
     * สร้างสินค้าใหม่
     *
     * ⚠️ ระบบเดิมของ oms ส่งคอลัมน์ `create_date` ลงตาราง inventory ที่ไม่มี
     * คอลัมน์นั้น และ Kotchasan ไม่กรองคอลัมน์ให้ ผลคือ **เพิ่มสินค้าใหม่พัง
     * ทุกครั้ง** ตรงนี้จึงกรองคีย์ที่รู้จักเท่านั้นก่อนเขียนเสมอ
     *
     * @param array $data
     *
     * @throws \Exception เมื่อข้อมูลไม่ครบหรือรหัสสินค้าซ้ำ
     *
     * @return int id ของสินค้าที่สร้าง
     */
    public static function createProduct(array $data)
    {
        $row = self::prepare($data);
        if ($row['topic'] === '') {
            throw new \Exception('กรุณากรอกชื่อสินค้า');
        }
        if ($row['product_code'] !== null && self::codeExists($row['product_code'], 0)) {
            throw new \Exception('รหัสสินค้า '.$row['product_code'].' ถูกใช้ไปแล้ว');
        }
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['last_update'] = time();

        return (int) static::createDB()->insert(Base::table('inventory'), $row);
    }

    /**
     * แก้ไขสินค้า
     *
     * ชื่อเมธอดไม่ใช่ update() เพราะ Kotchasan\Model มี update($table) เป็น
     * เมธอดของอินสแตนซ์อยู่แล้ว ประกาศทับเป็น static ไม่ได้ (fatal error)
     *
     * @param int   $id
     * @param array $data
     *
     * @throws \Exception
     *
     * @return bool
     */
    public static function updateProduct($id, array $data)
    {
        $id = (int) $id;
        $db = static::createDB();
        if (!$db->exists(Base::table('inventory'), ['id' => $id])) {
            return false;
        }
        $row = self::prepare($data);
        if ($row['product_code'] !== null && self::codeExists($row['product_code'], $id)) {
            throw new \Exception('รหัสสินค้า '.$row['product_code'].' ถูกใช้ไปแล้ว');
        }
        // ⚠️ เขียนเฉพาะคอลัมน์ที่ฟอร์มส่งมาจริง
        //
        // prepare() คืนคอลัมน์ครบทุกตัวพร้อมค่าปริยาย (ราคา/ต้นทุน/ภาษี = 0)
        // ถ้าเขียนทั้งชุด ฟอร์มที่ตัดช่องเหล่านั้นออกไป (เช่นทะเบียนพัสดุที่ไม่มี
        // เรื่องราคา) จะล้างค่าเดิมของสินค้าเป็น 0 ทุกครั้งที่กดบันทึก
        // คอลัมน์ที่ prepare() คำนวณเองจากตัวอื่นต้องเขียนตามตัวที่มันอ้างอิง
        $_derived = [
            'product_code' => ['product_code', 'product_no'],
            'product_no' => ['product_code', 'product_no'],
            'stockable' => ['count_stock'],
            'is_active' => ['is_active', 'inuse'],
            'inuse' => ['is_active', 'inuse']
        ];
        foreach (array_keys($row) as $_col) {
            $_sources = isset($_derived[$_col]) ? $_derived[$_col] : [$_col];
            $_given = false;
            foreach ($_sources as $_src) {
                if (array_key_exists($_src, $data)) {
                    $_given = true;
                    break;
                }
            }
            if (!$_given) {
                unset($row[$_col]);
            }
        }
        // ยอดคงเหลือไม่ใช่ค่าที่แก้จากหน้าแก้ไขสินค้าได้ — ต้องผ่านสมุดบัญชีเสมอ
        unset($row['stock']);
        if (empty($row)) {
            return true;
        }
        $row['updated_at'] = date('Y-m-d H:i:s');
        $row['last_update'] = time();
        $db->update(Base::table('inventory'), ['id', $id], $row);

        return true;
    }

    /**
     * ลบสินค้า
     *
     * สินค้าที่เคยมีการเคลื่อนไหวลบไม่ได้ เพราะสมุดบัญชีจะอ้างถึงสินค้าที่ไม่มีอยู่
     * ให้ปิดการใช้งาน (is_active = 0) แทน ซึ่งเป็นสิ่งที่ผู้ใช้ต้องการจริง ๆ อยู่แล้ว
     *
     * @param int $id
     *
     * @throws \Exception เมื่อสินค้ามีประวัติการเคลื่อนไหว
     *
     * @return bool
     */
    public static function remove($id)
    {
        $id = (int) $id;
        $db = static::createDB();
        if (!$db->exists(Base::table('inventory'), ['id' => $id])) {
            return false;
        }
        if ($db->exists(Base::table('inventory_stock_movement'), ['inventory_id' => $id])) {
            throw new \Exception(
                'สินค้านี้มีประวัติการเคลื่อนไหวของสต๊อกแล้ว จึงลบทิ้งไม่ได้ '
                .'ถ้าไม่ต้องการใช้งานอีก ให้ปิดการใช้งานสินค้าแทน'
            );
        }
        $db->delete(Base::table('inventory_items'), ['inventory_id', $id], 0);
        $db->delete(Base::table('inventory_meta'), ['inventory_id', $id], 0);
        $db->delete(Base::table('inventory_stock'), ['inventory_id', $id], 0);
        $db->delete(Base::table('inventory'), ['id', $id]);

        return true;
    }

    /**
     * เพิ่มหรือแก้ไขหน่วยย่อยของสินค้า
     *
     * @param int   $inventoryId
     * @param array $data ต้องมี sku หรือ product_no อย่างน้อยหนึ่งอย่าง
     * @param int   $itemId 0 = เพิ่มใหม่
     *
     * @throws \Exception เมื่อรหัสหน่วยย่อยซ้ำ
     *
     * @return int id ของหน่วยย่อย
     */
    public static function saveItem($inventoryId, array $data, $itemId = 0)
    {
        $sku = isset($data['sku']) ? trim((string) $data['sku']) : '';
        if ($sku === '' && isset($data['product_no'])) {
            $sku = trim((string) $data['product_no']);
        }
        if ($sku === '') {
            throw new \Exception('กรุณากรอกรหัสของหน่วยย่อย');
        }

        $db = static::createDB();
        $table = Base::table('inventory_items');
        $exists = $db->first($table, ['sku' => $sku]);
        if ($exists && (int) $exists->id !== (int) $itemId) {
            throw new \Exception('รหัส '.$sku.' ถูกใช้ไปแล้ว');
        }

        $row = [
            'sku' => $sku,
            'product_no' => $sku,
            'inventory_id' => (int) $inventoryId,
            'barcode' => isset($data['barcode']) && $data['barcode'] !== '' ? $data['barcode'] : null,
            'topic' => isset($data['topic']) ? $data['topic'] : null,
            'unit' => isset($data['unit']) ? $data['unit'] : null,
            'price' => isset($data['price']) ? (float) $data['price'] : null,
            'cut_stock' => isset($data['cut_stock']) && $data['cut_stock'] > 0 ? (float) $data['cut_stock'] : 1,
            'url' => isset($data['url']) ? $data['url'] : null,
            'last_update' => time()
        ];
        if ((int) $itemId > 0) {
            $db->update($table, ['id', (int) $itemId], $row);

            return (int) $itemId;
        }

        return (int) $db->insert($table, $row);
    }

    /**
     * ตั้งยอดเปิดให้สินค้า — ผ่านสมุดบัญชีเสมอ ไม่ใช่เขียนทับ inventory.stock
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param float $quantity
     * @param float $unitCost
     *
     * @return int
     */
    public static function openingBalance($inventoryId, $inventoryItemId, $quantity, $unitCost = 0)
    {
        return Posting::opening($inventoryId, $inventoryItemId, $quantity, $unitCost);
    }

    /**
     * แปลงข้อมูลที่รับมาให้เป็นแถวที่เขียนลงตารางได้ กรองคีย์ที่ไม่รู้จักทิ้ง
     *
     * ชื่อกลางกับชื่อเดิมต้องเขียนคู่กันเสมอระหว่างเปลี่ยนผ่าน — ระบบเดิมและ
     * รายงานที่ผู้ใช้เขียนเองยังอ่านคอลัมน์เดิมอยู่ ปล่อยให้ค้างค่าเก่าไม่ได้
     *
     * @param array $data
     *
     * @return array
     */
    protected static function prepare(array $data)
    {
        $code = isset($data['product_code']) ? trim((string) $data['product_code']) : '';
        if ($code === '' && isset($data['product_no'])) {
            $code = trim((string) $data['product_no']);
        }
        $countStock = isset($data['count_stock']) ? (int) $data['count_stock'] : 1;
        $isActive = isset($data['is_active']) ? (int) $data['is_active'] : (isset($data['inuse']) ? (int) $data['inuse'] : 1);

        return [
            'product_code' => $code === '' ? null : $code,
            'product_no' => $code === '' ? null : $code,
            'topic' => isset($data['topic']) ? trim((string) $data['topic']) : '',
            'description' => isset($data['description']) ? $data['description'] : null,
            'category_id' => isset($data['category_id']) && $data['category_id'] !== '' ? (string) $data['category_id'] : null,
            'model_id' => isset($data['model_id']) && $data['model_id'] !== '' ? (string) $data['model_id'] : null,
            'type_id' => isset($data['type_id']) && $data['type_id'] !== '' ? (string) $data['type_id'] : null,
            'unit' => isset($data['unit']) ? $data['unit'] : null,
            'price' => isset($data['price']) ? (float) $data['price'] : 0,
            'vat' => isset($data['vat']) ? (float) $data['vat'] : 0,
            'cost' => isset($data['cost']) ? (float) $data['cost'] : 0,
            'count_stock' => $countStock,
            // stockable เป็นมุมมองหนึ่งของ count_stock ไม่ใช่สวิตช์อิสระ
            'stockable' => $countStock > 0 ? 1 : 0,
            'allow_negative' => isset($data['allow_negative']) ? (int) $data['allow_negative'] : 0,
            'is_active' => $isActive,
            'inuse' => $isActive
        ];
    }

    /**
     * รหัสสินค้านี้ถูกใช้ไปแล้วหรือยัง
     *
     * @param string $code
     * @param int    $exceptId
     *
     * @return bool
     */
    protected static function codeExists($code, $exceptId)
    {
        $row = static::createDB()->first(Base::table('inventory'), ['product_code' => $code]);

        return $row && (int) $row->id !== (int) $exceptId;
    }
}
