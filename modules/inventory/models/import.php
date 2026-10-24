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
                'key' => 'sku',
                'required' => ['topic'],
                // บรรทัดละ variant แบบไฟล์สินค้าของร้านออนไลน์ทั่วไป — หลายบรรทัดที่
                // product_code เดียวกันคือสินค้าเดียวที่มีหลายแบบ (DESIGN.md ข้อ 2)
                // ⚠️ model กับ type เป็นหมวดหมู่ของระบบเดิม (รุ่น / ประเภทพัสดุ)
                // ถ้าไม่มีในไฟล์ ข้อมูลที่ส่งออกแล้วนำกลับเข้ามาจะเสียสองค่านี้ไป
                'columns' => ['product_code', 'topic', 'description', 'category', 'model', 'type', 'unit',
                    'count_stock', 'vat', 'option1_name', 'option1', 'option2_name', 'option2',
                    'option3_name', 'option3', 'sku', 'barcode', 'price', 'cost', 'stock', 'is_active'],
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
                ->select('P.product_code', 'P.description', 'P.category_id', 'P.model_id', 'P.type_id', 'P.unit',
                    'P.count_stock', 'P.vat', 'P.option1_name', 'V.option1', 'P.option2_name', 'V.option2',
                    'P.option3_name', 'V.option3', 'V.sku', 'V.price', 'V.cost', 'V.stock', 'V.is_active')
                ->selectRaw('P.`topic` AS `topic`')
                // บาร์โค้ดของหน่วยขายฐาน (ตัวคูณน้อยที่สุด) — หน่วยขายอื่นจัดการที่หน้าบาร์โค้ด
                ->selectRaw('(SELECT I.`barcode` FROM `'.Base::table('inventory_items').'` I WHERE I.`inventory_id` = V.`id`'
                    .' AND I.`serial_no` IS NULL ORDER BY I.`cut_stock`, I.`id` LIMIT 1) AS `barcode`')
                ->from('inventory V')
                ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
                ->orderBy('P.id')
                ->orderBy('V.sort_order')
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
                    (int) $row['count_stock'],
                    (int) $row['vat'],
                    (string) $row['option1_name'],
                    $row['option1'],
                    (string) $row['option2_name'],
                    $row['option2'],
                    (string) $row['option3_name'],
                    $row['option3'],
                    $row['sku'],
                    (string) $row['barcode'],
                    (float) $row['price'],
                    (float) $row['cost'],
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

        $text = function ($key) use ($row) {
            return trim((string) (isset($row[$key]) ? $row[$key] : ''));
        };
        $product = [
            'topic' => $topic,
            'description' => $text('description'),
            'category_id' => $categoryId,
            'model_id' => $categoryIds['model_id'],
            'type_id' => $categoryIds['type_id'],
            'unit' => $text('unit'),
            'count_stock' => $text('count_stock') === '' ? 1 : (int) $text('count_stock'),
            'vat' => (int) self::toNumber($text('vat')),
            'option1_name' => $text('option1_name'),
            'option2_name' => $text('option2_name'),
            'option3_name' => $text('option3_name')
        ];
        if ($productCode !== '') {
            $product['product_code'] = $productCode;
        }
        $variant = [
            'sku' => $text('sku') === '' ? $productCode : $text('sku'),
            'option1' => $text('option1'),
            'option2' => $text('option2'),
            'option3' => $text('option3'),
            'price' => self::toNumber($text('price')),
            'is_active' => $text('is_active') === '' ? 1 : (int) $text('is_active')
        ];

        return Base::transaction(function () use ($db, $row, $text, $product, $variant, $productCode) {
            // แบบเดิม (sku เดิม) = อัปเดต
            // ⚠️ ไม่แตะยอดคงเหลือของของที่มีอยู่แล้ว — การนำเข้าไฟล์ต้องไม่ใช่ช่องทางแก้สต๊อก
            // ไม่งั้นนำเข้าไฟล์เดิมซ้ำจะทำให้ยอดกระโดดทุกครั้ง
            $found = $variant['sku'] === '' ? null : $db->first(Base::table('inventory'), ['sku' => $variant['sku']]);
            if ($found) {
                \Inventory\Product\Model::saveProduct((int) $found->product_id, $product);
                \Inventory\Product\Model::saveVariant((int) $found->product_id, (int) $found->id, $variant);

                return 'update';
            }

            // แบบใหม่ของสินค้าที่มีอยู่แล้ว (product_code เดิม) หรือสินค้าใหม่ทั้งตัว
            $parent = $productCode === '' ? null : $db->first(Base::table('inventory_product'), ['product_code' => $productCode]);
            $data = $variant + ['barcode' => $text('barcode')];
            if ($parent) {
                \Inventory\Product\Model::saveProduct((int) $parent->id, $product);
                if ($data['sku'] === '') {
                    $data['sku'] = $productCode;
                }
                $id = \Inventory\Product\Model::saveVariant((int) $parent->id, 0, $data);
            } else {
                $id = \Inventory\Product\Model::addProduct($product + $data);
            }

            // ยอดตั้งต้นจากไฟล์เข้าสมุดบัญชีเป็นการเคลื่อนไหวชนิด opening
            // (สินค้านับรายชิ้นต้องลงหมายเลขเครื่องทีละเครื่อง ไม่รับยอดรวมจากไฟล์)
            $stock = self::toNumber($text('stock'));
            if ($stock != 0 && $product['count_stock'] === 1) {
                \Inventory\Product\Model::openingBalance($id, 0, $stock, self::toNumber($text('cost')));
            }

            return 'new';
        });
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
