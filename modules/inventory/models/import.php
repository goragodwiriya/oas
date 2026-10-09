<?php
/**
 * @filesource modules/inventory/models/import.php
 *
 * นำเข้าสินค้า/ลูกค้าจากไฟล์ CSV
 *
 * ⚠️ ระบบเดิม (inventory-import&type=product|customer) เขียนข้อมูลลงตาราง `user`
 * ทั้งสองชนิด — ไฟล์ models/customerimport.php กับ models/productimport.php
 * ต่างกันแค่ namespace และทั้งคู่เป็นสำเนาของตัวนำเข้าสมาชิก
 * กดใช้งานจริงจะได้สมาชิกใหม่เต็มระบบ ไม่ได้สินค้าหรือลูกค้าเลย
 * ที่นี่จึงเขียนใหม่ให้ลงตารางที่ถูกต้อง (inventory / customer)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Import;

use Inventory\Base\Model as Base;
use Kotchasan\Language;

/**
 * Model นำเข้าข้อมูลจากไฟล์ CSV
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชนิดข้อมูลที่นำเข้าได้ และคอลัมน์ของแต่ละชนิด
     *
     * key ของคอลัมน์ = หัวตารางในไฟล์ CSV (ต้องตรงกับที่ส่งออกไป)
     * required = คอลัมน์ที่ต้องมีในไฟล์ ไม่งั้นไม่ยอมรับไฟล์
     * key      = คอลัมน์ที่ใช้หาว่าแถวนี้มีอยู่แล้วหรือยัง (มีแล้ว = อัปเดต)
     *
     * @return array
     */
    public static function types()
    {
        return [
            'product' => [
                'title' => '{LNG_Inventory}',
                'table' => 'inventory',
                'key' => 'product_code',
                'required' => ['topic'],
                // ⚠️ model กับ type เป็นหมวดหมู่ของระบบเดิม (รุ่น / ประเภทพัสดุ)
                // ถ้าไม่มีในไฟล์ ข้อมูลที่ส่งออกแล้วนำกลับเข้ามาจะเสียสองค่านี้ไป
                'columns' => ['product_code', 'topic', 'description', 'category', 'model', 'type', 'unit',
                    'price', 'cost', 'count_stock', 'vat', 'stock', 'is_active'],
                'url' => '/inventory-setup'
            ],
            'customer' => [
                'title' => '{LNG_Customer list}-{LNG_Supplier}',
                'table' => 'customer',
                'key' => 'customer_no',
                'required' => ['company'],
                // ⚠️ is_customer/is_supplier/is_active เป็นธงจริงที่หน้ารายชื่อใช้กรอง
                // และฟอร์มเพิ่ม/แก้ใช้บันทึก ถ้าไม่มีสามคอลัมน์นี้ ส่งออกแล้วนำกลับเข้ามา
                // จะได้ลูกค้าที่เป็น "ลูกค้า" อย่างเดียวทุกแถว ผู้ขายที่มีอยู่จะหายไป
                'columns' => ['customer_no', 'company', 'branch', 'tax_id', 'name', 'contact', 'idcard', 'address',
                    'province', 'zipcode', 'country', 'phone', 'fax', 'email', 'website',
                    'bank', 'bank_name', 'bank_no', 'discount', 'invoice_due_date', 'expense_due_date',
                    'is_customer', 'is_supplier', 'is_active'],
                'url' => '/inventory-customers'
            ]
        ];
    }

    /**
     * ข้อมูลของชนิดที่เลือก
     *
     * @param string $type
     *
     * @return array|null
     */
    public static function type($type)
    {
        $types = self::types();

        return isset($types[$type]) ? $types[$type] : null;
    }

    /**
     * แถวข้อมูลสำหรับส่งออกเป็น CSV (ใช้เป็นไฟล์ตัวอย่างของการนำเข้าด้วย)
     *
     * @param string $type
     *
     * @return array
     */
    public static function rows($type)
    {
        $spec = self::type($type);
        if ($spec === null) {
            return [];
        }

        if ($type === 'product') {
            $categories = \Inventory\Category\Controller::init();
            $rows = static::createQuery()
                ->select('V.product_code', 'V.topic', 'V.description', 'V.category_id',
                    'V.model_id', 'V.type_id', 'V.unit',
                    'V.price', 'V.cost', 'V.count_stock', 'V.vat', 'V.stock', 'V.is_active')
                ->from('inventory V')
                ->orderBy('V.id')
                ->execute(null, 'array')
                ->fetchAll();
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    $row['product_code'],
                    $row['topic'],
                    $row['description'],
                    $categories->get('category_id', $row['category_id'], ''),
                    $categories->get('model_id', $row['model_id'], ''),
                    $categories->get('type_id', $row['type_id'], ''),
                    $row['unit'],
                    (float) $row['price'],
                    (float) $row['cost'],
                    (int) $row['count_stock'],
                    (float) $row['vat'],
                    (float) $row['stock'],
                    (int) $row['is_active']
                ];
            }

            return $result;
        }

        $rows = static::createQuery()
            ->select('customer_no', 'company', 'branch', 'tax_id', 'name', 'contact', 'idcard', 'address',
                'province', 'zipcode', 'country', 'phone', 'fax', 'email', 'website',
                'bank', 'bank_name', 'bank_no', 'discount', 'invoice_due_date', 'expense_due_date',
                'is_customer', 'is_supplier', 'is_active')
            ->from('customer')
            ->orderBy('id')
            ->execute(null, 'array')
            ->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $row['discount'] = (float) $row['discount'];
            $row['invoice_due_date'] = (int) $row['invoice_due_date'];
            $row['expense_due_date'] = (int) $row['expense_due_date'];
            $row['is_customer'] = (int) $row['is_customer'];
            $row['is_supplier'] = (int) $row['is_supplier'];
            $row['is_active'] = (int) $row['is_active'];
            $result[] = array_values($row);
        }

        return $result;
    }

    /**
     * นำเข้าหนึ่งแถว
     *
     * คืนค่า 'new' | 'update' | 'skip' เพื่อให้ตัวเรียกนับผลรวมได้
     *
     * @param string $type
     * @param array  $row  ข้อมูลหนึ่งแถวจากไฟล์ (คีย์คือหัวตาราง)
     *
     * @return string
     */
    public static function importRow($type, array $row)
    {
        $spec = self::type($type);
        if ($spec === null) {
            return 'skip';
        }

        foreach ($spec['required'] as $need) {
            if (!isset($row[$need]) || trim((string) $row[$need]) === '') {
                return 'skip';
            }
        }

        return $type === 'product' ? self::importProduct($row) : self::importCustomer($row);
    }

    /**
     * นำเข้าสินค้าหนึ่งรายการ
     *
     * @param array $row
     *
     * @return string
     */
    protected static function importProduct(array $row)
    {
        $db = static::createDB();
        $table = Base::table('inventory');

        $productCode = trim((string) (isset($row['product_code']) ? $row['product_code'] : ''));
        $topic = trim((string) $row['topic']);

        // หมวดหมู่ในไฟล์เป็น "ชื่อ" ถ้ายังไม่มีจะถูกสร้างให้ เหมือนตอนพิมพ์ในฟอร์ม
        // หมวดหมู่/รุ่น/ประเภทในไฟล์เป็น "ชื่อ" ถ้ายังไม่มีจะถูกสร้างให้
        // เหมือนตอนพิมพ์ชื่อใหม่ลงในฟอร์ม
        $categoryIds = [];
        foreach (['category' => 'category_id', 'model' => 'model_id', 'type' => 'type_id'] as $_col => $_type) {
            $_name = trim((string) (isset($row[$_col]) ? $row[$_col] : ''));
            $categoryIds[$_type] = $_name === '' || is_numeric($_name)
                ? ''
                : \Inventory\Category\Controller::save($_type, $_name);
        }
        $categoryId = $categoryIds['category_id'];

        // หน่วยนับเก็บเป็นชื่อลงคอลัมน์ unit อยู่แล้ว จดเข้ารายการแนะนำด้วย
        // เพื่อให้ฟอร์มหลังนำเข้ามีหน่วยนับจากไฟล์ให้เลือก ไม่ต้องพิมพ์ใหม่ทุกครั้ง
        $_unit = trim((string) (isset($row['unit']) ? $row['unit'] : ''));
        if ($_unit !== '') {
            \Inventory\Category\Controller::save('unit', $_unit);
        }

        $data = [
            'product_code' => $productCode,
            'topic' => $topic,
            'description' => (string) (isset($row['description']) ? $row['description'] : ''),
            'category_id' => $categoryId,
            'model_id' => $categoryIds['model_id'],
            'type_id' => $categoryIds['type_id'],
            'unit' => (string) (isset($row['unit']) ? $row['unit'] : ''),
            'price' => self::toNumber(isset($row['price']) ? $row['price'] : 0),
            'cost' => self::toNumber(isset($row['cost']) ? $row['cost'] : 0),
            'count_stock' => (int) (isset($row['count_stock']) ? $row['count_stock'] : 0),
            'vat' => (int) self::toNumber(isset($row['vat']) ? $row['vat'] : 0),
            'is_active' => isset($row['is_active']) && $row['is_active'] !== '' ? (int) $row['is_active'] : 1
        ];

        // หาของเดิมจากรหัสสินค้าก่อน ถ้าไม่มีรหัสมาให้ใช้ชื่อสินค้า
        $found = null;
        if ($productCode !== '') {
            $found = $db->first($table, ['product_code' => $productCode]);
        }
        if ($found === null || $found === false) {
            $found = $db->first($table, ['topic' => $topic]);
        }

        if ($found) {
            // ⚠️ ไม่แตะยอดคงเหลือของสินค้าที่มีอยู่แล้ว — การนำเข้าไฟล์ต้องไม่ใช่
            // ช่องทางแก้สต๊อก ไม่งั้นนำเข้าไฟล์เดิมซ้ำจะทำให้ยอดกระโดดทุกครั้ง
            \Inventory\Product\Model::updateProduct((int) $found->id, $data);

            return 'update';
        }

        // ⚠️ ต้องผ่าน Product\Model — มันกรองคีย์ที่ไม่รู้จักและเขียนชื่อกลางกับ
        // ชื่อเดิมคู่กันให้เอง เขียนตารางตรง ๆ จากที่นี่คือการมีตรรกะสองชุด
        $id = \Inventory\Product\Model::createProduct($data);

        // สินค้าต้องมีหน่วยย่อยอย่างน้อยหนึ่งรหัส ไม่งั้นค้นหาใส่เอกสารไม่เจอ
        if ($data['product_code'] !== '') {
            \Inventory\Product\Model::saveItem($id, [
                'sku' => $data['product_code'],
                'topic' => '',
                'price' => $data['price'],
                'unit' => $data['unit'],
                'cut_stock' => 1
            ]);
        }

        // ยอดตั้งต้นจากไฟล์เข้าสมุดบัญชีเป็นการเคลื่อนไหวชนิด opening
        $stock = self::toNumber(isset($row['stock']) ? $row['stock'] : 0);
        if ($stock != 0 && $data['count_stock'] > 0) {
            \Inventory\Product\Model::openingBalance($id, 0, $stock, $data['cost']);
        }

        return 'new';
    }

    /**
     * นำเข้าลูกค้าหนึ่งราย
     *
     * @param array $row
     *
     * @return string
     */
    protected static function importCustomer(array $row)
    {
        $db = static::createDB();
        $table = Base::table('customer');

        $customerNo = trim((string) (isset($row['customer_no']) ? $row['customer_no'] : ''));
        $company = trim((string) $row['company']);
        $country = strtoupper(trim((string) (isset($row['country']) ? $row['country'] : '')));
        if ($country === '') {
            $country = 'TH';
        }

        $province = trim((string) (isset($row['province']) ? $row['province'] : ''));
        $provinceId = $province === '' ? 0 : (int) \Kotchasan\Province::isoFromProvince($province, '', $country);

        $save = [
            'company' => $company,
            'branch' => (string) (isset($row['branch']) ? $row['branch'] : ''),
            'tax_id' => (string) (isset($row['tax_id']) ? $row['tax_id'] : ''),
            'name' => (string) (isset($row['name']) ? $row['name'] : ''),
            'contact' => (string) (isset($row['contact']) ? $row['contact'] : ''),
            'idcard' => (string) (isset($row['idcard']) ? $row['idcard'] : ''),
            'address' => (string) (isset($row['address']) ? $row['address'] : ''),
            'provinceID' => $provinceId,
            'province' => $province,
            'zipcode' => (string) (isset($row['zipcode']) ? $row['zipcode'] : ''),
            'country' => $country,
            'phone' => (string) (isset($row['phone']) ? $row['phone'] : ''),
            'fax' => (string) (isset($row['fax']) ? $row['fax'] : ''),
            'email' => (string) (isset($row['email']) ? $row['email'] : ''),
            'website' => (string) (isset($row['website']) ? $row['website'] : ''),
            'bank' => (string) (isset($row['bank']) ? $row['bank'] : ''),
            'bank_name' => (string) (isset($row['bank_name']) ? $row['bank_name'] : ''),
            'bank_no' => (string) (isset($row['bank_no']) ? $row['bank_no'] : ''),
            'discount' => self::toNumber(isset($row['discount']) ? $row['discount'] : 0),
            'invoice_due_date' => (int) (isset($row['invoice_due_date']) ? $row['invoice_due_date'] : 0),
            'expense_due_date' => (int) (isset($row['expense_due_date']) ? $row['expense_due_date'] : 0)
        ];

        // ธงลูกค้า/คู่ค้า/ใช้งาน — ใส่เฉพาะตอนไฟล์ระบุคอลัมน์นี้มาจริง ๆ ไฟล์รุ่นเก่า
        // ที่ยังไม่มีสามคอลัมน์นี้จะได้ไม่ไปเขียนทับธงเดิมของแถวที่มีอยู่แล้วให้เป็นค่าว่าง
        foreach (['is_customer', 'is_supplier', 'is_active'] as $flag) {
            if (isset($row[$flag]) && $row[$flag] !== '') {
                $save[$flag] = (int) $row[$flag];
            }
        }

        // หาของเดิมจากรหัสลูกค้า ถ้าไม่มีรหัสมาให้ดูชื่อบริษัท
        $found = null;
        if ($customerNo !== '') {
            $found = $db->first($table, ['customer_no', $customerNo]);
        }
        if ($found === null) {
            $found = $db->first($table, ['company', $company]);
        }

        if ($found) {
            $db->update($table, ['id', $found->id], $save);

            return 'update';
        }

        // รายการใหม่ที่ไฟล์ไม่ได้ระบุธงมา ต้องมีค่าเริ่มต้นเหมือนฟอร์มเพิ่มลูกค้า
        $save += ['is_customer' => 1, 'is_supplier' => 0, 'is_active' => 1];
        $save['id'] = $db->nextId($table);
        $save['customer_no'] = $customerNo === ''
            ? \Kotchasan\Number::printf(empty(self::$cfg->customer_no) ? 'CU%04d' : self::$cfg->customer_no, $save['id'])
            : $customerNo;
        $db->insert($table, $save);

        return 'new';
    }

    /**
     * แปลงข้อความในไฟล์เป็นตัวเลข
     *
     * ไฟล์ที่ผู้ใช้ทำจาก Excel มักมีลูกน้ำคั่นหลักพัน ถ้า cast ตรง ๆ "1,500" จะได้ 1
     *
     * @param mixed $value
     *
     * @return float
     */
    protected static function toNumber($value)
    {
        return (float) str_replace([',', ' '], '', (string) $value);
    }
}
