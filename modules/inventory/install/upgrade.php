<?php
/**
 * modules/inventory/install/upgrade.php — ปรับรุ่นตารางของโมดูล inventory
 *
 * install/upgrade_core.php เรียกไฟล์นี้ให้เองสำหรับทุกโมดูลที่มี ตัวแปรที่ใช้ได้
 * คือชุดเดียวกับที่ upgrade_core ใช้ : $db, $db_config, $prefix, $content, $config
 *
 * ต้นทางที่รองรับ (ขั้น 6 · ตัวปรับรุ่นมีที่ oas เท่านั้น)
 *   oas ระบบเดิม (GCMS11) · oas 7.0.x (เอกสารใน order/order_item) — เตรียมด้วย legacy-oas.php
 *   ทะเบียนแบบแบน (product_code + หน่วยย่อย) — แปลงเป็นสี่ชั้น (product → variant → หน่วยขาย/ชิ้น ·
 *   modules/inventory/DESIGN.md) ด้วย legacy.php ที่ใช้ร่วมกับ oms.in.th
 *   ฐานเปล่า — ensureTable สร้างจาก database.sql
 * ตารางทะเบียนสินค้า/สต๊อกปรับให้เท่ากับติดตั้งใหม่ท้ายไฟล์ (align.php) · cache สต๊อกคำนวณใหม่ในแอป (ธง inventory_rebuild)
 *
 * กฎเดียวกับ upgrade_core : ทุกเงื่อนไขถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 */
if (!defined('ROOT_PATH')) {
    exit;
}

$_t_inventory = $prefix.'_inventory';
include_once ROOT_PATH.'modules/inventory/install/legacy.php';
include_once ROOT_PATH.'modules/inventory/install/legacy-oas.php';

// `stock` คือบรรทัดเอกสารของระบบรุ่นก่อน (oms) — สร้างไว้ให้สคีมาเท่ากับไซต์ติดตั้งใหม่
$_inv_tables = [
    'inventory_product', 'inventory', 'inventory_items', 'inventory_meta', 'inventory_warehouse',
    'inventory_stock', 'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation',
    'inventory_template', 'customer', 'orders', 'order_items', 'stock'
];
foreach ($_inv_tables as $_t) {
    if (ensureTable($db, $prefix, $prefix.'_'.$_t)) {
        $content[] = '<li class="correct">inventory: สร้างตาราง '.$_t.'</li>';
    }
}

$_t_items = $prefix.'_inventory_items';
$_t_orders = $prefix.'_orders';
$_t_order_items = $prefix.'_order_items';
$_t_customer = $prefix.'_customer';
$_t_template = $prefix.'_inventory_template';

// =============================================================================
// คลังตั้งต้น — ยอด/สมุดบัญชี/ชั้นต้นทุนทุกแถวต้องอยู่คลังใดคลังหนึ่งเสมอ
// ensureTable สร้างแต่ตาราง ไม่ได้ใส่ข้อมูล — เติมคลังหลักจาก database.sql เมื่อยังไม่มีคลังเลย
// =============================================================================
$_t_warehouse = $prefix.'_inventory_warehouse';
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_warehouse`");
if (!empty($_rows) && (int) $_rows[0]->c === 0) {
    foreach (sqlCommands(ROOT_PATH.'modules/inventory/install/database.sql', $prefix) as $_command) {
        if (preg_match('/^\s*INSERT INTO `'.preg_quote($_t_warehouse, '/').'`/i', $_command)) {
            $db->query($_command);
            $content[] = '<li class="correct">inventory_warehouse: เพิ่มคลังหลัก</li>';
        }
    }
}

// =============================================================================
// แถวที่ตัวแปลงรุ่นก่อนลงไว้ "คลัง 0" (ตอนยังไม่มีทะเบียนคลัง) → คลังตั้งต้น
// engine ไม่รู้จักคลัง 0 (Warehouse::resolve) ของกองนั้นจึงหายจากทุกหน้า และ rebuild() ได้ยอดแยกสองกอง
// คลังตั้งต้น = คลังที่ใช้งานอยู่ is_default ก่อน แล้ว sort_order, id (ลำดับเดียวกับ Warehouse::getDefault)
// ยอด (inventory_stock) เป็น cache — รวมยอดของคลัง 0 เข้าแถวของคลังตั้งต้น (ผลรวมเท่าเดิม) แล้วลบแถวคลัง 0
// =============================================================================
$_rows = $db->customQuery("SELECT `id` FROM `$_t_warehouse` WHERE `is_active` = 1 ORDER BY `is_default` DESC, `sort_order`, `id` LIMIT 1");
$_main = empty($_rows) ? 0 : (int) $_rows[0]->id;
// ตารางสมุดบัญชีรุ่นแรกของโมดูลกลางยังไม่มีคอลัมน์คลัง — เพิ่มเป็นคลัง 0 แล้วย้ายไปคลังตั้งต้นข้างล่าง
foreach (['inventory_stock', 'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation'] as $_t) {
    if (!$db->fieldExists($prefix.'_'.$_t, 'warehouse_id')) {
        $db->query("ALTER TABLE `{$prefix}_$_t` ADD `warehouse_id` int(11) NOT NULL DEFAULT 0 AFTER `inventory_item_id`");
        $content[] = '<li class="correct">'.$_t.': เพิ่มคอลัมน์ warehouse_id</li>';
    }
}
if ($_main > 0) {
    foreach (['inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation'] as $_t) {
        $_n = $db->customQuery("SELECT COUNT(*) AS `c` FROM `{$prefix}_$_t` WHERE `warehouse_id` = 0");
        if (!empty($_n) && (int) $_n[0]->c > 0) {
            $db->query("UPDATE `{$prefix}_$_t` SET `warehouse_id` = $_main WHERE `warehouse_id` = 0");
            $content[] = '<li class="correct">'.$_t.': ย้าย '.(int) $_n[0]->c.' แถวจากคลัง 0 ไปคลังตั้งต้น</li>';
        }
    }
    $_t_balance = $prefix.'_inventory_stock';
    $_n = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_balance` WHERE `warehouse_id` = 0");
    if (!empty($_n) && (int) $_n[0]->c > 0) {
        $db->query("UPDATE `$_t_balance` S1 INNER JOIN `$_t_balance` S0 ON S0.`inventory_id` = S1.`inventory_id`"
            ." AND S0.`inventory_item_id` = S1.`inventory_item_id` AND S0.`warehouse_id` = 0"
            ." SET S1.`qty` = S1.`qty` + S0.`qty`, S1.`reserved_qty` = S1.`reserved_qty` + S0.`reserved_qty`"
            ." WHERE S1.`warehouse_id` = $_main");
        $db->query("DELETE S0 FROM `$_t_balance` S0 INNER JOIN `$_t_balance` S1 ON S1.`inventory_id` = S0.`inventory_id`"
            ." AND S1.`inventory_item_id` = S0.`inventory_item_id` AND S1.`warehouse_id` = $_main"
            ." WHERE S0.`warehouse_id` = 0");
        $db->query("UPDATE `$_t_balance` SET `warehouse_id` = $_main WHERE `warehouse_id` = 0");
        $content[] = '<li class="correct">inventory_stock: ย้ายยอด '.(int) $_n[0]->c.' แถวจากคลัง 0 ไปคลังตั้งต้น</li>';
    }
}

