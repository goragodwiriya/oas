<?php
/**
 * install/testdb-module.php — ข้อมูลทดสอบของโมดูล inventory (e-OMS)
 *
 * install/cli-testdb.php เรียกไฟล์นี้ตอนสร้างชุด F2/F4/F5/F6 เพื่อให้ฐานทดสอบ
 * มีข้อมูล "ทุกเส้นทาง" ของโปรเจ็คนี้ ไม่ใช่แค่ตารางแกน — ถ้าไม่มีข้อมูลของ
 * โมดูล การนับแถวก่อน/หลังปรับรุ่นก็พิสูจน์อะไรกับตารางของโมดูลไม่ได้เลย
 *
 * ไฟล์นี้เป็นของโปรเจ็คนี้เท่านั้น
 */
if (!defined('ROOT_PATH')) {
    exit;
}

/**
 * ใส่ข้อมูลตัวอย่างของโมดูล inventory ลงฐานทดสอบ
 *
 * ครอบทุกเส้นทางที่โมดูลใช้จริง: สินค้าที่นับสต๊อกและไม่นับสต๊อก, หน่วยย่อย,
 * ข้อมูลเพิ่มเติมของสินค้า, ลูกค้า, เอกสารฝั่งซื้อ (PO→IN) และฝั่งขาย (QUO→INV→OUT)
 * พร้อมบรรทัดรายการที่รับเข้าและตัดออกจากสต๊อก
 *
 * @param Db     $db
 * @param string $prefix
 * @param array  $notes รายการข้อความบรรยายสิ่งที่ทำ (เพิ่มต่อท้ายได้)
 * @param string $fixture ชื่อชุดทดสอบ ('f3' = จำลองไซต์ก่อนรับโมดูลกลาง)
 */
