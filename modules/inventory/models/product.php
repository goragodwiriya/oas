<?php
/**
 * @filesource modules/inventory/models/product.php
 *
 * ทะเบียนสินค้า — สินค้า (inventory_product) และ variant (inventory)
 *
 * สินค้า = สิ่งที่ลูกค้าเห็นเป็นหน้าเดียว · variant = กองสต๊อกที่นับ ขาย และรายงาน
 * สินค้าธรรมดามี variant เดียวที่ไม่มีตัวเลือก (modules/inventory/DESIGN.md ข้อ 2–3)
 *
 * ชั้นนี้ไม่แตะยอดคงเหลือเลย การเปลี่ยนสต๊อกทุกกรณีต้องผ่าน Inventory\Posting\Model
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Product;

use Inventory\Base\Model as Base;
use Inventory\Posting\Model as Posting;

/**
 * Model ของสินค้าและ variant
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คอลัมน์ของสินค้าที่รับจากผู้เรียก
     *
     * @var array
     */
    const PRODUCT_COLUMNS = [
        'product_code', 'topic', 'description', 'category_id', 'model_id', 'type_id',
        'count_stock', 'unit', 'vat', 'allow_negative',
        'option1_name', 'option2_name', 'option3_name', 'is_active', 'published'
    ];

    /**
     * คอลัมน์ของ variant ที่รับจากผู้เรียก (topic · cost · stock เป็น cache ไม่รับ)
     *
     * @var array
     */
    const VARIANT_COLUMNS = ['sku', 'option1', 'option2', 'option3', 'price', 'is_active', 'sort_order'];

    /**
     * variant หนึ่งตัวพร้อมข้อมูลของสินค้า หน่วยขาย/ชิ้น และยอดคงเหลือจริงจากสมุดบัญชี
     *
     * @param int $id inventory.id (variant)
     *
     * @return array|null
     */
    public static function get($id)
    {
        $rows = static::createQuery()
            ->select('V.*', 'P.product_code', 'P.description', 'P.category_id', 'P.model_id', 'P.type_id',
                'P.count_stock', 'P.unit', 'P.vat', 'P.allow_negative',
                'P.option1_name', 'P.option2_name', 'P.option3_name', 'P.published')
            ->selectRaw('P.`topic` AS `product_topic`')
            ->selectRaw('P.`is_active` AS `product_is_active`')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.id', (int) $id])
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            return null;
        }
        $variant = $rows[0];
        $variant['balance'] = Posting::balance((int) $id, 0);
        $variant['items'] = self::items((int) $id);

        return $variant;
    }

    /**
     * สินค้าหนึ่งตัวพร้อม variant ทั้งหมด
     *
     * @param int $productId
     *
     * @return array|null
     */
    public static function getProduct($productId)
    {
        $row = static::createDB()->first(Base::table('inventory_product'), ['id' => (int) $productId]);
        if (!$row) {
            return null;
        }
        $product = (array) $row;
        $product['variants'] = static::createQuery()
            ->select('*')
            ->from('inventory')
            ->where(['product_id', (int) $productId])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();

        return $product;
    }

    /**
     * หน่วยขายและชิ้นของ variant
     *
     * @param int $inventoryId
     *
     * @return array
     */
    public static function items($inventoryId)
    {
        return static::createQuery()
            ->select('id', 'barcode', 'serial_no', 'unit', 'cut_stock', 'price', 'stock', 'is_active')
            ->from('inventory_items')
            ->where(['inventory_id', (int) $inventoryId])
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();
    }

    /**
     * สร้างสินค้าพร้อม variant ตั้งต้นและหน่วยขายฐาน (×1)
     *
     * สินค้าส่วนใหญ่มีแบบเดียว จึงสร้างให้ครบในครั้งเดียว — ยิงบาร์โค้ดขายได้ทันที
     * ข้อมูลของ variant ส่งมาในคีย์เดียวกัน : sku (ว่าง = product_code) · price ·
     * option1..3 · barcode ของหน่วยขายฐาน (ว่าง = sku)
     *
     * @param array $data
     *
     * @throws \Exception เมื่อข้อมูลไม่ครบหรือรหัสซ้ำ
     *
     * @return int id ของ variant ที่สร้าง (inventory.id)
     */
    public static function addProduct(array $data)
    {
        return Base::transaction(function () use ($data) {
            $productId = self::saveProduct(0, $data);
            $code = (string) static::createDB()->first(Base::table('inventory_product'), ['id' => $productId])->product_code;
            $variant = self::pick($data, self::VARIANT_COLUMNS);
            if (!isset($variant['sku']) || trim((string) $variant['sku']) === '') {
                $variant['sku'] = $code;
            }
            if (isset($data['barcode'])) {
                $variant['barcode'] = $data['barcode'];
            }

            return self::saveVariant($productId, 0, $variant);
        });
    }

    /**
     * เพิ่มหรือแก้ไขสินค้า (ไม่รวม variant)
     *
     * แก้ไขเขียนเฉพาะคอลัมน์ที่ส่งมาจริง — ฟอร์มที่ไม่มีบางช่องต้องไม่ล้างค่าเดิม
     * วิธีนับ (count_stock) และหน่วยฐาน (unit) เปลี่ยนไม่ได้เมื่อมีรายการในสมุดบัญชีแล้ว
     * เพราะยอดเดิมนับด้วยกติกาเดิมและหน่วยเดิม
     *
     * @param int   $productId 0 = เพิ่มใหม่
     * @param array $data
     *
     * @throws \Exception
     *
     * @return int id ของสินค้า
     */
    public static function saveProduct($productId, array $data)
    {
        $productId = (int) $productId;
        $db = static::createDB();
        $table = Base::table('inventory_product');
        $row = self::pick($data, self::PRODUCT_COLUMNS);
        foreach (['product_code', 'topic', 'unit', 'option1_name', 'option2_name', 'option3_name'] as $_col) {
            if (isset($row[$_col])) {
                $row[$_col] = trim((string) $row[$_col]);
            }
        }
        foreach (['category_id', 'model_id', 'type_id', 'option1_name', 'option2_name', 'option3_name'] as $_col) {
            if (array_key_exists($_col, $row) && ($row[$_col] === '' || $row[$_col] === null)) {
                $row[$_col] = null;
            }
        }
        if (isset($row['count_stock']) && !in_array((int) $row['count_stock'], [0, 1, 2], true)) {
            throw new \Exception('วิธีนับสต๊อกไม่ถูกต้อง');
        }

        if ($productId === 0) {
            if (!isset($row['topic']) || $row['topic'] === '') {
                throw new \Exception('กรุณากรอกชื่อสินค้า');
            }
            $row += ['count_stock' => 1, 'unit' => '', 'is_active' => 1];
            $code = isset($row['product_code']) ? $row['product_code'] : '';
            if ($code !== '' && self::productCodeExists($code, 0)) {
                throw new \Exception('รหัสสินค้า '.$code.' ถูกใช้ไปแล้ว');
            }
            // ไม่กรอกรหัส = ออกให้จากรูปแบบในค่ากำหนด (product_code_format) ด้วย id ของสินค้า
            // ต้องรู้ id ก่อน จึงใส่รหัสชั่วคราวที่ไม่ซ้ำแน่ ๆ แล้วค่อยเปลี่ยน
            $row['product_code'] = $code === '' ? '#'.uniqid('', true) : $code;
            $row['created_at'] = date('Y-m-d H:i:s');
            $productId = (int) $db->insert($table, $row);
            if ($code === '') {
                $code = sprintf((string) Base::config('product_code_format', 'P%04d'), $productId);
                if (self::productCodeExists($code, $productId)) {
                    $code .= '-'.$productId;
                }
                $db->update($table, ['id', $productId], ['product_code' => $code]);
            }

            return $productId;
        }

        $current = $db->first($table, ['id' => $productId]);
        if (!$current) {
            throw new \Exception('ไม่พบสินค้า');
        }
        if (array_key_exists('topic', $row) && $row['topic'] === '') {
            throw new \Exception('กรุณากรอกชื่อสินค้า');
        }
        if (array_key_exists('product_code', $row)) {
            if ($row['product_code'] === '') {
                unset($row['product_code']);
            } elseif (self::productCodeExists($row['product_code'], $productId)) {
                throw new \Exception('รหัสสินค้า '.$row['product_code'].' ถูกใช้ไปแล้ว');
            }
        }
        foreach (['count_stock' => 'วิธีนับสต๊อก', 'unit' => 'หน่วยนับ'] as $_col => $_label) {
            if (array_key_exists($_col, $row) && (string) $row[$_col] !== (string) $current->{$_col}
                && self::hasMovement($productId)) {
                throw new \Exception('เปลี่ยน'.$_label.'ของสินค้าที่มีความเคลื่อนไหวแล้วไม่ได้ เพราะยอดเดิมนับด้วยกติกาเดิม');
            }
        }
        if (empty($row)) {
            return $productId;
        }
        $row['updated_at'] = date('Y-m-d H:i:s');
        $db->update($table, ['id', $productId], $row);
        foreach (self::variantIds($productId) as $variantId) {
            if (array_key_exists('topic', $row)) {
                self::refreshTopic($variantId);
            }
            self::changed($variantId);
        }

        return $productId;
    }

    /**
     * เพิ่มหรือแก้ไข variant ของสินค้า
     *
     * variant ใหม่ได้หน่วยขายฐาน (×1) ให้ทันที บาร์โค้ด = $data['barcode'] หรือ sku
     *
     * @param int   $productId
     * @param int   $variantId 0 = เพิ่มใหม่
     * @param array $data      sku · option1..3 · price · is_active · sort_order · (barcode สำหรับ variant ใหม่)
     *
     * @throws \Exception
     *
     * @return int id ของ variant (inventory.id)
     */
    public static function saveVariant($productId, $variantId, array $data)
    {
        $productId = (int) $productId;
        $variantId = (int) $variantId;
        $db = static::createDB();
        $table = Base::table('inventory');
        if (!$db->first(Base::table('inventory_product'), ['id' => $productId])) {
            throw new \Exception('ไม่พบสินค้า');
        }
        $row = self::pick($data, self::VARIANT_COLUMNS);
        foreach (['sku', 'option1', 'option2', 'option3'] as $_col) {
            if (array_key_exists($_col, $row)) {
                $row[$_col] = trim((string) $row[$_col]);
            }
        }
        $current = null;
        if ($variantId > 0) {
            $current = $db->first($table, ['id' => $variantId]);
            if (!$current || (int) $current->product_id !== $productId) {
                throw new \Exception('ไม่พบ variant ของสินค้านี้');
            }
        }
        if (array_key_exists('sku', $row) || $variantId === 0) {
            $sku = isset($row['sku']) ? $row['sku'] : '';
            if ($sku === '') {
                throw new \Exception('กรุณากรอก SKU');
            }
            $exists = $db->first($table, ['sku' => $sku]);
            if ($exists && (int) $exists->id !== $variantId) {
                throw new \Exception('SKU '.$sku.' ถูกใช้ไปแล้ว');
            }
        }
        // ค่าตัวเลือกชุดเดียวกันในสินค้าเดียวคือของชิ้นเดียวกัน — แยกเป็นสองกองไม่ได้
        $options = [];
        foreach (['option1', 'option2', 'option3'] as $_col) {
            $options[$_col] = array_key_exists($_col, $row) ? $row[$_col] : ($current ? (string) $current->{$_col} : '');
        }
        $same = $db->first($table, array_merge(['product_id' => $productId], $options));
        if ($same && (int) $same->id !== $variantId) {
            throw new \Exception('สินค้านี้มีแบบ '.trim(implode(' / ', array_filter($options, 'strlen'))).' อยู่แล้ว');
        }

        if ($variantId === 0) {
            $row += $options + ['price' => 0, 'is_active' => 1, 'sort_order' => 0];
            $row['product_id'] = $productId;
            $row['created_at'] = date('Y-m-d H:i:s');
            $variantId = (int) $db->insert($table, $row);
            \Inventory\Items\Model::savePack($variantId, 0, [
                'barcode' => isset($data['barcode']) ? $data['barcode'] : '',
                'cut_stock' => 1
            ]);
        } elseif (!empty($row)) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update($table, ['id', $variantId], $row);
        }
        self::refreshTopic($variantId);
        self::changed($variantId);

        return $variantId;
    }

    /**
     * ลบสินค้าทั้งตัว
     *
     * สินค้าที่เคยมีการเคลื่อนไหวลบไม่ได้ เพราะสมุดบัญชีจะอ้างถึงสินค้าที่ไม่มีอยู่
     * ให้ปิดการใช้งาน (is_active = 0) แทน
     *
     * @param int $productId
     *
     * @throws \Exception เมื่อสินค้ามีประวัติการเคลื่อนไหว
     *
     * @return bool
     */
    public static function remove($productId)
    {
        $productId = (int) $productId;
        $db = static::createDB();
        if (!$db->first(Base::table('inventory_product'), ['id' => $productId])) {
            return false;
        }
        if (self::hasMovement($productId)) {
            throw new \Exception('สินค้านี้มีความเคลื่อนไหวของสต๊อกแล้ว จึงลบไม่ได้ ให้ปิดการใช้งานสินค้าแทน');
        }
        // บรรทัดเอกสารที่อ้างสินค้า (ใบเสนอราคา · ร่าง) ต้องอ่านความหมายเดิมได้เสมอ
        $ids = self::variantIds($productId);
        if (!empty($ids) && $db->first(Base::table('order_items'), [['inventory_id', $ids]])) {
            throw new \Exception('สินค้านี้ถูกใช้ในเอกสารแล้ว จึงลบไม่ได้ ให้ปิดการใช้งานสินค้าแทน');
        }
        Base::transaction(function () use ($db, $productId) {
            foreach (self::variantIds($productId) as $variantId) {
                $db->delete(Base::table('inventory_items'), ['inventory_id', $variantId], 0);
                $db->delete(Base::table('inventory_stock'), ['inventory_id', $variantId], 0);
            }
            $db->delete(Base::table('inventory'), ['product_id', $productId], 0);
            $db->delete(Base::table('inventory_meta'), ['product_id', $productId], 0);
            $db->delete(Base::table('inventory_product'), ['id', $productId]);
        });
        foreach ($ids as $variantId) {
            self::removed($variantId);
        }

        return true;
    }

    /**
     * ลบ variant หนึ่งตัว — ต้องไม่เคยเคลื่อนไหว และต้องไม่ใช่ตัวสุดท้ายของสินค้า
     *
     * @param int $variantId
     *
     * @throws \Exception
     *
     * @return bool
     */
    public static function removeVariant($variantId)
    {
        $variantId = (int) $variantId;
        $db = static::createDB();
        $variant = $db->first(Base::table('inventory'), ['id' => $variantId]);
        if (!$variant) {
            return false;
        }
        if ($db->first(Base::table('inventory_stock_movement'), ['inventory_id' => $variantId])) {
            throw new \Exception('แบบนี้มีความเคลื่อนไหวของสต๊อกแล้ว จึงลบไม่ได้ ให้ปิดการใช้งานแทน');
        }
        if ($db->first(Base::table('order_items'), ['inventory_id' => $variantId])) {
            throw new \Exception('แบบนี้ถูกใช้ในเอกสารแล้ว จึงลบไม่ได้ ให้ปิดการใช้งานแทน');
        }
        if (count(self::variantIds((int) $variant->product_id)) <= 1) {
            throw new \Exception('สินค้าต้องมีอย่างน้อยหนึ่งแบบ ถ้าไม่ใช้แล้วให้ลบหรือปิดการใช้งานสินค้าแทน');
        }
        Base::transaction(function () use ($db, $variantId) {
            $db->delete(Base::table('inventory_items'), ['inventory_id', $variantId], 0);
            $db->delete(Base::table('inventory_stock'), ['inventory_id', $variantId], 0);
            $db->delete(Base::table('inventory'), ['id', $variantId]);
        });
        self::removed($variantId);

        return true;
    }

    /**
     * จุดเสียบ onProductSaved — แบบสินค้า (หรือสินค้าของมัน หน่วยขาย/เครื่องของมัน) ถูกเพิ่ม/แก้
     *
     * โมดูลที่เก็บข้อมูลต่อยอดของสินค้า (เช่น แคตตาล็อกอะไหล่ที่มีคำค้นรวม) ต้องรู้ทุกครั้งที่ชื่อ
     * รหัส หรือบาร์โค้ดเปลี่ยน · ทุกทางที่เขียนทะเบียนสินค้าผ่านโมเดลนี้และ Items\Model
     * (หน้าสินค้า · นำเข้าไฟล์ · หน่วยขาย/เครื่อง) จึงเรียกที่นี่ที่เดียว
     *
     * @param int $variantId inventory.id
     */
    public static function changed($variantId)
    {
        $params = ['inventory_id' => (int) $variantId];
        \Gcms\Controller::initModule(null, 'onProductSaved', null, $params);
    }

    /**
     * จุดเสียบ onProductRemove — โมดูลที่แขวนข้อมูลไว้กับแบบสินค้าเก็บของของตัวเอง (เรียกหลังลบแล้ว)
     *
     * @param int $variantId inventory.id
     */
    public static function removed($variantId)
    {
        $params = ['inventory_id' => (int) $variantId];
        \Gcms\Controller::initModule(null, 'onProductRemove', null, $params);
    }

    /**
     * ตั้งยอดเปิด — ผ่านสมุดบัญชีเสมอ ไม่ใช่เขียนทับ inventory.stock
     *
     * @param int   $inventoryId
     * @param int   $inventoryItemId
     * @param float $quantity
     * @param float $unitCost
     * @param int   $warehouseId 0 = คลังตั้งต้น
     * @param string|null $occurredAt วันเวลาของยอดยกมา · null = ตอนนี้
     *
     * @return int
     */
    public static function openingBalance($inventoryId, $inventoryItemId, $quantity, $unitCost = 0, $warehouseId = 0, $occurredAt = null)
    {
        return Posting::opening($inventoryId, $inventoryItemId, $quantity, $unitCost, $warehouseId, $occurredAt);
    }

    /**
     * วิธีนับสต๊อกของ variant (เป็นของสินค้า ใช้ร่วมกันทุก variant)
     *
     * @param int $inventoryId
     *
     * @return int 0 ไม่นับ · 1 นับจำนวน · 2 นับรายชิ้น · ไม่พบ = 0
     */
    public static function countStock($inventoryId)
    {
        $rows = static::createQuery()
            ->select('P.count_stock')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.id', (int) $inventoryId])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? 0 : (int) $rows[0]['count_stock'];
    }

    /**
     * สินค้านี้ (variant ไหนก็ได้) มีรายการในสมุดบัญชีแล้วหรือยัง
     *
     * @param int $productId
     *
     * @return bool
     */
    public static function hasMovement($productId)
    {
        $ids = self::variantIds($productId);
        if (empty($ids)) {
            return false;
        }

        return static::createDB()->first(Base::table('inventory_stock_movement'), [['inventory_id', $ids]]) ? true : false;
    }

    /**
     * ชื่อเต็มของ variant (cache) = ชื่อสินค้า + ค่าตัวเลือก
     *
     * @param int $variantId
     *
     * @return string|null ชื่อที่เขียน · null = ไม่พบ variant
     */
    public static function refreshTopic($variantId)
    {
        $rows = static::createQuery()
            ->select('V.option1', 'V.option2', 'V.option3', 'P.topic')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->where(['V.id', (int) $variantId])
            ->execute(null, 'array')
            ->fetchAll();
        if (empty($rows)) {
            return null;
        }
        $options = array_filter([$rows[0]['option1'], $rows[0]['option2'], $rows[0]['option3']], 'strlen');
        $topic = trim($rows[0]['topic'].(empty($options) ? '' : ' '.implode(' / ', $options)));
        $topic = mb_substr($topic, 0, 255);
        static::createDB()->update(Base::table('inventory'), ['id', (int) $variantId], ['topic' => $topic]);

        return $topic;
    }

    /**
     * id ของ variant ทั้งหมดของสินค้า
     *
     * @param int $productId
     *
     * @return array
     */
    protected static function variantIds($productId)
    {
        $ids = [];
        foreach (static::createQuery()->select('id')->from('inventory')->where(['product_id', (int) $productId])
            ->execute(null, 'array')->fetchAll() as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * รหัสสินค้านี้ถูกใช้ไปแล้วหรือยัง
     *
     * @param string $code
     * @param int    $exceptId
     *
     * @return bool
     */
    protected static function productCodeExists($code, $exceptId)
    {
        $row = static::createDB()->first(Base::table('inventory_product'), ['product_code' => $code]);

        return $row && (int) $row->id !== (int) $exceptId;
    }

    /**
     * เลือกเฉพาะคีย์ที่รู้จัก — Kotchasan ไม่กรองคอลัมน์ให้ คีย์แปลกปลอมทำให้ INSERT ล้ม
     *
     * @param array $data
     * @param array $columns
     *
     * @return array
     */
    protected static function pick(array $data, array $columns)
    {
        return array_intersect_key($data, array_flip($columns));
    }
}