// =============================================================================
// ทะเบียนสินค้ารุ่นก่อน → สี่ชั้น
//   oas ระบบเดิม : เตรียมเป็นทะเบียนแบบแบนก่อน (legacy-oas.php — รหัส/ราคาจากหน่วยย่อย · คีย์ของหน่วยย่อย · ยอดยกมา)
//   แบบแบน (inventory.product_code + inventory_items.sku) : แปลงด้วย legacy.php ที่ใช้ร่วมกับ oms.in.th
// =============================================================================
if (inventoryLegacyDetect($db, $prefix)) {
    inventoryPrepareFlat($db, $prefix, $content, $_main);
    inventoryConvertLegacy($db, $prefix, $content);
}
if ($db->tableExists($_t_inventory) && !$db->fieldExists($_t_inventory, 'product_id')) {
    throw new \Exception(
        'ทะเบียนสินค้าของฐานนี้ยังเป็นโครงเดิม (ตาราง <code>'.$_t_inventory.'</code> ไม่มี product_id)<br>'
        .'ตัวปรับรุ่นแปลงทะเบียนสินค้าเดิมไม่สำเร็จ จึงหยุดไว้ก่อน'
    );
}

// =============================================================================
// inventory_template — แม่แบบเอกสาร
// =============================================================================
if (convertToInnoDB($db, $_t_template)) {
    $content[] = '<li class="correct">inventory_template: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_template)) {
    $content[] = '<li class="correct">inventory_template: แปลงเป็น utf8mb4</li>';
}
// ธงทิศทางสต๊อกเปลี่ยนชื่อ (DESIGN.md ข้อ 3.6) — `cut_stock` เคยมีสามความหมายในโมดูลเดียว
foreach (['in_stock' => 'stock_in', 'cut_stock' => 'stock_out'] as $_old => $_new) {
    if ($db->fieldExists($_t_template, $_old) && !$db->fieldExists($_t_template, $_new)) {
        $db->query("ALTER TABLE `$_t_template` CHANGE `$_old` `$_new` tinyint(1) NOT NULL DEFAULT 0");
        $content[] = '<li class="correct">inventory_template: เปลี่ยนชื่อ '.$_old.' เป็น '.$_new.'</li>';
    }
}
foreach ([
    'document_type' => ['varchar(10)', true, null, 'id'],
    'status' => ['varchar(3)', true, null, 'document_type'],
    'mode' => ["enum('sell','buy','borrow','repair')", false, null, 'status'],
    'topic' => ['varchar(150)', true, null, 'mode'],
    'comment' => ['text', false, null, 'topic'],
    'due_date' => ['tinyint(1)', false, 0, 'comment'],
    'signature_1' => ['varchar(150)', true, null, 'due_date'],
    'signature_2' => ['varchar(150)', true, null, 'signature_1'],
    'signature_3' => ['varchar(150)', true, null, 'signature_2'],
    'published' => ['tinyint(1)', false, 1, 'signature_3'],
    'stock_in' => ['tinyint(1)', false, 0, 'published'],
    'stock_out' => ['tinyint(1)', false, 0, 'stock_in'],
    'reserve_stock' => ['tinyint(1)', false, 0, 'stock_out'],
    'movement_type' => ['varchar(20)', true, null, 'reserve_stock'],
    // เลขที่เอกสารเป็นค่าของเอกสาร "ชนิดหนึ่ง" จึงย้ายมาอยู่แถวเดียวกับชนิดนั้น
    'prefix' => ['varchar(20)', false, '', 'movement_type'],
    'number_format' => ['varchar(20)', false, '', 'prefix'],
    'columns' => ['varchar(255)', false, '', 'number_format']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_template, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">inventory_template: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if ($db->fieldExists($_t_template, 'status')) {
    $db->query("UPDATE `$_t_template` SET `document_type` = `status` WHERE (`document_type` IS NULL OR `document_type` = '') AND `status` IS NOT NULL");
}
if (ensureIndexes($db, $_t_template, ['status' => '`status`', 'mode' => '`mode`, `published`'])) {
    $content[] = '<li class="correct">inventory_template: ปรับดัชนี</li>';
}
if (!$db->indexExists($_t_template, 'document_type')) {
    $db->query("UPDATE `$_t_template` SET `document_type` = NULL WHERE `document_type` = ''");
    $_dup = $db->customQuery(
        "SELECT `document_type` AS `v`, COUNT(*) AS `c` FROM `$_t_template`
         WHERE `document_type` IS NOT NULL GROUP BY `document_type` HAVING `c` > 1 LIMIT 10"
    );
    if (empty($_dup)) {
        $db->query("ALTER TABLE `$_t_template` ADD UNIQUE `document_type` (`document_type`)");
        $content[] = '<li class="correct">inventory_template: เพิ่ม UNIQUE document_type</li>';
    } else {
        // แม่แบบซ้ำชนิดกันไม่ใช่เรื่องที่ต้องหยุดการปรับรุ่น — ระบบยังทำงานได้
        // แค่เลือกแม่แบบตัวแรกที่เจอ แจ้งให้เจ้าของไซต์ไปเก็บกวาดเองภายหลัง
        $content[] = '<li class="warning">inventory_template: มีแม่แบบที่ชนิดเอกสารซ้ำกัน ยังไม่บังคับให้ไม่ซ้ำ '
            .'กรุณาลบแม่แบบที่ซ้ำออกให้เหลือชนิดละหนึ่ง แล้วปรับรุ่นอีกครั้ง</li>';
    }
}
// ชนิดการเคลื่อนไหวที่เอกสารแต่ละชนิดสร้าง (ทะเบียนกลาง หัวข้อ 4.6 ของแผน)
foreach (['OUT' => 'sale', 'IN' => 'purchase', 'RET' => 'return_in', 'BOR' => 'borrow', 'RTN' => 'borrow_return'] as $_type => $_movement) {
    $db->query("UPDATE `$_t_template` SET `movement_type` = '$_movement' WHERE `document_type` = '$_type' AND (`movement_type` IS NULL OR `movement_type` = '')");
}

// -----------------------------------------------------------------------------
// เลขที่เอกสาร : ย้ายจากคีย์แบนใน config.php มาไว้ในแถวของชนิดเอกสาร
//
// เดิมเก็บเป็น `PO_prefix` / `PO_NO` ใน settings/config.php ทั้งที่เป็นค่าของ
// เอกสารชนิดเดียว ไม่ใช่ของทั้งระบบ — ชนิดเอกสารเป็นข้อมูลในตารางนี้อยู่แล้ว
// (ไซต์เพิ่มชนิดของตัวเองได้) ค่าของมันจึงต้องอยู่แถวเดียวกัน ไม่งั้นชนิดที่ไซต์
// เพิ่มเองจะตั้งเลขที่ไม่ได้เลยเพราะไม่มีใครไปเขียนคีย์ให้ในไฟล์ค่ากำหนด
//
// ย้ายเฉพาะแถวที่ยังว่าง — ตัวปรับรุ่นรันซ้ำได้ และห้ามเขียนทับค่าที่ผู้ใช้
// ตั้งใหม่จากหน้าแม่แบบไปแล้ว
// -----------------------------------------------------------------------------
// ⚠️ ทำเฉพาะฐานที่ยังมี `src` = ยังไม่เคยย้าย · รันซ้ำหลังย้ายแล้วต้องไม่แตะอะไร
// ไม่งั้นแม่แบบที่ผู้ใช้ตั้งใจเว้น prefix ว่างไว้จะถูกเขียนทับด้วยค่าเริ่มต้น
$_num_moved = 0;
foreach ($db->fieldExists($_t_template, 'src')
    ? $db->customQuery("SELECT `id`, `document_type`, `prefix`, `number_format` FROM `$_t_template`", true)
    : [] as $_tpl) {
    $_type = (string) $_tpl['document_type'];
    if ($_type === '') {
        continue;
    }
    $_save = [];
    if ((string) $_tpl['prefix'] === '') {
        $_save['prefix'] = isset($config[$_type.'_prefix']) && $config[$_type.'_prefix'] !== ''
            ? $config[$_type.'_prefix']
            : $_type.'%Y%M-';
    }
    if ((string) $_tpl['number_format'] === '' || $_tpl['number_format'] === null) {
        $_save['number_format'] = isset($config[$_type.'_NO']) && $config[$_type.'_NO'] !== ''
            ? $config[$_type.'_NO']
            : '%04d';
    }
    if (!empty($_save)) {
        // Db ของตัวติดตั้งรับ where แบบ associative (['id' => 5]) ไม่ใช่ ['id', 5] ของ runtime
        $db->update($_t_template, ['id' => (int) $_tpl['id']], $_save);
        ++$_num_moved;
    }
}
if ($_num_moved > 0) {
    $content[] = '<li class="correct">inventory_template: ย้ายรูปแบบเลขที่เอกสารจาก config มาไว้ในแม่แบบ '.$_num_moved.' ชนิด</li>';
}
// คีย์เดิมใน config ไม่มีใครอ่านแล้ว เก็บไว้ก็มีแต่จะทำให้เข้าใจผิดว่าแก้ที่นั่นได้
// (upgrade2.php บันทึก $config ให้หลังจากไฟล์นี้ทำงานจบ)
$_num_dropped = 0;
foreach (array_keys($config) as $_key) {
    if (preg_match('/^[A-Z0-9]{1,10}_(prefix|NO)$/', $_key)) {
        unset($config[$_key]);
        ++$_num_dropped;
    }
}
if ($_num_dropped > 0) {
    $content[] = '<li class="correct">inventory: เก็บกวาดคีย์เลขที่เอกสารที่ย้ายไปฐานข้อมูลแล้ว '.$_num_dropped.' คีย์</li>';
}

// -----------------------------------------------------------------------------
// คอลัมน์ของตารางรายการ : ย้ายจากไฟล์ modules/inventory/template/<ชนิด>.html
//
// ไฟล์เหล่านั้นถูกลบไปแล้ว — ทั้งห้าไฟล์มีรายชื่อคอลัมน์ชุดเดียวกันทุกไบต์ และ
// ตรงกับชุดมาตรฐานที่โค้ดใช้เป็นค่าสำรองอยู่แล้ว การเลือก `src` จึงไม่เคยเปลี่ยน
// อะไรเลย ที่นี่เขียนชุดมาตรฐานลงทุกแถวเพื่อให้ค่าที่ "เห็น" กับค่าที่ "ใช้" ตรงกัน
// แล้วแก้รายคอลัมน์ต่อได้จากหน้าแม่แบบ
// -----------------------------------------------------------------------------
$_cols_default = 'item,topic,quantity,price,discount,amount';
$_cols_set = $db->query("UPDATE `$_t_template` SET `columns` = '$_cols_default' WHERE `columns` = '' OR `columns` IS NULL");
if ($_cols_set > 0) {
    $content[] = '<li class="correct">inventory_template: ตั้งคอลัมน์ของตารางรายการ '.$_cols_set.' ชนิด</li>';
}
// `src` ชี้ไปไฟล์แม่แบบที่ถูกลบทิ้งไปแล้ว ทุกค่าที่ค้างอยู่จึงชี้ไปที่ไม่มีอยู่จริง
// ไม่ใช่ข้อมูลธุรกิจและกู้กลับมาก็ไม่มีความหมาย จึงตัดออกให้ขาดในรอบเดียว
if ($db->fieldExists($_t_template, 'src')) {
    $db->query("ALTER TABLE `$_t_template` DROP `src`");
    $content[] = '<li class="correct">inventory_template: ตัดคอลัมน์ src ที่เลิกใช้แล้ว</li>';
}

// =============================================================================
// customer
// =============================================================================
if (convertToInnoDB($db, $_t_customer)) {
    $content[] = '<li class="correct">customer: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_customer)) {
    $content[] = '<li class="correct">customer: แปลงเป็น utf8mb4</li>';
}
// รายการนี้ต้องเป็น "ทุกคอลัมน์ของสคีมากลาง" ไม่ใช่เฉพาะที่คิดว่าขาด
// เพราะแต่ละผลิตภัณฑ์ขาดคนละชุด (oms ไม่มีของ oas · oas ไม่มี customer_no ของ oms)
// ถ้าลิสต์ไม่ครบ ตัวปรับรุ่นจะไปล้มตอน UPDATE คอลัมน์ที่ยังไม่มี
foreach ([
    'customer_no' => ['varchar(20)', true, null, 'id'],
    'code' => ['varchar(50)', true, null, 'customer_no'],
    'type' => ['varchar(20)', true, null, 'code'],
    'is_customer' => ['tinyint(1)', false, 1, 'type'],
    'is_supplier' => ['tinyint(1)', false, 0, 'is_customer'],
    'is_active' => ['tinyint(1)', false, 1, 'is_supplier'],
    'company' => ['varchar(64)', false, '', 'is_active'],
    'branch' => ['varchar(50)', false, '', 'company'],
    'name' => ['varchar(50)', false, '', 'branch'],
    'contact' => ['varchar(150)', true, null, 'name'],
    'idcard' => ['varchar(13)', false, '', 'contact'],
    'tax_id' => ['varchar(13)', false, '', 'idcard'],
    'phone' => ['varchar(20)', false, '', 'tax_id'],
    'fax' => ['varchar(20)', false, '', 'phone'],
    'email' => ['varchar(255)', false, '', 'fax'],
    'line_id' => ['varchar(50)', true, null, 'email'],
    'line_name' => ['varchar(100)', true, null, 'line_id'],
    'address' => ['varchar(255)', false, '', 'line_name'],
    'provinceID' => ['smallint(3) unsigned', false, 0, 'address'],
    'province' => ['varchar(64)', false, '', 'provinceID'],
    'zipcode' => ['varchar(5)', false, '', 'province'],
    'country' => ['varchar(2)', false, 'TH', 'zipcode'],
    'website' => ['varchar(150)', false, '', 'country'],
    'bank' => ['varchar(100)', true, null, 'website'],
    'bank_name' => ['varchar(100)', true, null, 'bank'],
    'bank_no' => ['varchar(20)', true, null, 'bank_name'],
    'bank_branch' => ['varchar(100)', true, null, 'bank_no'],
    'price_group' => ['varchar(20)', true, null, 'bank_branch'],
    'payment_type' => ['varchar(20)', true, null, 'price_group'],
    'payment_terms' => ['int(11)', true, null, 'payment_type'],
    'discount' => ['decimal(10,2)', false, '0.00', 'payment_terms'],
    'expense_due_date' => ['int(11)', false, 0, 'discount'],
    'invoice_due_date' => ['int(11)', false, 0, 'expense_due_date'],
    'note' => ['text', true, null, 'invoice_due_date'],
    'created_at' => ['datetime', true, null, 'note'],
    'updated_at' => ['datetime', true, null, 'created_at']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_customer, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">customer: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $_t_customer, [
    'code' => '`code`',
    'company' => '`company`',
    'name' => '`name`',
    'tax_id' => '`tax_id`',
    'phone' => '`phone`',
    'email' => '`email`',
    'idx_partner_roles' => '`is_active`, `is_customer`, `is_supplier`'
])) {
    $content[] = '<li class="correct">customer: ปรับดัชนี</li>';
}
// ชื่อเดิมของแต่ละผลิตภัณฑ์ที่ถูกแทนด้วยชื่อกลาง — คัดลอกค่าเฉพาะแถวที่ยังว่าง
if ($db->fieldExists($_t_customer, 'province_id')) {
    $db->query("UPDATE `$_t_customer` SET `provinceID` = `province_id` WHERE `provinceID` = 0 AND `province_id` > 0");
}
if ($db->fieldExists($_t_customer, 'bank_account')) {
    $db->query("UPDATE `$_t_customer` SET `bank_no` = `bank_account` WHERE (`bank_no` IS NULL OR `bank_no` = '') AND `bank_account` IS NOT NULL AND `bank_account` != ''");
}
$db->query("UPDATE `$_t_customer` SET `code` = `customer_no` WHERE (`code` IS NULL OR `code` = '') AND `customer_no` IS NOT NULL AND `customer_no` != ''");
$db->query("UPDATE `$_t_customer` SET `customer_no` = `code` WHERE (`customer_no` IS NULL OR `customer_no` = '') AND `code` IS NOT NULL AND `code` != ''");

