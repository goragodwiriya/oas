<?php
/**
 * modules/inventory/install/upgrade.php — พาฐานเดิมมาถึงสคีมาของโมดูลกลาง
 *
 * install/upgrade_core.php เรียกไฟล์นี้ให้เองสำหรับทุกโมดูลที่มี ตัวแปรที่ใช้ได้
 * คือชุดเดียวกับที่ upgrade_core ใช้ : $db, $db_config, $prefix, $content, $config
 *
 * ต้นทางที่ต้องรองรับ (ดูหัวข้อ 4.3 ของ UPGRADE_PLAN_INVENTORY_BORROW_OAS.md)
 *   oms       inventory(product_no,inuse,stock) · inventory_items(PK product_no) · orders/stock · customer
 *   oas       inventory(product_code,inuse,stockable) · inventory_items(sku) · inventory_stock/_movement/cost_* · order/order_item
 *   inventory ~ borrow   inventory(is_active) · inventory_items(PK product_no) · ไม่มีเอกสาร
 *   ฐานเปล่า  ไม่มีอะไรเลย — ensureTable สร้างจาก database.sql ข้าง ๆ ให้ครบ
 *
 * กฎเดียวกับ upgrade_core : ทุกเงื่อนไขถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 * และห้าม DROP / RENAME ข้อมูลธุรกิจ — เปลี่ยนชื่อคอลัมน์ = เพิ่มคอลัมน์ใหม่
 * แล้วคัดลอกค่า โดยคงคอลัมน์เดิมไว้
 */
if (!defined('ROOT_PATH')) {
    exit;
}

// `stock` คือบรรทัดเอกสารของระบบรุ่นก่อน (oms) — ไซต์ที่ไม่เคยเป็น oms ได้ตารางว่าง
// เพื่อให้สคีมาเท่ากับไซต์ติดตั้งใหม่ ส่วนไซต์ oms ข้อมูลเดิมอยู่ครบ ensureTable ไม่แตะ
$_inv_tables = [
    'inventory', 'inventory_items', 'inventory_meta', 'inventory_stock',
    'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation',
    'inventory_template', 'customer', 'orders', 'order_items', 'stock'
];
foreach ($_inv_tables as $_t) {
    if (ensureTable($db, $prefix, $prefix.'_'.$_t)) {
        $content[] = '<li class="correct">inventory: สร้างตาราง '.$_t.'</li>';
    }
}

$_t_inventory = $prefix.'_inventory';
$_t_items = $prefix.'_inventory_items';
$_t_orders = $prefix.'_orders';
$_t_order_items = $prefix.'_order_items';
$_t_customer = $prefix.'_customer';
$_t_template = $prefix.'_inventory_template';

