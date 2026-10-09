<?php
/**
 * install/testdb-module.php — ข้อมูลทดสอบของโมดูล inventory (e-OMS)
 *
 * install/cli-testdb.php เรียกไฟล์นี้ตอนสร้างชุด F2/F4/F5/F6 เพื่อให้ฐานทดสอบ
 * มีข้อมูล "ทุกเส้นทาง" ของโปรเจ็คนี้ ไม่ใช่แค่ตารางแกน — ถ้าไม่มีข้อมูลของ
 * โมดูล การนับแถวก่อน/หลังปรับรุ่นก็พิสูจน์อะไรกับตารางของโมดูลไม่ได้เลย
 *
 * ไฟล์นี้เป็นของโปรเจ็คนี้เท่านั้น (cli-check-core.php ไม่นับเป็นไฟล์แกน)
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
    $stamp = time();

    // สินค้า — count_stock 0 = ไม่นับสต๊อก, 1 = นับรวม, 2 = นับแยกรายชิ้น
    $products = [
        ['product_no' => 'P001', 'topic' => 'สินค้านับสต๊อก', 'count_stock' => 1, 'price' => 100, 'cost' => 60],
        ['product_no' => 'P002', 'topic' => 'สินค้านับแยกรายชิ้น', 'count_stock' => 2, 'price' => 2500, 'cost' => 1800],
        ['product_no' => 'S001', 'topic' => 'ค่าบริการ (ไม่นับสต๊อก)', 'count_stock' => 0, 'price' => 3500, 'cost' => 0]
    ];
    foreach ($products as $item) {
        $db->insert($prefix.'_inventory', [
            'product_no' => $item['product_no'],
            'topic' => $item['topic'],
            'description' => '',
            'last_update' => $stamp,
            'price' => $item['price'],
            'vat' => 0,
            'cost' => $item['cost'],
            'unit' => 'ชิ้น',
            'category_id' => 0,
            'count_stock' => $item['count_stock'],
            'stock' => 0,
            'inuse' => 1
        ]);
    }
    // หน่วยย่อยของสินค้าที่นับแยกรายชิ้น
    foreach (['P002-A', 'P002-B'] as $no) {
        $db->insert($prefix.'_inventory_items', [
            // สคีมากลางบังคับ sku ไม่ซ้ำ ต้องเขียนคู่กับ product_no เสมอ
            // (เว้นว่างไว้สองแถวขึ้นไปจะชนกันเองที่ UNIQUE KEY `sku`)
            'sku' => $no,
            'product_no' => $no,
            'inventory_id' => 2,
            'topic' => 'เครื่องหมายเลข '.substr($no, -1),
            'price' => 2500,
            'cut_stock' => 1,
            'unit' => 'เครื่อง',
            'instock' => 1,
            'last_update' => $stamp
        ]);
    }
    $db->insert($prefix.'_inventory_meta', ['inventory_id' => 1, 'name' => 'description', 'value' => 'รายละเอียดเพิ่มเติม']);

    // ลูกค้า — ห้ามใส่อีเมลจริง ฐานทดสอบต้องส่งอีเมลออกไม่ได้เด็ดขาด
    foreach ([['CU0001', 'บริษัททดสอบ จำกัด'], ['CU0002', 'ร้านค้าทดสอบ']] as $index => $customer) {
        $db->insert($prefix.'_customer', [
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
            'customer_id' => $document[2],
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

    $notes[] = 'ใส่ข้อมูล inventory: สินค้า 3 หน่วยย่อย 2 ลูกค้า 2 เอกสาร 5 บรรทัดรายการ 5';
}

/**
 * จำลองสภาพฐานข้อมูลของไซต์ e-OMS "ก่อนรับโมดูล inventory กลาง"
 *
 * ⚠️ ชุด F1 (ติดตั้งใหม่) พิสูจน์ตัวปรับรุ่นไม่ได้เลย เพราะการติดตั้งใหม่ได้
 * ตารางครบจาก modules/inventory/install/database.sql อยู่แล้ว ความผิดพลาดของ
 * ตัวปรับรุ่นจะมองไม่เห็นจนกว่าจะมีคนเอาไปรันกับไซต์จริง (บทเรียนจาก oas
 * ซึ่งเจอข้อบกพร่องสามจุดตอนเป็นผลิตภัณฑ์ที่สองที่รับโมดูลไป)
 *
 * จึงต้องรื้อให้กลับไปเป็นสคีมาเดิมจริง ๆ :
 *   - ทิ้งตารางที่โมดูลกลางเพิ่มเข้ามา (order_items + สมุดบัญชีสต๊อก + ชั้นต้นทุน)
 *   - สร้างตารางเดิมใหม่จาก install/legacy/pre-module.sql (คัดจากของจริง)
 *   - ใส่ข้อมูลรูปแบบเดิม โดยบรรทัดเอกสารอยู่ในตาราง `stock` ไม่ใช่ `order_items`
 *
 * @param Db     $db
 * @param string $prefix
 * @param array  $notes
 */