// รหัสลูกค้าที่ห้ามซ้ำคือ `customer_no` (ชื่อกลาง) ส่วน `code` ของ oas เป็นแค่ดัชนีธรรมดา
//
// ⚠️ ensureIndexes() เทียบเฉพาะ "คอลัมน์ในดัชนี" ไม่ได้เทียบว่าเป็น UNIQUE หรือไม่
// ดัชนี `code` ของ oas ที่เป็น UNIQUE อยู่จึงรอดสายตามันไปตลอด ต้องจัดเอง
if (dropMismatchedIndex($db, $_t_customer, 'code', '`code`', false)) {
    $db->query("ALTER TABLE `$_t_customer` ADD INDEX `code` (`code`)");
    $content[] = '<li class="correct">customer: ดัชนี code ไม่ใช่ UNIQUE อีกต่อไป (ใช้ customer_no แทน)</li>';
}
// ⚠️ ของ oas มี `UNIQUE KEY customer_no (code)` — ชื่อตรงแต่ครอบคนละคอลัมน์
// ถ้าเช็คแค่ "มีดัชนีชื่อนี้ไหม" จะข้ามไปเลย แล้วรหัสลูกค้าจะไม่เคยถูกบังคับให้ห้ามซ้ำ
if (dropMismatchedIndex($db, $_t_customer, 'customer_no', '`customer_no`', true)) {
    $content[] = '<li class="correct">customer: ลบดัชนี customer_no ที่ครอบผิดคอลัมน์</li>';
}
if (!$db->indexExists($_t_customer, 'customer_no')) {
    // ตรวจก่อนแตะ — ไซต์ที่มีรหัสลูกค้าซ้ำต้องถูกปฏิเสธพร้อมบอกว่าต้องแก้อะไร
    // ไม่ใช่ปล่อยให้ ALTER ล้มด้วยข้อความของฐานข้อมูลที่ผู้ดูแลอ่านไม่ออก
    $_dup = $db->customQuery(
        "SELECT `customer_no`, COUNT(*) AS `c` FROM `$_t_customer`
          WHERE `customer_no` IS NOT NULL AND `customer_no` != ''
          GROUP BY `customer_no` HAVING `c` > 1 LIMIT 10"
    );
    if (!empty($_dup)) {
        $_list = [];
        foreach ($_dup as $_row) {
            $_list[] = $_row->customer_no.' ('.(int) $_row->c.' ราย)';
        }
        throw new \Exception(
            'รหัสลูกค้าซ้ำกันอยู่ ซึ่งกำลังจะถูกบังคับให้ห้ามซ้ำ<br>'
            .implode(', ', $_list).'<br>'
            .'กรุณาแก้รหัสลูกค้าในตาราง <code>'.$_t_customer.'</code> ให้ไม่ซ้ำกัน แล้วปรับรุ่นใหม่'
        );
    }
    $db->query("ALTER TABLE `$_t_customer` ADD UNIQUE `customer_no` (`customer_no`)");
    $content[] = '<li class="correct">customer: เพิ่ม UNIQUE customer_no</li>';
}