// =============================================================================
// inventory — Product Master
// =============================================================================
if (convertToInnoDB($db, $_t_inventory)) {
    $content[] = '<li class="correct">inventory: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_inventory)) {
    $content[] = '<li class="correct">inventory: แปลงเป็น utf8mb4</li>';
}
// category_id ต้องเป็น varchar(10) ให้ตรงกับตาราง category ของแกน
// ของ oms เป็น int(11) — แปลงเป็นข้อความไม่ทำให้ค่าหาย
if (ensureColumn($db, $_t_inventory, 'category_id', 'varchar(10)', true, null, '', 'description')) {
    $content[] = '<li class="correct">inventory: ปรับคอลัมน์ category_id</li>';
}
foreach ([
    'product_code' => ['varchar(150)', true, null, 'id'],
    'product_no' => ['varchar(150)', true, null, 'product_code'],
    'topic' => ['varchar(150)', false, null, 'product_no'],
    'description' => ['text', true, null, 'topic'],
    'model_id' => ['varchar(10)', true, null, 'category_id'],
    'type_id' => ['varchar(10)', true, null, 'model_id'],
    'unit' => ['varchar(20)', true, null, 'type_id'],
    'price' => ['double', false, 0, 'unit'],
    'vat' => ['double', false, 0, 'price'],
    'cost' => ['double', false, 0, 'vat'],
    'count_stock' => ['tinyint(1)', false, 1, 'cost'],
    'stockable' => ['tinyint(1)', false, 1, 'count_stock'],
    'allow_negative' => ['tinyint(1)', false, 0, 'stockable'],
    'stock' => ['double', false, 0, 'allow_negative'],
    'is_active' => ['tinyint(1)', false, 1, 'stock'],
    'inuse' => ['tinyint(1)', true, null, 'is_active'],
    'last_update' => ['int(11) unsigned', false, 0, 'inuse'],
    'created_at' => ['datetime', true, null, 'last_update'],
    'updated_at' => ['datetime', true, null, 'created_at']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_inventory, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">inventory: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
// ค่าที่ต้องคัดลอกข้ามชื่อคอลัมน์ — ทำเฉพาะแถวที่ยังว่าง จึงรันซ้ำได้
$db->query("UPDATE `$_t_inventory` SET `product_code` = `product_no` WHERE (`product_code` IS NULL OR `product_code` = '') AND `product_no` IS NOT NULL AND `product_no` != ''");
$db->query("UPDATE `$_t_inventory` SET `product_no` = `product_code` WHERE (`product_no` IS NULL OR `product_no` = '') AND `product_code` IS NOT NULL AND `product_code` != ''");
// is_active เป็นชื่อกลาง (ตรงกับตาราง category ของแกน) inuse คงไว้และเขียนคู่กัน
$db->query("UPDATE `$_t_inventory` SET `is_active` = `inuse` WHERE `inuse` IS NOT NULL AND `is_active` != `inuse`");
$db->query("UPDATE `$_t_inventory` SET `inuse` = `is_active` WHERE `inuse` IS NULL");
// stockable เป็นมุมมองหนึ่งของ count_stock ไม่ใช่สวิตช์อิสระ
$db->query("UPDATE `$_t_inventory` SET `stockable` = IF(`count_stock` > 0, 1, 0) WHERE `stockable` != IF(`count_stock` > 0, 1, 0)");
if (ensureIndexes($db, $_t_inventory, [
    'product_no' => '`product_no`',
    'category_id' => '`category_id`',
    'model_id' => '`model_id`',
    'type_id' => '`type_id`',
    'is_active' => '`is_active`'
])) {
    $content[] = '<li class="correct">inventory: ปรับดัชนี</li>';
}
// product_code ต้องไม่ซ้ำ — ปฏิเสธอย่างสุภาพถ้ายังซ้ำอยู่ ดีกว่าล้มกลางทาง
if (!$db->indexExists($_t_inventory, 'product_code')) {
    $db->query("UPDATE `$_t_inventory` SET `product_code` = NULL WHERE `product_code` = ''");
    $_dup = $db->customQuery(
        "SELECT `product_code` AS `v`, COUNT(*) AS `c` FROM `$_t_inventory`
         WHERE `product_code` IS NOT NULL GROUP BY `product_code` HAVING `c` > 1 LIMIT 10"
    );
    if (!empty($_dup)) {
        $_list = [];
        foreach ($_dup as $_row) {
            $_list[] = htmlspecialchars((string) $_row->v, ENT_QUOTES).' ('.(int) $_row->c.' รายการ)';
        }
        throw new \Exception(
            'ไม่สามารถบังคับให้รหัสสินค้าไม่ซ้ำกันได้ เพราะตอนนี้ยังมีรหัสซ้ำอยู่<br>'
            .implode(', ', $_list).'<br>'
            .'กรุณาแก้รหัสสินค้าในตาราง <code>'.$_t_inventory.'</code> ให้ไม่ซ้ำกัน แล้วปรับรุ่นใหม่อีกครั้ง'
        );
    }
    $db->query("ALTER TABLE `$_t_inventory` ADD UNIQUE `product_code` (`product_code`)");
    $content[] = '<li class="correct">inventory: เพิ่ม UNIQUE product_code</li>';
}

// =============================================================================
// inventory_items — หน่วยย่อย
//
// ⚠️ ของเดิมสามผลิตภัณฑ์ใช้ product_no เป็น PRIMARY KEY ต้องย้าย PK มาที่ id
// เพราะ ledger กับ cost layer อ้างหน่วยย่อยด้วย inventory_item_id (ตัวเลข)
// และเพราะฐานที่ติดตั้งใหม่กับฐานที่ปรับรุ่นมาต้องได้สคีมาเท่ากันเป๊ะ
// การย้ายคีย์ไม่ได้ลบข้อมูลสักแถว — product_no ยังอยู่ครบและยังห้ามซ้ำเหมือนเดิม
// =============================================================================
// ⚠️ สคีมากลางใช้ `inventory`.`id` เป็น int unsigned ส่วนของเดิมบางโปรเจ็ค
// (เช่น inventory/borrow) เป็น int ธรรมดา — ต่างกันแค่ signed/unsigned แต่
// cli-verify ถือว่าสคีมาไม่ตรงกับการติดตั้งใหม่ และคีย์นอกที่ชี้มาจะชนิดไม่ตรง
// MODIFY ไม่แตะค่าเดิมและไม่รีเซ็ต AUTO_INCREMENT
if (!$db->isColumnType($_t_inventory, 'id', 'int(11) unsigned')) {
    $db->query("ALTER TABLE `$_t_inventory` MODIFY `id` int(11) unsigned NOT NULL AUTO_INCREMENT");
    $content[] = '<li class="correct">inventory: ปรับชนิดคอลัมน์ id เป็น int unsigned</li>';
}

if (convertToInnoDB($db, $_t_items)) {
    $content[] = '<li class="correct">inventory_items: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_items)) {
    $content[] = '<li class="correct">inventory_items: แปลงเป็น utf8mb4</li>';
}
if (!$db->fieldExists($_t_items, 'id')) {
    $db->query("ALTER TABLE `$_t_items` ADD COLUMN `id` int(11) NOT NULL FIRST");
    $db->query("SET @row_number = 0");
    $db->query("UPDATE `$_t_items` SET `id` = (@row_number := @row_number + 1) ORDER BY `product_no`");
    $db->query(
        "ALTER TABLE `$_t_items` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`),
         MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY `product_no` (`product_no`)"
    );
    $content[] = '<li class="correct">inventory_items: ย้าย PRIMARY KEY จาก product_no ไป id</li>';
}
foreach ([
    'sku' => ['varchar(150)', false, null, 'id'],
    'product_no' => ['varchar(150)', true, null, 'sku'],
    'barcode' => ['varchar(150)', true, null, 'product_no'],
    'inventory_id' => ['int(11)', false, null, 'barcode'],
    'topic' => ['varchar(150)', true, null, 'inventory_id'],
    'unit' => ['varchar(50)', true, null, 'topic'],
    'price' => ['double', true, null, 'unit'],
    'cut_stock' => ['double', false, 1, 'price'],
    'stock' => ['double', false, 0, 'cut_stock'],
    'instock' => ['tinyint(1)', false, 0, 'stock'],
    'url' => ['varchar(255)', true, null, 'instock'],
    'last_update' => ['int(11)', false, 0, 'url']
] as $_col => $_def) {
    if (ensureColumn($db, $_t_items, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">inventory_items: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
$db->query("UPDATE `$_t_items` SET `sku` = `product_no` WHERE (`sku` IS NULL OR `sku` = '') AND `product_no` IS NOT NULL AND `product_no` != ''");
$db->query("UPDATE `$_t_items` SET `product_no` = `sku` WHERE (`product_no` IS NULL OR `product_no` = '') AND `sku` != ''");
// ของ oas ประกาศ barcode เป็น UNIQUE — สคีมากลางใช้ดัชนีธรรมดา
// (สินค้าหลายตัวมีบาร์โค้ดว่างเหมือนกันได้ UNIQUE จึงบล็อกการเพิ่มสินค้าตัวที่สอง)
if (dropMismatchedIndex($db, $_t_items, 'barcode', '`barcode`', false)) {
    $content[] = '<li class="correct">inventory_items: ดัชนี barcode ไม่ใช่ UNIQUE อีกต่อไป</li>';
}
if (ensureIndexes($db, $_t_items, [
    'barcode' => '`barcode`',
    'inventory_id' => '`inventory_id`',
    'instock' => '`instock`'
])) {
    $content[] = '<li class="correct">inventory_items: ปรับดัชนี</li>';
}
foreach (['sku', 'product_no'] as $_idx) {
    if ($db->indexExists($_t_items, $_idx) && indexIsUnique($db, $_t_items, $_idx)) {
        continue;
    }
    if ($db->indexExists($_t_items, $_idx)) {
        $db->query("ALTER TABLE `$_t_items` DROP INDEX `$_idx`");
    }
    $_dup = $db->customQuery(
        "SELECT `$_idx` AS `v`, COUNT(*) AS `c` FROM `$_t_items`
         WHERE `$_idx` IS NOT NULL AND `$_idx` != '' GROUP BY `$_idx` HAVING `c` > 1 LIMIT 10"
    );
    if (!empty($_dup)) {
        $_list = [];
        foreach ($_dup as $_row) {
            $_list[] = htmlspecialchars((string) $_row->v, ENT_QUOTES).' ('.(int) $_row->c.' รายการ)';
        }
        throw new \Exception(
            'ไม่สามารถบังคับให้ <code>'.$_idx.'</code> ของหน่วยย่อยไม่ซ้ำกันได้<br>'.implode(', ', $_list).'<br>'
            .'กรุณาแก้ข้อมูลในตาราง <code>'.$_t_items.'</code> ให้ไม่ซ้ำกัน แล้วปรับรุ่นใหม่อีกครั้ง'
        );
    }
    $db->query("ALTER TABLE `$_t_items` ADD UNIQUE `$_idx` (`$_idx`)");
    $content[] = '<li class="correct">inventory_items: เพิ่ม UNIQUE '.$_idx.'</li>';
}

// =============================================================================
// inventory_meta · inventory_stock · ledger · cost layer
// ตารางกลุ่มนี้ไม่มีในผลิตภัณฑ์ไหนนอกจาก oas — ensureTable สร้างให้แล้วข้างบน
// ที่ต้องทำคือปรับของ oas ให้ตรงสคีมากลาง (inventory_item_id ห้ามเป็น NULL)
// =============================================================================
$_t_meta = $prefix.'_inventory_meta';
if (convertToInnoDB($db, $_t_meta)) {
    $content[] = '<li class="correct">inventory_meta: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_meta)) {
    $content[] = '<li class="correct">inventory_meta: แปลงเป็น utf8mb4</li>';
}
if (ensureColumn($db, $_t_meta, 'value', 'mediumtext', false, null, '', 'name')) {
    $content[] = '<li class="correct">inventory_meta: ขยาย value เป็น mediumtext</li>';
}
if (ensureIndexes($db, $_t_meta, ['idx_inventory_meta' => '`inventory_id`, `name`', 'name' => '`name`'])) {
    $content[] = '<li class="correct">inventory_meta: ปรับดัชนี</li>';
}
// ระบบเดิมตั้งชื่อดัชนีเดียวกันว่า inventory_id — ซ้ำซ้อนกับ idx_inventory_meta
// ที่เพิ่งสร้าง ปล่อยไว้คือจ่ายค่าดูแลดัชนีสองชุดที่ทำงานเหมือนกันทุกการเขียน
if ($db->indexExists($_t_meta, 'inventory_id') && $db->indexExists($_t_meta, 'idx_inventory_meta')) {
    $db->query("ALTER TABLE `$_t_meta` DROP INDEX `inventory_id`");
    $content[] = '<li class="correct">inventory_meta: ลบดัชนีซ้ำซ้อน inventory_id</li>';
}
foreach (['inventory_stock', 'inventory_stock_movement', 'inventory_cost_layer', 'inventory_cost_allocation'] as $_t) {
    $_table = $prefix.'_'.$_t;
    // ⚠️ สี่ตารางนี้มีอยู่แล้วเฉพาะไซต์ที่เคยเป็น oas — ของไซต์อื่น ensureTable
    // เพิ่งสร้างให้จาก database.sql จึงถูกต้องอยู่แล้ว ที่ต้องจัดคือของ oas ซึ่ง
    // ประกาศ COLLATE=utf8mb4_unicode_ci ไว้เอง ต่างจากค่าปริยายที่ตัวติดตั้งใช้
    // ถ้าไม่แปลง ไซต์ที่ปรับรุ่นมาจะต่างจากไซต์ที่ติดตั้งใหม่ถาวร
    if (convertToInnoDB($db, $_table)) {
        $content[] = '<li class="correct">'.$_t.': แปลงเป็น InnoDB</li>';
    }
    if (convertToUtf8mb4($db, $_table)) {
        $content[] = '<li class="correct">'.$_t.': แปลงเป็น utf8mb4</li>';
    }
    // NULL ซ้ำกันได้ในดัชนี unique ของ MySQL ยอดของสินค้าที่นับรวมจึงต้องใช้ 0
    $db->query("UPDATE `$_table` SET `inventory_item_id` = 0 WHERE `inventory_item_id` IS NULL");
    if (ensureColumn($db, $_table, 'inventory_item_id', 'int(11)', false, 0, '', 'inventory_id')) {
        $content[] = '<li class="correct">'.$_t.': inventory_item_id ห้ามเป็น NULL</li>';
    }
    $db->query("UPDATE `$_table` SET `sku` = '' WHERE `sku` IS NULL");
    // ของ oas ประกาศ sku เป็น NOT NULL แต่ไม่มี DEFAULT — สคีมากลางใช้ DEFAULT ''
    if (ensureColumn($db, $_table, 'sku', 'varchar(150)', false, '', '', 'inventory_item_id')) {
        $content[] = '<li class="correct">'.$_t.': ปรับคอลัมน์ sku</li>';
    }
}
// คอลัมน์ที่สคีมากลางเพิ่มจากของเดิม — ไซต์ที่มีตารางเหล่านี้อยู่แล้ว (oas) จะขาดไป
// ถ้าไม่เติม Posting API จะล้มตอนเขียนยอดคงเหลือ ด้วย Unknown column
foreach ([
    'inventory_stock' => ['updated_at' => ['datetime', true, null, 'reserved_qty']],
    'inventory_cost_layer' => ['movement_id' => ['int(11)', true, null, 'sku']],
    'inventory_cost_allocation' => ['movement_id' => ['int(11)', true, null, 'sku']]
] as $_t => $_columns) {
    foreach ($_columns as $_col => $_def) {
        if (ensureColumn($db, $prefix.'_'.$_t, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
            $content[] = '<li class="correct">'.$_t.': ปรับคอลัมน์ '.$_col.'</li>';
        }
    }
}
// ดัชนีของสมุดบัญชีและชั้นต้นทุน — ไซต์ที่เคยเป็น oas มีดัชนีคนละชุดกับสคีมากลาง
// (ตั้งชื่อคนละอย่างและครอบคนละคอลัมน์) ถ้าไม่จัดให้ตรง ไซต์ที่ปรับรุ่นมาจะมี
// ดัชนีสองชุดที่ทำงานทับกัน และ cli-verify จะฟ้องว่าสคีมาไม่ตรงตลอดไป
foreach ([
    'inventory_stock' => ['sku' => '`sku`'],
    'inventory_stock_movement' => [
        'idx_item_time' => '`inventory_id`, `inventory_item_id`, `occurred_at`',
        'idx_reference' => '`reference_type`, `reference_id`',
        'movement_type' => '`movement_type`',
        'sku' => '`sku`'
    ],
    'inventory_cost_layer' => [
        'idx_fifo' => '`inventory_id`, `inventory_item_id`, `received_at`',
        'idx_remaining' => '`remaining_qty`',
        'movement_id' => '`movement_id`'
    ],
    'inventory_cost_allocation' => [
        'layer_id' => '`layer_id`',
        'movement_id' => '`movement_id`',
        'idx_reference' => '`reference_type`, `reference_id`'
    ]
] as $_t => $_indexes) {
    foreach (ensureIndexes($db, $prefix.'_'.$_t, $_indexes) as $_idx) {
        $content[] = '<li class="correct">'.$_t.': ปรับดัชนี '.$_idx.'</li>';
    }
}

// ยอดคงเหลือต้องมีแถวเดียวต่อหน่วยย่อย — ของ oas ไม่มีข้อบังคับนี้เลย
if (!$db->indexExists($prefix.'_inventory_stock', 'idx_balance')) {
    $_dup = $db->customQuery(
        "SELECT `inventory_id`, `inventory_item_id`, COUNT(*) AS `c`
         FROM `{$prefix}_inventory_stock` GROUP BY `inventory_id`, `inventory_item_id` HAVING `c` > 1 LIMIT 10"
    );
    if (!empty($_dup)) {
        $_list = [];
        foreach ($_dup as $_row) {
            $_list[] = 'สินค้า '.(int) $_row->inventory_id.' หน่วยย่อย '.(int) $_row->inventory_item_id.' ('.(int) $_row->c.' แถว)';
        }
        throw new \Exception(
            'ตารางยอดคงเหลือมีสินค้าที่มียอดซ้ำกันหลายแถว ซึ่งทำให้ยอดคงเหลือไม่มีคำตอบเดียว<br>'
            .implode(', ', $_list).'<br>'
            .'กรุณารวมแถวที่ซ้ำกันในตาราง <code>'.$prefix.'_inventory_stock</code> ให้เหลือแถวเดียวต่อสินค้า แล้วปรับรุ่นใหม่'
        );
    }
    $db->query("ALTER TABLE `{$prefix}_inventory_stock` ADD UNIQUE `idx_balance` (`inventory_id`, `inventory_item_id`)");
    $content[] = '<li class="correct">inventory_stock: บังคับยอดคงเหลือแถวเดียวต่อหน่วยย่อย</li>';
}

// แถวยอดคงเหลือระดับสินค้า (inventory_item_id = 0) ต้องถือรหัสของ "ตัวสินค้า"
// ไม่ใช่รหัสหน่วยขายที่บังเอิญเคลื่อนไหวเป็นรายการล่าสุด — สินค้าที่ขายหลายหน่วย
// (ยกลัง/ยกโหล) จะได้แถวที่จำนวนเป็นขวดแต่รหัสเป็นลัง แล้วสลับไปมาทุกใบที่ขายคนละหน่วย
$_stockSku = $db->customQuery(
    "SELECT COUNT(*) AS `c` FROM `{$prefix}_inventory_stock` S
     INNER JOIN `{$prefix}_inventory` I ON I.`id` = S.`inventory_id`
     WHERE S.`inventory_item_id` = 0 AND I.`product_code` != '' AND S.`sku` != I.`product_code`"
);
if (!empty($_stockSku) && (int) $_stockSku[0]->c > 0) {
    $db->query(
        "UPDATE `{$prefix}_inventory_stock` S
         INNER JOIN `{$prefix}_inventory` I ON I.`id` = S.`inventory_id`
         SET S.`sku` = I.`product_code`
         WHERE S.`inventory_item_id` = 0 AND I.`product_code` != '' AND S.`sku` != I.`product_code`"
    );
    $content[] = '<li class="correct">inventory_stock: แก้รหัสบนแถวยอดรวมของสินค้าให้เป็นรหัสสินค้า '.(int) $_stockSku[0]->c.' แถว</li>';
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
    'movement_type' => ['varchar(20)', true, null, 'cut_stock'],
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
    'vat' => ['decimal(10,2)', false, '0.00', 'discount_percent'],
    'vat_status' => ['tinyint(1) unsigned', false, 0, 'vat'],
    'tax' => ['decimal(10,2)', false, '0.00', 'vat_status'],
    'tax_status' => ['decimal(10,2)', false, '0.00', 'tax'],
    'total' => ['decimal(10,2)', false, '0.00', 'shipping_cost'],
    'paid' => ['decimal(10,2)', false, '0.00', 'total'],
    'order_no' => ['varchar(50)', false, null, 'id'],
    // ระบบเดิมบังคับให้มีลูกค้าและผู้บันทึกเสมอ แต่เอกสารขายสด/นำเข้าจากระบบอื่น
    // อาจไม่มีทั้งคู่ — ยอมให้ว่างได้ ตรงกับสคีมากลาง
    'customer_id' => ['int(11) unsigned', true, null, 'customer_zipcode'],
    'member_id' => ['int(11) unsigned', true, null, 'customer_id'],
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
    'subtotal' => ['double', false, 0, 'member_id'],
    'shipping_cost' => ['double', false, 0, 'tax_status'],
    'change_amount' => ['double', false, 0, 'paid'],
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
if (ensureIndexes($db, $_t_orders, [
    'customer_id' => '`customer_id`',
    'member_id' => '`member_id`',
    'order' => '`order`',
    'idx_type_date' => '`document_type`, `order_date`',
    'public_key' => '`public_key`',
    'source_document_id' => '`source_document_id`',
    'root_document_id' => '`root_document_id`'
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
// ตาราง stock มีอยู่เสมอ (ensureTable ข้างบน) และเก็บไว้ตามกฎ "ห้ามลบของเดิม"
// ยังต้องแปลงให้เป็น InnoDB + utf8mb4 เพราะข้อความไทยในนั้นเป็นข้อมูลจริง
$_t_stock = $prefix.'_stock';
if (convertToInnoDB($db, $_t_stock)) {
    $content[] = '<li class="correct">stock (ตารางเดิม): แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $_t_stock)) {
    $content[] = '<li class="correct">stock (ตารางเดิม): แปลงเป็น utf8mb4</li>';
}
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_order_items`");
$_have = empty($_rows) ? 0 : (int) $_rows[0]->c;
$_rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$_t_stock`");
$_legacy = empty($_rows) ? 0 : (int) $_rows[0]->c;
if ($_have === 0 && $_legacy > 0) {
    $db->query(
        "INSERT INTO `$_t_order_items`
            (`order_id`, `inventory_id`, `product_no`, `topic`, `quantity`, `unit`,
             `price`, `discount`, `vat`, `total`, `cut_stock`, `member_id`, `create_date`)
         SELECT `order_id`, `inventory_id`, `product_no`, `topic`, `quantity`, `unit`,
                `price`, `discount`, `vat`, `total`, `cut_stock`, `member_id`, `create_date`
           FROM `$_t_stock`"
    );
    $content[] = '<li class="correct">order_items: คัดลอกบรรทัดเอกสารเดิมมา '.$_legacy.' แถว (ตาราง stock เดิมยังอยู่ครบ)</li>';
}
$db->query("UPDATE `$_t_order_items` SET `product_code` = `product_no` WHERE (`product_code` IS NULL OR `product_code` = '') AND `product_no` IS NOT NULL AND `product_no` != ''");

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


// =============================================================================
// ย้ายยอดของสินค้า "นับรวม" ที่ค้างอยู่ระดับหน่วยย่อย มาไว้ที่ระดับสินค้า
//
// ⚠️ ทำไมต้องย้าย — เพื่อให้ขายแบบ "ยกลัง / ยกโหล" ได้
//
// กติกาที่ตกลงแล้ว: `count_stock` เป็นตัวตัดสินว่ายอดอยู่ระดับไหน
//   1 = นับรวม        → ของกองเดียวที่ inventory_item_id = 0
//                       หน่วยย่อยเป็นหน่วยขายที่มีตัวคูณ `cut_stock` ของตัวเอง
//   2 = นับแยกรายชิ้น → แต่ละหน่วยย่อยมียอดของตัวเอง
//
// ไซต์รุ่นก่อนลงยอดของสินค้า count_stock = 1 ไว้ที่ระดับหน่วยย่อย ถ้าไม่ย้าย
// ขายปลีกกับขายยกลังจะกลายเป็นของคนละกองที่ไม่รู้จักกัน และหน้าสินค้าจะแสดง
// ยอดเป็น 0 ทั้งที่ของมีอยู่ (Product\Model อ่านยอดที่ระดับ 0)
//
// รันซ้ำได้ — รอบที่สองไม่มีแถวไหนเข้าเงื่อนไขแล้ว
// =============================================================================
$_t_movement = $prefix.'_inventory_stock_movement';
$_t_balance = $prefix.'_inventory_stock';
$_t_layer = $prefix.'_inventory_cost_layer';
$_t_alloc = $prefix.'_inventory_cost_allocation';

if ($db->tableExists($_t_movement) && $db->tableExists($_t_inventory)) {
    $_rows = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM `$_t_movement` M
          INNER JOIN `$_t_inventory` V ON V.`id` = M.`inventory_id`
          WHERE V.`count_stock` = 1 AND M.`inventory_item_id` <> 0"
    );
    $_misplaced = empty($_rows) ? 0 : (int) $_rows[0]->c;
    if ($_misplaced > 0) {
        // สมุดบัญชีกับชั้นต้นทุนต้องย้ายพร้อมกัน ไม่งั้น FIFO จะหาชั้นต้นทุนไม่เจอ
        foreach ([$_t_movement, $_t_layer, $_t_alloc] as $_t) {
            if (!$db->tableExists($_t)) {
                continue;
            }
            $db->query(
                "UPDATE `$_t` X
                  INNER JOIN `$_t_inventory` V ON V.`id` = X.`inventory_id`
                     SET X.`inventory_item_id` = 0
                   WHERE V.`count_stock` = 1 AND X.`inventory_item_id` <> 0"
            );
        }

        // ยอดคงเหลือคือผลสรุปของสมุดบัญชี จึงล้างแล้วรวมใหม่ ไม่ใช่ย้ายแถว
        // (ย้ายแถวจะชนกับดัชนี UNIQUE เมื่อสินค้าหนึ่งมีหลายหน่วยย่อย)
        if ($db->tableExists($_t_balance)) {
            $db->query(
                "DELETE B FROM `$_t_balance` B
                  INNER JOIN `$_t_inventory` V ON V.`id` = B.`inventory_id`
                  WHERE V.`count_stock` = 1"
            );
            $db->query(
                "INSERT INTO `$_t_balance` (`inventory_id`, `inventory_item_id`, `sku`, `qty`, `reserved_qty`, `updated_at`)
                 SELECT M.`inventory_id`, 0, '',
                        SUM(IF(M.`movement_direction` = 'in', M.`quantity`, -M.`quantity`)), 0, NOW()
                   FROM `$_t_movement` M
                  INNER JOIN `$_t_inventory` V ON V.`id` = M.`inventory_id`
                  WHERE V.`count_stock` = 1
                  GROUP BY M.`inventory_id`"
            );
            // คอลัมน์ cache ของตารางเดิมต้องตามยอดจริงด้วย
            $db->query(
                "UPDATE `$_t_inventory` V
                  INNER JOIN `$_t_balance` B ON B.`inventory_id` = V.`id` AND B.`inventory_item_id` = 0
                     SET V.`stock` = B.`qty`
                   WHERE V.`count_stock` = 1"
            );
        }

        $content[] = '<li class="correct">inventory: ย้ายยอดของสินค้านับรวมมาไว้ที่ระดับสินค้า '
            .$_misplaced.' รายการ (รองรับการขายยกลัง/ยกโหล)</li>';
    }
}

// =============================================================================
// ยอดยกมา — ย้ายยอดคงเหลือเดิมเข้าสมุดบัญชีสต๊อก
//
// ไซต์ที่ใช้ระบบเดิมเก็บยอดคงเหลือไว้ที่คอลัมน์ `stock` ของ inventory /
// inventory_items ตรง ๆ พอมาใช้โมดูลกลาง "ความจริง" ย้ายไปอยู่ที่สมุดบัญชี
// ถ้าไม่ย้ายยอดเดิมเข้ามา ทุกสินค้าจะกลายเป็นยอด 0 ทันทีที่อัปเกรด
// (แผนข้อ 5.3 — เกณฑ์ผ่านคือยอดจาก ledger เท่ากับยอดเดิมทุกสินค้า)
//
// ⚠️ ทำ "ต่อสินค้า" ไม่ใช่ "ต่อทั้งเล่ม" — สินค้าที่ยังไม่มีรายการในสมุดบัญชีเลย
// เท่านั้นที่ได้ยอดยกมา สินค้าที่เดินบัญชีมาแล้วไม่ถูกแตะ จึงรันซ้ำกี่รอบก็ไม่เกิดยอดซ้อน
//
// ของเดิมเช็คว่า "สมุดบัญชีว่างทั้งเล่ม" ซึ่งพลาดไซต์ที่รับสมุดบัญชีมาแล้วบางส่วน
// (เช่นแอดมินเคยปรับยอดสินค้าตัวเดียว หรือระบบเดิมลงบัญชีไว้ไม่กี่รายการ) — สินค้า
// ที่เหลือจะกลายเป็นยอด 0 ตลอดไปโดยไม่มีอะไรฟ้อง เจอจริงกับฐานของ pos 2026-09-12
// (สมุดบัญชีมี 14 แถว แต่สินค้า 17 ตัวมียอดอยู่ที่คอลัมน์ stock เท่านั้น)
//
// เขียนด้วย SQL ตรง ๆ ไม่ผ่าน Posting API เพราะตัวปรับรุ่นทำงานนอกแอป
// แต่ต้องได้ผลเหมือนกันเป๊ะ : movement + ยอดคงเหลือ + ชั้นต้นทุน ครบสามที่
// =============================================================================
$_t_movement = $prefix.'_inventory_stock_movement';
$_t_balance = $prefix.'_inventory_stock';
$_t_layer = $prefix.'_inventory_cost_layer';

if ($db->tableExists($_t_movement)) {
    $_now = date('Y-m-d H:i:s');
    $_note = 'ยอดยกมาตอนเริ่มใช้สมุดบัญชีสต๊อก';

    // หน่วยย่อยที่มียอดคงเหลือ
    //
    // ⚠️ ระดับที่ลงบัญชีต้องตรงกับกติกาของ Posting::stockLevel() เป๊ะ :
    // count_stock = 2 (นับแยกรายชิ้น) ลงที่หน่วยย่อย · นอกนั้นลงที่ระดับสินค้า (0)
    // เพราะหน่วยย่อยของสินค้าที่นับรวมเป็น "หน่วยขาย" (ยกลัง/ยกโหล) ที่ตัดจากกองเดียวกัน
    // ลงผิดระดับแล้วแอปอ่านยอดไม่เจอ — สินค้าขึ้น 0 ทั้งที่สมุดบัญชีมียอดอยู่
    $db->query(
        "INSERT INTO `$_t_movement`
            (`inventory_id`, `inventory_item_id`, `sku`, `movement_direction`, `movement_type`,
             `reference_type`, `quantity`, `unit_cost`, `total_cost`, `note`, `occurred_at`, `created_at`)
         SELECT I.`inventory_id`, IF(V.`count_stock` = 2, I.`id`, 0), IFNULL(I.`sku`, ''),
                IF(I.`stock` > 0, 'in', 'out'), IF(I.`stock` > 0, 'opening', 'adjust_out'),
                'opening', ABS(I.`stock`), IFNULL(V.`cost`, 0), ABS(I.`stock`) * IFNULL(V.`cost`, 0),
                '$_note', '$_now', '$_now'
           FROM `$_t_items` I
           LEFT JOIN `$_t_inventory` V ON V.`id` = I.`inventory_id`
          WHERE I.`stock` <> 0
            AND NOT EXISTS (SELECT 1 FROM `$_t_movement` M WHERE M.`inventory_id` = I.`inventory_id`)"
    );

    // สินค้าที่นับรวม (ยอดอยู่ที่ตัวสินค้า ไม่ได้แยกรายหน่วยย่อย)
    // ต้องไม่นับซ้ำกับยอดของหน่วยย่อยข้างบน จึงข้ามสินค้าที่หน่วยย่อยมียอดแล้ว
    $db->query(
        "INSERT INTO `$_t_movement`
            (`inventory_id`, `inventory_item_id`, `sku`, `movement_direction`, `movement_type`,
             `reference_type`, `quantity`, `unit_cost`, `total_cost`, `note`, `occurred_at`, `created_at`)
         SELECT V.`id`, 0, '',
                IF(V.`stock` > 0, 'in', 'out'), IF(V.`stock` > 0, 'opening', 'adjust_out'),
                'opening', ABS(V.`stock`), IFNULL(V.`cost`, 0), ABS(V.`stock`) * IFNULL(V.`cost`, 0),
                '$_note', '$_now', '$_now'
           FROM `$_t_inventory` V
          WHERE V.`stock` <> 0
            AND NOT EXISTS (SELECT 1 FROM `$_t_items` I WHERE I.`inventory_id` = V.`id` AND I.`stock` <> 0)
            AND NOT EXISTS (SELECT 1 FROM `$_t_movement` M WHERE M.`inventory_id` = V.`id`)"
    );

    // ⚠️ นับเฉพาะแถวที่เพิ่งใส่ไปรอบนี้ — ชี้ด้วยเวลาและหมายเหตุที่เขียนไว้เอง
    // ถ้านับทั้งเล่มแล้วเอาไปสร้างชั้นต้นทุน จะได้ชั้นซ้ำของรายการที่มีอยู่แล้วทุกครั้งที่ปรับรุ่น
    $_rows = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM `$_t_movement`
          WHERE `reference_type` = 'opening' AND `note` = '$_note' AND `created_at` = '$_now'"
    );
    $_seeded = empty($_rows) ? 0 : (int) $_rows[0]->c;
    if ($_seeded > 0) {
        // ยอดคงเหลือ = ผลรวมของสมุดบัญชี — คิดใหม่เฉพาะคู่ (สินค้า, หน่วยย่อย) ที่เพิ่งได้ยอดยกมา
        $db->query(
            "INSERT INTO `$_t_balance` (`inventory_id`, `inventory_item_id`, `sku`, `qty`, `reserved_qty`, `updated_at`)
             SELECT M.`inventory_id`, M.`inventory_item_id`, MAX(M.`sku`),
                    SUM(IF(M.`movement_direction` = 'in', M.`quantity`, -M.`quantity`)), 0, '$_now'
               FROM `$_t_movement` M
              WHERE EXISTS (
                    SELECT 1 FROM `$_t_movement` S
                     WHERE S.`inventory_id` = M.`inventory_id`
                       AND S.`inventory_item_id` = M.`inventory_item_id`
                       AND S.`reference_type` = 'opening' AND S.`note` = '$_note' AND S.`created_at` = '$_now')
              GROUP BY M.`inventory_id`, M.`inventory_item_id`
             ON DUPLICATE KEY UPDATE `qty` = VALUES(`qty`), `updated_at` = VALUES(`updated_at`)"
        );

        // ชั้นต้นทุนของยอดที่รับเข้า เพื่อให้ FIFO ตัดต้นทุนของที่ขายต่อจากนี้ได้
        $db->query(
            "INSERT INTO `$_t_layer`
                (`inventory_id`, `inventory_item_id`, `sku`, `movement_id`, `reference_type`,
                 `received_qty`, `remaining_qty`, `unit_cost`, `received_at`, `created_at`)
             SELECT `inventory_id`, `inventory_item_id`, `sku`, `id`, 'opening',
                    `quantity`, `quantity`, IFNULL(`unit_cost`, 0), `occurred_at`, `created_at`
               FROM `$_t_movement`
              WHERE `movement_direction` = 'in'
                AND `reference_type` = 'opening' AND `note` = '$_note' AND `created_at` = '$_now'"
        );

        $content[] = '<li class="correct">สมุดบัญชีสต๊อก: ย้ายยอดคงเหลือเดิมเข้าเป็นยอดยกมา '.$_seeded.' รายการ</li>';
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
// ใบสั่งขายที่จองของ (2026-09-12)
// -----------------------------------------------------------------------------
$_t_template = $prefix.'_inventory_template';
if ($db->tableExists($_t_template)) {
    if (ensureColumn($db, $_t_template, 'reserve_stock', 'tinyint(1)', false, 0, '', 'cut_stock')) {
        $content[] = '<li class="correct">inventory: เพิ่มคอลัมน์ inventory_template.reserve_stock</li>';
    }
    // แม่แบบ SO ต้องมีให้ทุกไซต์ — ไซต์เก่าไม่เคยมีใบสั่งขาย ถ้าไม่เติมให้ ร้านค้าออนไลน์
    // (และการจองทางโทรศัพท์) จะไม่มีชนิดเอกสารให้ใช้ แล้วล้มตอนสั่งซื้อครั้งแรก
    // ⚠️ Db ของตัวติดตั้ง (install/db.php) ไม่มี exists() — มีแต่ first() ที่คืน false เมื่อไม่พบ
    if ($db->first($_t_template, ['document_type' => 'SO']) === false) {
        $db->query(
            "INSERT INTO `$_t_template`
                (`document_type`, `status`, `mode`, `topic`, `comment`, `due_date`, `published`,
                 `in_stock`, `cut_stock`, `reserve_stock`, `movement_type`, `prefix`, `number_format`, `columns`)
             VALUES ('SO', 'SO', 'sell', 'ใบสั่งขาย', '', 1, 1, 0, 0, 1, NULL,
                     'SO%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount')"
        );
        $content[] = '<li class="correct">inventory: เพิ่มแม่แบบใบสั่งขาย (SO)</li>';
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
foreach ([
    'customer_no' => 'CU%04d',
    'product_no' => 'P%04d',
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

$content[] = '<li class="correct">inventory อัปเกรดสำเร็จ</li>';