function testdbPreModuleState($db, $prefix, array &$notes)
{
    // ตารางที่ "ยังไม่เคยมี" บนไซต์รุ่นก่อน
    $newTables = [
        'order_items', 'inventory_stock', 'inventory_stock_movement',
        'inventory_cost_layer', 'inventory_cost_allocation'
    ];
    foreach ($newTables as $table) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
    }

    // ตารางเดิมต้องเป็นสคีมาเดิม ไม่ใช่ของกลาง จึงสร้างใหม่จากไฟล์ที่คัดไว้
    $legacy = ['inventory', 'inventory_items', 'inventory_meta', 'inventory_template', 'customer', 'orders'];
    foreach ($legacy as $table) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
    }
    foreach (sqlCommands(ROOT_PATH.'install/legacy/pre-module.sql', $prefix) as $command) {
        $db->query($command);
    }

    // ข้อมูลรูปแบบเดิม — คีย์ของสินค้าคือ product_no ไม่ใช่ product_code
    $now = date('Y-m-d H:i:s');
    $stamp = time();
    // ⚠️ คอลัมน์ต้องตรงกับสคีมา "เดิม" เป๊ะ ๆ (ไม่มี product_code/is_active/created_at)
    // ถ้าใส่คอลัมน์ของสคีมากลางลงไป ชุดทดสอบจะไม่ได้จำลองไซต์เก่าจริง
    $db->insert($prefix.'_inventory', [
        'product_no' => 'OLD001', 'topic' => 'สินค้ารุ่นก่อน', 'description' => '',
        'unit' => 'ชิ้น', 'price' => 250, 'cost' => 150, 'category_id' => 0,
        'count_stock' => 1, 'stock' => 0, 'inuse' => 1, 'vat' => 1, 'last_update' => $stamp
    ]);
    $db->insert($prefix.'_inventory_items', [
        'product_no' => 'OLD001-A', 'inventory_id' => 1, 'topic' => 'หน่วยย่อยรุ่นก่อน',
        'unit' => 'ชิ้น', 'price' => 250, 'cut_stock' => 1, 'instock' => 1, 'last_update' => $stamp
    ]);
    $db->insert($prefix.'_inventory_meta', [
        'inventory_id' => 1, 'name' => 'detail', 'value' => 'รายละเอียดรุ่นก่อน'
    ]);
    $db->insert($prefix.'_customer', [
        'customer_no' => 'CU0001', 'company' => 'บริษัทรุ่นก่อน', 'name' => 'ผู้ติดต่อ',
        'email' => '', 'phone' => '021234567', 'country' => 'TH'
    ]);
    // เอกสารหนึ่งใบพร้อมบรรทัดรายการที่อยู่ในตาราง `stock` แบบระบบเดิม
    $db->insert($prefix.'_orders', [
        'order_no' => 'OUT6809-0001', 'status' => 'OUT', 'customer_id' => 1,
        'order_date' => $now, 'member_id' => 1, 'total' => 250, 'vat' => 0,
        'discount' => 0, 'tax' => 0, 'paid' => 0
    ]);
    $db->insert($prefix.'_stock', [
        'order_id' => 1, 'member_id' => 1, 'inventory_id' => 1, 'product_no' => 'OLD001',
        'status' => 'OUT', 'create_date' => $now, 'topic' => 'สินค้ารุ่นก่อน',
        'quantity' => 1, 'price' => 250, 'vat' => 0, 'discount' => 0, 'total' => 250,
        'unit' => 'ชิ้น', 'cut_stock' => 1, 'used' => 0
    ]);

    $notes[] = 'จำลองไซต์ e-OMS ก่อนรับโมดูลกลาง : ตารางเดิม 6 ตาราง + `stock` '
        .'(บรรทัดเอกสารอยู่ใน stock) และยังไม่มี order_items/สมุดบัญชีสต๊อก/ชั้นต้นทุน';
}

/**
 * ข้อยืนยันหลังปรับรุ่น — สิ่งที่ตัวปรับรุ่น "ต้องเติม" ไม่ใช่แค่โครงสร้าง
 *
 * การเทียบสคีมาของ cli-testdb จับคอลัมน์/ดัชนีที่หายได้ แต่จับข้อมูลที่หายไม่ได้ —
 * ถอดบล็อก "เพิ่มแม่แบบ SO" ออกจากตัวปรับรุ่นแล้ว สคีมายังตรงทุกอย่าง ทั้งที่ร้านค้า
 * ออนไลน์จะรับคำสั่งซื้อไม่ได้เลยเพราะไม่มีชนิดเอกสารให้ใช้ (ยืนยันด้วยการถอดจริง)
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

    // แม่แบบใบสั่งขายที่จองของ ต้องมีทุกไซต์หลังปรับรุ่น
    $so = $db->first($prefix.'_inventory_template', ['document_type' => 'SO']);
    if ($so === false) {
        $problems[] = 'ไม่มีแม่แบบใบสั่งขาย (SO) — ร้านค้าออนไลน์รับคำสั่งซื้อไม่ได้';
    } elseif ((int) $so->reserve_stock !== 1 || (int) $so->cut_stock !== 0) {
        $problems[] = 'แม่แบบ SO ต้องจองของ (reserve_stock=1) และไม่ตัดของ (cut_stock=0)';
    }

    return $problems;
}