// =============================================================================
// orders — หัวเอกสาร
// =============================================================================
if (convertToInnoDB($db, $_t_orders)) {
    $content[] = '<li class="correct">orders: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_orders)) {
    $content[] = '<li class="correct">orders: แปลงเป็น utf8mb4</li>';
}
foreach ([
    // CONVERT TO CHARACTER SET เลื่อนชนิด TEXT เป็น MEDIUMTEXT ต้องดึงกลับ
    // ไม่งั้นเครื่องที่ปรับรุ่นมาได้ชนิดคอลัมน์ไม่ตรงกับเครื่องที่ติดตั้งใหม่
    'comment' => ['text', true, null, 'cancelled_at'],
    'remark' => ['text', true, null, 'comment'],
    // คอลัมน์เงินของระบบเดิมไม่มี DEFAULT — แถวที่เพิ่มโดยไม่ระบุค่าจะล้ม
    'discount_percent' => ['decimal(10,2)', false, '0.00', 'discount'],
    'vat' => ['decimal(14,4)', false, '0.0000', 'discount_percent'],
    'vat_status' => ['tinyint(1) unsigned', false, 0, 'vat'],
    'tax' => ['decimal(14,4)', false, '0.0000', 'vat_status'],
    'tax_status' => ['decimal(10,2)', false, '0.00', 'tax'],
    'total' => ['decimal(14,4)', false, '0.0000', 'shipping_cost'],
    'paid' => ['decimal(14,4)', false, '0.0000', 'total'],
    'order_no' => ['varchar(50)', false, null, 'id'],
    // oas เดิม : status ห้ามว่าง · discount เป็น double · order_date เป็น date
    // (date → datetime ไม่เสียค่า วันเดิมได้เวลา 00:00:00)
    'status' => ['varchar(3)', true, null, 'document_type'],
    'discount' => ['decimal(14,4)', true, null, 'subtotal'],
    'order_date' => ['datetime', false, null, 'currency'],
    // ระบบเดิมบังคับให้มีลูกค้าและผู้บันทึกเสมอ แต่เอกสารขายสด/นำเข้าจากระบบอื่น
    // อาจไม่มีทั้งคู่ — ยอมให้ว่างได้ ตรงกับสคีมากลาง
    'customer_id' => ['int(11) unsigned', true, null, 'customer_zipcode'],
    'member_id' => ['int(11) unsigned', true, null, 'customer_id'],
    // คลังของเอกสาร (ว่าง = คลังตั้งต้น · Warehouse::resolve) — oas ระบบเดิมไม่มี
    'warehouse_id' => ['int(11)', true, null, 'member_id'],
    'document_type' => ['varchar(10)', false, '', 'order_no'],
    'document_status' => ["enum('draft','issued','cancelled')", false, 'issued', 'status'],
    'payment_status' => ['varchar(20)', false, '', 'document_status'],
    'source_document_id' => ['int(11)', true, null, 'payment_status'],
    'root_document_id' => ['int(11)', true, null, 'source_document_id'],
    'reference_document_no' => ['varchar(50)', true, null, 'root_document_id'],
    'public_key' => ['varchar(32)', true, null, 'reference_document_no'],
    'customer_name' => ['varchar(150)', true, null, 'customer_id'],
    'customer_company' => ['varchar(150)', true, null, 'customer_name'],
    'customer_contact' => ['varchar(150)', true, null, 'customer_company'],
    'customer_phone' => ['varchar(20)', true, null, 'customer_contact'],
    'customer_email' => ['varchar(255)', true, null, 'customer_phone'],
    'customer_tax_id' => ['varchar(13)', true, null, 'customer_email'],
    'customer_address' => ['text', true, null, 'customer_tax_id'],
    'customer_province' => ['varchar(64)', true, null, 'customer_address'],
    'customer_zipcode' => ['varchar(5)', true, null, 'customer_province'],
    'subtotal' => ['decimal(14,4)', false, '0.0000', 'member_id'],
    'shipping_cost' => ['decimal(14,4)', false, '0.0000', 'tax_status'],
    'change_amount' => ['decimal(14,4)', false, '0.0000', 'paid'],
    'currency' => ['varchar(3)', false, 'THB', 'change_amount'],
    'payment_ref' => ['varchar(100)', true, null, 'payment_method'],
    'completed_at' => ['datetime', true, null, 'payment_ref'],
    'cancelled_at' => ['datetime', true, null, 'completed_at'],
    'internal_note' => ['text', true, null, 'remark'],
    'created_at' => ['datetime', true, null, 'internal_note'],
    'updated_at' => ['datetime', true, null, 'created_at']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_orders, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">orders: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if ($db->fieldExists($_t_orders, 'status')) {
    $db->query("UPDATE `$_t_orders` SET `document_type` = `status` WHERE `document_type` = '' AND `status` IS NOT NULL");
}
if ($db->fieldExists($_t_orders, 'order')) {
    $db->query("UPDATE `$_t_orders` SET `public_key` = `order` WHERE `public_key` IS NULL AND `order` IS NOT NULL");
}
if (ensureAutoIncrement($db, $_t_orders, 'id', 'int(11) unsigned')) {
    $content[] = '<li class="correct">orders: ปรับชนิดคอลัมน์ id เป็น int unsigned</li>';
}
if (ensureIndexes($db, $_t_orders, [
    'order_no' => '`order_no`',
    'customer_id' => '`customer_id`',
    'member_id' => '`member_id`',
    'order' => '`order`',
    'idx_type_date' => '`document_type`, `order_date`',
    'status_date' => '`status`, `order_date`',
    'public_key' => '`public_key`',
    'source_document_id' => '`source_document_id`',
    'root_document_id' => '`root_document_id`',
    'warehouse_id' => '`warehouse_id`'
])) {
    $content[] = '<li class="correct">orders: ปรับดัชนี</li>';
}

// =============================================================================
// order_items — บรรทัดรายการของเอกสาร
//
// ระบบเดิมของ oms เก็บบรรทัดเอกสารไว้ในตาราง `stock` รวมกับการเดินสต๊อก
// ย้ายมาที่นี่เพื่อให้เอกสารเก่าพิมพ์ได้เหมือนเดิม โดย **ไม่ลบตาราง stock**
// ทำครั้งเดียวตอนที่ order_items ยังว่าง จึงรันซ้ำกี่รอบก็ไม่เกิดแถวซ้ำ
// =============================================================================
if (convertToInnoDB($db, $_t_order_items)) {
    $content[] = '<li class="correct">order_items: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_order_items)) {
    $content[] = '<li class="correct">order_items: แปลงเป็น utf8mb4</li>';
}
// คอลัมน์ตามแบบใหม่ (DESIGN.md ข้อ 3.6) — product_code/product_no → sku + code ·
// cut_stock (หน่วยฐานรวมของบรรทัด) → unit_factor + base_qty · เงินและจำนวนเป็น DECIMAL
foreach ([
    'inventory_id' => ['int(11) unsigned', false, 0, 'root_item_id'],
    'inventory_item_id' => ['int(11)', true, null, 'inventory_id'],
    'sku' => ['varchar(150)', false, '', 'inventory_item_id'],
    'code' => ['varchar(150)', false, '', 'sku'],
    'lot_no' => ['varchar(50)', true, null, 'code'],
    'expiry_date' => ['date', true, null, 'lot_no'],
    'topic' => ['varchar(255)', true, null, 'expiry_date'],
    'quantity' => ['decimal(16,4)', false, '1.0000', 'topic'],
    'unit' => ['varchar(50)', true, null, 'quantity'],
    'unit_factor' => ['decimal(16,4)', false, '1.0000', 'unit'],
    'base_qty' => ['decimal(16,4)', false, '0.0000', 'unit_factor'],
    'price' => ['decimal(14,4)', false, '0.0000', 'base_qty'],
    'cost_price' => ['decimal(14,4)', true, null, 'price'],
    'discount' => ['decimal(10,2)', false, '0.00', 'cost_price'],
    'vat' => ['decimal(10,2)', false, '0.00', 'discount'],
    'total' => ['decimal(14,4)', false, '0.0000', 'vat']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_order_items, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">order_items: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
// ค่าของคอลัมน์ชื่อเดิม — คัดลอกก่อนตัดทิ้ง (รันซ้ำได้ : รอบหลังไม่มีคอลัมน์เดิมแล้ว)
foreach (['product_code', 'product_no'] as $_old) {
    if ($db->fieldExists($_t_order_items, $_old)) {
        $db->query("UPDATE `$_t_order_items` SET `code` = `$_old` WHERE `code` = '' AND `$_old` IS NOT NULL");
    }
}
if ($db->fieldExists($_t_order_items, 'cut_stock')) {
    // cut_stock เดิม = หน่วยฐานรวมของบรรทัด (oms เก่าเก็บขาออกเป็นลบ) · 0 = เอกสารที่ไม่แตะสต๊อก
    $db->query("UPDATE `$_t_order_items` SET `unit_factor` = IF(`cut_stock` <> 0 AND `quantity` > 0, ABS(`cut_stock`) / `quantity`, 1)");
}
foreach (['product_code', 'product_no', 'cut_stock'] as $_old) {
    if ($db->fieldExists($_t_order_items, $_old)) {
        $db->query("ALTER TABLE `$_t_order_items` DROP `$_old`");
        $content[] = '<li class="correct">order_items: ตัดคอลัมน์ '.$_old.' (ย้ายค่าไปคอลัมน์ใหม่แล้ว)</li>';
    }
}
if (ensureIndexes($db, $_t_order_items, [
    'order_id' => '`order_id`',
    'source_item_id' => '`source_item_id`',
    'root_item_id' => '`root_item_id`',
    'inventory_id' => '`inventory_id`',
    'inventory_item_id' => '`inventory_item_id`',
    'sku' => '`sku`',
    'code' => '`code`'
])) {
    $content[] = '<li class="correct">order_items: ปรับดัชนี</li>';
}
// ตาราง stock มีอยู่เสมอ (ensureTable ข้างบน) และเก็บไว้ตามกฎ "ห้ามลบของเดิม"
// ยังต้องแปลงให้เป็น InnoDB + utf8mb4 เพราะข้อความไทยในนั้นเป็นข้อมูลจริง
$_t_stock = $prefix.'_stock';
if (convertToInnoDB($db, $_t_stock)) {
    $content[] = '<li class="correct">stock (ตารางเดิม): แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_stock)) {
    $content[] = '<li class="correct">stock (ตารางเดิม): แปลงเป็น utf8mb4</li>';
}
// ชนิดคอลัมน์ต้องตรงกับไซต์ที่ติดตั้งใหม่ — oas เดิมยอมให้ inventory_id/product_no/
// quantity/cut_stock ว่างได้ และเงินเป็น double (quantity เป็น float จึงต้องเป็น
// double ในสคีมากลาง ไม่ใช่ int ไม่งั้นจำนวนที่มีทศนิยมถูกตัดทิ้ง)
if (ensureAutoIncrement($db, $_t_stock, 'id', 'int(11) unsigned')) {
    $content[] = '<li class="correct">stock (ตารางเดิม): ปรับชนิดคอลัมน์ id เป็น int unsigned</li>';
}
foreach ([
    'order_id' => ['int(11)', false, null, 'id'],
    'member_id' => ['int(11) unsigned', false, null, 'order_id'],
    'inventory_id' => ['int(11) unsigned', false, null, 'member_id'],
    'product_no' => ['varchar(50)', false, null, 'inventory_id'],
    'status' => ['varchar(3)', true, null, 'product_no'],
    'create_date' => ['datetime', false, null, 'status'],
    'topic' => ['varchar(150)', true, null, 'create_date'],
    'quantity' => ['double', false, null, 'topic'],
    'price' => ['decimal(10,2)', false, null, 'quantity'],
    'vat' => ['decimal(10,2)', false, null, 'price'],
    'discount' => ['decimal(10,2)', false, null, 'vat'],
    'total' => ['decimal(10,2)', false, null, 'discount'],
    'unit' => ['varchar(50)', true, null, 'total'],
    'cut_stock' => ['double', false, 0, 'unit'],
    'used' => ['double', false, 0, 'cut_stock']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_stock, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">stock (ตารางเดิม): ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $_t_stock, [
    'order_id' => '`order_id`',
    'inventory_id' => '`inventory_id`, `status`',
    'product_no' => '`product_no`',
    'fifo' => '`inventory_id`, `status`, `create_date`'
])) {
    $content[] = '<li class="correct">stock (ตารางเดิม): ปรับดัชนี</li>';
}
// oas เดิมมีดัชนี owner_id ที่ครอบ order_id ซ้ำกับดัชนี order_id ข้างบน
if ($db->indexExists($_t_stock, 'owner_id') && indexColumns($db, $_t_stock, 'owner_id') === ['order_id']) {
    $db->query("ALTER TABLE `$_t_stock` DROP INDEX `owner_id`");
    $content[] = '<li class="correct">stock (ตารางเดิม): ลบดัชนีซ้ำซ้อน owner_id</li>';
}
// oas ระบบเดิม (GCMS11) มีดัชนี status เดี่ยว — ดัชนี inventory_id (inventory_id, status) ครอบงานเดียวกันแล้ว
if ($db->indexExists($_t_stock, 'status') && indexColumns($db, $_t_stock, 'status') === ['status']) {
    $db->query("ALTER TABLE `$_t_stock` DROP INDEX `status`");
    $content[] = '<li class="correct">stock (ตารางเดิม): ลบดัชนีซ้ำซ้อน status</li>';
}
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_order_items`");
$_have = empty($_rows) ? 0 : (int) $_rows[0]->c;
// แถวที่ order_id = 0 ไม่ใช่บรรทัดเอกสาร — ระบบเดิมลงไว้ตอนตั้งยอดหรือแก้ยอด
// จากหน้าสินค้า (Product\Model::create/update) ยอดของมันอยู่ใน inventory.stock
// ซึ่งกลายเป็นยอดยกมาของสมุดบัญชีแล้ว คัดลอกไปจะได้บรรทัดที่ไม่มีหัวเอกสาร
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_stock` WHERE `order_id` > 0");
$_legacy = empty($_rows) ? 0 : (int) $_rows[0]->c;
if ($_have === 0 && $_legacy > 0) {
    $db->query(
        // stock.cut_stock = หน่วยฐานรวมของบรรทัด (ขาออกเป็นลบ · 0 = เอกสารที่ไม่แตะสต๊อก)
        "INSERT INTO `$_t_order_items`
            (`order_id`, `inventory_id`, `code`, `topic`, `quantity`, `unit`, `unit_factor`,
             `price`, `discount`, `vat`, `total`, `member_id`, `create_date`)
         SELECT `order_id`, `inventory_id`, `product_no`, `topic`, `quantity`, `unit`,
                IF(`cut_stock` <> 0 AND `quantity` > 0, ABS(`cut_stock`) / `quantity`, 1),
                `price`, `discount`, `vat`, `total`, `member_id`, `create_date`
           FROM `$_t_stock`
          WHERE `order_id` > 0"
    );
    $content[] = '<li class="correct">order_items: คัดลอกบรรทัดเอกสารเดิมมา '.$_legacy.' แถว (ตาราง stock เดิมยังอยู่ครบ)</li>';
}
// ค่าที่เติมได้จากข้อมูลอื่น — เฉพาะแถวที่ยังว่าง
$db->query("UPDATE `$_t_order_items` SET `base_qty` = `quantity` * `unit_factor` WHERE `base_qty` = 0 AND `quantity` <> 0");
$db->query("UPDATE `$_t_order_items` T INNER JOIN `$_t_inventory` V ON V.`id` = T.`inventory_id` SET T.`sku` = V.`sku` WHERE T.`sku` = ''");
// เอกสารของ oas 7.0.x (order / order_item) → orders / order_items · เติมชนิดที่ว่างของใบที่คัดลอกมาแล้ว (legacy-oas.php)
inventoryConvertOrders($db, $prefix, $content);

// =============================================================================
// แม่แบบเอกสารตั้งต้น
//
// ⚠️ ensureTable สร้างแต่ตาราง ไม่ได้ใส่ข้อมูล ไซต์ที่ยังไม่เคยมีแม่แบบเลย
// (ผลิตภัณฑ์ที่เพิ่งรับโมดูลนี้ไป) จะสร้างเอกสารไม่ได้สักใบและเมนูจะว่างเปล่า
// เติมเฉพาะตอนที่ตารางยังไม่มีแถวเลย จึงไม่ทับแม่แบบที่ไซต์ตั้งเอง และรันซ้ำได้
// =============================================================================
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_template`");
if (!empty($_rows) && (int) $_rows[0]->c === 0) {
    // อ่านคำสั่ง INSERT ของแม่แบบจาก database.sql ไฟล์เดียวกับที่ตัวติดตั้งใช้
    // ⚠️ ห้ามใช้ runSqlFile() ทั้งไฟล์ เพราะจะรัน CREATE TABLE ซ้ำแล้วหยุดกลางคัน
    // และห้ามคัดลอกข้อมูลแม่แบบมาเขียนไว้ที่นี่อีกชุด เดี๋ยวสองที่ค่อย ๆ ต่างกัน
    $_seeded = 0;
    foreach (sqlCommands(ROOT_PATH.'modules/inventory/install/database.sql', $prefix) as $_command) {
        if (preg_match('/^\s*INSERT INTO `'.preg_quote($_t_template, '/').'`/i', $_command)) {
            $db->query($_command);
            ++$_seeded;
        }
    }
    if ($_seeded > 0) {
        $_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_template`");
        $content[] = '<li class="correct">inventory_template: เพิ่มแม่แบบเอกสารตั้งต้น '
            .(empty($_rows) ? 0 : (int) $_rows[0]->c).' ชนิด</li>';
    }
}