function testdbSeedModule($db, $prefix, array &$notes, $fixture = '')
{
    if ($fixture === 'f3') {
        testdbPreModuleState($db, $prefix, $notes);

        return;
    }

    $now = date('Y-m-d H:i:s');

    // สินค้า → variant (กองสต๊อก) → หน่วยขาย/ชิ้น (modules/inventory/DESIGN.md)
    // count_stock 0 = ไม่นับสต๊อก · 1 = นับจำนวน · 2 = นับรายชิ้น
    $products = [
        ['P001', 'สินค้านับสต๊อก', 1, 'ชิ้น', 100],
        ['P002', 'สินค้านับแยกรายชิ้น', 2, 'เครื่อง', 2500],
        ['S001', 'ค่าบริการ (ไม่นับสต๊อก)', 0, 'งาน', 3500]
    ];
    foreach ($products as $index => $item) {
        $productId = $db->insert($prefix.'_inventory_product', [
            'product_code' => $item[0],
            'topic' => $item[1],
            'count_stock' => $item[2],
            'unit' => $item[3],
            'is_active' => 1,
            'created_at' => $now
        ]);
        $variantId = $db->insert($prefix.'_inventory', [
            'product_id' => $productId,
            'sku' => $item[0],
            'topic' => $item[1],
            'price' => $item[4],
            'is_active' => 1,
            'created_at' => $now
        ]);
        if ($item[2] === 2) {
            // ชิ้น — serial ไม่ซ้ำ ไม่มีบาร์โค้ด
            foreach (['P002-A', 'P002-B'] as $serial) {
                $db->insert($prefix.'_inventory_items', [
                    'inventory_id' => $variantId, 'serial_no' => $serial, 'unit' => $item[3],
                    'cut_stock' => 1, 'created_at' => $now
                ]);
            }
        } elseif ($item[2] === 1) {
            // หน่วยขาย ×1 และ ×12
            $db->insert($prefix.'_inventory_items', [
                'inventory_id' => $variantId, 'barcode' => $item[0], 'unit' => $item[3],
                'cut_stock' => 1, 'created_at' => $now
            ]);
            $db->insert($prefix.'_inventory_items', [
                'inventory_id' => $variantId, 'barcode' => $item[0].'-12', 'unit' => 'โหล',
                'cut_stock' => 12, 'created_at' => $now
            ]);
        }
    }
    $db->insert($prefix.'_inventory_meta', ['product_id' => 1, 'name' => 'description', 'value' => 'รายละเอียดเพิ่มเติม']);

    // ลูกค้า — ห้ามใส่อีเมลจริง ฐานทดสอบต้องส่งอีเมลออกไม่ได้เด็ดขาด
    // ตัวติดตั้งมีคู่ค้าเงินสดอยู่ก่อนแล้ว — เอกสารอ้าง id ที่ได้จริง (ลำดับที่ 1, 2 ในรายการนี้)
    $customerIds = [];
    foreach ([['CU0001', 'บริษัททดสอบ จำกัด'], ['CU0002', 'ร้านค้าทดสอบ']] as $index => $customer) {
        $customerIds[$index + 1] = (int) $db->insert($prefix.'_customer', [
            'customer_no' => $customer[0],
            'company' => $customer[1],
            'branch' => 'สำนักงานใหญ่',
            'name' => 'ผู้ติดต่อ '.($index + 1),
            'idcard' => '',
            'tax_id' => '000000000000'.($index + 1),
            'phone' => '02000000'.$index,
            'fax' => '',
            'email' => '',
            'address' => 'ที่อยู่ทดสอบ',
            'provinceID' => 0,
            'province' => 'กรุงเทพมหานคร',
            'zipcode' => '10100',
            'country' => 'TH',
            'website' => '',
            'discount' => 0,
            'expense_due_date' => 0,
            'invoice_due_date' => 30
        ]);
    }

    // เอกสาร — ฝั่งซื้อ PO → IN (รับเข้าสต๊อก) และฝั่งขาย QUO → INV → OUT (ตัดสต๊อก)
    $documents = [
        ['PO', 'PO6901-0001', 1, 'buy'],
        ['IN', 'IN6901-0001', 1, 'buy'],
        ['QUO', 'QUO6901-0001', 2, 'sell'],
        ['INV', 'INV6901-0001', 2, 'sell'],
        ['OUT', 'OUT6901-0001', 2, 'sell']
    ];
    $chain = md5('testdb-chain');
    foreach ($documents as $order_id => $document) {
        $db->insert($prefix.'_orders', [
            'order_no' => $document[1],
            'customer_id' => $customerIds[$document[2]],
            'order_date' => $now,
            'member_id' => 1,
            'discount' => 0,
            'vat' => 0,
            'tax' => 0,
            'total' => 10000,
            'status' => $document[0],
            'paid' => 0,
            'discount_percent' => 0,
            'tax_status' => 0,
            'vat_status' => 1,
            'order' => $document[3] === 'sell' ? $chain : null,
            'comment' => '',
            'remark' => ''
        ]);
        // บรรทัดรายการ — เอกสารรับเข้าใส่ค่า cut_stock เป็นบวก เอกสารขายเป็นลบ
        $db->insert($prefix.'_stock', [
            'order_id' => $order_id + 1,
            'member_id' => 1,
            'inventory_id' => 1,
            'product_no' => 'P001',
            'status' => $document[0],
            'create_date' => $now,
            'topic' => 'สินค้านับสต๊อก',
            'quantity' => 10,
            'price' => 100,
            'vat' => 0,
            'discount' => 0,
            'total' => 1000,
            'unit' => 'ชิ้น',
            'cut_stock' => $document[0] === 'OUT' ? -10 : ($document[0] === 'IN' ? 10 : 0),
            'used' => 0
        ]);
    }

    // แม่แบบ SO / RTS ที่ตัวปรับรุ่นรุ่นก่อนเติมให้ — oas ไม่มีสองชนิดนี้แล้ว (มีเฉพาะ oms.in.th)
    // ตัวปรับรุ่นต้องปิดไว้ (testdbAfterUpgrade ตรวจ)
    foreach ([['SO', 'sell', 'ใบสั่งขาย', 0, 1, null], ['RTS', 'buy', 'ใบคืนสินค้าไปยังผู้ขาย', 1, 0, 'return_out']] as $_tpl) {
        $db->insert($prefix.'_inventory_template', [
            'document_type' => $_tpl[0], 'status' => $_tpl[0], 'mode' => $_tpl[1], 'topic' => $_tpl[2], 'comment' => '',
            'due_date' => 0, 'published' => 1, 'stock_in' => 0, 'stock_out' => $_tpl[3], 'reserve_stock' => $_tpl[4],
            'movement_type' => $_tpl[5], 'prefix' => $_tpl[0].'%Y%M-', 'number_format' => '%04d',
            'columns' => 'item,topic,quantity,price,discount,amount'
        ]);
    }

    $notes[] = 'ใส่ข้อมูล inventory: สินค้า 3 หน่วยขาย 2 ชิ้น 2 ลูกค้า 2 เอกสาร 5 บรรทัดรายการ 5 · แม่แบบ SO/RTS ของรุ่นก่อน';
}