// -----------------------------------------------------------------------------
// ช่องทางที่เอกสารเกิด (2026-09-12)
// -----------------------------------------------------------------------------
$_t_orders = $prefix.'_orders';
if ($db->tableExists($_t_orders)) {
    // ช่องทางที่เอกสารเกิด — หน้าร้าน/เว็บ/สำนักงาน (ใบเก่าไม่รู้ ปล่อยว่าง)
    if (ensureColumn($db, $_t_orders, 'sales_channel', 'varchar(20)', true, null, '', 'member_id')) {
        $content[] = '<li class="correct">inventory: เพิ่มคอลัมน์ orders.sales_channel</li>';
    }
    foreach (ensureIndexes($db, $_t_orders, ['sales_channel' => 'sales_channel']) as $_idx) {
        $content[] = '<li class="correct">inventory: เพิ่มดัชนี orders.'.$_idx.'</li>';
    }
}

// -----------------------------------------------------------------------------
// คอลัมน์ reserve_stock ของแม่แบบ — สคีมาของ oas เป็นสับเซตของ oms.in.th (DESIGN.md ข้อ 7)
// oas ไม่มีแม่แบบที่จองของ คอลัมน์จึงเป็น 0 ทุกแถว
// -----------------------------------------------------------------------------
$_t_template = $prefix.'_inventory_template';
if ($db->tableExists($_t_template)) {
    if (ensureColumn($db, $_t_template, 'reserve_stock', 'tinyint(1)', false, 0, '', 'stock_out')) {
        $content[] = '<li class="correct">inventory: เพิ่มคอลัมน์ inventory_template.reserve_stock</li>';
    }
}