/**
 * จำลองสภาพฐานข้อมูลของ oas ระบบเดิม (GCMS11 · ก่อนรับโมดูล inventory กลาง)
 *
 * ⚠️ ชุด F1 (ติดตั้งใหม่) พิสูจน์ตัวปรับรุ่นไม่ได้เลย เพราะการติดตั้งใหม่ได้
 * ตารางครบจาก modules/inventory/install/database.sql อยู่แล้ว ความผิดพลาดของ
 * ตัวปรับรุ่นจะมองไม่เห็นจนกว่าจะมีคนเอาไปรันกับไซต์จริง
 *
 * จึงต้องรื้อให้กลับไปเป็นสคีมาเดิมจริง ๆ :
 *   - ทิ้งตารางที่โมดูลกลางเพิ่มเข้ามา (ทะเบียนสี่ชั้น · คลัง · แม่แบบ · order_items · สมุดบัญชีสต๊อก · ชั้นต้นทุน)
 *   - สร้างตารางเดิมใหม่จาก install/legacy/pre-module.sql (คัดจาก install/database.sql ของ oas ระบบเดิม)
 *   - ใส่ข้อมูลรูปแบบเดิม : รหัส/ราคาอยู่ที่หน่วยย่อย · ยอดอยู่ที่ inventory.stock · บรรทัดเอกสารอยู่ใน `stock`
 *
 * @param Db     $db
 * @param string $prefix
 * @param array  $notes
 */
function testdbPreModuleState($db, $prefix, array &$notes)
{
    // ตารางที่ "ยังไม่เคยมี" บนไซต์รุ่นก่อน
    $newTables = [
        'inventory_product', 'inventory_warehouse', 'inventory_template', 'order_items', 'inventory_stock',
        'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation'
    ];
    foreach ($newTables as $table) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
    }

    // ตารางเดิมต้องเป็นสคีมาเดิม ไม่ใช่ของกลาง จึงสร้างใหม่จากไฟล์ที่คัดไว้
    $legacy = ['inventory', 'inventory_items', 'inventory_meta', 'customer', 'orders', 'stock'];
    foreach ($legacy as $table) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
    }
    foreach (sqlCommands(ROOT_PATH.'install/legacy/pre-module.sql', $prefix) as $command) {
        $db->query($command);
    }

    // ⚠️ คอลัมน์ต้องตรงกับสคีมา "เดิม" เป๊ะ ๆ (ไม่มี product_code/price/is_active ที่ตัวสินค้า)
    // ถ้าใส่คอลัมน์ของสคีมากลางลงไป ชุดทดสอบจะไม่ได้จำลองไซต์เก่าจริง
    $now = date('Y-m-d H:i:s');
    // 1 = นับจำนวน มีหน่วยขายฐาน + กล่อง ×10 · ยอด 4 (รับ 5 ขาย 1) · 2 = ค่าบริการ (ไม่นับสต๊อก)
    $db->insert($prefix.'_inventory', [
        'id' => 1, 'category_id' => '0', 'topic' => 'สินค้ารุ่นก่อน', 'cost' => 150, 'vat' => 0,
        'stock' => 4, 'count_stock' => 1, 'inuse' => 1, 'create_date' => '2021-02-13', 'unit' => '0'
    ]);
    $db->insert($prefix.'_inventory', [
        'id' => 2, 'category_id' => '0', 'topic' => 'ค่าบริการรุ่นก่อน', 'cost' => 0, 'vat' => 0,
        'stock' => 0, 'count_stock' => 0, 'inuse' => 1, 'create_date' => '2021-02-13', 'unit' => '0'
    ]);
    $db->insert($prefix.'_inventory_items', [
        'product_no' => 'OLD001', 'inventory_id' => 1, 'topic' => '', 'price' => 250, 'cut_stock' => 1,
        'unit' => 'ชิ้น', 'instock' => 1
    ]);
    $db->insert($prefix.'_inventory_items', [
        'product_no' => 'OLD001-BOX', 'inventory_id' => 1, 'topic' => 'กล่อง', 'price' => 2400, 'cut_stock' => 10,
        'unit' => 'กล่อง', 'instock' => 1
    ]);
    $db->insert($prefix.'_inventory_items', [
        'product_no' => 'SRV001', 'inventory_id' => 2, 'topic' => '', 'price' => 500, 'cut_stock' => 1,
        'unit' => 'ครั้ง', 'instock' => 0
    ]);
    $db->insert($prefix.'_inventory_meta', [
        'inventory_id' => 1, 'name' => 'detail', 'value' => 'รายละเอียดรุ่นก่อน'
    ]);
    $db->insert($prefix.'_customer', [
        'customer_no' => 'CU0001', 'company' => 'บริษัทรุ่นก่อน', 'name' => 'ผู้ติดต่อ',
        'email' => '', 'phone' => '021234567', 'provinceID' => 102, 'province' => 'กรุงเทพมหานคร', 'country' => 'TH'
    ]);
    // เอกสารหนึ่งใบพร้อมบรรทัดรายการที่อยู่ในตาราง `stock` แบบระบบเดิม · การตั้งยอดอยู่ใน stock แถว order_id = 0
    $db->insert($prefix.'_orders', [
        'order_no' => 'OUT6809-0001', 'status' => 'OUT', 'customer_id' => 1, 'order_date' => date('Y-m-d'),
        'member_id' => 1, 'total' => 250, 'vat' => 0, 'discount' => 0, 'tax' => 0, 'paid' => 0,
        'discount_percent' => 0, 'tax_status' => 0, 'vat_status' => 0
    ]);
    $db->insert($prefix.'_stock', [
        'order_id' => 0, 'member_id' => 1, 'inventory_id' => 1, 'product_no' => 'OLD001',
        'status' => 'IN', 'create_date' => '2021-02-13 00:00:00', 'topic' => 'สินค้ารุ่นก่อน',
        'quantity' => 5, 'price' => 150, 'vat' => 0, 'discount' => 0, 'total' => 750,
        'unit' => 'ชิ้น', 'cut_stock' => 1, 'used' => 0
    ]);
    $db->insert($prefix.'_stock', [
        'order_id' => 1, 'member_id' => 1, 'inventory_id' => 1, 'product_no' => 'OLD001',
        'status' => 'OUT', 'create_date' => $now, 'topic' => 'สินค้ารุ่นก่อน',
        'quantity' => 1, 'price' => 250, 'vat' => 0, 'discount' => 0, 'total' => 250,
        'unit' => 'ชิ้น', 'cut_stock' => 1, 'used' => 0
    ]);

    $notes[] = 'จำลอง oas ระบบเดิม (GCMS11) : ตารางเดิม 6 ตาราง (รหัส/ราคาที่หน่วยย่อย · ยอดที่ inventory.stock · '
        .'บรรทัดเอกสารใน stock) และยังไม่มีทะเบียนสี่ชั้น/คลัง/แม่แบบ/order_items/สมุดบัญชีสต๊อก/ชั้นต้นทุน';
}