// -----------------------------------------------------------------------------
// เอกสารคืนสินค้า (2026-10-23 · DESIGN.md ข้อ 5.4)
//   RET = ลูกค้าคืนของเท่านั้น → ฝั่งขาย (เดิมอยู่ฝั่งซื้อทั้งที่เป็นของรับเข้า)
// แก้เฉพาะแถวที่ยังเป็นค่าตั้งต้น — แม่แบบที่ไซต์ตั้งเองไม่แตะ
// -----------------------------------------------------------------------------
if ($db->tableExists($_t_template)) {
    $_moved = $db->query("UPDATE `$_t_template` SET `mode` = 'sell' WHERE `document_type` = 'RET' AND `mode` = 'buy' AND `movement_type` = 'return_in'");
    if ($_moved > 0) {
        $content[] = '<li class="correct">inventory: ใบคืนสินค้า (RET) ย้ายไปฝั่งขาย — ลูกค้าคืนของ</li>';
    }
    $db->query("UPDATE `$_t_template` SET `topic` = 'ใบคืนสินค้าจากลูกค้า' WHERE `document_type` = 'RET' AND `topic` = 'ใบคืนสินค้า'");
}

// -----------------------------------------------------------------------------
// ใบสั่งขาย (SO) และใบคืนสินค้าไปยังผู้ขาย (RTS) มีเฉพาะ oms.in.th (ผู้ใช้ตัดสิน 2026-10-24)
// ตัวปรับรุ่นรุ่นก่อนเติมสองแม่แบบนี้ให้ — ปิด (published = 0) ไม่ลบ : เมนูและฟอร์มไม่มีชนิดนี้แล้ว
// แต่เอกสารที่ออกไปแล้ว (ถ้ามี) ยังเปิดและพิมพ์ได้ · ลบแถวไม่ได้เพราะด่านกันข้อมูลหาย (rowCountHtml)
// -----------------------------------------------------------------------------
if ($db->tableExists($_t_template)) {
    foreach (['SO' => 'ใบสั่งขาย', 'RTS' => 'ใบคืนสินค้าไปยังผู้ขาย'] as $_type => $_label) {
        $_tpl = $db->first($_t_template, ['document_type' => $_type]);
        if ($_tpl !== false && (int) $_tpl->published === 1) {
            $db->update($_t_template, ['id' => (int) $_tpl->id], ['published' => 0]);
            $content[] = '<li class="correct">inventory: ปิดแม่แบบ'.$_label.' ('.$_type.') — มีเฉพาะ oms.in.th</li>';
        }
    }
}