/**
 * ข้อยืนยันของ F3 — ทะเบียนสินค้า ยอด และบรรทัดเอกสารของ oas ระบบเดิมต้องมาครบหลังปรับรุ่น
 *
 * @param Db     $db
 * @param string $prefix
 *
 * @return array ข้อความที่ผิด (ว่าง = ผ่าน)
 */
function testdbAfterLegacyUpgrade($db, $prefix)
{
    $problems = [];
    $product = $db->first($prefix.'_inventory_product', ['id' => 1]);
    if ($product === false || $product->product_code !== 'OLD001' || (int) $product->count_stock !== 1 || $product->unit !== 'ชิ้น') {
        $problems[] = 'สินค้า 1 ไม่ได้รหัส OLD001 / นับจำนวน / หน่วยชิ้น จากหน่วยย่อยเดิม';
    }
    $variant = $db->first($prefix.'_inventory', ['id' => 1]);
    if ($variant === false || $variant->sku !== 'OLD001' || abs((float) $variant->price - 250) > 0.001) {
        $problems[] = 'แบบของสินค้า 1 ไม่ได้ sku OLD001 ราคา 250';
    }
    $box = $db->first($prefix.'_inventory_items', ['barcode' => 'OLD001-BOX']);
    if ($box === false || (int) $box->inventory_id !== 1 || abs((float) $box->cut_stock - 10) > 0.001) {
        $problems[] = 'หน่วยขายกล่อง ×10 (OLD001-BOX) ไม่อยู่กับแบบของสินค้า 1';
    }
    $rows = $db->customQuery(
        "SELECT SUM(IF(`movement_direction` = 'in', `quantity`, -`quantity`)) AS `q`, MIN(`warehouse_id`) AS `w`
           FROM `{$prefix}_inventory_stock_movement` WHERE `inventory_id` = 1"
    );
    if (empty($rows) || abs((float) $rows[0]->q - 4) > 0.001 || (int) $rows[0]->w < 1) {
        $problems[] = 'ยอดยกมาของสินค้า 1 ในสมุดบัญชีไม่เท่ายอดเดิม 4 หรือไม่ได้ลงคลังตั้งต้น';
    }
    $rows = $db->customQuery("SELECT SUM(`remaining_qty`) AS `q`, MAX(`unit_cost`) AS `c` FROM `{$prefix}_inventory_cost_layer` WHERE `inventory_id` = 1");
    if (empty($rows) || abs((float) $rows[0]->q - 4) > 0.001 || abs((float) $rows[0]->c - 150) > 0.001) {
        $problems[] = 'ชั้นต้นทุนของยอดยกมาไม่ใช่ 4 หน่วย ทุน 150';
    }
    if ($db->first($prefix.'_inventory_stock_movement', ['inventory_id' => 2]) !== false) {
        $problems[] = 'สินค้าที่ไม่นับสต๊อกได้ยอดยกมา';
    }
    $line = $db->first($prefix.'_order_items', ['order_id' => 1]);
    if ($line === false || $line->code !== 'OLD001' || $line->sku !== 'OLD001' || abs((float) $line->base_qty - 1) > 0.001) {
        $problems[] = 'บรรทัดเอกสารเดิมไม่ได้ย้ายมา order_items (code/sku OLD001 · ฐาน 1)';
    }
    $order = $db->first($prefix.'_orders', ['id' => 1]);
    if ($order === false || $order->document_type !== 'OUT') {
        $problems[] = 'เอกสารเดิมไม่ได้ชนิด OUT จาก status';
    }
    if ($db->fieldExists($prefix.'_inventory', 'create_date') || $db->fieldExists($prefix.'_inventory_items', 'product_no')) {
        $problems[] = 'ยังเหลือคอลัมน์เดิมที่ย้ายค่าแล้ว (inventory.create_date / inventory_items.product_no)';
    }

    return $problems;
}