// =============================================================================
// ค่ากำหนดของโมดูล — เติมเฉพาะคีย์ที่ยังไม่มี (upgrade2.php บันทึก $config ให้)
// ห้ามเขียนทับของเดิม เพราะเป็นค่าที่ผู้ใช้ตั้งเองได้จากหน้าตั้งค่า
// ไซต์ที่ย้ายมาจากระบบเดิมมีค่าเหล่านี้อยู่แล้วใน datas/config/*.php
//
// ⚠️ ห้ามเติม <ชนิด>_prefix / <ชนิด>_NO กลับมาที่นี่ รูปแบบเลขที่เอกสารย้ายไป
// อยู่ในแถวของชนิดเอกสารแล้ว (inventory_template) และบล็อกข้างบนเพิ่งลบคีย์เก่าทิ้ง
// =============================================================================
$_inv_added = 0;
// รูปแบบรหัสสินค้าเปลี่ยนชื่อตามความหมาย (เดิม product_no) — ย้ายค่าที่ผู้ใช้ตั้งไว้ก่อนเติมค่าเริ่มต้น
if (isset($config['product_no'])) {
    if (!isset($config['product_code_format']) && is_string($config['product_no']) && $config['product_no'] !== '') {
        $config['product_code_format'] = $config['product_no'];
    }
    unset($config['product_no']);
    $content[] = '<li class="correct">inventory: ย้ายค่ากำหนด product_no เป็น product_code_format</li>';
}
foreach ([
    'customer_no' => 'CU%04d',
    'product_code_format' => 'P%04d',
    'stock_method' => 'fifo',
    'lot_tracking' => 0,
    'input_vat_creditable' => 1,
    'vat' => 7
] as $_key => $_default) {
    if (!isset($config[$_key])) {
        $config[$_key] = $_default;
        ++$_inv_added;
    }
}
if ($_inv_added > 0) {
    $content[] = '<li class="correct">inventory: เพิ่มค่ากำหนดเริ่มต้น '.$_inv_added.' รายการ</li>';
}

// =============================================================================
// ตารางทะเบียนสินค้า/สต๊อกให้เท่ากับไซต์ติดตั้งใหม่ (ชนิด · คอลัมน์ใหม่ · ดัชนี) — modules/inventory/install/align.php
// ตารางที่ตัวแปลงรุ่นก่อนสร้างไว้ค้างชนิดเดิมและขาดคอลัมน์ที่ engine เขียนทุกครั้ง (cost_layer.lot_no)
// =============================================================================
include_once ROOT_PATH.'modules/inventory/install/align.php';
inventoryAlignTables($db, $prefix, [
    'inventory_product', 'inventory', 'inventory_items', 'inventory_meta', 'inventory_warehouse',
    'inventory_stock', 'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation'
], $content);

// ยอดคงเหลือ · ต้นทุน · ยอดจอง เป็น cache ของสมุดบัญชี (DESIGN.md ข้อ 5.6) — ตัวปรับรุ่นทำงานนอกแอป
// เรียก Posting::rebuild() เองไม่ได้ จึงตั้งธงให้แอปคำนวณใหม่ครั้งเดียวตอนเปิดใช้ครั้งแรก (Inventory\Upgrade\Model)
$config['inventory_rebuild'] = 1;

$content[] = '<li class="correct">inventory อัปเกรดสำเร็จ</li>';