/**
 * ข้อยืนยันหลังปรับรุ่น — สิ่งที่ตัวปรับรุ่น "ต้องเติม" ไม่ใช่แค่โครงสร้าง
 *
 * การเทียบสคีมาของ cli-testdb จับคอลัมน์/ดัชนีที่หายได้ แต่จับข้อมูลที่หาย/ค้างไม่ได้ —
 * เลขที่เอกสารที่ไม่ได้ย้ายเข้าแม่แบบ หรือแม่แบบที่ oas ไม่มีแล้วยังค้างอยู่ สคีมายังตรงทุกอย่าง
 *
 * @param Db     $db
 * @param string $prefix
 * @param string $fixture
 *
 * @return array ข้อความที่ผิด (ว่าง = ผ่าน)
 */
function testdbAfterUpgrade($db, $prefix, $fixture)
{
    $problems = [];

    // -------------------------------------------------------------------------
    // เลขที่เอกสารต้องย้ายจาก config มาอยู่ในแถวแม่แบบครบทุกชนิด
    //
    // การเทียบสคีมาจับได้แค่ว่า "มีคอลัมน์" — ถอดบล็อกย้ายค่าออกจากตัวปรับรุ่นแล้ว
    // คอลัมน์ยังอยู่ครบ แต่ทุกแถวจะว่าง แล้วเอกสารทุกใบจะได้เลขที่ผิดรูปทันที
    // (ยืนยันด้วยการถอดจริง)
    // -------------------------------------------------------------------------
    if ($db->fieldExists($prefix.'_inventory_template', 'src')) {
        $problems[] = 'inventory_template ยังมีคอลัมน์ src ที่เลิกใช้แล้ว';
    }
    foreach (['prefix', 'number_format', 'columns'] as $_col) {
        if (!$db->fieldExists($prefix.'_inventory_template', $_col)) {
            $problems[] = 'inventory_template ไม่มีคอลัมน์ '.$_col;
        }
    }
    if (empty($problems)) {
        $_blank = $db->customQuery(
            "SELECT `document_type` FROM `{$prefix}_inventory_template`
             WHERE `prefix` = '' OR `number_format` = '' OR `columns` = '' LIMIT 5"
        );
        if (!empty($_blank)) {
            $problems[] = 'แม่แบบที่ยังไม่มีรูปแบบเลขที่/คอลัมน์ : '
                .implode(', ', array_map(function ($r) { return $r->document_type; }, $_blank))
                .' — เอกสารชนิดนี้จะได้เลขที่ผิดรูปทุกใบ';
        }
    }

    // oas ไม่มีใบสั่งขาย (SO) และใบคืนสินค้าไปยังผู้ขาย (RTS) — มีเฉพาะ oms.in.th
    // แม่แบบที่ตัวปรับรุ่นรุ่นก่อนเติมให้ต้องถูกปิด (ไม่ลบ — ด่านกันข้อมูลหาย) · F3 ต้องไม่ได้ RTS จากตัวแปลงทะเบียน
    foreach (['SO', 'RTS'] as $_type) {
        $_tpl = $db->first($prefix.'_inventory_template', ['document_type' => $_type]);
        if ($_tpl !== false && (int) $_tpl->published !== 0) {
            $problems[] = 'แม่แบบ '.$_type.' ยังเปิดอยู่ — oas ไม่มีเอกสารชนิดนี้ (มีเฉพาะ oms.in.th)';
        }
        if ($fixture === 'f3' && $_tpl !== false) {
            $problems[] = 'ตัวแปลงทะเบียนเติมแม่แบบ '.$_type.' ให้ oas';
        }
    }

    // F3 = oas ระบบเดิม — ทะเบียน ยอด และบรรทัดเอกสารต้องมาครบ
    if ($fixture === 'f3') {
        $problems = array_merge($problems, testdbAfterLegacyUpgrade($db, $prefix));
    }

    return $problems;
}
