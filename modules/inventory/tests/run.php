<?php
/**
 * modules/inventory/tests/run.php — ชุดทดสอบ Stock Engine และต้นทุน FIFO
 *
 * ⚠️ ทั้งการเดินสต๊อกแบบมีสมุดบัญชีและต้นทุน FIFO **ไม่เคยเดินจริงที่ไหนเลย**
 * (oas cost_layer = 0 แถว · oms stock.used > 0 = 0 แถว) ชุดนี้จึงไม่ใช่การ
 * ยืนยันว่า "ย้ายของเดิมมาครบ" แต่เป็นด่านเดียวที่พิสูจน์ว่าของใหม่ถูกต้อง
 *
 * สร้างฐานทดสอบของตัวเองด้วยตัวติดตั้งจริง (install/cli-fresh.php) แล้วชี้
 * Kotchasan ไปที่ฐานนั้นผ่าน APP_PATH ชั่วคราว จึงไม่แตะฐานหรือไฟล์ตั้งค่าของ
 * โปรเจ็คเลยแม้แต่ไฟล์เดียว และลบฐานทดสอบทิ้งเมื่อจบ (ใส่ --keep เพื่อเก็บไว้ดู)
 *
 * ใช้:  php modules/inventory/tests/run.php [--db=<ชื่อฐานทดสอบ>] [--keep]
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$root = dirname(__DIR__, 3);
chdir($root);

$options = ['db' => 'nowtest_engine', 'keep' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $match) && array_key_exists($match[1], $options)) {
        $options[$match[1]] = isset($match[2]) ? $match[2] : true;
    } else {
        fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
        exit(1);
    }
}
$dbname = $options['db'];

// ---- สร้างฐานทดสอบด้วยตัวติดตั้งของจริง ----
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/install/cli-fresh.php')
    .' '.escapeshellarg($dbname).' app --no-admin';
exec($command.' 2>&1', $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "สร้างฐานทดสอบไม่ได้\n".implode("\n", $output)."\n");
    exit(1);
}

// ชี้ฐานข้อมูลไปที่ฐานทดสอบ โดยไม่แตะ settings/ ของโปรเจ็ค
//
// Kotchasan อ่าน settings/database.php จาก APP_PATH ก่อน ROOT_PATH เสมอ
// (Kotchasan/Database.php : loadConfigFromFile) และ APP_PATH ประกาศทับได้ถ้า
// ประกาศก่อน load.php — จึงทำโฟลเดอร์ชั่วคราวที่มีแต่ไฟล์ค่ากำหนดฐานทดสอบ
$work = sys_get_temp_dir().'/nowjs-engine-'.md5($root);
@mkdir($work.'/settings', 0700, true);
$database = include $root.'/settings/database.php';
$database['mysql']['dbname'] = $dbname;
file_put_contents($work.'/settings/database.php', "<?php\nreturn ".var_export($database, true).";\n");
define('APP_PATH', $work.'/');

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api';
$_SERVER['SCRIPT_NAME'] = '/api.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_save_path(sys_get_temp_dir());
@session_start();

include $root.'/load.php';
Kotchasan::createWebApplication('Gcms\Config');

$ok = 0;
$fail = 0;
$failed = [];

/**
 * ยืนยันหนึ่งข้อ
 *
 * @param string $label
 * @param bool   $cond
 */
function t($label, $cond)
{
    global $ok, $fail, $failed;
    if ($cond) {
        ++$ok;
        echo "  [ok]   $label\n";
    } else {
        ++$fail;
        $failed[] = $label;
        echo "  [FAIL] $label\n";
    }
}

/**
 * ยืนยันว่าตัวเลขเท่ากัน (เทียบแบบทศนิยม)
 *
 * @param string $label
 * @param float  $expected
 * @param float  $actual
 */
function tnum($label, $expected, $actual)
{
    t($label.' = '.$expected.(abs($expected - $actual) < 0.0001 ? '' : ' (ได้ '.$actual.')'),
        abs($expected - $actual) < 0.0001);
}

/**
 * ยืนยันว่าการกระทำหนึ่งต้องโยนข้อผิดพลาดพร้อมข้อความที่บอกเหตุผล
 *
 * @param string   $label
 * @param callable $fn
 * @param string   $contains ข้อความที่ต้องปรากฏ
 */
function tthrows($label, $fn, $contains = '')
{
    try {
        $fn();
        t($label, false);
    } catch (\Exception $e) {
        t($label.($contains === '' ? '' : ' — "'.$contains.'"'),
            $contains === '' || mb_strpos($e->getMessage(), $contains) !== false);
    }
}

/**
 * หัวข้อกลุ่ม
 *
 * @param string $title
 */
function group($title)
{
    echo "\n== $title\n";
}

$db = \Kotchasan\Model::createDB();
$prefix = \Inventory\Base\Model::table('');
$T = function ($name) {
    return \Inventory\Base\Model::table($name);
};

/**
 * สร้างสินค้าหนึ่งรายการ คืน id
 *
 * @param string $code
 * @param string $topic
 * @param int    $countStock
 * @param int    $allowNegative
 *
 * @return int
 */
function product($code, $topic, $countStock = 1, $allowNegative = 0)
{
    // สินค้า + variant ตั้งต้น (sku = รหัสสินค้า) + หน่วยขายฐาน — คืน id ของ variant
    return \Inventory\Product\Model::addProduct([
        'product_code' => $code, 'topic' => $topic, 'count_stock' => $countStock,
        'allow_negative' => $allowNegative, 'unit' => 'ชิ้น', 'price' => 100
    ]);
}

// -----------------------------------------------------------------------------
group('ข้อมูลตั้งต้นของการติดตั้งใหม่ — คู่ค้าเงินสด · หมวดหมู่ · หน่วยนับ (database.sql ของโมดูล)');

// ต้องเป็นกลุ่มแรก — กลุ่มอื่นลบ/เพิ่มลูกค้าและหมวดหมู่ระหว่างทาง
$cashCustomer = $db->first($T('customer'), ['customer_no', 'CASH-C']);
$cashSupplier = $db->first($T('customer'), ['customer_no', 'CASH-S']);
t('ลูกค้าเงินสดเป็นลูกค้าอย่างเดียว ใช้งานอยู่', $cashCustomer && (int) $cashCustomer->is_customer === 1
    && (int) $cashCustomer->is_supplier === 0 && (int) $cashCustomer->is_active === 1);
t('ผู้ขายเงินสดเป็นผู้ขายอย่างเดียว ใช้งานอยู่', $cashSupplier && (int) $cashSupplier->is_customer === 0
    && (int) $cashSupplier->is_supplier === 1 && (int) $cashSupplier->is_active === 1);
t('คู่ค้าเงินสดมีชื่อและที่อยู่ (ฟอร์มลูกค้าบังคับสองช่องนี้ — แก้รายการได้)',
    $cashCustomer && $cashCustomer->company !== '' && $cashCustomer->address !== ''
    && $cashSupplier && $cashSupplier->company !== '' && $cashSupplier->address !== '');

$seeded = [];
foreach (['category_id', 'unit'] as $_type) {
    $seeded[$_type] = array_map(function ($row) {
        return $row->topic;
    }, $db->select($T('category'), ['type', $_type]));
}
tnum('หมวดหมู่สินค้าตั้งต้น', 10, count($seeded['category_id']));
tnum('หน่วยนับตั้งต้น', 30, count($seeded['unit']));
t('ชื่อหมวดหมู่และหน่วยไม่ซ้ำกันในชนิดเดียวกัน',
    count(array_unique($seeded['category_id'])) === count($seeded['category_id'])
    && count(array_unique($seeded['unit'])) === count($seeded['unit']));

// ไฟล์สินค้าตัวอย่าง อ่านด้วยตัวอ่านเดียวกับหน้านำเข้า (Kotchasan\Csv::read ตัด BOM ให้)
$sampleCsv = ROOT_PATH.'modules/inventory/install/sample-products.csv';
$sampleHeader = [];
$sampleRows = [];
\Kotchasan\Csv::read($sampleCsv, function ($row) use (&$sampleRows) {
    $sampleRows[] = (array) $row;
}, null, 'UTF-8', function ($columns) use (&$sampleHeader) {
    $sampleHeader = (array) $columns;
});
t('หัวตารางของไฟล์สินค้าตัวอย่าง = คอลัมน์ของการนำเข้าสินค้า',
    $sampleHeader === \Inventory\Import\Model::type('product')['columns']);
t('หมวดหมู่ทุกแถวของไฟล์ตัวอย่างมีอยู่ในข้อมูลตั้งต้น',
    !empty($sampleRows) && empty(array_diff(array_column($sampleRows, 'category'), $seeded['category_id'])));
t('หน่วยทุกแถวของไฟล์ตัวอย่างมีอยู่ในข้อมูลตั้งต้น',
    !empty($sampleRows) && empty(array_diff(array_column($sampleRows, 'unit'), $seeded['unit'])));

// -----------------------------------------------------------------------------
group('ทะเบียนชนิดการเคลื่อนไหว (หัวข้อ 4.6 ของแผน)');

$types = \Inventory\Base\Model::movementTypes();
t('ทะเบียนมีครบ 13 ชนิด', count($types) === 13);
t('opening เป็นขาเข้า', \Inventory\Base\Model::movementDirection('opening') === 'in');
t('sale เป็นขาออก', \Inventory\Base\Model::movementDirection('sale') === 'out');
t('borrow เป็นขาออก · borrow_return เป็นขาเข้า',
    \Inventory\Base\Model::movementDirection('borrow') === 'out'
    && \Inventory\Base\Model::movementDirection('borrow_return') === 'in');
t('ชนิดที่ไม่ได้ลงทะเบียนไว้ตอบ null', \Inventory\Base\Model::movementDirection('ขายของ') === null);

// -----------------------------------------------------------------------------
group('ข้อมูลที่ผิดต้องถูกปฏิเสธ ไม่ใช่บันทึกแล้วค่อยไปงงทีหลัง');

$p1 = product('P001', 'สินค้านับรวม');
tthrows('ไม่ระบุสินค้า', function () {
    \Inventory\Posting\Model::post(['movement_type' => 'purchase', 'quantity' => 1]);
}, 'ต้องระบุสินค้า');
tthrows('ชนิดการเคลื่อนไหวที่ไม่รู้จัก', function () use ($p1) {
    \Inventory\Posting\Model::post(['inventory_id' => $p1, 'movement_type' => 'ซื้อของ', 'quantity' => 1]);
}, 'ไม่รู้จักชนิดการเคลื่อนไหว');
tthrows('จำนวนติดลบ (ทิศทางต้องมาจากชนิด ไม่ใช่เครื่องหมาย)', function () use ($p1) {
    \Inventory\Posting\Model::post(['inventory_id' => $p1, 'movement_type' => 'purchase', 'quantity' => -5]);
}, 'ต้องมากกว่าศูนย์');
tthrows('สินค้าที่ไม่มีอยู่จริง', function () {
    \Inventory\Posting\Model::post(['inventory_id' => 99999, 'movement_type' => 'purchase', 'quantity' => 1]);
}, 'ไม่พบสินค้า');

// -----------------------------------------------------------------------------
group('รับเข้า/ตัดออก และยอดคงเหลือ');

\Inventory\Posting\Model::receipt([
    'inventory_id' => $p1, 'quantity' => 10, 'unit_cost' => 20,
    'reference_type' => 'order', 'reference_id' => 900001, 'reference_no' => 'IN-001',  // ⚠️ id ปลอมต้องไม่ชนเอกสารจริงที่ชุดนี้สร้างทีหลัง (Posting::reverse เลือกตาม reference_id)
    'occurred_at' => '2026-01-01 09:00:00'
]);
tnum('รับเข้า 10 แล้วคงเหลือ', 10, \Inventory\Posting\Model::balance($p1));

\Inventory\Posting\Model::issue([
    'inventory_id' => $p1, 'quantity' => 4,
    'reference_type' => 'order', 'reference_id' => 900002, 'reference_no' => 'OUT-001',
    'occurred_at' => '2026-01-02 09:00:00'
]);
tnum('ตัดออก 4 แล้วคงเหลือ', 6, \Inventory\Posting\Model::balance($p1));

$row = $db->first($T('inventory'), ['id' => $p1]);
tnum('inventory.stock (cache ของตารางเดิม) ตามยอดจริง', 6, (float) $row->stock);

t('สินค้าที่ไม่นับสต๊อกไม่สร้างแถวในสมุดบัญชี',
    \Inventory\Posting\Model::post([
        'inventory_id' => product('S001', 'ค่าบริการ', 0), 'movement_type' => 'sale', 'quantity' => 3
    ]) === 0);

// -----------------------------------------------------------------------------
group('สต๊อกติดลบ');

tthrows('ตัดเกินยอดคงเหลือต้องถูกปฏิเสธพร้อมบอกยอดที่มี', function () use ($p1) {
    \Inventory\Posting\Model::issue(['inventory_id' => $p1, 'quantity' => 99]);
}, 'ไม่พอ');
tnum('ยอดไม่เปลี่ยนหลังถูกปฏิเสธ', 6, \Inventory\Posting\Model::balance($p1));

$p2 = product('P002', 'สินค้ายอมให้ติดลบ', 1, 1);
\Inventory\Posting\Model::issue(['inventory_id' => $p2, 'quantity' => 3]);
tnum('สินค้าที่ตั้ง allow_negative ตัดจนติดลบได้', -3, \Inventory\Posting\Model::balance($p2));

// -----------------------------------------------------------------------------
group('ต้นทุน FIFO — ตัดของที่เข้ามาก่อนออกก่อน');

$p3 = product('P003', 'สินค้าคิดต้นทุน');
\Inventory\Posting\Model::receipt(['inventory_id' => $p3, 'quantity' => 10, 'unit_cost' => 10,
    'occurred_at' => '2026-01-01 09:00:00', 'reference_no' => 'L1']);
\Inventory\Posting\Model::receipt(['inventory_id' => $p3, 'quantity' => 10, 'unit_cost' => 20,
    'occurred_at' => '2026-01-05 09:00:00', 'reference_no' => 'L2']);
tnum('รับเข้าสองชั้น รวม', 20, \Inventory\Posting\Model::balance($p3));

$m = \Inventory\Posting\Model::issue(['inventory_id' => $p3, 'quantity' => 15,
    'occurred_at' => '2026-01-10 09:00:00', 'reference_no' => 'S1']);
$row = $db->first($T('inventory_stock_movement'), ['id' => $m]);
// 10 ชิ้นแรกจากชั้นละ 10 บาท + อีก 5 ชิ้นจากชั้นละ 20 บาท = 200 บาท
tnum('ต้นทุนของที่ขายออก 15 ชิ้น', 200, (float) $row->total_cost);
tnum('ต้นทุนเฉลี่ยต่อหน่วยที่ขาย', 200 / 15, (float) $row->unit_cost);

list($qty, $amount, $unit) = \Inventory\Fifo\Model::value($p3);
tnum('ของที่เหลือ 5 ชิ้น', 5, $qty);
tnum('มูลค่าคงเหลือ (เหลือแต่ชั้นละ 20)', 100, $amount);
tnum('ต้นทุนเฉลี่ยของที่เหลือ', 20, $unit);

$rows = \Inventory\Fifo\Model::createQuery()->select('id', 'remaining_qty')->from('inventory_cost_layer')
    ->where(['inventory_id', $p3])->orderBy('id')->execute(null, 'array')->fetchAll();
tnum('ชั้นเก่าถูกตัดจนหมด', 0, (float) $rows[0]['remaining_qty']);
tnum('ชั้นใหม่เหลือ 5', 5, (float) $rows[1]['remaining_qty']);

// -----------------------------------------------------------------------------
group('ลำดับ FIFO ยึดเวลาที่เกิดรายการ ไม่ใช่ลำดับที่บันทึก');

$p4 = product('P004', 'สินค้าที่บันทึกย้อนหลัง');
\Inventory\Posting\Model::receipt(['inventory_id' => $p4, 'quantity' => 5, 'unit_cost' => 50,
    'occurred_at' => '2026-02-10 09:00:00']);
// ใบรับสินค้าที่เพิ่งคีย์เข้าระบบ แต่ของเข้ามาก่อนใบข้างบน
\Inventory\Posting\Model::receipt(['inventory_id' => $p4, 'quantity' => 5, 'unit_cost' => 30,
    'occurred_at' => '2026-02-01 09:00:00']);
$m = \Inventory\Posting\Model::issue(['inventory_id' => $p4, 'quantity' => 5,
    'occurred_at' => '2026-02-20 09:00:00']);
$row = $db->first($T('inventory_stock_movement'), ['id' => $m]);
tnum('ตัดจากใบที่ของเข้าก่อน (30 บาท) ไม่ใช่ใบที่คีย์ก่อน', 150, (float) $row->total_cost);

// -----------------------------------------------------------------------------
group('กลับรายการเมื่อยกเลิกเอกสาร');

$p5 = product('P005', 'สินค้าทดสอบการยกเลิก');
\Inventory\Posting\Model::receipt(['inventory_id' => $p5, 'quantity' => 10, 'unit_cost' => 15,
    'occurred_at' => '2026-03-01 09:00:00', 'reference_type' => 'order', 'reference_id' => 900010]);
\Inventory\Posting\Model::issue(['inventory_id' => $p5, 'quantity' => 4,
    'occurred_at' => '2026-03-05 09:00:00', 'reference_type' => 'order', 'reference_id' => 900011]);
tnum('ก่อนยกเลิก คงเหลือ', 6, \Inventory\Posting\Model::balance($p5));

t('ยกเลิกใบขาย คืนได้ 1 รายการ', \Inventory\Posting\Model::reverse('order', 900011) === 1);
tnum('หลังยกเลิกใบขาย คงเหลือกลับเป็น', 10, \Inventory\Posting\Model::balance($p5));
list($qty, $amount) = \Inventory\Fifo\Model::value($p5);
tnum('ของคืนกลับเข้าชั้นต้นทุนเดิม ไม่ใช่ชั้นใหม่', 10, $qty);
tnum('มูลค่าคงเหลือกลับมาเท่าเดิม', 150, $amount);

t('ยกเลิกซ้ำไม่ทำให้ยอดเพี้ยน', \Inventory\Posting\Model::reverse('order', 900011) === 0);
tnum('ยอดหลังยกเลิกซ้ำ', 10, \Inventory\Posting\Model::balance($p5));

$count = \Inventory\Posting\Model::createQuery()->select('id')->from('inventory_stock_movement')
    ->where(['reference_id', 900011])->execute(null, 'array')->fetchAll();
t('แถวเดิมยังอยู่ครบ ไม่ได้ถูกลบทิ้ง (สมุดบัญชีลบแถวไม่ได้)', count($count) === 2);

// -----------------------------------------------------------------------------
group('ยอดยกมาตอนอัปเกรดจากระบบเดิม');

$p6 = product('P006', 'สินค้าที่ย้ายมาจากระบบเดิม');
$db->update($T('inventory'), ['id', $p6], ['stock' => 25]);
\Inventory\Posting\Model::opening($p6, 0, 25, 12);
tnum('ยอดยกมาเข้าสมุดบัญชีแล้ว', 25, \Inventory\Posting\Model::balance($p6));
$row = $db->first($T('inventory_stock_movement'), ['inventory_id' => $p6]);
t('ยอดยกมาใช้ชนิด opening', $row->movement_type === 'opening');
t('ยอดยกมาอ้างอิงชนิด opening', $row->reference_type === 'opening');
t('ยอดยกมาเป็นศูนย์ไม่ต้องบันทึกอะไร', \Inventory\Posting\Model::opening($p6, 0, 0) === 0);

// -----------------------------------------------------------------------------
group('คำนวณ cache ใหม่จากข้อมูลดิบ — Posting::rebuild (DESIGN.md ข้อ 5.6)');

t('ทุกทางที่ผ่าน Posting ทำให้ cache ตรงกับข้อมูลดิบเสมอ', \Inventory\Posting\Model::rebuild() === []);

$db->update($T('inventory_stock'), ['inventory_id', $p3], ['qty' => 999]);
$db->update($T('inventory'), ['id', $p3], ['stock' => 7, 'cost' => 1, 'topic' => 'ชื่อที่ถูกแก้มั่ว']);
$db->update($T('inventory_cost_layer'), ['inventory_id', $p3], ['remaining_qty' => 3]);
tnum('ยอดที่ถูกแก้มั่วอ่านได้ 999', 999, \Inventory\Posting\Model::balance($p3));
$diff = \Inventory\Posting\Model::rebuild($p3);
$fields = array_map(function ($row) {
    return $row['table'].'.'.$row['field'];
}, $diff);
sort($fields);
t('รายงานครบทุก cache ที่ไม่ตรงกับข้อมูลดิบ', $fields === ['inventory.cost', 'inventory.stock', 'inventory.topic',
    'inventory_cost_layer.remaining_qty', 'inventory_cost_layer.remaining_qty', 'inventory_stock.qty']);
t('รายงานบอกค่าเดิมและค่าที่ถูก', $diff[0]['table'] === 'inventory_cost_layer' && (float) $diff[0]['cached'] === 3.0);
tnum('ยอดกลับมาตรงกับสมุดบัญชี', 5, \Inventory\Posting\Model::balance($p3));
tnum('ยอดบน variant กลับมาตรง', 5, (float) $db->first($T('inventory'), ['id' => $p3])->stock);
tnum('ต้นทุนบน variant = ชั้นที่จะถูกตัดถัดไป', 20, (float) $db->first($T('inventory'), ['id' => $p3])->cost);
t('ชื่อบน variant กลับมาเป็นชื่อสินค้า', $db->first($T('inventory'), ['id' => $p3])->topic === 'สินค้าคิดต้นทุน');
list($qty) = \Inventory\Fifo\Model::value($p3);
tnum('remaining_qty ของชั้นต้นทุน = รับเข้า − ที่ถูกตัด', 5, $qty);
t('เรียกซ้ำแล้วไม่มีอะไรต้องแก้', \Inventory\Posting\Model::rebuild($p3) === []);

$db->update($T('inventory_stock'), ['inventory_id', $p1], ['qty' => 0, 'reserved_qty' => 4]);
$diff = \Inventory\Posting\Model::rebuild();
t('เรียกทั้งระบบเจอแถวที่เพี้ยนของสินค้าอื่นด้วย (ยอด + ยอดจอง)', array_map(function ($row) {
    return $row['field'];
}, $diff) === ['qty', 'reserved_qty']);
tnum('ยอดจองไม่มีเอกสารรองรับ กลับเป็น 0', 0, \Inventory\Posting\Model::reserved($p1));

// -----------------------------------------------------------------------------
group('ชิ้นของสินค้านับรายชิ้น (count_stock = 2)');

$p7 = product('P007', 'สินค้านับแยกรายชิ้น', 2);
$i1 = \Inventory\Items\Model::addSerial($p7, 'P007-A');
$i2 = \Inventory\Items\Model::addSerial($p7, 'P007-B');
tnum('ลงทะเบียนชิ้นแล้วยังไม่มีของจนกว่าจะรับเข้า', 0, (float) $db->first($T('inventory_items'), ['id' => $i1])->stock);
\Inventory\Posting\Model::receipt(['inventory_id' => $p7, 'inventory_item_id' => $i1, 'quantity' => 1, 'unit_cost' => 400]);
\Inventory\Posting\Model::receipt(['inventory_id' => $p7, 'inventory_item_id' => $i2, 'quantity' => 1, 'unit_cost' => 450]);
tnum('ยอดของชิ้น A', 1, \Inventory\Posting\Model::balance($p7, $i1));
tnum('ยอดของชิ้น B', 1, \Inventory\Posting\Model::balance($p7, $i2));
tnum('ยอดรวมของ variant เท่ากับจำนวนชิ้นในคลัง', 2, (float) $db->first($T('inventory'), ['id' => $p7])->stock);
tnum('inventory_items.stock (cache) ตามยอดจริง', 1, (float) $db->first($T('inventory_items'), ['id' => $i1])->stock);
t('สมุดบัญชีเก็บหมายเลขเครื่องที่ใช้จริง',
    $db->first($T('inventory_stock_movement'), ['inventory_item_id' => $i1])->sku === 'P007-A');

\Inventory\Posting\Model::issue(['inventory_id' => $p7, 'inventory_item_id' => $i1, 'quantity' => 1]);
tnum('ขายชิ้น A แล้วไม่อยู่ในคลัง', 0, \Inventory\Posting\Model::balance($p7, $i1));
tnum('ชิ้น B ไม่ถูกกระทบ', 1, \Inventory\Posting\Model::balance($p7, $i2));
tnum('cache ของชิ้นที่ขายแล้วเป็น 0', 0, (float) $db->first($T('inventory_items'), ['id' => $i1])->stock);

// -----------------------------------------------------------------------------
group('ขายยกลัง/ยกโหล — หน่วยขายที่มีตัวคูณ (count_stock = 1)');

// ⚠️ กติกา: count_stock เป็นตัวตัดสินว่ายอดอยู่ระดับไหน ไม่ใช่ว่าบรรทัดเอกสาร
// อ้างหน่วยขายหรือไม่ — สินค้านับจำนวนมีของกองเดียวที่ variant หน่วยขายเป็นแค่
// บาร์โค้ดที่มีราคาและตัวคูณของตัวเอง ขายปลีกกับขายยกลังจึงกินของกองเดียวกัน
$pbox = product('BOX01', 'น้ำดื่มขวดเล็ก', 1);
$itemPiece = (int) $db->first($T('inventory_items'), ['inventory_id' => $pbox])->id;
\Inventory\Items\Model::savePack($pbox, $itemPiece, ['barcode' => 'BOX01-P', 'unit' => 'ขวด', 'price' => 10]);
$itemBox = \Inventory\Items\Model::savePack($pbox, 0, ['barcode' => 'BOX01-B', 'unit' => 'ลัง', 'cut_stock' => 12, 'price' => 100]);
\Inventory\Posting\Model::opening($pbox, 0, 120, 8);
tnum('ตั้งยอดยกมา 120 ขวด', 120, \Inventory\Posting\Model::balance($pbox, 0));

// ขายยกลัง 2 ลัง = 24 ขวด — ตัดจากกองเดียวกับที่ขายปลีก
\Inventory\Posting\Model::issue([
    'inventory_id' => $pbox, 'inventory_item_id' => $itemBox,
    'quantity' => 24, 'reference_type' => 'order'
]);
tnum('ขาย 2 ลัง (24 ขวด) แล้วเหลือ 96 ขวด', 96, \Inventory\Posting\Model::balance($pbox, 0));

// ขายปลีก 6 ขวด — กินของกองเดียวกัน
\Inventory\Posting\Model::issue([
    'inventory_id' => $pbox, 'inventory_item_id' => $itemPiece,
    'quantity' => 6, 'reference_type' => 'order'
]);
tnum('ขายปลีกอีก 6 ขวด แล้วเหลือ 90 ขวด', 90, \Inventory\Posting\Model::balance($pbox, 0));

// ⚠️ ข้อนี้คือหัวใจ : ถามยอดด้วย id ของหน่วยขายไหนก็ต้องได้ยอดของกองเดียวกัน
tnum('ถามยอดผ่านหน่วยขาย "ลัง" ได้ยอดของกองรวม', 90,
    \Inventory\Posting\Model::balance($pbox, $itemBox));
tnum('ถามยอดผ่านหน่วยขาย "ขวด" ก็ได้ยอดเดียวกัน', 90,
    \Inventory\Posting\Model::balance($pbox, $itemPiece));
tnum('ยอดรวมของสินค้าไม่ซ้ำซ้อน', 90, \Inventory\Posting\Model::totalBalance($pbox));
tnum('มียอดคงเหลือแถวเดียว ไม่แตกเป็นรายหน่วยขาย', 1,
    $db->count($T('inventory_stock'), ['inventory_id', $pbox]));

// หน่วยขายที่ใช้จริงยังตรวจย้อนหลังได้จากรหัสบนแถวสมุดบัญชี (ข้อมูลดิบ)
$skus = [];
foreach ($db->select($T('inventory_stock_movement'), ['inventory_id', $pbox]) as $row) {
    $skus[(string) $row->sku] = true;
}
t('สมุดบัญชียังบอกได้ว่ารายการไหนขายเป็นลัง รายการไหนขายเป็นขวด',
    isset($skus['BOX01-B']) && isset($skus['BOX01-P']));

// ⚠️ แถวยอดคงเหลือระดับ variant เป็นยอดของทั้งกอง (หน่วยฐาน) ไม่ใช่ของหน่วยขาย
// ที่บังเอิญขายเป็นรายการล่าสุด — ถ้าเก็บรหัสของหน่วยขายไว้ แถวจะอ่านได้ว่า
// "90 · BOX01-B (ลัง)" ทั้งที่ยอดนั้นเป็นขวด และสลับไปมาทุกครั้งที่ขายคนละหน่วย
t('แถวยอดรวมถือ sku ของ variant ไม่ใช่รหัสหน่วยขายที่ขายล่าสุด',
    (string) $db->first($T('inventory_stock'), ['inventory_id' => $pbox, 'inventory_item_id' => 0])->sku === 'BOX01');

// ขายเกินของที่มีต้องถูกปฏิเสธ โดยนับเป็นหน่วยฐาน
tthrows('ขายยกลังเกินของที่มีถูกปฏิเสธ', function () use ($pbox, $itemBox) {
    \Inventory\Posting\Model::issue([
        'inventory_id' => $pbox, 'inventory_item_id' => $itemBox,
        'quantity' => 96, 'reference_type' => 'order'
    ]);
}, 'ไม่พอ');

// เอกสารจริง — บรรทัดอ้างหน่วยขาย "ลัง" เอกสารเติมตัวคูณจากทะเบียนเอง (DESIGN.md ข้อ 3.6)
// ไม่รับตัวคูณที่ส่งมา — ฟอร์มเดิมไม่เคยส่งหน่วยขายกลับมา ยิงลังแล้วตัดไปแค่ขวดเดียว
$db->insert($T('inventory_template'), [
    'document_type' => 'SEL', 'status' => 'SEL', 'mode' => 'sell', 'topic' => 'ขายยกลัง',
    'comment' => '', 'stock_in' => 0, 'stock_out' => 1, 'movement_type' => 'sale',
    'published' => 1, 'prefix' => 'SEL%Y%M-', 'number_format' => '%04d',
    'columns' => 'item,topic,quantity,price,discount,amount'
]);
\Inventory\Document\Model::clearCache();
$boxDoc = \Inventory\Document\Model::save(
    ['document_type' => 'SEL', 'order_no' => 'BOX-001', 'order_date' => '2026-07-01 09:00:00', 'total' => 300],
    [[
        'inventory_id' => $pbox, 'inventory_item_id' => $itemBox,
        'code' => 'BOX01-B', 'topic' => 'ยกลัง',
        'quantity' => 3, 'unit' => 'ลัง', 'price' => 100, 'total' => 300,
        'unit_factor' => 1
    ]], 0, 1
);
tnum('ขายผ่านเอกสาร 3 ลัง ตัด 36 ขวด', 54, \Inventory\Posting\Model::balance($pbox, 0));
$boxLine = \Inventory\Document\Model::items($boxDoc)[0];
t('บรรทัดเก็บหน่วยขาย ตัวคูณ และหน่วยฐานที่ตัด (3 ลัง × 12 = 36)',
    $boxLine['inventory_item_id'] == $itemBox && $boxLine['unit_factor'] === 12.0 && $boxLine['base_qty'] === 36.0
    && $boxLine['sku'] === 'BOX01' && $boxLine['code'] === 'BOX01-B');
tnum('ทุนของบรรทัด = Σ allocation ของการตัด 36 ขวด (ทุนยกมา 8)', 36 * 8,
    (float) $db->first($T('order_items'), ['id' => $boxLine['id']])->cost_price);
tnum('ยกเลิกเอกสารแล้วของกลับมาครบ', 90, (function () use ($boxDoc, $pbox) {
    \Inventory\Document\Model::cancel($boxDoc, 1);

    return \Inventory\Posting\Model::balance($pbox, 0);
})());

// -----------------------------------------------------------------------------
group('ทะเบียนสินค้า — สินค้า · variant · หน่วยขาย');

$new = \Inventory\Product\Model::addProduct([
    'product_code' => 'M001', 'topic' => 'สินค้าที่สร้างผ่านโมเดล',
    'category_id' => '3', 'unit' => 'ชิ้น', 'price' => 250, 'count_stock' => 1
]);
t('สร้างสินค้าได้ คืน id ของ variant', $new > 0);
$variant = $db->first($T('inventory'), ['id' => $new]);
$newProductId = (int) $variant->product_id;
$prod = $db->first($T('inventory_product'), ['id' => $newProductId]);
t('สินค้าได้รหัส หน่วยฐาน และวิธีนับ', $prod->product_code === 'M001' && $prod->unit === 'ชิ้น' && (int) $prod->count_stock === 1);
t('variant ตั้งต้นใช้ sku = รหัสสินค้า ชื่อเต็มเท่าชื่อสินค้า', $variant->sku === 'M001' && $variant->topic === 'สินค้าที่สร้างผ่านโมเดล');
tnum('ราคาต่อหน่วยฐานอยู่ที่ variant', 250, (float) $variant->price);
$newItems = \Inventory\Product\Model::items($new);
t('ได้หน่วยขายฐาน ×1 บาร์โค้ด = sku ยิงขายได้ทันที', count($newItems) === 1
    && $newItems[0]['barcode'] === 'M001' && (float) $newItems[0]['cut_stock'] === 1.0 && $newItems[0]['serial_no'] === null);

$auto = \Inventory\Product\Model::addProduct(['topic' => 'สินค้าไม่กรอกรหัส']);
t('ไม่กรอกรหัสสินค้า ระบบออกให้ตามรูปแบบในค่ากำหนด',
    preg_match('/^P\d{4}/', (string) $db->first($T('inventory'), ['id' => $auto])->sku) === 1);

$service = \Inventory\Product\Model::addProduct(['product_code' => 'M002', 'topic' => 'ค่าบริการ', 'count_stock' => 0]);
tthrows('ไม่กรอกชื่อสินค้า', function () {
    \Inventory\Product\Model::addProduct(['product_code' => 'M003']);
}, 'กรุณากรอกชื่อสินค้า');
tthrows('รหัสสินค้าซ้ำ', function () {
    \Inventory\Product\Model::addProduct(['product_code' => 'M001', 'topic' => 'ซ้ำ']);
}, 'ถูกใช้ไปแล้ว');
tthrows('SKU ซ้ำกับ variant ของสินค้าอื่น', function () {
    \Inventory\Product\Model::addProduct(['product_code' => 'M009', 'topic' => 'sku ซ้ำ', 'sku' => 'M001']);
}, 'ถูกใช้ไปแล้ว');
t('ถูกปฏิเสธแล้วไม่ทิ้งสินค้าครึ่งตัวไว้ (transaction)', $db->first($T('inventory_product'), ['product_code' => 'M009']) === null);

\Inventory\Product\Model::saveProduct($newProductId, ['topic' => 'ชื่อใหม่', 'is_active' => 0]);
$variant = $db->first($T('inventory'), ['id' => $new]);
t('แก้ชื่อสินค้าแล้วชื่อเต็มของ variant ตาม', $variant->topic === 'ชื่อใหม่');
tnum('แก้เฉพาะคอลัมน์ที่ส่งมา ราคาเดิมไม่หาย', 250, (float) $variant->price);
t('ปิดการใช้งานสินค้าได้', (int) $db->first($T('inventory_product'), ['id' => $newProductId])->is_active === 0);

$box = \Inventory\Items\Model::savePack($new, 0, ['unit' => 'กล่อง', 'cut_stock' => 12]);
$row = $db->first($T('inventory_items'), ['id' => $box]);
t('ไม่กรอกบาร์โค้ด ระบบออกให้เป็น sku-ตัวคูณ', $row->barcode === 'M001-12');
tnum('ตัวคูณของหน่วยขาย', 12, (float) $row->cut_stock);
t('ราคาว่าง = ใช้ราคา variant × ตัวคูณ', $row->price === null);
tthrows('หน่วยขายที่ไม่ใช่ ×1 ต้องมีชื่อหน่วย', function () use ($new) {
    \Inventory\Items\Model::savePack($new, 0, ['cut_stock' => 6]);
}, 'ชื่อหน่วยขาย');
tthrows('บาร์โค้ดซ้ำ', function () use ($new) {
    \Inventory\Items\Model::savePack($new, 0, ['barcode' => 'M001-12', 'unit' => 'แพ็ค', 'cut_stock' => 2]);
}, 'ถูกใช้ไปแล้ว');
tthrows('สินค้านับจำนวนไม่มีหมายเลขเครื่อง', function () use ($new) {
    \Inventory\Items\Model::addSerial($new);
}, 'ไม่ได้นับรายชิ้น');
t('หน่วยขายของสินค้าอ่านกลับมาได้', count(\Inventory\Product\Model::items($new)) === 2);

\Inventory\Product\Model::openingBalance($new, 0, 30, 100);
tnum('ตั้งยอดเปิดผ่านสมุดบัญชี', 30, \Inventory\Posting\Model::balance($new));
$product = \Inventory\Product\Model::get($new);
tnum('get() คืนยอดคงเหลือจริงจากสมุดบัญชี', 30, $product['balance']);
t('get() รวมข้อมูลของสินค้าแม่มาด้วย', $product['product_code'] === 'M001' && (int) $product['count_stock'] === 1);
tthrows('เปลี่ยนวิธีนับของสินค้าที่เคลื่อนไหวแล้วไม่ได้', function () use ($newProductId) {
    \Inventory\Product\Model::saveProduct($newProductId, ['count_stock' => 2]);
}, 'กติกาเดิม');
tthrows('เปลี่ยนหน่วยฐานของสินค้าที่เคลื่อนไหวแล้วไม่ได้', function () use ($newProductId) {
    \Inventory\Product\Model::saveProduct($newProductId, ['unit' => 'โหล']);
}, 'กติกาเดิม');
\Inventory\Product\Model::saveProduct($newProductId, ['topic' => 'ชื่อใหม่', 'stock' => 999]);
tnum('แก้ไขสินค้าเขียนทับยอดคงเหลือไม่ได้', 30, \Inventory\Posting\Model::balance($new));

// หน่วยขายที่ถูกใช้แล้ว — บรรทัดเก่าต้องอ่านความหมายเดิมได้เสมอ
\Inventory\Posting\Model::issue(['inventory_id' => $new, 'inventory_item_id' => $box, 'quantity' => 12]);
tthrows('เปลี่ยนตัวคูณของหน่วยขายที่ถูกใช้แล้วไม่ได้', function () use ($new, $box) {
    \Inventory\Items\Model::savePack($new, $box, ['cut_stock' => 24]);
}, 'ปิดการใช้งาน');
\Inventory\Items\Model::savePack($new, $box, ['is_active' => 0, 'price' => 90]);
t('ปิดการใช้งานหน่วยขายที่ถูกใช้แล้วได้', (int) $db->first($T('inventory_items'), ['id' => $box])->is_active === 0);
tthrows('ลบหน่วยขายที่ถูกใช้แล้วไม่ได้', function () use ($box) {
    \Inventory\Items\Model::remove($box);
}, 'ปิดการใช้งานแทน');

tthrows('ลบสินค้าที่มีประวัติการเคลื่อนไหวไม่ได้', function () use ($newProductId) {
    \Inventory\Product\Model::remove($newProductId);
}, 'ปิดการใช้งานสินค้าแทน');
$serviceProductId = (int) $db->first($T('inventory'), ['id' => $service])->product_id;
t('ลบสินค้าที่ยังไม่เคยเคลื่อนไหวได้', \Inventory\Product\Model::remove($serviceProductId) === true);
t('ลบแล้วไม่เหลือ variant และหน่วยขาย', $db->first($T('inventory'), ['id' => $service]) === null
    && $db->first($T('inventory_items'), ['inventory_id' => $service]) === null);

// -----------------------------------------------------------------------------
group('แบบข้อมูลตามความจริง — ไข่ · เสื้อ · โน้ตบุ๊ก (DESIGN.md ข้อ 5.5)');

// ไข่ไก่ : หน่วยฐาน ฟอง · variant ตามเบอร์ · บาร์โค้ด ฟอง ×1 / โหล ×12 / แผง ×30
$egg = \Inventory\Product\Model::addProduct([
    'product_code' => 'EGG', 'topic' => 'ไข่ไก่', 'unit' => 'ฟอง', 'option1_name' => 'เบอร์',
    'sku' => 'EGG-2', 'option1' => '2', 'price' => 4, 'barcode' => '8850000000011'
]);
$eggProductId = (int) $db->first($T('inventory'), ['id' => $egg])->product_id;
$eggPiece = (int) $db->first($T('inventory_items'), ['barcode' => '8850000000011'])->id;
$eggDozen = \Inventory\Items\Model::savePack($egg, 0, ['barcode' => '8850000000028', 'unit' => 'โหล', 'cut_stock' => 12, 'price' => 45]);
$eggTray = \Inventory\Items\Model::savePack($egg, 0, ['barcode' => '8850000000035', 'unit' => 'แผง', 'cut_stock' => 30]);
$egg3 = \Inventory\Product\Model::saveVariant($eggProductId, 0, ['sku' => 'EGG-3', 'option1' => '3', 'price' => 3.5]);
t('ชื่อเต็มของ variant = ชื่อสินค้า + ค่าตัวเลือก', $db->first($T('inventory'), ['id' => $egg])->topic === 'ไข่ไก่ 2');
t('ไข่เบอร์ 3 เป็นกองสต๊อกแยกพร้อมหน่วยขายฐานของตัวเอง', count(\Inventory\Product\Model::items($egg3)) === 1);
tthrows('ค่าตัวเลือกชุดเดียวกันในสินค้าเดียวซ้ำไม่ได้', function () use ($eggProductId) {
    \Inventory\Product\Model::saveVariant($eggProductId, 0, ['sku' => 'EGG-2B', 'option1' => '2']);
}, 'มีแบบ');

\Inventory\Posting\Model::receipt(['inventory_id' => $egg, 'inventory_item_id' => $eggTray, 'quantity' => 300,
    'unit_cost' => 3.5, 'occurred_at' => '2026-10-01 08:00:00', 'reference_type' => 'adjustment']);
\Inventory\Posting\Model::receipt(['inventory_id' => $egg, 'inventory_item_id' => $eggTray, 'quantity' => 150,
    'unit_cost' => 3.8, 'occurred_at' => '2026-10-02 08:00:00', 'reference_type' => 'adjustment']);
$dozenSale = \Inventory\Posting\Model::issue(['inventory_id' => $egg, 'inventory_item_id' => $eggDozen, 'quantity' => 12,
    'occurred_at' => '2026-10-03 09:00:00', 'reference_type' => 'adjustment']);
\Inventory\Posting\Model::issue(['inventory_id' => $egg, 'inventory_item_id' => $eggPiece, 'quantity' => 3,
    'occurred_at' => '2026-10-03 10:00:00', 'reference_type' => 'adjustment']);
tnum('คงเหลือเป็นหน่วยฐาน (ฟอง) — 300 + 150 − 12 − 3', 435, \Inventory\Posting\Model::balance($egg));
tnum('inventory.stock (cache) ตรงกับสมุดบัญชี', 435, (float) $db->first($T('inventory'), ['id' => $egg])->stock);
tnum('ยอดคงเหลือแถวเดียวต่อกอง ไม่แตกตามหน่วยขาย', 1, $db->count($T('inventory_stock'), ['inventory_id', $egg]));
t('แถวยอดถือ sku ของ variant', (string) $db->first($T('inventory_stock'), ['inventory_id' => $egg])->sku === 'EGG-2');
$eggCodes = [];
foreach ($db->select($T('inventory_stock_movement'), ['inventory_id', $egg]) as $row) {
    $eggCodes[(string) $row->sku] = true;
}
t('สมุดบัญชีบอกได้ว่ารับเป็นแผง ขายเป็นโหลและเป็นฟอง',
    isset($eggCodes['8850000000035']) && isset($eggCodes['8850000000028']) && isset($eggCodes['8850000000011']));
tnum('ต้นทุนของโหลที่ขายมาจากชั้นแรก (12 × 3.50)', 42,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $dozenSale])->total_cost);
tnum('ชั้นต้นทุนเหลือ 285 + 150 ฟอง', 435, \Inventory\Fifo\Model::value($egg)[0]);
tnum('ไข่เบอร์ 3 ไม่ถูกกระทบ', 0, \Inventory\Posting\Model::balance($egg3));

// เสื้อ : สองตัวเลือก (สี · ไซซ์) แต่ละแบบมีสต๊อกของตัวเอง แปลงกันไม่ได้
$shirtRedS = \Inventory\Product\Model::addProduct([
    'product_code' => 'TS', 'topic' => 'เสื้อยืด', 'unit' => 'ตัว', 'option1_name' => 'สี', 'option2_name' => 'ไซซ์',
    'sku' => 'TS-RED-S', 'option1' => 'แดง', 'option2' => 'S', 'price' => 199
]);
$shirtProductId = (int) $db->first($T('inventory'), ['id' => $shirtRedS])->product_id;
$shirtRedM = \Inventory\Product\Model::saveVariant($shirtProductId, 0, ['sku' => 'TS-RED-M', 'option1' => 'แดง', 'option2' => 'M', 'price' => 199]);
$shirtBlkS = \Inventory\Product\Model::saveVariant($shirtProductId, 0, ['sku' => 'TS-BLK-S', 'option1' => 'ดำ', 'option2' => 'S', 'price' => 199]);
t('ชื่อเต็มของแบบที่มีสองตัวเลือก', $db->first($T('inventory'), ['id' => $shirtRedM])->topic === 'เสื้อยืด แดง / M');
foreach ([[$shirtRedS, 10], [$shirtRedM, 5], [$shirtBlkS, 8]] as $_in) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $_in[0], 'quantity' => $_in[1], 'unit_cost' => 80, 'reference_type' => 'adjustment']);
}
\Inventory\Posting\Model::issue(['inventory_id' => $shirtRedS, 'quantity' => 3, 'reference_type' => 'adjustment']);
\Inventory\Posting\Model::issue(['inventory_id' => $shirtBlkS, 'quantity' => 8, 'reference_type' => 'adjustment']);
$byColor = [];
foreach (\Kotchasan\Model::createQuery()->select('option1')->selectRaw('SUM(`stock`) AS `qty`')->from('inventory')
    ->where(['product_id', $shirtProductId])->groupBy('option1')->execute(null, 'array')->fetchAll() as $row) {
    $byColor[$row['option1']] = (float) $row['qty'];
}
t('รวมยอดตามสีได้ — แดงเหลือ 12 ดำหมดแล้ว', $byColor === ['ดำ' => 0.0, 'แดง' => 12.0]);

// โน้ตบุ๊ก : นับรายชิ้น · หน่วยขาย = บาร์โค้ดรุ่น · ชิ้น = หมายเลขเครื่อง · ต้นทุนรายเครื่อง
$nb = \Inventory\Product\Model::addProduct([
    'product_code' => 'NB-A550', 'topic' => 'ASUS A550JX', 'unit' => 'เครื่อง', 'count_stock' => 2,
    'option1_name' => 'RAM', 'sku' => 'NB-A550-8', 'option1' => '8GB', 'price' => 29500, 'barcode' => '8850001234567'
]);
tthrows('สินค้ารายชิ้นมีหน่วยขายตัวคูณอื่นไม่ได้', function () use ($nb) {
    \Inventory\Items\Model::savePack($nb, 0, ['unit' => 'แพ็ค', 'cut_stock' => 2]);
}, 'ทีละหนึ่งชิ้น');
$sn1 = \Inventory\Items\Model::addSerial($nb, 'F7N0CV1');
$sn2 = \Inventory\Items\Model::addSerial($nb);
t('ไม่กรอกหมายเลขเครื่อง ระบบออกให้ต่อจากที่มี', $db->first($T('inventory_items'), ['id' => $sn2])->serial_no === 'NB-A550-8-0002');
tthrows('หมายเลขเครื่องซ้ำกับบาร์โค้ดไม่ได้ (ยิงแล้วจะไม่รู้ว่าเป็นอะไร)', function () use ($nb) {
    \Inventory\Items\Model::addSerial($nb, '8850001234567');
}, 'ถูกใช้ไปแล้ว');
\Inventory\Posting\Model::receipt(['inventory_id' => $nb, 'inventory_item_id' => $sn1, 'quantity' => 1, 'unit_cost' => 25000, 'reference_type' => 'adjustment']);
\Inventory\Posting\Model::receipt(['inventory_id' => $nb, 'inventory_item_id' => $sn2, 'quantity' => 1, 'unit_cost' => 24000, 'reference_type' => 'adjustment']);
tnum('คงเหลือ 2 เครื่อง', 2, (float) $db->first($T('inventory'), ['id' => $nb])->stock);
$nbSale = \Inventory\Posting\Model::issue(['inventory_id' => $nb, 'inventory_item_id' => $sn1, 'quantity' => 1, 'reference_type' => 'adjustment']);
tnum('ขายเครื่อง F7N0CV1 ได้ต้นทุนของเครื่องนั้น ไม่ใช่ของเครื่องอื่น', 25000,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $nbSale])->total_cost);
tnum('เครื่องที่ขายแล้วไม่อยู่ในคลัง', 0, (float) $db->first($T('inventory_items'), ['id' => $sn1])->stock);
tnum('อีกเครื่องยังอยู่', 1, (float) $db->first($T('inventory_items'), ['id' => $sn2])->stock);

// -----------------------------------------------------------------------------
group('รู้ตัวตน ใช้ของตัวตนนั้น · ไม่รู้ ใช้วิธีตัดที่ตั้งไว้ (DESIGN.md ข้อ 1.3 · 5.1–5.3)');

$cfgReader = new class extends \Kotchasan\KBase {
    public static function get()
    {
        return self::$cfg;
    }
};
$siteCfg = $cfgReader::get();
$methodBefore = isset($siteCfg->stock_method) ? $siteCfg->stock_method : null;

// นมกล่อง : ล็อต A หมดอายุช้า เข้าก่อน · ล็อต B หมดอายุเร็ว เข้าทีหลัง · ชั้นที่ไม่มีล็อต
$milk = product('MILK', 'นมกล่อง');
\Inventory\Posting\Model::receipt(['inventory_id' => $milk, 'quantity' => 10, 'unit_cost' => 10,
    'lot_no' => 'A', 'expiry_date' => '2026-12-31', 'occurred_at' => '2026-04-01 09:00:00']);
\Inventory\Posting\Model::receipt(['inventory_id' => $milk, 'quantity' => 10, 'unit_cost' => 12,
    'lot_no' => ' B ', 'expiry_date' => '2026-11-30', 'occurred_at' => '2026-04-02 09:00:00']);
\Inventory\Posting\Model::receipt(['inventory_id' => $milk, 'quantity' => 5, 'unit_cost' => 11,
    'lot_no' => '', 'occurred_at' => '2026-04-03 09:00:00']);
$layers = \Inventory\Fifo\Model::createQuery()->select('lot_no', 'expiry_date')->from('inventory_cost_layer')
    ->where(['inventory_id', $milk])->orderBy('id')->execute(null, 'array')->fetchAll();
t('ชั้นต้นทุนเก็บเลขล็อตและวันหมดอายุของการรับเข้า (ตัดช่องว่าง · ว่าง = NULL)',
    $layers[0]['lot_no'] === 'A' && $layers[0]['expiry_date'] === '2026-12-31'
    && $layers[1]['lot_no'] === 'B' && $layers[2]['lot_no'] === null && $layers[2]['expiry_date'] === null);
tnum('ต้นทุนบน variant = ชั้นแรกตาม FIFO (ล็อต A)', 10, (float) $db->first($T('inventory'), ['id' => $milk])->cost);

$m = \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 3]);
tnum('ไม่ระบุล็อต ตัดตามวิธีของระบบ (FIFO → ล็อต A)', 30,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $m])->total_cost);
$m = \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 2, 'lot_no' => 'B']);
tnum('ระบุล็อต ตัดจากล็อตนั้นเท่านั้น (B @12)', 24,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $m])->total_cost);
tthrows('ล็อตที่ไม่เคยรับเข้า = รหัสผิด ไม่ใช่ไปตัดล็อตอื่นแทน', function () use ($milk) {
    \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 1, 'lot_no' => 'Z']);
}, 'ไม่พบล็อต Z');
tthrows('ล็อตที่เหลือไม่พอถูกปฏิเสธ ถึงยอดรวมจะพอ', function () use ($milk) {
    \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 9, 'lot_no' => 'B']);
}, 'ล็อต B ของ นมกล่อง คงเหลือ 8');
tthrows('วันหมดอายุต้องเป็นวันที่จริง', function () use ($milk) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $milk, 'quantity' => 1, 'expiry_date' => '2026-02-30']);
}, 'วันหมดอายุ');
tthrows('เลขล็อตยาวเกินคอลัมน์ถูกปฏิเสธ ไม่ใช่ถูกตัดเงียบ ๆ', function () use ($milk) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $milk, 'quantity' => 1, 'lot_no' => str_repeat('L', 51)]);
}, 'ไม่เกิน 50');

// FEFO : หมดอายุก่อนออกก่อน · ชั้นที่ไม่มีวันหมดอายุไว้ท้าย
$siteCfg->stock_method = 'fefo';
t('วิธีตัดอ่านจากค่าตั้ง stock_method', \Inventory\Fifo\Model::method() === 'fefo');
\Inventory\Posting\Model::rebuild($milk);
tnum('ต้นทุนถัดไปตาม FEFO = ล็อต B (หมดอายุก่อน)', 12, (float) $db->first($T('inventory'), ['id' => $milk])->cost);
$m = \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 10]);
tnum('FEFO ตัดล็อต B ที่เหลือ 8 @12 แล้วต่อด้วยล็อต A 2 @10', 116,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $m])->total_cost);
$m = \Inventory\Posting\Model::issue(['inventory_id' => $milk, 'quantity' => 6]);
tnum('ชั้นที่ไม่มีวันหมดอายุถูกตัดหลังสุด (A 5 @10 + ไม่มีล็อต 1 @11)', 61,
    (float) $db->first($T('inventory_stock_movement'), ['id' => $m])->total_cost);
tnum('เหลือเฉพาะชั้นที่ไม่มีล็อต', 4, \Inventory\Posting\Model::balance($milk));
if ($methodBefore === null) {
    unset($siteCfg->stock_method);
} else {
    $siteCfg->stock_method = $methodBefore;
}
t('ค่าตั้งเริ่มต้นคือ FIFO', \Inventory\Fifo\Model::method() === 'fifo');

// ต้นทุนบน variant เมื่อของหมด = ชั้นที่รับล่าสุด ไม่นับใบรับที่ถูกยกเลิก
$last = product('LASTCOST', 'สินค้าขายหมด');
\Inventory\Posting\Model::receipt(['inventory_id' => $last, 'quantity' => 5, 'unit_cost' => 7,
    'occurred_at' => '2026-06-01 09:00:00']);
\Inventory\Posting\Model::receipt(['inventory_id' => $last, 'quantity' => 5, 'unit_cost' => 9,
    'occurred_at' => '2026-06-02 09:00:00']);
\Inventory\Posting\Model::issue(['inventory_id' => $last, 'quantity' => 10]);
tnum('ของหมด ต้นทุนที่แสดง = ชั้นที่รับล่าสุด', 9, (float) $db->first($T('inventory'), ['id' => $last])->cost);
\Inventory\Posting\Model::receipt(['inventory_id' => $last, 'quantity' => 3, 'unit_cost' => 15,
    'reference_type' => 'order', 'reference_id' => 900040, 'occurred_at' => '2026-06-03 09:00:00']);
tnum('รับเข้าใหม่ ต้นทุนที่แสดงตามชั้นใหม่', 15, (float) $db->first($T('inventory'), ['id' => $last])->cost);
\Inventory\Posting\Model::reverse('order', 900040);
tnum('ยกเลิกใบรับนั้น ต้นทุนที่แสดงกลับไปชั้นก่อนหน้า', 9, (float) $db->first($T('inventory'), ['id' => $last])->cost);
$service = product('COSTSVC', 'ค่าแรงติดตั้ง', 0);
$db->update($T('inventory'), ['id', $service], ['cost' => 250]);
t('ไม่เคยรับของเข้า คงต้นทุนที่ผู้ใช้กรอกไว้', \Inventory\Posting\Model::rebuild($service) === []);

// นับรายชิ้น : ทุกรายการต้องเป็นเครื่องที่ระบุตัวได้ ทีละหนึ่งเครื่อง
$pc = \Inventory\Product\Model::addProduct([
    'product_code' => 'PC-X', 'topic' => 'คอมพิวเตอร์ X', 'unit' => 'เครื่อง', 'count_stock' => 2,
    'price' => 20000, 'barcode' => '8850009999991'
]);
$pcPack = (int) $db->first($T('inventory_items'), ['barcode' => '8850009999991'])->id;
$pcA = \Inventory\Items\Model::addSerial($pc, 'PCX-A');
$pcB = \Inventory\Items\Model::addSerial($pc, 'PCX-B');
tthrows('รับเข้าโดยไม่ระบุเครื่องไม่ได้', function () use ($pc) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'quantity' => 1, 'unit_cost' => 1]);
}, 'ต้องระบุหมายเลขเครื่อง');
tthrows('บาร์โค้ดรุ่นไม่ใช่เครื่อง — รับเข้าด้วยบาร์โค้ดรุ่นไม่ได้', function () use ($pc, $pcPack) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $pcPack, 'quantity' => 1]);
}, 'ต้องระบุหมายเลขเครื่อง');
tthrows('เครื่องเดียวรับทีละ 2 ไม่ได้', function () use ($pc, $pcA) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $pcA, 'quantity' => 2]);
}, 'ทีละหนึ่งเครื่อง');
tthrows('อ้างเครื่องของสินค้าอื่นไม่ได้', function () use ($pc, $sn2) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $sn2, 'quantity' => 1]);
}, 'ไม่ใช่ของสินค้า');
\Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $pcA, 'quantity' => 1,
    'unit_cost' => 20000, 'occurred_at' => '2026-05-02 09:00:00']);
\Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $pcB, 'quantity' => 1,
    'unit_cost' => 19000, 'occurred_at' => '2026-05-01 09:00:00']);
tthrows('รับเครื่องที่อยู่ในคลังแล้วซ้ำไม่ได้', function () use ($pc, $pcA) {
    \Inventory\Posting\Model::receipt(['inventory_id' => $pc, 'inventory_item_id' => $pcA, 'quantity' => 1]);
}, 'อยู่ในคลังแล้ว');
$next = \Inventory\Fifo\Model::nextPiece($pc);
t('เครื่องถัดไปที่ควรขาย = เครื่องที่รับเข้าก่อน (B) ไม่ใช่ที่ลงทะเบียนก่อน', $next !== null && $next['id'] === $pcB);
tnum('ต้นทุนบน variant = ทุนของเครื่องนั้น', 19000, (float) $db->first($T('inventory'), ['id' => $pc])->cost);
$scan = \Inventory\Products\Model::detail('8850009999991');
t('ยิงบาร์โค้ดรุ่น บรรทัดได้เครื่องที่ระบบเลือกพร้อมหมายเลข',
    $scan['inventory_item_id'] === $pcB && $scan['code'] === 'PCX-B');
tthrows('สมุดบัญชีไม่เลือกเครื่องให้เอง — ตัดด้วยบาร์โค้ดรุ่นไม่ได้', function () use ($pc, $pcPack) {
    \Inventory\Posting\Model::issue(['inventory_id' => $pc, 'inventory_item_id' => $pcPack, 'quantity' => 1]);
}, 'ต้องระบุหมายเลขเครื่อง');
$m = \Inventory\Posting\Model::issue(['inventory_id' => $pc, 'inventory_item_id' => $pcB, 'quantity' => 1,
    'reference_type' => 'order', 'reference_id' => 900041]);
tnum('ขายเครื่อง B ได้ทุนของเครื่อง B', 19000, (float) $db->first($T('inventory_stock_movement'), ['id' => $m])->total_cost);
tthrows('ขายเครื่องที่ขายไปแล้วซ้ำไม่ได้', function () use ($pc, $pcB) {
    \Inventory\Posting\Model::issue(['inventory_id' => $pc, 'inventory_item_id' => $pcB, 'quantity' => 1]);
}, 'ไม่อยู่ในคลัง');
t('เครื่องถัดไปเลื่อนเป็น A', \Inventory\Fifo\Model::nextPiece($pc)['id'] === $pcA);
\Inventory\Posting\Model::reverse('order', 900041);
tnum('ยกเลิกการขาย เครื่อง B กลับเข้าคลัง', 1, (float) $db->first($T('inventory_items'), ['id' => $pcB])->stock);
tnum('กลับเข้าชั้นเดิมด้วยทุนเดิม', 19000, (float) $db->first($T('inventory'), ['id' => $pc])->cost);
t('cache ของกลุ่มนี้ตรงกับข้อมูลดิบ', \Inventory\Posting\Model::rebuild() === []);

// -----------------------------------------------------------------------------
group('ชั้นเอกสาร — เอกสารสั่งเดินสต๊อกผ่าน Posting API เท่านั้น');

// ⚠️ ฐานที่ติดตั้งใหม่มีแม่แบบตั้งต้นมาแล้วจาก database.sql
// ชุดทดสอบต้องคุมแม่แบบเองทั้งหมด (มีตัวที่ตั้งค่าผิดไว้ทดสอบการปฏิเสธด้วย)
// จึงล้างของเดิมก่อน — ฐานนี้เป็นฐานทดสอบของชุดนี้เอง ไม่กระทบใคร
$db->delete($T('inventory_template'), ['id', '>', 0], 0);

// แม่แบบเอกสารตามทะเบียนกลาง (หัวข้อ 4.6) — เท่าที่ชุดนี้ใช้
// oas ไม่มีใบสั่งขาย (SO) และใบคืนสินค้าไปยังผู้ขาย (RTS) — มีเฉพาะ oms.in.th
foreach ([
    ['QUO', 'sell', 'ใบเสนอราคา', 0, 0, null],
    ['IN', 'buy', 'ใบรับสินค้า', 1, 0, 'purchase'],
    ['OUT', 'sell', 'ใบเสร็จรับเงิน', 0, 1, 'sale'],
    // ใบคืนสินค้า = ลูกค้าคืนของ (ฝั่งขาย)
    ['RET', 'sell', 'ใบคืนสินค้าจากลูกค้า', 1, 0, 'return_in'],
    ['BAD', 'sell', 'แม่แบบที่ตั้งค่าผิด', 0, 1, 'purchase']
] as $tpl) {
    $db->insert($T('inventory_template'), [
        'document_type' => $tpl[0], 'status' => $tpl[0], 'mode' => $tpl[1], 'topic' => $tpl[2],
        'comment' => '', 'stock_in' => $tpl[3], 'stock_out' => $tpl[4],
        'movement_type' => $tpl[5], 'published' => 1,
        'prefix' => $tpl[0].'%Y%M-', 'number_format' => '%04d',
        'columns' => 'item,topic,quantity,price,discount,amount'
    ]);
}
\Inventory\Document\Model::clearCache();
t('อ่านแม่แบบเอกสารได้ครบ', count(\Inventory\Document\Model::templates()) === 5);

$p8 = product('P008', 'สินค้าที่ขายผ่านเอกสาร');
$line = function ($qty, $price) use ($p8) {
    return ['inventory_id' => $p8, 'code' => 'P008',
        'topic' => 'สินค้าที่ขายผ่านเอกสาร', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => $price, 'total' => $qty * $price];
};

$quo = \Inventory\Document\Model::save(
    ['document_type' => 'QUO', 'order_no' => 'QUO-001', 'order_date' => '2026-04-01 09:00:00', 'total' => 500],
    [$line(5, 100)], 0, 1
);
tnum('ใบเสนอราคาไม่แตะสต๊อกเลย', 0, \Inventory\Posting\Model::balance($p8));
t('แต่บรรทัดรายการถูกบันทึกไว้', count(\Inventory\Document\Model::items($quo)) === 1);

$in = \Inventory\Document\Model::save(
    ['document_type' => 'IN', 'order_no' => 'IN-001', 'order_date' => '2026-04-02 09:00:00', 'total' => 2000],
    [$line(20, 100)], 0, 1
);
tnum('ใบรับสินค้ารับเข้า 20', 20, \Inventory\Posting\Model::balance($p8));

$out = \Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'order_no' => 'OUT-001', 'order_date' => '2026-04-03 09:00:00', 'total' => 750],
    [$line(5, 150)], 0, 1
);
tnum('ใบเสร็จตัดออก 5 เหลือ', 15, \Inventory\Posting\Model::balance($p8));

// กดบันทึกซ้ำโดยไม่เปลี่ยนอะไร — ยอดต้องเท่าเดิม ไม่ใช่ตัดซ้ำ
\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'order_no' => 'OUT-001', 'order_date' => '2026-04-03 09:00:00', 'total' => 750],
    [$line(5, 150)], $out, 1
);
tnum('กดบันทึกเอกสารเดิมซ้ำ ยอดไม่เปลี่ยน', 15, \Inventory\Posting\Model::balance($p8));

// แก้จำนวนในเอกสาร — ผลของเดิมต้องถูกถอนออกก่อนลงใหม่
\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'order_no' => 'OUT-001', 'order_date' => '2026-04-03 09:00:00', 'total' => 1200],
    [$line(8, 150)], $out, 1
);
tnum('แก้จำนวนจาก 5 เป็น 8 แล้วยอดเหลือ', 12, \Inventory\Posting\Model::balance($p8));

// ลบบรรทัดออกจากเอกสาร
\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'order_no' => 'OUT-001', 'order_date' => '2026-04-03 09:00:00', 'total' => 0],
    [], $out, 1
);
tnum('ลบบรรทัดออกหมด ยอดกลับเป็น', 20, \Inventory\Posting\Model::balance($p8));

\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'order_no' => 'OUT-001', 'order_date' => '2026-04-03 09:00:00', 'total' => 900],
    [$line(6, 150)], $out, 1
);
tnum('ใส่บรรทัดกลับเข้าไปใหม่', 14, \Inventory\Posting\Model::balance($p8));

\Inventory\Document\Model::cancel($out, 1);
tnum('ยกเลิกใบเสร็จ ยอดกลับไปเป็น', 20, \Inventory\Posting\Model::balance($p8));
$row = $db->first($T('orders'), ['id' => $out]);
t('ใบที่ยกเลิกยังอยู่ เปิดดูและพิมพ์ได้', $row && $row->document_status === 'cancelled');

\Inventory\Document\Model::remove($in, 1);
tnum('ลบใบรับสินค้าทิ้ง ยอดกลับเป็นศูนย์ (ระบบเดิมค้างค่าเดิมไว้)', 0, \Inventory\Posting\Model::balance($p8));
t('บรรทัดของเอกสารที่ลบไปแล้วหายตามไปด้วย', count(\Inventory\Document\Model::items($in)) === 0);

tthrows('ชนิดเอกสารที่ไม่มีแม่แบบต้องถูกปฏิเสธ', function () use ($line) {
    \Inventory\Document\Model::save(['document_type' => 'ZZZ', 'order_no' => 'X-1'], [$line(1, 10)], 0, 1);
}, 'ไม่พบแม่แบบ');
tthrows('แม่แบบที่ตั้งชนิดการเคลื่อนไหวขัดกับทิศทางของตัวเอง', function () use ($line) {
    \Inventory\Document\Model::save(['document_type' => 'BAD', 'order_no' => 'X-2'], [$line(1, 10)], 0, 1);
}, 'ขัดกับทิศทางของเอกสาร');

// -----------------------------------------------------------------------------
group('เอกสารร่างต้องไม่แตะสต๊อก (ตะกร้าที่พักไว้ของหน้าร้าน)');

$pdraft = product('DRAFT01', 'สินค้าสำหรับทดสอบเอกสารร่าง');
\Inventory\Posting\Model::opening($pdraft, 0, 50, 10);
$draftLine = function ($qty) use ($pdraft) {
    return ['inventory_id' => $pdraft, 'code' => 'DRAFT01',
        'topic' => 'สินค้าสำหรับทดสอบเอกสารร่าง', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => 20, 'total' => $qty * 20];
};

$draft = \Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'document_status' => 'draft', 'order_no' => 'DRAFT-001',
        'order_date' => '2026-06-01 09:00:00', 'total' => 200],
    [$draftLine(10)], 0, 1
);
tnum('บันทึกเอกสารร่างแล้วสต๊อกไม่ขยับ', 50, \Inventory\Posting\Model::balance($pdraft));
t('แต่บรรทัดรายการถูกเก็บไว้ครบ', count(\Inventory\Document\Model::items($draft)) === 1);
tnum('ไม่มีการเคลื่อนไหวของเอกสารร่างในสมุดบัญชี', 0,
    $db->count($T('inventory_stock_movement'), [['reference_type', 'order'], ['reference_id', $draft]]));

// แก้ร่างซ้ำ ๆ (ลูกค้าเปลี่ยนใจหลายรอบ) ต้องไม่สะสมผลอะไรไว้
\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'document_status' => 'draft', 'order_no' => 'DRAFT-001',
        'order_date' => '2026-06-01 09:00:00', 'total' => 400],
    [$draftLine(20)], $draft, 1
);
tnum('แก้ร่างซ้ำแล้วสต๊อกก็ยังไม่ขยับ', 50, \Inventory\Posting\Model::balance($pdraft));

// พอจ่ายเงินจริง ร่างกลายเป็นเอกสารที่ออกแล้ว — ตรงนี้ค่อยตัดสต๊อก
\Inventory\Document\Model::save(
    ['document_type' => 'OUT', 'document_status' => 'issued', 'order_no' => 'DRAFT-001',
        'order_date' => '2026-06-01 09:00:00', 'total' => 400],
    [$draftLine(20)], $draft, 1
);
tnum('เปลี่ยนร่างเป็นออกแล้ว จึงตัดสต๊อก 20', 30, \Inventory\Posting\Model::balance($pdraft));
tnum('ตัดครั้งเดียว ไม่ใช่สะสมจากตอนเป็นร่าง', 1,
    $db->count($T('inventory_stock_movement'), [['reference_type', 'order'], ['reference_id', $draft]]));

// -----------------------------------------------------------------------------
group('ลบเอกสารแล้วต้องตัดสายในสมุดบัญชี (id ของเอกสารถูกใช้ซ้ำได้)');

// ⚠️ MariaDB คืนเลข AUTO_INCREMENT ให้เมื่อแถวท้ายสุดถูกลบ เอกสารใบถัดไปจึงได้
// id เดียวกับใบที่ลบไปแล้ว ถ้าแถวเก่าในสมุดบัญชียังชี้ที่ id นั้นอยู่
// Posting::reverse ของใบใหม่จะไปกลับรายการของใบเก่าด้วย = สต๊อกเพี้ยนเงียบ ๆ
$pdel = product('DEL01', 'สินค้าสำหรับทดสอบการลบเอกสาร');
$delLine = function ($qty) use ($pdel) {
    return ['inventory_id' => $pdel, 'code' => 'DEL01',
        'topic' => 'สินค้าสำหรับทดสอบการลบเอกสาร', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => 10, 'total' => $qty * 10];
};
$delDoc = \Inventory\Document\Model::save(
    ['document_type' => 'IN', 'order_no' => 'DEL-001', 'order_date' => '2026-08-01 09:00:00', 'total' => 100],
    [$delLine(10)], 0, 1
);
tnum('รับเข้า 10', 10, \Inventory\Posting\Model::balance($pdel));
tnum('มีแถวในสมุดบัญชีที่ชี้ถึงเอกสารใบนี้', 1,
    $db->count($T('inventory_stock_movement'), [['reference_type', 'order'], ['reference_id', $delDoc]]));

t('ลบเอกสารได้', \Inventory\Document\Model::remove($delDoc, 1));
tnum('ลบแล้วสต๊อกกลับเป็นศูนย์', 0, \Inventory\Posting\Model::balance($pdel));
tnum('ไม่มีแถวไหนชี้ถึง id ของเอกสารที่ลบไปแล้วอีก', 0,
    $db->count($T('inventory_stock_movement'), [['reference_type', 'order'], ['reference_id', $delDoc]]));
// แถวเก่ายังอยู่ครบ (สมุดบัญชีที่ลบแถวได้ไม่ใช่สมุดบัญชี) แค่ถูกตัดสายเท่านั้น
tnum('แถวเดิมยังอยู่ในสมุดบัญชี ไม่ถูกลบทิ้ง', 2,
    $db->count($T('inventory_stock_movement'), [['inventory_id', $pdel], ['reference_type', 'order_removed']]));
$orphan = $db->first($T('inventory_stock_movement'), [
    'inventory_id' => $pdel, 'reference_type' => 'order_removed'
]);
t('เลขที่เอกสารเดิมยังอยู่ ตรวจย้อนหลังได้', (string) $orphan->reference_no === 'DEL-001');

// -----------------------------------------------------------------------------
group('ต้นทุนของสินค้าที่ขายผ่านเอกสาร');

$p9 = product('P009', 'สินค้าคิดต้นทุนผ่านเอกสาร');
$line9 = function ($qty, $price) use ($p9) {
    return ['inventory_id' => $p9, 'code' => 'P009', 'topic' => 'สินค้าคิดต้นทุนผ่านเอกสาร',
        'quantity' => $qty, 'price' => $price, 'total' => $qty * $price];
};
\Inventory\Document\Model::save(['document_type' => 'IN', 'order_no' => 'IN-010',
    'order_date' => '2026-05-01 09:00:00'], [$line9(10, 30)], 0, 1);
\Inventory\Document\Model::save(['document_type' => 'IN', 'order_no' => 'IN-011',
    'order_date' => '2026-05-05 09:00:00'], [$line9(10, 50)], 0, 1);
$sale = \Inventory\Document\Model::save(['document_type' => 'OUT', 'order_no' => 'OUT-010',
    'order_date' => '2026-05-10 09:00:00'], [$line9(12, 90)], 0, 1);
// ⚠️ ยึดสินค้าด้วยเสมอ — สมุดบัญชีเก็บแถวของทุกสินค้าปนกัน
$row = $db->first($T('inventory_stock_movement'), ['reference_id' => $sale, 'inventory_id' => $p9]);
// 10 ชิ้นจากใบละ 30 + 2 ชิ้นจากใบละ 50 = 400
tnum('ต้นทุนของที่ขายมาจากราคาในใบรับสินค้า ตามลำดับเข้าก่อน', 400, (float) $row->total_cost);

$ret = \Inventory\Document\Model::save(['document_type' => 'RET', 'order_no' => 'RET-010',
    'order_date' => '2026-05-12 09:00:00'], [$line9(2, 50)], 0, 1);
tnum('ใบคืนสินค้ารับกลับเข้าสต๊อก', 10, \Inventory\Posting\Model::balance($p9));
// ⚠️ ต้องยึดสินค้าด้วย ไม่ใช่ reference_id อย่างเดียว — สมุดบัญชีเก็บแถวของทุก
// สินค้าปนกัน และเอกสารที่ถูกลบไปแล้วเคยทิ้งแถวที่ชี้มาที่ id เดียวกันไว้ได้
$row = $db->first($T('inventory_stock_movement'), ['reference_id' => $ret, 'inventory_id' => $p9]);
t('ใบคืนสินค้าใช้ชนิดการเคลื่อนไหว return_in', $row->movement_type === 'return_in');
tnum('ของที่ลูกค้าคืนกลับเข้าด้วยทุนเดิม (ทุนต่อหน่วยของการขายล่าสุด 400 ÷ 12) ไม่ใช่ราคาบนใบคืน',
    400 / 12 * 2, (float) $row->total_cost);

// -----------------------------------------------------------------------------
group('บรรทัดเอกสารตามความจริง — หน่วยขาย ล็อต และเครื่อง (DESIGN.md ข้อ 3.6 · 4)');

$Doc = '\Inventory\Document\Model';
// ไข่เป็ด : รับเป็นแผง (×30) มีส่วนลด · ขายเป็นโหล (×12) · ล็อตและวันหมดอายุมาจากบรรทัดใบรับ
$duck = \Inventory\Product\Model::addProduct(['product_code' => 'DOC-EGG', 'topic' => 'ไข่เป็ด', 'unit' => 'ฟอง',
    'price' => 5, 'barcode' => 'DOCEGG-1']);
$duckTray = \Inventory\Items\Model::savePack($duck, 0, ['barcode' => 'DOCEGG-30', 'unit' => 'แผง', 'cut_stock' => 30]);
$duckDozen = \Inventory\Items\Model::savePack($duck, 0, ['barcode' => 'DOCEGG-12', 'unit' => 'โหล', 'cut_stock' => 12, 'price' => 55]);
$duckInHeader = ['document_type' => 'IN', 'order_no' => 'IN-DUCK-1', 'order_date' => '2026-08-01 08:00:00', 'total' => 360];
$duckIn = $Doc::save($duckInHeader, [[
    'inventory_id' => $duck, 'inventory_item_id' => $duckTray, 'code' => 'DOCEGG-30', 'topic' => 'ไข่เป็ด',
    'quantity' => 4, 'unit' => 'แผง', 'price' => 100, 'discount' => 10, 'total' => 360,
    'lot_no' => 'F-0801', 'expiry_date' => '2026-08-20'
]], 0, 1);
$duckLine = $Doc::items($duckIn)[0];
t('รับ 4 แผง = 120 ฟอง (ตัวคูณจากหน่วยขายที่อ้าง)', $duckLine['unit_factor'] === 30.0 && $duckLine['base_qty'] === 120.0);
$duckLayer = $db->first($T('inventory_cost_layer'), ['inventory_id' => $duck]);
tnum('ทุนต่อฟอง = ยอดหลังส่วนลด ÷ ฟองที่รับ (360 ÷ 120) ไม่ใช่ราคาต่อแผง', 3, (float) $duckLayer->unit_cost);
t('ล็อตและวันหมดอายุของบรรทัดใบรับไปอยู่บนชั้นต้นทุน',
    $duckLayer->lot_no === 'F-0801' && $duckLayer->expiry_date === '2026-08-20');
tnum('ทุนของบรรทัดใบรับ = ยอดที่รับ', 360, (float) $duckLine['cost_price']);
$Doc::save($duckInHeader, $Doc::items($duckIn), $duckIn, 1);
$duckLayer = $db->first($T('inventory_cost_layer'), [['inventory_id', $duck], ['remaining_qty', '>', 0]]);
t('บันทึกใบรับซ้ำ ชั้นใหม่ยังได้ล็อตเดิม (บรรทัดคือข้อมูลดิบ)', $duckLayer && $duckLayer->lot_no === 'F-0801');

$duckOut = $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DUCK-1', 'order_date' => '2026-08-02 08:00:00', 'total' => 110], [[
    'inventory_id' => $duck, 'inventory_item_id' => $duckDozen, 'code' => 'DOCEGG-12', 'topic' => 'ไข่เป็ด',
    'quantity' => 2, 'unit' => 'โหล', 'price' => 55, 'total' => 110
]], 0, 1);
$duckLine = $Doc::items($duckOut)[0];
tnum('ขาย 2 โหล ตัด 24 ฟอง', 96, \Inventory\Posting\Model::balance($duck));
t('บรรทัดขายเก็บรหัสที่ยิงและ sku ของ variant', $duckLine['code'] === 'DOCEGG-12' && $duckLine['sku'] === 'DOC-EGG');
tnum('ทุนของบรรทัดขาย = Σ allocation (24 ฟอง × 3)', 72, (float) $duckLine['cost_price']);
$duckLot = $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DUCK-2', 'order_date' => '2026-08-02 09:00:00', 'total' => 55], [[
    'inventory_id' => $duck, 'inventory_item_id' => $duckDozen, 'code' => 'DOCEGG-12', 'topic' => 'ไข่เป็ด',
    'quantity' => 1, 'unit' => 'โหล', 'price' => 55, 'total' => 55, 'lot_no' => 'F-0801'
]], 0, 1);
tnum('บรรทัดที่ระบุล็อต ตัดจากล็อตนั้น', 84, \Inventory\Posting\Model::balance($duck));
tthrows('ล็อตบนบรรทัดที่ไม่มีอยู่จริงถูกปฏิเสธทั้งใบ', function () use ($Doc, $duck, $duckDozen) {
    $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DUCK-3', 'total' => 55], [[
        'inventory_id' => $duck, 'inventory_item_id' => $duckDozen, 'code' => 'DOCEGG-12', 'topic' => 'ไข่เป็ด',
        'quantity' => 1, 'price' => 55, 'total' => 55, 'lot_no' => 'X-9999'
    ]], 0, 1);
}, 'ไม่พบล็อต X-9999');
tthrows('วันหมดอายุบนบรรทัดต้องเป็นวันที่จริง', function () use ($Doc, $duck, $duckTray) {
    $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-DUCK-2', 'total' => 100], [[
        'inventory_id' => $duck, 'inventory_item_id' => $duckTray, 'code' => 'DOCEGG-30', 'topic' => 'ไข่เป็ด',
        'quantity' => 1, 'price' => 100, 'total' => 100, 'expiry_date' => '2026-13-01'
    ]], 0, 1);
}, 'วันหมดอายุของ ไข่เป็ด');
tthrows('หน่วยขายของสินค้าอื่นอ้างไม่ได้', function () use ($Doc, $duck, $itemBox) {
    $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DUCK-4', 'total' => 100], [[
        'inventory_id' => $duck, 'inventory_item_id' => $itemBox, 'code' => 'BOX01-B', 'topic' => 'ไข่เป็ด',
        'quantity' => 1, 'price' => 100, 'total' => 100
    ]], 0, 1);
}, 'ไม่ใช่ของสินค้านี้');
$duckQuo = $Doc::save(['document_type' => 'QUO', 'order_no' => 'QUO-DUCK-1', 'total' => 165], [[
    'inventory_id' => $duck, 'inventory_item_id' => $duckDozen, 'code' => 'DOCEGG-12', 'topic' => 'ไข่เป็ด',
    'quantity' => 3, 'unit' => 'โหล', 'price' => 55, 'total' => 165
]], 0, 1);
$duckLine = $Doc::items($duckQuo)[0];
t('ใบเสนอราคาเก็บหน่วยฐานไว้ด้วย แต่ไม่แตะสต๊อกและไม่มีทุน',
    $duckLine['base_qty'] === 36.0 && $duckLine['cost_price'] === null && \Inventory\Posting\Model::balance($duck) == 84);
$Doc::cancel($duckOut, 1);
t('ยกเลิกใบขายแล้ว บรรทัดไม่มีทุน (ประวัติยังอยู่ในสมุดบัญชี)', $Doc::items($duckOut)[0]['cost_price'] === null);
$cartLines = \Inventory\Cart\Model::buildLines([['code' => 'DOCEGG-12', 'quantity' => 2]]);
t('ตะกร้าส่งรหัสที่ยิง sku และตัวคูณให้เอกสาร',
    $cartLines[0]['code'] === 'DOCEGG-12' && $cartLines[0]['sku'] === 'DOC-EGG' && $cartLines[0]['unit_factor'] === 12.0
    && (int) $cartLines[0]['inventory_item_id'] === $duckDozen);

// โน้ตบุ๊กผ่านเอกสาร : บรรทัด n เครื่อง = n บรรทัด บรรทัดละเครื่อง
$dnb = \Inventory\Product\Model::addProduct(['product_code' => 'DOC-NB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร', 'unit' => 'เครื่อง',
    'count_stock' => 2, 'price' => 20000, 'barcode' => 'DOCNB']);
$dnbPack = (int) $db->first($T('inventory_items'), ['barcode' => 'DOCNB'])->id;
$scan = \Inventory\Products\Model::detail('DOCNB', false);
t('ยิงบาร์โค้ดรุ่นในใบรับ บรรทัดคงเป็นหน่วยขาย (ยังไม่มีเครื่องให้เลือก)', $scan['inventory_item_id'] === $dnbPack);
$dnbInHeader = ['document_type' => 'IN', 'order_no' => 'IN-DNB-1', 'order_date' => '2026-08-03 08:00:00', 'total' => 45000];
$dnbIn = $Doc::save($dnbInHeader, [[
    'inventory_id' => $dnb, 'inventory_item_id' => $dnbPack, 'code' => 'DOCNB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
    'quantity' => 3, 'unit' => 'เครื่อง', 'price' => 15000, 'total' => 45000
]], 0, 1);
$codes = function ($orderId) use ($Doc) {
    return array_map(function ($line) {
        return $line['code'];
    }, $Doc::items($orderId));
};
t('รับ 3 เครื่องด้วยบาร์โค้ดรุ่น = 3 บรรทัด ระบบออกเลขเครื่องให้',
    $codes($dnbIn) === ['DOC-NB-0001', 'DOC-NB-0002', 'DOC-NB-0003']);
tnum('คงเหลือ 3 เครื่อง', 3, (float) $db->first($T('inventory'), ['id' => $dnb])->stock);
tnum('ยอดเงินแบ่งตามเครื่อง รวมเท่าบรรทัดเดิม', 45000, array_sum(array_map(function ($line) {
    return $line['total'];
}, $Doc::items($dnbIn))));
$Doc::save($dnbInHeader, $Doc::items($dnbIn), $dnbIn, 1);
t('บันทึกใบรับซ้ำได้เครื่องเดิม ไม่ออกเลขใหม่',
    $codes($dnbIn) === ['DOC-NB-0001', 'DOC-NB-0002', 'DOC-NB-0003']
    && $db->count($T('inventory_items'), [['inventory_id', $dnb], ['serial_no', 'LIKE', 'DOC-NB-%']]) === 3);
$sn3 = (int) $db->first($T('inventory_items'), ['serial_no' => 'DOC-NB-0003'])->id;
$dnbOutHeader = ['document_type' => 'OUT', 'order_no' => 'OUT-DNB-1', 'order_date' => '2026-08-04 08:00:00', 'total' => 60000];
$dnbOut = $Doc::save($dnbOutHeader, [
    ['inventory_id' => $dnb, 'inventory_item_id' => $dnbPack, 'code' => 'DOCNB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
        'quantity' => 2, 'price' => 20000, 'total' => 40000],
    ['inventory_id' => $dnb, 'inventory_item_id' => $sn3, 'code' => 'DOC-NB-0003', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
        'quantity' => 1, 'price' => 20000, 'total' => 20000]
], 0, 1);
t('ขาย : เครื่องที่ระบุได้เครื่องนั้น ที่เหลือระบบเลือกตามวิธีตัดโดยไม่ชนเครื่องที่ระบุ',
    $codes($dnbOut) === ['DOC-NB-0001', 'DOC-NB-0002', 'DOC-NB-0003']);
tnum('ขายครบ 3 เครื่อง', 0, (float) $db->first($T('inventory'), ['id' => $dnb])->stock);
t('ทุนของแต่ละบรรทัด = ทุนของเครื่องนั้น', array_map(function ($line) {
    return $line['cost_price'];
}, $Doc::items($dnbOut)) === [15000.0, 15000.0, 15000.0]);
$Doc::save($dnbOutHeader, $Doc::items($dnbOut), $dnbOut, 1);
t('บันทึกใบขายซ้ำได้เครื่องเดิม', $codes($dnbOut) === ['DOC-NB-0001', 'DOC-NB-0002', 'DOC-NB-0003']);
tthrows('ขายเกินจำนวนเครื่องที่มี', function () use ($Doc, $dnb, $dnbPack) {
    $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DNB-2', 'total' => 20000], [[
        'inventory_id' => $dnb, 'inventory_item_id' => $dnbPack, 'code' => 'DOCNB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
        'quantity' => 1, 'price' => 20000, 'total' => 20000
    ]], 0, 1);
}, 'มีเครื่องพร้อมขายไม่ถึง 1 เครื่อง');
tthrows('จำนวนเครื่องต้องเป็นจำนวนเต็ม', function () use ($Doc, $dnb, $dnbPack) {
    $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-DNB-2', 'total' => 1], [[
        'inventory_id' => $dnb, 'inventory_item_id' => $dnbPack, 'code' => 'DOCNB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
        'quantity' => 1.5, 'price' => 1, 'total' => 1
    ]], 0, 1);
}, 'จำนวนเต็มเครื่อง');
tthrows('เครื่องเดียวกันอยู่สองบรรทัดไม่ได้', function () use ($Doc, $dnb, $sn3) {
    $line = ['inventory_id' => $dnb, 'inventory_item_id' => $sn3, 'code' => 'DOC-NB-0003', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
        'quantity' => 1, 'price' => 1, 'total' => 1];
    $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-DNB-3', 'total' => 2], [$line, $line], 0, 1);
}, 'มากกว่าหนึ่งบรรทัด');
$Doc::cancel($dnbOut, 1);
$dnbDraft = $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-DNB-4', 'document_status' => 'draft', 'total' => 40000], [[
    'inventory_id' => $dnb, 'inventory_item_id' => $dnbPack, 'code' => 'DOCNB', 'topic' => 'โน้ตบุ๊กผ่านเอกสาร',
    'quantity' => 2, 'price' => 20000, 'total' => 40000
]], 0, 1);
t('ร่างยังไม่ระบุเครื่อง (ยังไม่ได้ขายจริง) บรรทัดคงเป็นหน่วยขาย',
    $codes($dnbDraft) === ['DOCNB'] && (float) $db->first($T('inventory'), ['id' => $dnb])->stock === 3.0);
$db->update($T('order_items'), ['order_id', $dnbIn], ['cost_price' => 1]);
$diff = \Inventory\Posting\Model::rebuild($dnb);
t('rebuild() เจอทุนของบรรทัดที่ไม่ตรงกับสมุดบัญชี', count($diff) === 3 && $diff[0]['table'] === 'order_items'
    && $diff[0]['field'] === 'cost_price' && (float) $diff[0]['actual'] === 15000.0);
t('cache ของกลุ่มนี้ตรงกับข้อมูลดิบ', \Inventory\Posting\Model::rebuild() === []);

// -----------------------------------------------------------------------------
group('ต้นทุนการซื้อตามมาตรฐานการบัญชี — ส่วนลดท้ายบิล · ภาษีซื้อ (TAS 2 · DESIGN.md ข้อ 5.2)');

// ใบรับเดียว : A 10 × 100 (คิด VAT) · B 5 × 200 (ไม่คิด VAT) · ค่าบริการ 500 (คิด VAT · ไม่นับสต๊อก)
// ยอดรวม 2,500 ส่วนลดท้ายบิล 100 → หลังเฉลี่ย A 960 · B 960 · บริการ 480 · ฐานภาษี (A + บริการ) 1,440
$costA = product('TAS-A', 'สินค้าทดสอบทุน ก');
$costB = product('TAS-B', 'สินค้าทดสอบทุน ข');
$costSvc = product('TAS-S', 'ค่าบริการติดตั้ง', 0);
$receiptLines = [
    ['inventory_id' => $costA, 'code' => 'TAS-A', 'topic' => 'ก', 'quantity' => 10, 'price' => 100, 'total' => 1000, 'vat' => 1],
    ['inventory_id' => $costB, 'code' => 'TAS-B', 'topic' => 'ข', 'quantity' => 5, 'price' => 200, 'total' => 1000, 'vat' => 0],
    ['inventory_id' => $costSvc, 'code' => 'TAS-S', 'topic' => 'บริการ', 'quantity' => 1, 'price' => 500, 'total' => 500, 'vat' => 1]
];
$lineCost = function ($orderId, $inventoryId) use ($Doc) {
    foreach ($Doc::items($orderId) as $line) {
        if ((int) $line['inventory_id'] === $inventoryId) {
            return $line['cost_price'];
        }
    }

    return null;
};
$siteCfg->input_vat_creditable = 1;
$tas1 = $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TAS-1', 'order_date' => '2026-08-10 09:00:00',
    'vat_status' => 1, 'discount' => 100, 'vat' => 100.80, 'subtotal' => 2500, 'total' => 2400], $receiptLines, 0, 1);
tnum('ส่วนลดท้ายบิลเฉลี่ยลดทุนตามสัดส่วนยอด (A 1,000 → 960)', 960, $lineCost($tas1, $costA));
tnum('ภาษีแยก + เครดิตภาษีซื้อได้ = ทุนไม่รวมภาษี (A ฟองละ 96)', 96,
    (float) $db->first($T('inventory_cost_layer'), ['inventory_id' => $costA])->unit_cost);
tnum('บรรทัดที่ไม่คิด VAT ได้ส่วนลดท้ายบิลแต่ไม่มีภาษี (B 960)', 960, $lineCost($tas1, $costB));
t('บรรทัดที่ไม่นับสต๊อกไม่มีทุนในสมุดบัญชี แต่ได้ส่วนแบ่งส่วนลด (ส่วนลดไม่กองอยู่ที่สินค้าเกินส่วน)',
    $lineCost($tas1, $costSvc) === null);

$siteCfg->input_vat_creditable = 0;
$tas2 = $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TAS-2', 'order_date' => '2026-08-10 10:00:00',
    'vat_status' => 1, 'discount' => 100, 'vat' => 100.80, 'subtotal' => 2500, 'total' => 2400], $receiptLines, 0, 1);
tnum('เครดิตภาษีซื้อไม่ได้ = ภาษีซื้อเป็นส่วนหนึ่งของทุน (A 960 + ภาษี 100.80 × 960/1,440)', 1027.2, $lineCost($tas2, $costA));
tnum('บรรทัดที่ไม่คิด VAT ไม่ได้ภาษีมาบวก', 960, $lineCost($tas2, $costB));

$siteCfg->input_vat_creditable = 1;
// ราคารวมภาษี : ภาษีของใบ = 1,440 − 1,440 × 100/107 = 94.21 → A = 960 − 94.21 × 960/1,440
$tas3 = $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TAS-3', 'order_date' => '2026-08-10 11:00:00',
    'vat_status' => 2, 'discount' => 100, 'vat' => 94.21, 'subtotal' => 2500, 'total' => 2305.79], $receiptLines, 0, 1);
tnum('ราคารวมภาษี + เครดิตได้ = ถอดภาษีที่บันทึกบนใบออกจากยอดบรรทัด', 960 - 94.21 * 960 / 1440, $lineCost($tas3, $costA));
tnum('ราคารวมภาษี บรรทัดที่ไม่คิด VAT ไม่ถูกถอดภาษี', 960, $lineCost($tas3, $costB));
$sum = 0.0;
foreach ([$tas1] as $orderId) {
    foreach ($Doc::items($orderId) as $line) {
        $sum += (float) $line['cost_price'];
    }
}
tnum('ทุนรวมของสินค้า + ส่วนแบ่งของบริการ = ยอดสุทธิของใบก่อนภาษี (ไม่มีเงินหายหรือเกิน)', 2400 - 480, $sum);
unset($siteCfg->input_vat_creditable);
t('ค่าตั้งเริ่มต้น = เครดิตภาษีซื้อได้', (int) \Inventory\Base\Model::config('input_vat_creditable', 1) === 1);

// ค่าใช้จ่ายในการซื้อ (ค่าขนส่งเข้า) 120 เฉลี่ยลงสินค้าที่นับสต๊อกตามมูลค่า — บรรทัดบริการไม่รับ
$tas4 = $Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TAS-4', 'order_date' => '2026-08-11 09:00:00',
    'vat_status' => 0, 'discount' => 0, 'vat' => 0, 'shipping_cost' => 120, 'subtotal' => 2500, 'total' => 2620], $receiptLines, 0, 1);
tnum('ค่าใช้จ่ายในการซื้อเป็นทุนของสินค้า (A 1,000 + 60)', 1060, $lineCost($tas4, $costA));
tnum('เฉลี่ยตามมูลค่า (B 1,000 + 60)', 1060, $lineCost($tas4, $costB));
t('ใบที่ไม่มีช่องค่าใช้จ่าย (ฝั่งขาย) ไม่ถูกล้างค่าจัดส่งเดิม — ตัวรับฟอร์มเขียนเฉพาะเมื่อมีช่องนี้',
    (bool) preg_match('/shipping_cost\'\)->exists\(\)/', file_get_contents(ROOT_PATH.'modules/inventory/controllers/order.php')));
t('ช่องค่าใช้จ่ายในการซื้อมีเฉพาะใบฝั่งซื้อ และหน้าพิมพ์มีบรรทัดค่าจัดส่งเฉพาะใบที่มีค่าจัดส่ง',
    (bool) preg_match('/data-if="mode == \'buy\'"[^>]*>\s*<label for="shipping_cost"/', file_get_contents(ROOT_PATH.'templates/inventory/order-edit.html'))
    && strpos(file_get_contents(ROOT_PATH.'modules/export/views/sheet.html'), '%SHIPPINGROW%<div') !== false
    && strpos(file_get_contents(ROOT_PATH.'modules/export/views/slip.html'), '%SHIPPINGROW%<div') !== false);

// เศษของทุนต่อหน่วย : 60 ชิ้น 200 บาท (3.3333) ขายหมดสามใบ ทุนขายรวมต้องเท่า 200 พอดี
$third = product('TAS-THIRD', 'สินค้าทุนหารไม่ลงตัว');
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TAS-5', 'order_date' => '2026-08-12 09:00:00'], [
    ['inventory_id' => $third, 'code' => 'TAS-THIRD', 'topic' => 'หารไม่ลงตัว', 'quantity' => 60, 'price' => 200 / 60, 'total' => 200]
], 0, 1);
$sold = 0.0;
$thirdSales = [];
foreach (['A', 'B', 'C'] as $n => $suffix) {
    $sale = $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-TAS-'.$suffix, 'order_date' => '2026-08-13 0'.($n + 1).':00:00'], [
        ['inventory_id' => $third, 'code' => 'TAS-THIRD', 'topic' => 'หารไม่ลงตัว', 'quantity' => 20, 'price' => 5, 'total' => 100]
    ], 0, 1);
    $sold += (float) $lineCost($sale, $third);
    $thirdSales[] = $sale;
}
tnum('ชิ้นสุดท้ายของชั้นรับเศษ — ทุนขายรวม = ทุนซื้อรวม (ไม่ใช่ 199.998)', 200, $sold);
tnum('ทุนขายของใบแรกยังเป็นทุนต่อหน่วยปกติ (20 × 3.3333)', 66.666, (float) $lineCost($thirdSales[0], $third));

// -----------------------------------------------------------------------------
group('เอกสารคืนสินค้า — ลูกค้าคืนด้วยทุนเดิม · คืนผู้ขายด้วยทุนของใบรับที่มา (DESIGN.md ข้อ 5.4)');

// เครื่องที่ลูกค้าคืน = ทุนของเครื่องนั้น ไม่ใช่ราคาบนใบคืน
$rtnNb = \Inventory\Product\Model::addProduct(['product_code' => 'RTN-NB', 'topic' => 'โน้ตบุ๊กรับคืน', 'unit' => 'เครื่อง',
    'count_stock' => 2, 'price' => 20000, 'barcode' => 'RTNNB']);
$rtnPack = (int) $db->first($T('inventory_items'), ['barcode' => 'RTNNB'])->id;
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-RTN-1', 'order_date' => '2026-08-20 09:00:00'], [
    ['inventory_id' => $rtnNb, 'inventory_item_id' => $rtnPack, 'code' => 'RTNNB', 'topic' => 'โน้ตบุ๊ก', 'quantity' => 1, 'price' => 15000, 'total' => 15000]
], 0, 1);
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-RTN-2', 'order_date' => '2026-08-21 09:00:00'], [
    ['inventory_id' => $rtnNb, 'inventory_item_id' => $rtnPack, 'code' => 'RTNNB', 'topic' => 'โน้ตบุ๊ก', 'quantity' => 1, 'price' => 16000, 'total' => 16000]
], 0, 1);
$rtnSecond = (int) $db->first($T('inventory_items'), ['serial_no' => 'RTN-NB-0002'])->id;
$Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-RTN-1', 'order_date' => '2026-08-22 09:00:00'], [
    ['inventory_id' => $rtnNb, 'inventory_item_id' => $rtnSecond, 'code' => 'RTN-NB-0002', 'topic' => 'โน้ตบุ๊ก', 'quantity' => 1, 'price' => 22000, 'total' => 22000]
], 0, 1);
$retNb = $Doc::save(['document_type' => 'RET', 'order_no' => 'RET-RTN-1', 'order_date' => '2026-08-23 09:00:00'], [
    ['inventory_id' => $rtnNb, 'inventory_item_id' => $rtnSecond, 'code' => 'RTN-NB-0002', 'topic' => 'โน้ตบุ๊ก', 'quantity' => 1, 'price' => 22000, 'total' => 22000]
], 0, 1);
tnum('ลูกค้าคืนเครื่อง = ทุนของเครื่องนั้น (16,000) ไม่ใช่ราคาขาย 22,000', 16000, $lineCost($retNb, $rtnNb));
tnum('เครื่องกลับเข้าคลัง', 1, (float) $db->first($T('inventory_items'), ['id' => $rtnSecond])->stock);

// แปลงจากใบขาย : บรรทัดคืนชี้บรรทัดขาย → ทุนต่อหน่วยของบรรทัดขายนั้น
$rtnEgg = product('RTN-EGG', 'ไข่รับคืน');
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-RTN-3', 'order_date' => '2026-08-20 10:00:00'], [
    ['inventory_id' => $rtnEgg, 'code' => 'RTN-EGG', 'topic' => 'ไข่', 'quantity' => 10, 'price' => 3, 'total' => 30]
], 0, 1);
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-RTN-4', 'order_date' => '2026-08-20 11:00:00'], [
    ['inventory_id' => $rtnEgg, 'code' => 'RTN-EGG', 'topic' => 'ไข่', 'quantity' => 10, 'price' => 4, 'total' => 40]
], 0, 1);
$eggSale = $Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-RTN-2', 'order_date' => '2026-08-22 10:00:00'], [
    ['inventory_id' => $rtnEgg, 'code' => 'RTN-EGG', 'topic' => 'ไข่', 'quantity' => 4, 'price' => 5, 'total' => 20]
], 0, 1);
// ขายครั้งหลังด้วยทุนอีกระดับ — ทางสำรอง (การขายล่าสุด) จะได้ทุนนี้ ส่วนการคืนที่ผูกสายต้องได้ทุนของบรรทัดขายเดิม
$Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-RTN-3', 'order_date' => '2026-08-22 11:00:00'], [
    ['inventory_id' => $rtnEgg, 'code' => 'RTN-EGG', 'topic' => 'ไข่', 'quantity' => 10, 'price' => 5, 'total' => 50]
], 0, 1);
$eggRet = $Doc::convert($eggSale, 'RET', 1);
$eggRetLine = $Doc::items($eggRet)[0];
t('การแปลงเอกสารผูกสายบรรทัด (บรรทัดคืน → บรรทัดขาย)', (int) $eggRetLine['source_item_id'] === (int) $Doc::items($eggSale)[0]['id']);
tnum('ลูกค้าคืนของจากใบขายที่อ้าง = ทุนของบรรทัดขายนั้น (4 × 3)', 12, $lineCost($eggRet, $rtnEgg));
$retLines = $Doc::items($eggRet);
$retHeader = ['document_type' => 'RET', 'order_no' => 'RET-RTN-2'];
$Doc::save($retHeader, array_map(function ($line) {
    // แบบที่ฟอร์มส่ง : มี id ของบรรทัดเดิม ไม่มีสาย
    unset($line['source_item_id'], $line['root_item_id']);

    return $line;
}, $retLines), $eggRet, 1);
t('บันทึกซ้ำจากฟอร์ม (ไม่ได้ส่งสายมา) สายของบรรทัดยังอยู่', (int) $Doc::items($eggRet)[0]['source_item_id'] === (int) $eggRetLine['source_item_id']);
tnum('ทุนของใบคืนหลังบันทึกซ้ำยังเป็นทุนของบรรทัดขายเดิม', 12, $lineCost($eggRet, $rtnEgg));

// รายงานหน้าแรก : ของที่ลูกค้าคืนหักออกจากยอดขายและสินค้าขายดี
$topProduct = function ($sku) {
    foreach (\Inventory\Dashboard\Model::topProducts(100) as $row) {
        if ($row['sku'] === $sku) {
            return $row;
        }
    }

    return null;
};
$topEgg = product('TOP-RTN', 'ไข่ขายดี');
$Doc::save(['document_type' => 'IN', 'order_no' => 'IN-TOP-1', 'order_date' => '2026-08-01 09:00:00'], [
    ['inventory_id' => $topEgg, 'code' => 'TOP-RTN', 'topic' => 'ไข่', 'quantity' => 10, 'price' => 3, 'total' => 30]
], 0, 1);
$Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-TOP-1'], [
    ['inventory_id' => $topEgg, 'code' => 'TOP-RTN', 'topic' => 'ไข่', 'quantity' => 3, 'price' => 5, 'total' => 15]
], 0, 1);
$Doc::save(['document_type' => 'RET', 'order_no' => 'RET-TOP-1'], [
    ['inventory_id' => $topEgg, 'code' => 'TOP-RTN', 'topic' => 'ไข่', 'quantity' => 1, 'price' => 5, 'total' => 5]
], 0, 1);
$top = $topProduct('TOP-RTN');
t('สินค้าขายดีของเดือนหักของที่ลูกค้าคืน (ขาย 3 คืน 1 = 2 ฟอง 10 บาท)',
    $top !== null && (float) $top['quantity'] === 2.0 && (float) $top['amount'] === 10.0);
// ยอดขายนับจากใบที่ส่งมอบสินค้า (ตัดสต๊อก) เท่านั้น — ใบเสนอราคาและร่างในสายเดียวกันไม่นับซ้ำ
$salesCard = function () {
    return (float) str_replace(',', '', \Inventory\Dashboard\Model::cards()[0]['value']);
};
$salesBefore = $salesCard();
$topLine = ['inventory_id' => $topEgg, 'code' => 'TOP-RTN', 'topic' => 'ไข่', 'quantity' => 2, 'price' => 5, 'total' => 10];
$Doc::save(['document_type' => 'QUO', 'order_no' => 'QUO-TOP-1', 'total' => 10], [$topLine], 0, 1);
$Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-TOP-DRAFT', 'document_status' => 'draft', 'total' => 10], [$topLine], 0, 1);
$top = $topProduct('TOP-RTN');
t('ใบเสนอราคาและร่าง ไม่นับเป็นยอดขายหรือสินค้าขายดี',
    (float) $top['quantity'] === 2.0 && abs($salesCard() - $salesBefore) < 0.001);
$Doc::save(['document_type' => 'OUT', 'order_no' => 'OUT-TOP-2', 'total' => 10], [$topLine], 0, 1);
tnum('ใบเสร็จที่ออกแล้วเพิ่มยอดขายของเดือน', 10, $salesCard() - $salesBefore);
$revenue = \Inventory\Base\Model::revenueStatuses();
t('ชนิดที่นับเป็นยอดขาย = ฝั่งขายที่ตัดสต๊อก · หักใบฝั่งขายที่รับของคืน',
    in_array('OUT', $revenue['sales'], true) && !in_array('QUO', $revenue['sales'], true)
    && $revenue['returns'] === ['RET']);
$installSql = file_get_contents(ROOT_PATH.'modules/inventory/install/database.sql');
t('ใบคืนสินค้าอยู่ฝั่งขาย (ตัวติดตั้ง)', (bool) preg_match("/\\('RET', 'RET', 'sell',[^)]*'return_in'/", $installSql));
t('ตัวติดตั้งไม่มีใบสั่งขาย (SO) และใบคืนสินค้าไปยังผู้ขาย (RTS) — มีเฉพาะ oms.in.th',
    !preg_match("/\\('(SO|RTS)',/", $installSql));
t('cache ของกลุ่มนี้ตรงกับข้อมูลดิบ', \Inventory\Posting\Model::rebuild() === []);

// -----------------------------------------------------------------------------
group('ยอดคงเหลือมีแถวเดียวต่อหน่วยย่อยเสมอ');

$rows = \Inventory\Posting\Model::createQuery()
    ->select('inventory_id', 'inventory_item_id')
    ->selectRaw('COUNT(*) AS `c`')
    ->from('inventory_stock')
    ->groupBy('inventory_id', 'inventory_item_id')
    ->having('c > 1')
    ->execute(null, 'array')
    ->fetchAll();
t('ไม่มีสินค้าใดมียอดคงเหลือซ้ำสองแถว', empty($rows));

// -----------------------------------------------------------------------------
group('ชั้นหน้าเว็บ — สัญญาระหว่าง PHP กับเทมเพลต');

$moduleDir = $root.'/modules/inventory';
$adminJs = file_get_contents($moduleDir.'/admin.js');
preg_match_all("/RouterManager\\.register\\('([^']+)',\\s*\\{[^}]*template:\\s*'([^']+)'/s", $adminJs, $routes, PREG_SET_ORDER);
t('admin.js ลงทะเบียน route ของโมดูลไว้', count($routes) > 0);

$registered = [];
$missingTemplate = [];
foreach ($routes as $route) {
    $registered[] = $route[1];
    if (!is_file($root.'/templates/'.$route[2])) {
        $missingTemplate[] = $route[1].' → '.$route[2];
    }
}
t('ทุก route ชี้ไปเทมเพลตที่มีอยู่จริง'.(empty($missingTemplate) ? '' : ' ('.implode(', ', $missingTemplate).')'),
    empty($missingTemplate));

// ⚠️ ปุ่มในแถวของตารางจะ "เปลี่ยนหน้า" ก็ต่อเมื่อ method เป็น get เท่านั้น
// ค่าเริ่มต้นของ method คือ POST ลืมใส่แล้วปุ่มจะ fetch หน้า SPA มาเป็น response
// แล้วไม่มีอะไรเกิดขึ้น — เจอมาแล้วตอนทำหน้าลูกค้าของ oms
$badAction = [];
$unknownRoute = [];
foreach (glob($root.'/templates/inventory/*.html') ?: [] as $file) {
    $html = file_get_contents($file);
    if (!preg_match_all("/data-row-actions='([^']+)'/s", $html, $blocks)) {
        continue;
    }
    foreach ($blocks[1] as $json) {
        $actions = json_decode($json, true);
        if (!is_array($actions)) {
            $badAction[] = basename($file).' : อ่าน JSON ของ data-row-actions ไม่ได้';
            continue;
        }
        foreach ($actions as $name => $action) {
            if (!is_array($action) || empty($action['url']) || $action['url'][0] !== '/') {
                continue;
            }
            if (!isset($action['method']) || strtolower($action['method']) !== 'get') {
                $badAction[] = basename($file).' : ปุ่ม '.$name.' ขาด method="get"';
            }
            // ปุ่มที่มี target (เช่น _blank) เปิดแท็บใหม่จริง ๆ ไม่ผ่าน SPA router
            // จึงชี้ไปสคริปต์นอกแอป (เช่น export.php) ได้โดยไม่ต้องลงทะเบียนเป็น route
            if (!empty($action['target'])) {
                continue;
            }
            $path = explode('?', $action['url'])[0];
            if (!in_array($path, $registered, true)) {
                $unknownRoute[] = basename($file).' : ปุ่ม '.$name.' ชี้ไป '.$path.' ที่ไม่ได้ลงทะเบียน';
            }
        }
    }
}
t('ปุ่มในแถวที่เปิดหน้าอื่นระบุ method="get" ครบ'.(empty($badAction) ? '' : ' — '.implode(' · ', $badAction)),
    empty($badAction));
t('ปุ่มในแถวชี้ไป route ที่ลงทะเบียนไว้จริง'.(empty($unknownRoute) ? '' : ' — '.implode(' · ', $unknownRoute)),
    empty($unknownRoute));

// endpoint ที่เทมเพลตเรียก ต้องมีคอนโทรลเลอร์และเมธอดรองรับจริง
$missingEndpoint = [];
foreach (glob($root.'/templates/inventory/*.html') ?: [] as $file) {
    $html = file_get_contents($file);
    preg_match_all('#(?:data-source|data-load-api|data-action-url|action)="(api/inventory/[a-z0-9\-/]+)"#', $html, $found);
    foreach (array_unique($found[1]) as $endpoint) {
        $parts = explode('/', $endpoint);        // api / inventory / <controller> / <method>
        $controller = $moduleDir.'/controllers/'.$parts[2].'.php';
        if (!is_file($controller)) {
            $missingEndpoint[] = basename($file).' → '.$endpoint.' (ไม่มีคอนโทรลเลอร์)';
            continue;
        }
        if (isset($parts[3])) {
            $class = '\\Inventory\\'.ucfirst($parts[2]).'\\Controller';
            $method = str_replace('-', '', $parts[3]);
            if (!class_exists($class) || !method_exists($class, $method)) {
                $missingEndpoint[] = basename($file).' → '.$endpoint.' (ไม่มีเมธอด '.$method.')';
            }
        }
    }
}
t('endpoint ที่เทมเพลตเรียกมีคอนโทรลเลอร์รองรับครบ'.(empty($missingEndpoint) ? '' : ' — '.implode(' · ', $missingEndpoint)),
    empty($missingEndpoint));

// ใช้ของแกนที่มีอยู่แล้ว อย่าเขียนใหม่ — หมวดหมู่ต้องไม่ประกาศ get()/save() ทับ
$reflection = new \ReflectionClass('\\Inventory\\Categories\\Controller');
$ownMethods = [];
foreach (['get', 'save'] as $method) {
    if ($reflection->hasMethod($method)
        && $reflection->getMethod($method)->getDeclaringClass()->getName() === 'Inventory\\Categories\\Controller') {
        $ownMethods[] = $method;
    }
}
t('หมวดหมู่ใช้ตัวจัดการของแกน ไม่ได้ประกาศ get()/save() ขึ้นมาใหม่'.(empty($ownMethods) ? '' : ' ('.implode(', ', $ownMethods).')'),
    empty($ownMethods));
t('ชนิดหมวดหมู่ของเมนูมาจากที่เดียวกับของคอนโทรลเลอร์',
    \Inventory\Categories\Controller::types() === (new \ReflectionClass('\\Inventory\\Category\\Controller'))
        ->getDefaultProperties()['categories']);

// ตารางทุกตัวต้องตรวจสิทธิ์ของตัวเอง
$tableControllers = ['\\Inventory\\Products\\Controller', '\\Inventory\\Inventories\\Controller'];
$noAuth = [];
foreach ($tableControllers as $class) {
    $r = new \ReflectionClass($class);
    if (!$r->hasMethod('checkAuthorization')
        || $r->getMethod('checkAuthorization')->getDeclaringClass()->getName() === 'Gcms\\Table') {
        $noAuth[] = $class;
    }
}
t('ตารางทุกตัวประกาศการตรวจสิทธิ์ของตัวเอง'.(empty($noAuth) ? '' : ' ('.implode(', ', $noAuth).')'), empty($noAuth));

// -----------------------------------------------------------------------------
group('ชั้นหน้าเว็บ — ข้อมูลที่คอนโทรลเลอร์ส่งให้เทมเพลต');

$found = \Inventory\Products\Model::search('P00');
t('ค้นหาสินค้าคืนรายการที่มี value กับ text ครบ',
    !empty($found) && isset($found[0]['value'], $found[0]['text']));
$detail = \Inventory\Products\Model::detail('P008');
t('รายละเอียดสินค้าคืนคีย์ครบตามที่ตารางรายการต้องใช้',
    $detail !== null && !array_diff(
        ['inventory_id', 'inventory_item_id', 'sku', 'code', 'topic', 'quantity', 'unit', 'unit_factor', 'price', 'discount', 'vat', 'total'],
        array_keys($detail)
    ));
t('สินค้าที่ไม่มีอยู่จริงคืน null', \Inventory\Products\Model::detail('ไม่มีรหัสนี้') === null);
$cards = \Inventory\Dashboard\Model::cards();
t('การ์ดหน้าแรกมีครบ 4 ใบ พร้อมลิงก์ไปหน้าที่ดูต่อได้',
    count($cards) === 4 && !empty($cards[0]['url']) && isset($cards[0]['value']));

// -----------------------------------------------------------------------------
group('ตัวจัดการหน้าต้องผูกด้วย attribute ที่แกนเรียกจริง');

// ⚠️ ข้อนี้จับข้อบกพร่องที่เกิดจริงและเงียบที่สุด (2026-09-12) :
//
//   RouterManager.executePageScripts() เรียกเฉพาะ [data-script] ตัวแรกในหน้า
//   หลังเปลี่ยนหน้าทุกครั้ง — ส่วน data-on-load เป็นของ "กล่องที่โหลดข้อมูลเอง"
//   (data-component="api" หรือฟอร์มที่มี data-load-api) ซึ่ง ApiComponent/FormManager
//   เรียกให้หลังข้อมูลมาถึง
//
//   ใส่ data-on-load บนกล่องที่ไม่มีตัวโหลดข้อมูล (เช่น <main> เปล่า ๆ) จะถูกเรียก
//   แค่ตอนโหลดเว็บครั้งแรก (AppConfigManager กวาดทั้งหน้าครั้งเดียว) แต่ไม่ถูกเรียกเลย
//   เมื่อเดินมาจากหน้าอื่น — หน้าจอว่างเปล่า ไม่มี error ใน console ให้ตามหา
//   (หน้าร้านออนไลน์กับหน้าขายหน้าร้านเคยเป็นแบบนี้ทั้งคู่)
$_wrongBinding = [];
foreach (glob(ROOT_PATH.'templates/*/*.html') as $_tplFile) {
    $_tpl = file_get_contents($_tplFile);
    if (!preg_match_all('/<([a-z]+)([^>]*\bdata-on-load="[a-zA-Z0-9_]+"[^>]*)>/', $_tpl, $_tags, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($_tags as $_tag) {
        // ผูกถูกเมื่อ "ตัวเดียวกัน" มีตัวโหลดข้อมูลของตัวเอง
        if (strpos($_tag[2], 'data-load-api') !== false
            || strpos($_tag[2], 'data-component="api"') !== false) {
            continue;
        }
        $_wrongBinding[] = str_replace(ROOT_PATH, '', $_tplFile).' (<'.$_tag[1].'>)';
    }
}
t('ไม่มีแม่แบบไหนใส่ data-on-load บนกล่องที่ไม่มีตัวโหลดข้อมูล'
    .(empty($_wrongBinding) ? '' : ' — ผิด: '.implode(' · ', $_wrongBinding)), empty($_wrongBinding));

// และทุกชื่อฟังก์ชันที่แม่แบบเรียก ต้องมีอยู่จริงในไฟล์ JS ของโมดูลสักตัว
// ตัวจัดการของหน้าตั้งค่าที่เป็นของแกนอยู่ใน js/main.js ไม่ใช่ของโมดูล
$_allJs = file_get_contents(ROOT_PATH.'js/main.js');
foreach (glob(ROOT_PATH.'modules/*/admin.js') as $_jsFile) {
    $_allJs .= file_get_contents($_jsFile);
}
$_missingFn = [];
foreach (glob(ROOT_PATH.'templates/*/*.html') as $_tplFile) {
    if (!preg_match_all('/data-(?:script|on-load)="([a-zA-Z0-9_]+)"/',
        file_get_contents($_tplFile), $_fns)) {
        continue;
    }
    foreach (array_unique($_fns[1]) as $_fn) {
        if (strpos($_allJs, 'function '.$_fn) === false && strpos($_allJs, 'window.'.$_fn.' =') === false) {
            $_missingFn[] = str_replace(ROOT_PATH, '', $_tplFile).' → '.$_fn.'()';
        }
    }
}
t('ทุกตัวจัดการที่แม่แบบเรียก มีอยู่จริงในโมดูล'
    .(empty($_missingFn) ? '' : ' — ขาด: '.implode(' · ', $_missingFn)), empty($_missingFn));

// -----------------------------------------------------------------------------
group('เมนูของโมดูลต้องไม่มีลิงก์ตาย');

// แอดมิน (status = 1) ผ่านทุกสิทธิ์ จึงเห็นเมนูครบทุกรายการ
$fakeLogin = (object) ['id' => 1, 'status' => 1, 'permission' => '', 'active' => 1];
$menus = \Inventory\Init\Controller::initMenus([], null, $fakeLogin);
$menuUrls = [];
$walk = function ($items) use (&$walk, &$menuUrls) {
    foreach ($items as $item) {
        if (!empty($item['url'])) {
            $menuUrls[] = explode('?', $item['url'])[0];
        }
        if (!empty($item['children'])) {
            $walk($item['children']);
        }
    }
};
$walk($menus);
t('เมนูของโมดูลสร้างรายการออกมาได้', count($menuUrls) > 0);
$deadLinks = array_values(array_diff(array_unique($menuUrls), $registered));
t('ทุกเมนูชี้ไป route ที่ลงทะเบียนไว้'.(empty($deadLinks) ? '' : ' — ตาย: '.implode(', ', $deadLinks)),
    empty($deadLinks));

$permissions = \Inventory\Init\Controller::initPermission([], null, $fakeLogin);
$names = array_column($permissions, 'value');
t('ชื่อสิทธิ์ตรงกับที่เก็บไว้ในระบบเดิมครบทั้ง 5 ตัว',
    $names === ['can_manage_inventory', 'can_inventory_order', 'can_stock', 'can_sell', 'can_buy']);

// -----------------------------------------------------------------------------
group('ทะเบียนลูกค้าและเลขที่เอกสาร');

$customerId = (int) $db->insert($T('customer'), [
    'customer_no' => 'CU0001', 'code' => 'CU0001', 'company' => 'บริษัททดสอบ จำกัด',
    'name' => 'ผู้ติดต่อ', 'email' => '', 'phone' => '021234567',
    'address' => 'ที่อยู่ทดสอบ', 'province' => 'กรุงเทพมหานคร', 'zipcode' => '10100'
]);
$found = \Inventory\Customers\Model::search('ทดสอบ');
t('ค้นหาลูกค้าคืน value เป็น id และมีข้อความให้ผู้ใช้อ่าน',
    !empty($found) && (int) $found[0]['value'] === $customerId && $found[0]['text'] !== '');
$found = \Inventory\Customers\Model::search('CU00', 'customer_no');
t('ค้นหาด้วยรหัสลูกค้าคืน value เป็นรหัส ไม่ใช่ id',
    !empty($found) && $found[0]['value'] === 'CU0001');
t('อ่านลูกค้าหนึ่งรายได้', \Inventory\Customers\Model::get($customerId)['company'] === 'บริษัททดสอบ จำกัด');
t('ลูกค้าที่ไม่มีอยู่จริงคืน null', \Inventory\Customers\Model::get(999999) === null);

// -----------------------------------------------------------------------------
group('รายการเอกสารและสายเอกสาร');

$rows = \Inventory\Orders\Model::toDataTable(['status' => 'OUT'])->execute(null, 'array')->fetchAll();
t('ตารางเอกสารกรองตามชนิดได้', is_array($rows));
$rows = \Inventory\Orders\Model::toDataTable(['search' => 'OUT-'])->execute(null, 'array')->fetchAll();
t('ค้นหาเอกสารด้วยเลขที่ได้', is_array($rows) && count($rows) > 0);

$key = \Inventory\Orders\Model::ensureKey($out);
t('กุญแจสาธารณะของเอกสารยาว 32 ตัวและสุ่มจริง (ไม่ใช่ md5 ของ id)',
    strlen($key) === 32 && $key !== md5($out));
t('เรียกซ้ำได้กุญแจเดิม', \Inventory\Orders\Model::ensureKey($out) === $key);
t('อ่านเอกสารจากกุญแจได้', \Inventory\Orders\Model::getByKey($key)['id'] == $out);
t('กุญแจผิดรูปแบบคืน null ไม่ยิงคิวรี', \Inventory\Orders\Model::getByKey('123') === null);

// -----------------------------------------------------------------------------
group('ตัวจัดการฝั่ง JS ที่เทมเพลตเรียก ต้องมีอยู่จริง');

// ⚠️ ข้อนี้จับกรณีที่พลาดมาแล้ว : ยกเทมเพลตมาแต่ลืมยกตรรกะคำนวณใน admin.js
// ผลคือฟอร์มเอกสารเปิดได้แต่ยอดรวมไม่ขยับ โดยไม่มีข้อความผิดพลาดใด ๆ
$jsFunctions = [];
if (preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', $adminJs, $found)) {
    $jsFunctions = $found[1];
}
$referenced = [];
foreach (glob($root.'/templates/inventory/*.html') ?: [] as $file) {
    $html = file_get_contents($file);
    foreach (['data-on-calculate', 'data-formatter', 'data-on-load'] as $attribute) {
        if (preg_match_all('/'.$attribute.'="([A-Za-z0-9_]+)"/', $html, $found)) {
            foreach ($found[1] as $name) {
                $referenced[$name] = basename($file);
            }
        }
    }
    // data-on="event[.modifier]:handler" — อาจมีหลายคู่คั่นด้วยช่องว่าง
    if (preg_match_all('/data-on="([^"]+)"/', $html, $found)) {
        foreach ($found[1] as $value) {
            foreach (preg_split('/\s+/', trim($value)) as $pair) {
                $parts = explode(':', $pair, 2);
                if (count($parts) === 2 && preg_match('/^[A-Za-z0-9_]+$/', $parts[1])) {
                    $referenced[$parts[1]] = basename($file);
                }
            }
        }
    }
}
t('เทมเพลตอ้างถึงตัวจัดการฝั่ง JS อยู่จริง', count($referenced) > 0);
$missingJs = [];
foreach ($referenced as $name => $file) {
    if (!in_array($name, $jsFunctions, true)) {
        $missingJs[] = $file.' → '.$name.'()';
    }
}
t('ทุกตัวจัดการที่เทมเพลตเรียก มีฟังก์ชันใน admin.js'.(empty($missingJs) ? '' : ' — ขาด: '.implode(', ', $missingJs)),
    empty($missingJs));

// -----------------------------------------------------------------------------
group('สัญญาของฟอร์มตามคู่มือ (data.data / data.options)');

// ⚠️ ฟอร์มอ่านค่าจาก data.data และตัวเลือกจาก data.options[<ชื่อ field>]
// คืนแบบแบน (ฟิลด์อยู่ที่รากของ data) ฟอร์มจะโหลดขึ้นมาว่างเปล่าโดยไม่เตือนอะไร
$formControllers = [
    '\\Inventory\\Product\\Controller' => 'product-edit.html',
    '\\Inventory\\Customer\\Controller' => 'customer.html',
    '\\Inventory\\Order\\Controller' => 'order-edit.html'
];
$notNested = [];
foreach ($formControllers as $class => $template) {
    $source = file_get_contents((new \ReflectionClass($class))->getFileName());
    // ต้องมี successResponse ที่ห่อด้วย 'data' => ... อยู่ในเมธอด get() · 'options' เฉพาะฟอร์มที่มีช่องเลือก
    $needsOptions = strpos(file_get_contents(ROOT_PATH.'templates/inventory/'.$template), 'data-options-key') !== false;
    if (strpos($source, "'data' => ") === false || ($needsOptions && strpos($source, "'options' => ") === false)) {
        $notNested[] = $class;
    }
}
t('ฟอร์มทุกตัวคืนข้อมูลแบบ data.data + data.options'.(empty($notNested) ? '' : ' ('.implode(', ', $notNested).')'),
    empty($notNested));

$missingKey = [];
foreach ($formControllers as $class => $template) {
    $file = $root.'/templates/inventory/'.$template;
    if (!is_file($file)) {
        continue;
    }
    $source = file_get_contents((new \ReflectionClass($class))->getFileName());
    if (preg_match_all('/data-options-key="([A-Za-z0-9_]+)"/', file_get_contents($file), $found)) {
        foreach (array_unique($found[1]) as $key) {
            // ชื่อคีย์ของตัวเลือกต้องตรงกับที่คอนโทรลเลอร์ส่งมา ไม่งั้นช่องจะว่าง
            if (strpos($source, "'".$key."' => ") === false) {
                $missingKey[] = $template.' → options['.$key.']';
            }
        }
    }
}
t('ทุก data-options-key มีตัวเลือกที่คอนโทรลเลอร์ส่งมาให้'.(empty($missingKey) ? '' : ' — ขาด: '.implode(', ', $missingKey)),
    empty($missingKey));

// -----------------------------------------------------------------------------
group('ตารางรายการของเอกสาร (data-line-items) และช่องค้นหา');

$orderForm = file_get_contents($root.'/templates/inventory/order-edit.html');
t('ตารางรายการประกาศ data-line-items และปลายทางรายละเอียด',
    strpos($orderForm, 'data-line-items=') !== false && strpos($orderForm, 'data-detail-api=') !== false);

// ⚠️ ช่องค้นหาต้องมีทั้ง name และ data-role
// ไม่มี name → _getInputValue() หา hidden ไม่เจอ แล้วใช้ข้อความที่แสดงแทนค่าจริง
// ผลคือ detail API หาไม่เจอ แถวไม่ถูกเพิ่ม เงียบ ๆ
$searchOk = preg_match('/<input[^>]*name="code"[^>]*data-role="code"/', $orderForm)
    || preg_match('/<input[^>]*data-role="code"[^>]*name="code"/', $orderForm);
t('ช่องค้นหาสินค้ามีทั้ง name และ data-role', (bool) $searchOk);
t('ตารางฟังช่องค้นหาและส่งพารามิเตอร์จาก data-role',
    strpos($orderForm, "data-listen-select=\"[data-role='code']\"") !== false
    && strpos($orderForm, 'data-api-params="[data-role]"') !== false);
t('ยิงรหัสเดิมซ้ำรวมเป็นจำนวนตามรหัสที่ยิง', strpos($orderForm, 'data-merge-key="code"') !== false);
t('ชนิดเอกสารถูกส่งไปกับการค้นหา (ใบรับเข้าไม่เลือกเครื่องที่มีอยู่แล้ว)',
    (bool) preg_match('/<select[^>]*name="document_type"[^>]*data-role="document_type"/', $orderForm));
// ⚠️ ตารางรายการส่งกลับเฉพาะคอลัมน์ที่ประกาศไว้ — ขาดคอลัมน์ไหน บันทึกซ้ำแล้วค่านั้นหาย
foreach (['inventory_item_id', 'sku', 'unit_factor', 'lot_no', 'expiry_date', 'code'] as $field) {
    t('ตารางรายการวนคอลัมน์ '.$field.' กลับมากับฟอร์ม', strpos($orderForm, 'data-field="'.$field.'"') !== false);
}

// ⚠️ ไม่มี data-auto-calc / data-sum-to อยู่จริงในเฟรมเวิร์ก (โผล่แค่ในคอมเมนต์
// ของ LineItemsManager.js) การคำนวณมีทางเดียวคือ data-on-calculate
// นับเฉพาะที่ใช้เป็น attribute จริง (มี =") ไม่ใช่ที่เอ่ยถึงในคอมเมนต์เตือนตัวเอง
t('ไม่ได้ใช้ data-auto-calc / data-sum-to ที่ไม่มีอยู่จริง',
    strpos($orderForm, 'data-auto-calc="') === false && strpos($orderForm, 'data-sum-to="') === false);

// autocomplete ต้องคืน value เป็นรหัสที่ detail API ใช้ค้นได้ ไม่ใช่ id
$found = \Inventory\Products\Model::search('P008');
t('autocomplete คืน value เป็นรหัสที่ detail API ใช้ค้นได้',
    !empty($found) && \Inventory\Products\Model::detail($found[0]['value']) !== null);

// -----------------------------------------------------------------------------
group('รายละเอียดของเทมเพลตที่พลาดแล้วเห็นยาก');

// .switch วาดสวิตช์ด้วยตัวเชื่อมพี่น้อง input ต้องมาก่อน label เสมอ
$badSwitch = [];
foreach (glob($root.'/templates/inventory/*.html') ?: [] as $file) {
    $html = file_get_contents($file);
    if (preg_match_all('/<label[^>]*for="([A-Za-z0-9_]+)"[^>]*>.*?<\/label>\s*<input[^>]*id="\1"[^>]*class="[^"]*switch/s', $html, $found)) {
        $badSwitch[] = basename($file);
    }
}
t('สวิตช์ทุกตัวเรียง input มาก่อน label'.(empty($badSwitch) ? '' : ' ('.implode(', ', $badSwitch).')'), empty($badSwitch));

// ตัวเลือก "ทั้งหมด" ของตัวกรอง JS เติมให้เอง PHP ไม่ต้องใส่ซ้ำ
$doubleAll = [];
foreach ([\Inventory\Inventories\Controller::class, \Inventory\Products\Controller::class] as $class) {
    $source = file_get_contents((new \ReflectionClass($class))->getFileName());
    if (preg_match("/'value' => '',\s*'text' => '\{LNG_All/", $source)) {
        $doubleAll[] = $class;
    }
}
t('ไม่ได้ใส่ตัวเลือก "ทั้งหมด" ซ้ำกับที่ JS เติมให้', empty($doubleAll));

// ทั้งโมดูลต้องไม่มีคำขอออกนอกเครื่อง (ฟอนต์อยู่ในโปรเจ็คแล้ว)
$external = [];
foreach (array_merge(
    glob($root.'/templates/inventory/*.html') ?: [],
    glob($root.'/modules/inventory/template/*') ?: [],
    [$root.'/modules/inventory/admin.js']
) as $file) {
    if (is_file($file) && preg_match('#https?://(?!www\.kotchasan\.com|nowjs\.net)[a-z0-9.\-]+#i', (string) file_get_contents($file), $m)) {
        $external[] = basename($file).' → '.$m[0];
    }
}
t('ไม่มีคำขอออกนอกเครื่องในเทมเพลตของโมดูล'.(empty($external) ? '' : ' — '.implode(', ', $external)), empty($external));

// -----------------------------------------------------------------------------
group('ชั้นพิมพ์เอกสารและนำเข้า/ส่งออก');

$export = new \Inventory\Export\Controller();
t('ตัวพิมพ์เอกสารสืบทอดบริการกลาง modules/export',
    $export instanceof \Export\Export\Controller);

// ลิงก์ที่ส่งให้ลูกค้าเปิดได้โดยไม่ต้องเข้าระบบ — ชนิดที่เปิดสาธารณะต้องประกาศไว้
// ชัดเจน ชนิดอื่นยังต้องยืนยันตัวตนเสมอ
$public = $export->publicTypes();
t('ประกาศชนิดที่เปิดสาธารณะไว้ชัดเจน', is_array($public) && in_array('print', $public, true));
t('ชนิดที่ไม่ได้ประกาศไม่ถือว่าสาธารณะ', !in_array('csv', $public, true) && !in_array('billing', $public, true));

// ⚠️ ตัวจ่ายงานส่งออกของแกนใช้ ReflectionMethod ตรวจว่าเมธอดเป็น public ที่โมดูล
// ประกาศเอง เมธอดช่วยภายในจึงต้องไม่เป็น public ไม่งั้นเรียกผ่าน typ= ได้
$reflection = new \ReflectionClass($export);
$leaked = [];
foreach (['resolveOrder', 'buildBilling', 'itemRows', 'signatureBoxes', 'wrapPrintPage', 'loadTemplate'] as $method) {
    if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic()) {
        $leaked[] = $method;
    }
}
t('เมธอดช่วยภายในไม่ได้เป็น public'.(empty($leaked) ? '' : ' ('.implode(', ', $leaked).')'), empty($leaked));

// คอลัมน์ของตารางรายการมาจากคอลัมน์ `columns` ของแม่แบบเอกสารในฐานข้อมูล
// (เดิมเป็นไฟล์ละชนิดใน modules/inventory/template/ ที่ทั้งห้าไฟล์มีรายชื่อ
// คอลัมน์ชุดเดียวกันทุกไบต์ — เลิกใช้แล้ว ดู Export\Controller::templateColumns())
$templateDir = $root.'/modules/inventory/template/';
t('ไม่เหลือไฟล์คอลัมน์ต่อชนิดเอกสารแบบเก่า',
    empty(array_diff(glob($templateDir.'*.html') ?: [], [$templateDir.'email.html'])));
$defaultColumns = \Inventory\Export\Controller::defaultColumns();
t('มีชุดคอลัมน์เริ่มต้นของเอกสาร ('.implode(', ', $defaultColumns).')', !empty($defaultColumns));
// oas ไม่มีซองจดหมาย (DESIGN.md ข้อ 7) — ตัดทั้งแม่แบบและหน้าพิมพ์ ไม่เหลือปลายทางที่ชี้ไปไฟล์ที่ไม่มี
t('แม่แบบอีเมลมาด้วย · ไม่มีซองจดหมาย',
    is_file($templateDir.'email.html') && !is_file($templateDir.'envelope.html')
    && !method_exists(\Inventory\Export\Controller::class, 'envelope'));

// ⚠️ กับดักเดิม : "ไม่พิมพ์วันที่" ซ่อนแค่วันที่ใต้ลายเซ็น วันที่กับวันครบกำหนดที่หัวเอกสาร
// ยังพิมพ์ออกไป ทุกจุดที่พิมพ์ %ORDERDATE% / %DUEDATE% ต้องอยู่ในคลาสที่กฎ #no_date ซ่อนได้
$exportViews = $root.'/modules/export/views/';
$printCss = file_get_contents($exportViews.'print.css');
foreach (['sheet.html', 'slip.html'] as $view) {
    $html = file_get_contents($exportViews.$view);
    $dates = substr_count($html, '%ORDERDATE%') + substr_count($html, '%DUEDATE%');
    t($view.': วันที่และวันครบกำหนดที่หัวเอกสารอยู่ในคลาส doc-date ทุกจุด',
        $dates > 0 && preg_match_all('/<[^>]*class="[^"]*\bdoc-date\b[^"]*"[^>]*>%(ORDERDATE|DUEDATE)%</', $html) === $dates);
}
t('ตัวเลือก "ไม่พิมพ์วันที่" ซ่อนทั้งวันที่หัวเอกสารและใต้ลายเซ็น',
    strpos($printCss, '#no_date:checked ~ * .doc-date') !== false
    && strpos($printCss, '#no_date:checked ~ * .sign-date') !== false);

// รูปแบบไฟล์ CSV — คอลัมน์ต้องเป็นชื่อกลาง ไม่ใช่ชื่อเดิม
$spec = \Inventory\Import\Model::type('product');
t('ไฟล์ CSV ของสินค้าเป็นบรรทัดละ variant และใช้ชื่อคอลัมน์กลาง',
    $spec !== null && $spec['key'] === 'sku' && in_array('product_code', $spec['columns'], true)
    && in_array('sku', $spec['columns'], true) && in_array('barcode', $spec['columns'], true)
    && in_array('option1', $spec['columns'], true) && in_array('is_active', $spec['columns'], true)
    && !in_array('product_no', $spec['columns'], true)
    && !in_array('inuse', $spec['columns'], true));
$spec = \Inventory\Import\Model::type('customer');
t('ไฟล์ CSV ของลูกค้ามีคอลัมน์ครบ ทั้งข้อมูลทั่วไปและธงลูกค้า/คู่ค้า/ใช้งาน',
    $spec !== null && in_array('customer_no', $spec['columns'], true)
    && in_array('is_customer', $spec['columns'], true)
    && in_array('is_supplier', $spec['columns'], true)
    && in_array('is_active', $spec['columns'], true));
t('ชนิดที่ไม่รู้จักคืน null', \Inventory\Import\Model::type('ไม่มีชนิดนี้') === null);

// ⚠️ กับดักเดิม : ไฟล์นี้เคยไม่มีคอลัมน์ธงเลย นำเข้าผู้ขายจากไฟล์จึงกลายเป็น
// ลูกค้าเสมอ (ค่าเริ่มต้นของคอลัมน์) ผู้ขายที่มีอยู่จริงจะหายไปตอนส่งออก-นำเข้าซ้ำ
$result = \Inventory\Import\Model::importRow('customer', [
    'company' => 'บริษัท ผู้ขายนำเข้าไฟล์ จำกัด', 'is_customer' => 0, 'is_supplier' => 1, 'is_active' => 1
]);
t('นำเข้าผู้ขายใหม่จากไฟล์ได้', $result === 'new');
$importedCustomer = $db->first($T('customer'), ['company' => 'บริษัท ผู้ขายนำเข้าไฟล์ จำกัด']);
t('ผู้ขายที่นำเข้าได้ธงตามไฟล์ ไม่ใช่ค่าเริ่มต้นของลูกค้า',
    $importedCustomer && (int) $importedCustomer->is_customer === 0 && (int) $importedCustomer->is_supplier === 1);

// ไฟล์ตัวอย่าง = ข้อมูลจริงที่ส่งออกมา ธงที่บันทึกไว้ต้องส่งออกมาตรงกัน ไม่งั้น
// แก้ไฟล์แล้วนำกลับเข้าไปจะเปลี่ยนผู้ขายรายนี้กลับเป็นลูกค้าโดยไม่ตั้งใจ
$customerColumns = \Inventory\Import\Model::type('customer')['columns'];
$exportedCustomer = null;
foreach (\Inventory\Import\Model::rows('customer') as $row) {
    $byName = array_combine($customerColumns, $row);
    if ($byName['company'] === 'บริษัท ผู้ขายนำเข้าไฟล์ จำกัด') {
        $exportedCustomer = $byName;
    }
}
t('ส่งออกลูกค้าเป็น CSV แล้วธงลูกค้า/คู่ค้ายังตรงกับที่บันทึกไว้',
    $exportedCustomer !== null && (int) $exportedCustomer['is_customer'] === 0
    && (int) $exportedCustomer['is_supplier'] === 1);

// ไฟล์รุ่นเก่าที่ยังไม่มีคอลัมน์ธง (หรือไฟล์ที่ผู้ใช้ตัดคอลัมน์นี้ทิ้งเอง) ต้อง
// ไม่ไปเขียนทับธงเดิมของแถวที่มีอยู่แล้วให้กลายเป็นค่าว่าง
\Inventory\Import\Model::importRow('customer', [
    'company' => 'บริษัท ผู้ขายนำเข้าไฟล์ จำกัด', 'phone' => '029999999'
]);
$importedCustomer = $db->first($T('customer'), ['company' => 'บริษัท ผู้ขายนำเข้าไฟล์ จำกัด']);
t('นำเข้าไฟล์ที่ไม่มีคอลัมน์ธง ต้องไม่เปลี่ยนธงเดิมของแถวที่มีอยู่แล้ว',
    $importedCustomer && (int) $importedCustomer->is_customer === 0 && (int) $importedCustomer->is_supplier === 1
    && $importedCustomer->phone === '029999999');

$rows = \Inventory\Import\Model::rows('product');
t('ส่งออกสินค้าเป็นแถวได้ และจำนวนคอลัมน์ตรงกับหัวตาราง',
    !empty($rows) && count($rows[0]) === count(\Inventory\Import\Model::type('product')['columns']));

// นำเข้าสินค้าใหม่จากไฟล์ ต้องผ่าน Product\Model และยอดตั้งต้นต้องเข้าสมุดบัญชี
$before = count(\Inventory\Import\Model::rows('product'));
$result = \Inventory\Import\Model::importRow('product', [
    'product_code' => 'IMP001', 'topic' => 'สินค้านำเข้าจากไฟล์', 'count_stock' => 1,
    'price' => 90, 'cost' => 40, 'stock' => 12, 'unit' => 'ชิ้น', 'is_active' => 1
]);
t('นำเข้าสินค้าใหม่จากไฟล์ได้', $result === 'new');
$imported = $db->first($T('inventory'), ['sku' => 'IMP001']);
t('ไม่กรอก sku = ใช้รหัสสินค้า และได้หน่วยขายฐานยิงขายได้',
    $imported && (int) $imported->is_active === 1 && \Inventory\Products\Model::detail('IMP001') !== null);
tnum('ยอดตั้งต้นจากไฟล์เข้าสมุดบัญชีแล้ว', 12, \Inventory\Posting\Model::balance((int) $imported->id));
$row = $db->first($T('inventory_stock_movement'), ['inventory_id' => (int) $imported->id]);
t('ยอดจากไฟล์ใช้ชนิด opening ไม่ใช่เขียนทับคอลัมน์ stock', $row && $row->movement_type === 'opening');

// นำเข้าไฟล์เดิมซ้ำต้องไม่ทำให้ยอดกระโดด
\Inventory\Import\Model::importRow('product', [
    'product_code' => 'IMP001', 'topic' => 'สินค้านำเข้าจากไฟล์', 'count_stock' => 1,
    'price' => 95, 'cost' => 40, 'stock' => 999, 'unit' => 'ชิ้น', 'is_active' => 1
]);
tnum('นำเข้าไฟล์เดิมซ้ำ ยอดคงเหลือไม่เปลี่ยน', 12, \Inventory\Posting\Model::balance((int) $imported->id));
$imported = $db->first($T('inventory'), ['sku' => 'IMP001']);
tnum('แต่ราคาที่แก้ในไฟล์ถูกอัปเดต', 95, (float) $imported->price);

// แบบที่สองของสินค้าเดิม — product_code เดิม sku ใหม่ = variant ใหม่ ไม่ใช่สินค้าใหม่
$result = \Inventory\Import\Model::importRow('product', [
    'product_code' => 'IMP001', 'topic' => 'สินค้านำเข้าจากไฟล์', 'count_stock' => 1, 'option1_name' => 'ขนาด',
    'sku' => 'IMP001-L', 'option1' => 'L', 'price' => 120, 'stock' => 5, 'unit' => 'ชิ้น'
]);
$importedL = $db->first($T('inventory'), ['sku' => 'IMP001-L']);
t('บรรทัดที่ product_code เดิม sku ใหม่ = แบบใหม่ของสินค้าเดิม', $result === 'new' && $importedL
    && (int) $importedL->product_id === (int) $imported->product_id);
tnum('ยอดตั้งต้นของแบบใหม่เข้าสมุดบัญชีแยกกอง', 5, \Inventory\Posting\Model::balance((int) $importedL->id));
t('ส่งออกได้บรรทัดละแบบ', count(\Inventory\Import\Model::rows('product')) === $before + 2);

// -----------------------------------------------------------------------------
group('หน่วยขาย/ชิ้น — ลงทะเบียน ลบ และฉลากบาร์โค้ด (Inventory\\Items)');

// ลงทะเบียนชิ้น ≠ มีของ — ของมาจากการรับเข้าเท่านั้น (สมุดบัญชีคือความจริง)
$pi = product('ITM01', 'สินค้านับแยกรายชิ้น', 2);
$sn = \Inventory\Items\Model::addSerial($pi, 'SN-001');
tnum('ลงทะเบียนหมายเลขเครื่องแล้วยอดยังเป็น 0', 0, \Inventory\Posting\Model::totalBalance($pi));
tthrows('หมายเลขเครื่องซ้ำในสินค้าเดียวกันไม่ได้', function () use ($pi) {
    \Inventory\Items\Model::addSerial($pi, 'SN-001');
}, 'ถูกใช้ไปแล้ว');
$pi2 = product('ITM02', 'สินค้านับแยกอีกตัว', 2);
tthrows('หมายเลขเครื่องที่เป็นของสินค้าอื่นใช้ซ้ำไม่ได้', function () use ($pi2) {
    \Inventory\Items\Model::addSerial($pi2, 'SN-001');
}, 'ถูกใช้ไปแล้ว');

$snSpare = \Inventory\Items\Model::addSerial($pi, 'SN-002');
t('ลบชิ้นที่ยังไม่เคยใช้ได้', \Inventory\Items\Model::remove($snSpare) === true);
\Inventory\Posting\Model::receipt(['inventory_id' => $pi, 'inventory_item_id' => $sn, 'quantity' => 1, 'unit_cost' => 500, 'reference_type' => 'adjustment']);
tthrows('ลบชิ้นที่รับเข้าแล้วไม่ได้ (สมุดบัญชีอ้างอยู่)', function () use ($sn) {
    \Inventory\Items\Model::remove($sn);
}, 'ปิดการใช้งานแทน');

t('บาร์โค้ดออกมาเป็นรูป data URI', strpos(\Inventory\Items\Model::barcodeImage('SN-001'), 'data:image/png;base64,') === 0);
t('ไม่มีรหัส = ไม่วาดบาร์โค้ด', \Inventory\Items\Model::barcodeImage('') === '');

// -----------------------------------------------------------------------------
group('หน้าย่อยของสินค้า — ความเคลื่อนไหวสต๊อก (Inventory\\Stocks)');

$ps = product('STK01', 'สินค้าดูความเคลื่อนไหว');
\Inventory\Posting\Model::opening($ps, 0, 100, 7);
\Inventory\Posting\Model::issue([
    'inventory_id' => $ps,
    'movement_type' => 'sale',
    'quantity' => 30,
    'reference_type' => 'order',
    'reference_id' => 0
]);

$sum = \Inventory\Stocks\Model::summary($ps);
tnum('รับเข้าสะสม = 100', 100, $sum['buy']);
tnum('จ่ายออกสะสม = 30', 30, $sum['sell']);
tnum('ยอดคงเหลือ = 70', 70, $sum['instock']);
tnum('ต้นทุนของที่จ่ายออก = 30 x 7', 210, $sum['cost']);
tnum('มูลค่าคงเหลือตาม FIFO = 70 x 7', 490, $sum['balances']);

// ⚠️ ข้อนี้จับ bug ที่รุ่นเดิมมี : รุ่นเดิมเดาทิศทางจาก "ชนิดเอกสาร" พอ status
// กลายเป็นชนิดการเคลื่อนไหว การเทียบจะเป็นเท็จเสมอและขาออกจะแสดงเป็นค่าบวก
$rows = \Inventory\Stocks\Model::toDataTable(['inventory_id' => $ps])
    ->execute(null, 'array')->fetchAll();
t('ตารางความเคลื่อนไหวมีสองแถว', count($rows) === 2);
$dirs = [];
foreach ($rows as $row) {
    $dirs[$row['status']] = $row['movement_direction'];
}
t('opening เป็นขาเข้า · sale เป็นขาออก ในตาราง',
    ($dirs['opening'] ?? '') === 'in' && ($dirs['sale'] ?? '') === 'out');
// ⚠️ reference_type อยู่ในนี้ด้วยเพราะ QueryBuilder::select() **เขียนทับ** รายการ
// คอลัมน์ทั้งชุด ส่วน selectRaw() ต่อท้าย — เผลอเรียก select() คั่นหลัง selectRaw()
// เมื่อไร คอลัมน์หายเกือบหมดและตารางคืนค่าว่างโดยไม่มีข้อผิดพลาดให้เห็น
$need = ['create_date', 'status', 'price', 'total', 'order_id', 'order_no', 'quantity',
    'movement_direction', 'reference_type', 'topic'];
$missingCols = array_values(array_diff($need, array_keys($rows[0])));
t('ตารางส่งคอลัมน์ที่หน้าเว็บอ้างครบ'.(empty($missingCols) ? '' : ' (ขาด '.implode(', ', $missingCols).')'),
    empty($missingCols));

$months = \Inventory\Stocks\Model::monthlyReport($ps, (int) date('Y'));
t('รายงานรายเดือนคืน 12 เดือนเสมอ', count($months) === 12);
$thisMonth = $months[(int) date('n') - 1];
tnum('เดือนนี้รับเข้า 100', 100, $thisMonth['buy']);
tnum('เดือนนี้จ่ายออก 30', 30, $thisMonth['sell']);

$years = \Inventory\Stocks\Model::listYears($ps);
t('ตัวเลือกปีมีปีปัจจุบันเสมอ', in_array((int) date('Y'), array_column($years, 'value'), true));

$empty = \Inventory\Stocks\Model::summary(product('STK02', 'สินค้าไม่มีการเคลื่อนไหว'));
t('สินค้าที่ยังไม่มีการเคลื่อนไหวคืนศูนย์ทุกช่อง ไม่ใช่ error',
    $empty['buy'] == 0 && $empty['sell'] == 0 && $empty['instock'] == 0 && $empty['balances'] == 0);

// -----------------------------------------------------------------------------
group('สัญญาของคอลัมน์ orders.total — ต้องเหมือนกันทุกทางที่เขียนเอกสาร');

// ⚠️ ข้อนี้จับข้อบกพร่องที่เกิดจริง : `orders.total` เก็บ **ยอดหลังส่วนลด
// ไม่รวมภาษี** และทุกที่ในระบบคิดยอดที่ต้องจ่ายเป็น `total + vat - tax`
// ถ้าทางไหนเก็บยอดรวมภาษีลง total ทางนั้นจะถูกบวกภาษีซ้ำอีกรอบเงียบ ๆ
// (หน้ารับชำระเคยแสดง 228 สำหรับบิลที่ต้องจ่าย 214 มาแล้ว)
$sum = \Inventory\Cart\Model::totals([['total' => 1000, 'vat' => 1]], 7, 1);
tnum('แยกภาษี — total ไม่รวมภาษี', 1000, $sum['total']);
tnum('แยกภาษี — vat แยกออกมา', 70, $sum['vat']);
tnum('แยกภาษี — payable = total + vat', 1070, $sum['payable']);

$sum = \Inventory\Cart\Model::totals([['total' => 1070, 'vat' => 1]], 7, 2);
tnum('รวมภาษีแล้ว — vat ถูกแยกออกจากราคา', 70, $sum['vat']);
tnum('รวมภาษีแล้ว — total คือยอดหักภาษีออกแล้ว', 1000, $sum['total']);
tnum('รวมภาษีแล้ว — payable เท่าราคาที่ตั้งไว้', 1070, $sum['payable']);

// สูตรที่ผู้อ่านค่าใช้ ต้องได้ payable กลับมาเสมอ
$sum = \Inventory\Cart\Model::totals([['total' => 500, 'vat' => 1]], 7, 1);
tnum('สูตร total + vat - tax ให้ผลเท่ากับ payable',
    $sum['payable'], $sum['total'] + $sum['vat'] - $sum['tax']);

// ทุกที่ที่คิดยอดที่ต้องจ่ายต้องใช้สูตรเดียวกัน — ตรวจจากซอร์สว่าไม่มีใครคิดเอง
foreach ([
    'modules/inventory/models/orders.php',
    'modules/inventory/models/dashboard.php'
] as $_f) {
    t($_f.' ใช้สูตร total + vat - tax',
        preg_match('/`total`\s*\+\s*O?\.?`?vat`?\s*-\s*O?\.?`?tax`?/',
            file_get_contents(ROOT_PATH.$_f)) === 1);
}

// -----------------------------------------------------------------------------
group('ลำดับคำสั่งของตัวติดตั้ง — โมดูลเสริมขยายตารางของโมดูลอื่นได้');

// นโยบาย 2026-09-12 : โมดูลขยายตารางที่มีอยู่ได้ (ห้ามตัดคอลัมน์ออก) เช่นโมดูล
// ส่งของเพิ่ม shipping_* ลงตาราง `orders` ของโมดูล inventory
//
// ⚠️ ข้อนี้จับกับดักที่ "ชื่อโฟลเดอร์โมดูลเป็นตัวตัดสินว่าติดตั้งใหม่จะล้มหรือไม่"
// schemaFiles() เรียงไฟล์โมดูลตามตัวอักษร โมดูลชื่อขึ้นต้นด้วย a-h จึงมาก่อน
// modules/inventory แล้ว ALTER จะวิ่งใส่ตารางที่ยังไม่ถูกสร้าง
require_once ROOT_PATH.'install/common.php';
$_cmds = schemaCommands('app');
t('schemaCommands() คืนคำสั่งออกมาได้', is_array($_cmds) && count($_cmds) > 10);

$_lastCreate = -1;
$_firstAlter = PHP_INT_MAX;
$_firstInsert = PHP_INT_MAX;
foreach ($_cmds as $_i => $_c) {
    $_head = ltrim($_c);
    if (preg_match('/^CREATE\s/i', $_head)) {
        $_lastCreate = $_i;
    } elseif (preg_match('/^ALTER\s+TABLE/i', $_head) && $_i < $_firstAlter) {
        $_firstAlter = $_i;
    } elseif (preg_match('/^INSERT\s/i', $_head) && $_i < $_firstInsert) {
        $_firstInsert = $_i;
    }
}
t('CREATE TABLE ทุกตัวมาก่อน ALTER TABLE ทุกตัว',
    $_firstAlter === PHP_INT_MAX || $_lastCreate < $_firstAlter);
t('ALTER TABLE ทุกตัวมาก่อน INSERT ทุกตัว',
    $_firstAlter === PHP_INT_MAX || $_firstInsert === PHP_INT_MAX || $_firstAlter < $_firstInsert);
t('ข้อมูลตั้งต้นใส่หลังตารางถูกสร้างครบแล้ว',
    $_firstInsert === PHP_INT_MAX || $_lastCreate < $_firstInsert);

// ตัวติดตั้งกับตัวปรับรุ่นต้องอ่านรายการเดียวกัน ไม่งั้นฐานที่ติดตั้งใหม่กับฐานที่
// ปรับรุ่นมาจะต่างกันถาวร (เป็นเหตุผลที่มี cli-fresh กับชุด F1-F7 ตั้งแต่แรก)
foreach (['install/cli-fresh.php', 'install/step4.php'] as $_f) {
    t($_f.' ใช้ schemaCommands() ตัวเดียวกัน',
        strpos(file_get_contents(ROOT_PATH.$_f), 'schemaCommands(') !== false);
}

// -----------------------------------------------------------------------------
group('ปรับยอดสินค้าคงคลัง (Inventory\\Stocks::adjust)');

// ระบบเดิมมีปุ่มนี้แต่ฟอร์มของมัน query ตาราง `product` ที่ไม่มีอยู่ในสคีมาของ oms
// กดแล้วพังทุกครั้ง — ชุดนี้จึงคุมทั้งเส้นทางใหม่ ไม่ใช่แค่ "ยกของเดิมมา"
$pa = product('ADJ01', 'สินค้าปรับยอด');
\Inventory\Posting\Model::opening($pa, 0, 100, 5);

$up = \Inventory\Stocks\Model::adjust($pa, 0, 120, 'นับสต๊อกประจำเดือน พบเกิน', 1);
tnum('ปรับยอดขึ้นลงบัญชีเฉพาะส่วนต่าง', 20, $up['difference']);
tnum('ยอดคงเหลือหลังปรับขึ้น', 120, \Inventory\Posting\Model::balance($pa, 0));
$mv = $db->first($T('inventory_stock_movement'), ['id' => $up['movement_id']]);
t('ส่วนต่างบวกลงเป็น adjust_in ขาเข้า',
    $mv->movement_type === 'adjust_in' && $mv->movement_direction === 'in');
t('อ้างอิงเป็น adjustment ไม่ใช่เอกสาร', $mv->reference_type === 'adjustment');
t('เหตุผลถูกเก็บไว้ในสมุดบัญชี', $mv->note === 'นับสต๊อกประจำเดือน พบเกิน');
t('บันทึกว่าใครเป็นคนปรับ', (int) $mv->created_by === 1);

$down = \Inventory\Stocks\Model::adjust($pa, 0, 90, 'ของชำรุด ตัดออก', 1);
tnum('ปรับยอดลงคิดส่วนต่างจากยอดล่าสุด ไม่ใช่ยอดตั้งต้น', -30, $down['difference']);
tnum('ยอดคงเหลือหลังปรับลง', 90, \Inventory\Posting\Model::balance($pa, 0));
t('ส่วนต่างลบลงเป็น adjust_out ขาออก',
    $db->first($T('inventory_stock_movement'), ['id' => $down['movement_id']])->movement_type === 'adjust_out');

// ⚠️ ข้อนี้คือหัวใจของการกันคนสองคนเขียนทับกัน : ยอดปัจจุบันต้องอ่านจากฐานตอน
// บันทึก ไม่ใช่เชื่อค่าที่ฟอร์มส่งมา ถ้าเชื่อฟอร์ม คนที่บันทึกทีหลังจะกลืนงานคนแรก
tnum('ยอดคงเหลือหลังปรับสองครั้งเท่ากับผลรวมของสมุดบัญชี',
    90, \Inventory\Posting\Model::balance($pa, 0));

tthrows('ยอดใหม่เท่ายอดเดิมต้องถูกปฏิเสธ ไม่ใช่บันทึกแถวศูนย์',
    function () use ($pa) {
        \Inventory\Stocks\Model::adjust($pa, 0, 90, 'ไม่มีอะไรเปลี่ยน', 1);
    }, 'เท่ากับ');
tthrows('ไม่กรอกเหตุผลต้องถูกปฏิเสธ',
    function () use ($pa) {
        \Inventory\Stocks\Model::adjust($pa, 0, 50, '   ', 1);
    });
tnum('การปรับที่ถูกปฏิเสธไม่ทิ้งแถวไว้ในสมุดบัญชี',
    3, $db->count($T('inventory_stock_movement'), ['inventory_id', $pa]));

// การปรับยอดต้องโผล่ในตารางความเคลื่อนไหวของหน้าสินค้าด้วย ไม่งั้นผู้ใช้ปรับแล้ว
// ไม่เห็นว่าเกิดอะไรขึ้น (รายการปรับยอดไม่มีเอกสาร จึงไม่มีเลขที่เอกสารให้แสดง)
$rows = \Inventory\Stocks\Model::toDataTable(['inventory_id' => $pa, 'status' => 'adjust_in'])
    ->execute(null, 'array')->fetchAll();
t('กรองตารางด้วยชนิด adjust_in ได้ผลลัพธ์', count($rows) === 1);

// หน่วยย่อย — ปรับยอดทีละหน่วย และห้ามข้ามไปปรับของสินค้าตัวอื่น
$pb = product('ADJ02', 'สินค้าปรับยอดรายชิ้น', 2);
$ia = \Inventory\Items\Model::addSerial($pb, 'ADJ02-A');
$ib = \Inventory\Items\Model::addSerial($pb, 'ADJ02-B');
\Inventory\Posting\Model::receipt(['inventory_id' => $pb, 'inventory_item_id' => $ia, 'quantity' => 1, 'unit_cost' => 80]);
\Inventory\Stocks\Model::adjust($pb, $ib, 1, 'นับพบเครื่องที่ไม่ได้ลงรับ', 1);
tnum('ปรับยอดของชิ้นที่เลือก', 1, \Inventory\Posting\Model::balance($pb, $ib));
tnum('ชิ้นอื่นไม่ถูกกระทบ', 1, \Inventory\Posting\Model::balance($pb, $ia));

t('หน่วยย่อยของสินค้าตัวเองผ่านการตรวจ', \Inventory\Stocks\Model::ownsItem($pb, $ia));
t('หน่วยย่อยของสินค้าตัวอื่นถูกปฏิเสธ', !\Inventory\Stocks\Model::ownsItem($pa, $ia));
t('ยอดระดับสินค้า (id 0) ผ่านเสมอ', \Inventory\Stocks\Model::ownsItem($pa, 0));

t('สินค้านับรวมไม่มีตัวเลือกหน่วยย่อยให้เลือก',
    \Inventory\Stocks\Model::unitOptions($pa, 1) === []);
$units = \Inventory\Stocks\Model::unitOptions($pb, 2);
t('สินค้านับแยกรายชิ้นได้ตัวเลือกครบทุกชิ้น', count($units) === 2);
t('ตัวเลือกบอกหมายเลขเครื่องและยอดของชิ้นนั้น',
    strpos($units[0]['text'], 'ADJ02-A') !== false && strpos($units[0]['text'], '1') !== false && (int) $units[0]['value'] === $ia);

// ป้ายชื่อ "ที่มา" ของแต่ละแถว — ยอดยกมากับการปรับยอดต้องไม่ถูกเรียกชื่อเดียวกัน
$src = \Inventory\Stocks\Model::describeSource(null, 0, 'opening', 'ยอดยกมาตอนเริ่มใช้สมุดบัญชีสต๊อก');
t('ยอดยกมาป้ายว่า "ยอดยกมา"', $src['order_no'] === \Kotchasan\Language::get('Beginning Inventory'));
t('ยอดยกมาไม่เอาหมายเหตุของระบบมาแสดง', $src['note'] === '');

$src = \Inventory\Stocks\Model::describeSource(null, 0, 'adjustment', 'ของชำรุด');
t('การปรับยอดป้ายว่า "ปรับยอดสินค้าคงคลัง" ไม่ใช่ "ยอดยกมา"',
    $src['order_no'] === \Kotchasan\Language::get('Inventory Adjust'));
t('การปรับยอดแสดงเหตุผลที่ผู้ใช้กรอกไว้', $src['note'] === 'ของชำรุด');
t('แถวที่ไม่มีเอกสารไม่มีลิงก์', $src['order_url'] === '');

$src = \Inventory\Stocks\Model::describeSource('INV2601-0001', 12, 'order', 'INV2601-0001');
t('แถวที่มาจากเอกสารคงเลขที่เดิมและมีลิงก์ไปหน้าเอกสาร',
    $src['order_no'] === 'INV2601-0001' && $src['order_url'] === '/inventory-order?id=12');
t('แถวที่มาจากเอกสารไม่แสดงหมายเหตุซ้ำกับเลขที่', $src['note'] === '');

$src = \Inventory\Stocks\Model::describeSource('', 12, 'order', '');
t('เอกสารเก่าที่ไม่มีเลขที่แสดง #id แทนช่องว่าง', $src['order_no'] === '#12');

// โครงสร้างฝั่งหน้าเว็บของปุ่มปรับยอด
foreach (['modal', 'adjust'] as $method) {
    t('มี \Inventory\Stocks\Controller::'.$method.'()',
        method_exists('\Inventory\Stocks\Controller', $method));
}
t('มีแม่แบบ inventory/stock-adjust.html',
    is_file(ROOT_PATH.'templates/inventory/stock-adjust.html'));
$adjustTpl = file_get_contents(ROOT_PATH.'templates/inventory/stock-adjust.html');
t('ฟอร์มปรับยอดส่งไปที่ api/inventory/stocks/adjust',
    strpos($adjustTpl, 'action="api/inventory/stocks/adjust"') !== false);
$stockTpl = file_get_contents(ROOT_PATH.'templates/inventory/product-stock.html');
t('หน้าสต๊อกมีปุ่มเปิดฟอร์มปรับยอด',
    strpos($stockTpl, 'api/inventory/stocks/modal') !== false);
// ⚠️ ปุ่มซ่อนตัวเองด้วยค่าจาก product/page ถ้า page ไม่ส่งค่านี้มา ปุ่มจะหายทั้งที่มีสิทธิ์
t('ปุ่มปรับยอดผูกกับ can_adjust_stock',
    strpos($stockTpl, 'can_adjust_stock') !== false
    && strpos(file_get_contents(ROOT_PATH.'modules/inventory/controllers/product.php'),
        "'can_adjust_stock'") !== false);

// ⚠️ ข้อนี้จับข้อบกพร่องที่มีอยู่จริงทั้งสามปุ่ม : ปุ่มที่เปิดหน้าต่างซ้อนด้วย
// data-modal-api ยิงมาเป็น POST เสมอ (ModalDataBinder.loadModalFromApi ใช้
// client.post) แต่คอนโทรลเลอร์บังคับ GET ทุกตัว ปุ่ม "เพิ่มลูกค้า" และ
// "เพิ่มสินค้า" จึงตอบ 405 แล้วกดไม่ขึ้นเลย โดยไม่มีอะไรฟ้องให้เห็น
$methodBefore = $_SERVER['REQUEST_METHOD'];
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach ([
    'api/inventory/customer/modal' => ['\Inventory\Customer\Controller', 'modal'],
    'api/inventory/product/modal' => ['\Inventory\Product\Controller', 'modal'],
    'api/inventory/stocks/modal' => ['\Inventory\Stocks\Controller', 'modal']
] as $endpoint => $target) {
    list($class, $method) = $target;
    $controller = new $class();
    $response = $controller->$method(new \Kotchasan\Http\Request());
    // ยังไม่ได้เข้าระบบจึงต้องได้ 401 — ถ้าได้ 400/405 แปลว่าตายตั้งแต่ด่านตรวจเมธอด
    t($endpoint.' รับคำขอแบบ POST ได้ (ปุ่ม data-modal-api ยิง POST)',
        $response->getStatusCode() === 401);
}
$_SERVER['REQUEST_METHOD'] = $methodBefore;

// -----------------------------------------------------------------------------
group('หน้าย่อยของสินค้า — รายละเอียดเพิ่มเติม (Inventory\\Detail)');

$pd = (int) $db->first($T('inventory'), ['id' => product('DTL01', 'สินค้ามีรายละเอียด')])->product_id;
\Inventory\Detail\Model::save($pd, ['description' => 'คำโปรย', 'detail' => 'รายละเอียดยาว ๆ']);
$metas = [];
foreach ($db->select($T('inventory_meta'), ['product_id', $pd]) as $row) {
    $metas[$row->name] = $row->value;
}
t('บันทึก description และ detail ลง inventory_meta',
    ($metas['description'] ?? '') === 'คำโปรย' && ($metas['detail'] ?? '') === 'รายละเอียดยาว ๆ');
$prod = $db->first($T('inventory_product'), ['id' => $pd]);
t('เขียนคอลัมน์ inventory_product.description ให้ตรงกันด้วย', $prod->description === 'คำโปรย');

// บันทึกซ้ำต้องไม่เกิดแถว meta ซ้อน
\Inventory\Detail\Model::save($pd, ['description' => 'คำโปรยใหม่', 'detail' => 'รายละเอียดยาว ๆ']);
$count = $db->count($T('inventory_meta'), [['product_id', $pd], ['name', 'description']]);
tnum('บันทึกซ้ำแล้ว meta ไม่ซ้อนกัน', 1, $count);

// ค่าว่างต้องลบแถวทิ้ง ไม่ใช่เก็บสตริงว่างไว้
\Inventory\Detail\Model::save($pd, ['description' => '', 'detail' => '']);
tnum('บันทึกค่าว่างแล้วแถว meta ถูกลบ', 0, $db->count($T('inventory_meta'), ['product_id', $pd]));

t('ไม่มีไฟล์รูป = imageUrl คืน null', \Inventory\Detail\Model::imageUrl($pd) === null);

// -----------------------------------------------------------------------------
group('โครงสร้างหน้าย่อยของสินค้า');

$adminJs = file_get_contents(ROOT_PATH.'modules/inventory/admin.js');
foreach (['/inventory-overview', '/inventory-barcode', '/inventory-detail', '/inventory-stock'] as $route) {
    t('มี route '.$route, strpos($adminJs, "register('".$route."'") !== false);
}
foreach (['product-overview', 'product-items', 'product-detail', 'product-stock'] as $tpl) {
    t('มีแม่แบบ '.$tpl.'.html', is_file(ROOT_PATH.'templates/inventory/'.$tpl.'.html'));
}
foreach ([
    '\Inventory\Detail\Controller' => ['get', 'save', 'removeImage'],
    '\Inventory\Items\Controller' => ['get', 'save'],
    '\Inventory\Overview\Controller' => ['get', 'chart'],
    '\Inventory\Product\Controller' => ['page']
] as $class => $methods) {
    foreach ($methods as $method) {
        t('มี '.$class.'::'.$method.'()', method_exists($class, $method));
    }
}
t('โมดูลมีแฟ้มสไตล์ของตัวเอง (index.php โหลดให้อัตโนมัติ)',
    is_file(ROOT_PATH.'modules/inventory/styles.css'));

// ⚠️ ปุ่มที่เปิดหน้าต่างซ้อนต้องได้ action ชนิด modal พร้อม "ชื่อแม่แบบ" กลับไป
// ถ้าคืนแค่ข้อมูล เบราว์เซอร์ไม่รู้จะเปิดไฟล์ไหน ปุ่มจะกดแล้วเงียบสนิท ไม่มี error
// (โมดูลกลางเคยลอกมาไม่ครบตรงจุดนี้ทั้งสองปุ่ม)
foreach ([
    'modules/inventory/controllers/product.php' => 'inventory/product-modal.html',
    'modules/inventory/controllers/customer.php' => 'inventory/customer-modal.html'
] as $file => $template) {
    $src = file_get_contents(ROOT_PATH.$file);
    $modal = strpos($src, 'public function modal') === false ? '' :
        substr($src, strpos($src, 'public function modal'), 2000);
    t(basename($file, '.php').'/modal คืน action ชนิด modal',
        strpos($modal, "'type' => 'modal'") !== false);
    t(basename($file, '.php').'/modal ชี้ไปที่ '.$template,
        strpos($modal, "'".$template."'") !== false);
    t('มีแม่แบบ '.$template, is_file(ROOT_PATH.'templates/'.$template));
}

// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
group('สูตรคำนวณยอดของเอกสาร (ฝั่ง JS)');

// ตรรกะคำนวณอยู่ใน admin.js จึงต้องรันด้วย node ไม่ใช่ PHP — เรียกต่อจากที่นี่
// เพื่อให้คำสั่งเดียวได้ผลครบทั้งสองฝั่ง ไม่ต้องจำว่ามีชุดทดสอบสองชุด
$calc = __DIR__.'/calc.cjs';
if (!is_file($calc)) {
    t('มีชุดทดสอบสูตรคำนวณฝั่ง JS', false);
} else {
    $output = [];
    $code = 0;
    exec('node '.escapeshellarg($calc).' 2>&1', $output, $code);
    $summary = '';
    foreach ($output as $line) {
        if (strpos($line, 'ผ่าน ') === 0) {
            $summary = trim($line);
        }
    }
    t('สูตรคำนวณยอดของเอกสารผ่านทุกข้อ'.($summary === '' ? '' : ' ('.$summary.')'), $code === 0);
    if ($code !== 0) {
        foreach ($output as $line) {
            if (strpos($line, '[FAIL]') !== false) {
                echo '         '.trim($line)."\n";
            }
        }
    }
}

// -----------------------------------------------------------------------------
group('สมุดบัญชีสต๊อก — เปิดดูได้ ไม่ใช่มีแต่เก็บไว้');

// ⚠️ ก่อนมีหน้านี้ ระบบมีสมุดบัญชีแต่ไม่มีทางเปิดดูข้ามสินค้า : ยอดคงเหลือที่เห็น
// เป็นแค่ cache ถ้ามันเพี้ยน ไม่มีใครหาสาเหตุได้ — ตัวเลขที่ตรวจสอบไม่ได้เชื่อไม่ได้
$ledgerRows = \Inventory\Ledger\Model::movements([])->execute(null, 'array')->fetchAll();
t('สมุดบัญชีอ่านได้และมีรายการ', count($ledgerRows) > 0);
t('แต่ละแถวบอกได้ว่าเป็นสินค้าอะไร',
    !empty($ledgerRows) && array_key_exists('product_topic', $ledgerRows[0]));

$sales = \Inventory\Ledger\Model::movements(['movement_type' => 'sale'])
    ->execute(null, 'array')->fetchAll();
$onlySale = true;
foreach ($sales as $row) {
    if ($row['movement_type'] !== 'sale') {
        $onlySale = false;
    }
}
t('กรองตามชนิดการเคลื่อนไหวได้', $onlySale && count($sales) > 0);

$out = \Inventory\Ledger\Model::movements(['movement_direction' => 'out'])
    ->execute(null, 'array')->fetchAll();
$onlyOut = true;
foreach ($out as $row) {
    if ($row['movement_direction'] !== 'out') {
        $onlyOut = false;
    }
}
t('กรองตามทิศทางได้', $onlyOut && count($out) > 0);

// ⚠️ ข้อนี้คือกับดักเดิมของโปรเจ็คนี้ : where([...], 'OR') กระจายออกมาแล้วตัวกรอง
// ที่ต่อกันไว้ก่อนหน้าหายผลทันทีที่มีคำค้น ต้องครอบเป็นวงเล็บเดียวเสมอ
$searchSale = \Inventory\Ledger\Model::movements(['movement_type' => 'sale', 'search' => 'ทดสอบ'])
    ->execute(null, 'array')->fetchAll();
$stillSale = true;
foreach ($searchSale as $row) {
    if ($row['movement_type'] !== 'sale') {
        $stillSale = false;
    }
}
t('ค้นหาพร้อมกรองแล้วตัวกรองต้องไม่หลุด', $stillSale);

// ชนิดการเคลื่อนไหวต้องแปลที่เดียว ไม่งั้นสองหน้าเรียกของเดียวกันคนละชื่อ
$labelA = \Inventory\Ledger\Model::movementTypeLabel('sale');
t('ชนิดการเคลื่อนไหวมีชื่อภาษาคน ไม่ใช่รหัสดิบ',
    $labelA !== 'sale' && mb_strpos($labelA, '(') !== false);
t('ชนิดที่ไม่อยู่ในทะเบียนยังต้องอ่านออก ไม่ใช่หายไปเฉย ๆ',
    mb_strpos(\Inventory\Ledger\Model::movementTypeLabel('ชนิดที่เลิกใช้'), 'ชนิดที่เลิกใช้') === 0);
$typeOptions = \Inventory\Ledger\Model::movementTypeOptions();
tnum('ตัวเลือกชนิดมาจากทะเบียนกลาง ไม่ใช่ SELECT DISTINCT จากข้อมูล',
    count(\Inventory\Base\Model::movementTypes()) + 1, count($typeOptions));

// -----------------------------------------------------------------------------
group('บล็อกบนหน้าแรก — โมดูลส่งตารางให้แกน ไม่ได้สร้างหน้าของตัวเอง');

$fakeAdmin = (object) ['id' => 1, 'status' => 1, 'permission' => '', 'active' => 1];
$blocks = \Inventory\Init\Controller::initDashboardBlocks([], null, $fakeAdmin);
t('โมดูลส่งบล็อกให้หน้าแรกได้', count($blocks) >= 2);

// ⚠️ id ของบล็อกต้องขึ้นต้นด้วยชื่อโมดูล — สองโมดูลที่ส่งตารางมาหน้าเดียวกัน
// แล้วใช้ id ซ้ำ จะทับกันใน TableManager.state.tables แล้วตารางหนึ่งหายไปเงียบ ๆ
$badIds = [];
foreach ($blocks as $block) {
    if (empty($block['id']) || mb_strpos($block['id'], 'inventory') !== 0) {
        $badIds[] = isset($block['id']) ? $block['id'] : '(ไม่มี id)';
    }
}
t('id ของบล็อกขึ้นต้นด้วยชื่อโมดูล'.(empty($badIds) ? '' : ' — ผิด: '.implode(' ', $badIds)),
    empty($badIds));

// ⚠️ และต้องชี้ไป endpoint ที่มีจริง ไม่งั้นหน้าแรกขึ้นตารางว่างโดยไม่มีอะไรบอก
$deadBlocks = [];
foreach ($blocks as $block) {
    if (!preg_match('#^api/([a-z]+)/([a-zA-Z]+)/([a-zA-Z]+)$#', $block['url'], $m)) {
        $deadBlocks[] = $block['url'];
        continue;
    }
    $class = '\\'.ucfirst($m[1]).'\\'.ucfirst($m[2]).'\\Controller';
    if (!class_exists($class) || !method_exists($class, $m[3])) {
        $deadBlocks[] = $block['url'];
    }
}
t('ทุกบล็อกชี้ไป endpoint ที่มีจริง'.(empty($deadBlocks) ? '' : ' — ตาย: '.implode(' ', $deadBlocks)),
    empty($deadBlocks));

$low = \Inventory\Dashboard\Model::lowStock(5);
t('รายการสินค้าใกล้หมดอ่านได้', is_array($low));
t('และเรียงจากยอดน้อยที่สุดขึ้นก่อน',
    count($low) < 2 || $low[0]['balance'] <= $low[count($low) - 1]['balance']);
// ⚠️ ยอดต้องมาจาก inventory_stock (ผลสรุปของสมุดบัญชี) ไม่ใช่ inventory.stock
// ที่เป็น cache — ถ้า cache เพี้ยน หน้าแรกต้องบอกความจริง
$lowSrc = file_get_contents(ROOT_PATH.'modules/inventory/models/dashboard.php');
$lowBody = substr($lowSrc, strpos($lowSrc, 'function lowStock'), 900);
t('ยอดคงเหลือบนหน้าแรกอ่านจากสมุดบัญชี ไม่ใช่จาก cache',
    strpos($lowBody, 'inventory_stock') !== false);

$top = \Inventory\Dashboard\Model::topProducts(5);
t('รายการสินค้าขายดีอ่านได้', is_array($top));
t('และเรียงจากยอดเงินมากที่สุดลงมา',
    count($top) < 2 || $top[0]['amount'] >= $top[count($top) - 1]['amount']);

// -----------------------------------------------------------------------------
group('เอกสารที่ล้มตอนเดินสต๊อกต้องไม่เหลือหัวเอกสารค้าง');

$rsv = product('RSV01', 'สินค้าทดสอบเอกสารที่ล้ม');
\Inventory\Posting\Model::opening($rsv, 0, 10, 50);

// ⚠️ เอกสารที่ล้มตอนเดินสต๊อก (ของไม่พอ) ต้องไม่เหลือหัวเอกสารค้างไว้ — ก่อน 2026-09-12
// save() ไม่ได้อยู่ใน transaction ใบที่ล้มจึงกลายเป็น "ออกแล้ว" โดยไม่มีของเดิน
$docsBefore = $db->count($T('orders'));
tthrows('ใบเสร็จที่ของไม่พอต้องถูกปฏิเสธ', function () {
    \Inventory\Document\Model::save(
        ['document_type' => 'OUT', 'document_status' => 'issued', 'order_no' => 'FAIL-001',
            'order_date' => date('Y-m-d H:i:s'), 'vat_status' => 0, 'total' => 1, 'subtotal' => 1],
        \Inventory\Cart\Model::buildLines([['code' => 'RSV01', 'quantity' => 99999]]), 0, 1
    );
}, 'ไม่พอ');
tnum('และต้องไม่เหลือหัวเอกสารค้างไว้ในตาราง', $docsBefore, $db->count($T('orders')));
tnum('ยอดไม่เปลี่ยน', 10, \Inventory\Posting\Model::balance($rsv));

// -----------------------------------------------------------------------------
group('ชั้นต้นทุน — ตรวจต้นทุนขายย้อนกลับได้');

$layers = \Inventory\Ledger\Model::costLayers([])->execute(null, 'array')->fetchAll();
t('ชั้นต้นทุนอ่านได้', count($layers) > 0);
t('บอกมูลค่าคงเหลือของแต่ละชั้น',
    !empty($layers) && array_key_exists('remaining_value', $layers[0]));

$openLayers = \Inventory\Ledger\Model::costLayers(['remaining' => 'open'])
    ->execute(null, 'array')->fetchAll();
$allOpen = true;
foreach ($openLayers as $row) {
    if ((float) $row['remaining_qty'] <= 0) {
        $allOpen = false;
    }
}
t('กรองเฉพาะชั้นที่ยังมีของได้', $allOpen);

// ชั้นที่ตัดหมดแล้วต้องยังอยู่ — เป็นหลักฐานของต้นทุนที่เคยตัดไป
$closedLayers = \Inventory\Ledger\Model::costLayers(['remaining' => 'closed'])
    ->execute(null, 'array')->fetchAll();
t('ชั้นที่ตัดหมดแล้วยังเก็บไว้เป็นหลักฐาน ไม่ได้ถูกลบทิ้ง',
    count($layers) === count($openLayers) + count($closedLayers));

// -----------------------------------------------------------------------------
group('ทะเบียนคู่ค้า — ลูกค้ากับผู้ขายเป็นธงคนละตัว ไม่ใช่ประเภทเดียว');

$db->delete($T('customer'), ['id', '>', 0], 0);
$both = (int) $db->insert($T('customer'), [
    'customer_no' => 'C001', 'name' => 'บริษัท ซื้อและขาย จำกัด',
    'is_customer' => 1, 'is_supplier' => 1, 'is_active' => 1
]);
$db->insert($T('customer'), [
    'customer_no' => 'C002', 'name' => 'ลูกค้าอย่างเดียว',
    'is_customer' => 1, 'is_supplier' => 0, 'is_active' => 1
]);
$db->insert($T('customer'), [
    'customer_no' => 'C003', 'name' => 'ผู้ขายอย่างเดียว',
    'is_customer' => 0, 'is_supplier' => 1, 'is_active' => 1
]);

$rows = function ($params) {
    return \Inventory\Customers\Model::toDataTable($params)->execute(null, 'array')->fetchAll();
};
tnum('หน้า "ลูกค้า" เห็นเฉพาะที่เป็นลูกค้า', 2, count($rows(['type' => 'customer'])));
tnum('หน้า "คู่ค้า" เห็นเฉพาะที่เป็นผู้ขาย', 2, count($rows(['type' => 'supplier'])));
tnum('ไม่ระบุหน้า = หน้าลูกค้า (เมนูมีแค่ลูกค้ากับคู่ค้า)', 2, count($rows([])));

// ⚠️ เหตุผลที่ใช้ธงสองตัวแทนคอลัมน์ประเภทเดียว : บริษัทที่ทั้งซื้อและขายต้องเป็น
// แถวเดียว ไม่งั้นยอดลูกหนี้กับเจ้าหนี้ของรายเดียวกันจะแยกกันอยู่คนละที่ตลอดไป
$ids = [];
foreach (array_merge($rows(['type' => 'customer']), $rows(['type' => 'supplier'])) as $row) {
    if ((int) $row['id'] === $both) {
        $ids[] = $row['id'];
    }
}
tnum('บริษัทที่เป็นทั้งสองอย่าง โผล่ทั้งสองหน้าโดยใช้แถวเดียว', 2, count($ids));

// ⚠️ กับดักเดิมของไฟล์นี้ : where([...], 'OR') ของคำค้นกระจายออกมาทับตัวกรอง
$found = $rows(['type' => 'supplier', 'search' => 'อย่างเดียว']);
$allSupplier = true;
foreach ($found as $row) {
    if ((int) $row['is_supplier'] !== 1) {
        $allSupplier = false;
    }
}
tnum('ค้นหาในหน้าคู่ค้า ต้องไม่ดึงลูกค้าที่ไม่ใช่คู่ค้ามาด้วย', 1, count($found));
t('และทุกแถวที่ได้ต้องเป็นคู่ค้าจริง', $allSupplier);

// -----------------------------------------------------------------------------
group('ฟอร์มเพิ่ม/แก้ลูกค้า — ปุ่มเพิ่มจากแท็บไหนต้องได้ธงของแท็บนั้น');

// ⚠️ กับดักเดิม : ฟอร์มมี is_customer/is_supplier ให้บันทึกใน save() แต่ไม่มี
// ช่องติ๊กในเทมเพลตเลย กดบันทึกลูกค้าที่มีอยู่ผ่านฟอร์มนี้จึงรีเซ็ตธงเป็น 0 ทุกครั้ง
t('เพิ่มจากแท็บ "ลูกค้า" ได้ธง is_customer=1, is_supplier=0',
    \Inventory\Customer\Controller::defaultFlags('customer') === ['is_customer' => 1, 'is_supplier' => 0]);
t('เพิ่มจากแท็บ "คู่ค้า" ได้ธง is_customer=0, is_supplier=1',
    \Inventory\Customer\Controller::defaultFlags('supplier') === ['is_customer' => 0, 'is_supplier' => 1]);
t('ไม่ระบุมุม (เปิดจากหน้าเอกสาร) ตกเป็นค่าเริ่มต้นของลูกค้า',
    \Inventory\Customer\Controller::defaultFlags('') === ['is_customer' => 1, 'is_supplier' => 0]);

$customerTpl = file_get_contents(ROOT_PATH.'templates/inventory/customer.html');
t('ฟอร์มมีช่องธงลูกค้า/คู่ค้า/ใช้งาน และคอลัมน์ที่ save() รองรับแต่เทมเพลตเคยขาด',
    strpos($customerTpl, 'name="is_customer"') !== false
    && strpos($customerTpl, 'name="is_supplier"') !== false
    && strpos($customerTpl, 'name="is_active"') !== false
    && strpos($customerTpl, 'name="name"') !== false
    && strpos($customerTpl, 'name="tax_id"') !== false
    && strpos($customerTpl, 'name="expense_due_date"') !== false);

// -----------------------------------------------------------------------------
group('ฟอร์มเอกสาร — "บันทึกแล้วสร้างใหม่" และข้อมูลที่ฟอร์มต้องได้');

// ผ่านคอนโทรลเลอร์จริงทั้งเส้น ข้ามแค่ด่านเข้าระบบกับ CSRF ซึ่งไม่ใช่เรื่องที่ทดสอบ
$orderApi = new class extends \Inventory\Order\Controller {
    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        return (object) ['id' => 1, 'status' => 1, 'permission' => '', 'active' => 1, 'social' => 'user'];
    }

    protected function validateCsrfToken(\Kotchasan\Http\Request $request)
    {
    }
};
// แม่แบบของกลุ่มนี้เอง — ชุดข้างบนล้างแม่แบบตั้งต้นทิ้งแล้ว
// TNODUE ไม่ใช้วันครบกำหนด · TDUE ใช้ ทั้งคู่ไม่เดินสต๊อก
foreach ([['TNODUE', 'ใบไม่มีครบกำหนด', 0], ['TDUE', 'ใบมีครบกำหนด', 1]] as $tpl) {
    $db->insert($T('inventory_template'), [
        'document_type' => $tpl[0], 'status' => substr($tpl[0], 0, 3), 'mode' => 'sell', 'topic' => $tpl[1],
        'comment' => '', 'due_date' => $tpl[2], 'stock_in' => 0, 'stock_out' => 0, 'reserve_stock' => 0,
        'movement_type' => null, 'published' => 1, 'prefix' => $tpl[0].'%Y%M-', 'number_format' => '%04d',
        'columns' => 'item,topic,quantity,price,discount,amount'
    ]);
}
\Inventory\Document\Model::clearCache();
$service = product('SAC01', 'ค่าบริการทดสอบสร้างใหม่', 0);
// กลุ่มทะเบียนลูกค้าข้างบนล้างตารางลูกค้าไปแล้ว
$customerId = (int) $db->insert($T('customer'), [
    'customer_no' => 'SAC0001', 'code' => 'SAC0001', 'company' => 'บริษัทสร้างใหม่ จำกัด', 'name' => 'ผู้ติดต่อ',
    'is_customer' => 1, 'is_supplier' => 0, 'is_active' => 1
]);
$today = date('Y-m-d');
// ข้อมูลแบบที่ยกมาจากระบบเดิม: ชนิดที่ไม่ใช้วันครบกำหนด แต่มีวันครบกำหนด = วันที่เอกสาร
$sourceId = \Inventory\Document\Model::save([
    'document_type' => 'TNODUE', 'order_no' => 'TNODUE-SRC-1', 'customer_id' => $customerId,
    'order_date' => '2026-01-15 10:00:00', 'due_date' => '2026-01-15', 'total' => 100
], [[
    'inventory_id' => $service, 'code' => 'SAC01',
    'topic' => 'ค่าบริการทดสอบสร้างใหม่', 'quantity' => 1, 'price' => 100, 'total' => 100
]], 0, 1);

$response = $orderApi->get((new \Kotchasan\Http\Request())->withQueryParams(['id' => $sourceId]));
$loaded = json_decode($response->getContent(), true)['data']['data'];
// ⚠️ กับดักเดิม : ผูกช่อง autocomplete ด้วยชื่อลูกค้าอย่างเดียว ตัวจัดการเขียนชื่อลง
// hidden customer_id ด้วย ทุกการบันทึกจึงส่งชื่อไป แล้ว toInt() ได้ลูกค้า 0
t('ฟอร์มได้ลูกค้าเป็นคู่ {value: id, text: ชื่อ} ให้ช่อง autocomplete',
    $loaded['customer_option'] === ['value' => (string) $customerId, 'text' => 'บริษัทสร้างใหม่ จำกัด']);
t('ชนิดที่แม่แบบไม่ใช้วันครบกำหนด ไม่ส่งวันครบกำหนดที่ค้างมาไปแสดง', $loaded['due_date'] === '');
$dueMap = json_decode($loaded['due_date_map'], true);
t('แผนที่วันครบกำหนดมาเป็น JSON ตามแม่แบบ (TNODUE ไม่ใช้ · TDUE ใช้)',
    is_array($dueMap) && $dueMap['TNODUE'] === 0 && $dueMap['TDUE'] === 1);
t('ฟอร์มได้หัวเรื่องและโหมดของเอกสาร',
    $loaded['template']['topic'] === 'ใบไม่มีครบกำหนด' && $loaded['mode'] === 'sell');
$printTemplate = \Inventory\Document\Model::template('TDUE');
t('แม่แบบที่อ่านมามี comment · due_date · signature_* ที่หน้าพิมพ์ใช้',
    array_key_exists('comment', $printTemplate) && array_key_exists('due_date', $printTemplate)
    && array_key_exists('signature_1', $printTemplate) && array_key_exists('signature_3', $printTemplate));

$postOrder = function (array $fields) use ($orderApi, $service) {
    $body = $fields + [
        'customer_id' => '', 'order_no' => '', 'order_date' => '', 'due_date' => '',
        'items' => [[
            'inventory_id' => $service, 'code' => 'SAC01', 'topic' => 'ค่าบริการทดสอบสร้างใหม่',
            'quantity' => '1', 'price' => '100', 'total' => '100'
        ]]
    ];
    // ⚠️ Request::getMethod() อ่านจาก $_SERVER ทุกครั้ง withMethod() ไม่มีผล
    $methodBefore = $_SERVER['REQUEST_METHOD'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $response = $orderApi->save((new \Kotchasan\Http\Request())->withParsedBody($body));
    $_SERVER['REQUEST_METHOD'] = $methodBefore;

    return json_decode($response->getContent(), true);
};
$lastOrder = function () use ($db, $T) {
    $rows = $db->select($T('orders'), [], ['orderBy' => ['id' => 'DESC'], 'limit' => 1]);

    return empty($rows) ? null : $rows[0];
};

// เปลี่ยนชนิดแล้วติ๊ก "บันทึกแล้วสร้างใหม่" = แปลงเป็นใบใหม่ ฟอร์มยังถือเลขที่และวันที่ของใบเดิม
$result = $postOrder([
    'id' => $sourceId, 'document_type' => 'TDUE', 'save_and_create' => '1',
    'order_no' => 'TNODUE-SRC-1', 'order_date' => '2026-01-15', 'due_date' => '2026-02-15',
    'customer_id' => (string) $customerId
]);
$child = $lastOrder();
t('แปลงชนิดด้วยการสร้างใหม่สำเร็จ', !empty($result['success']) && (int) $child->id !== $sourceId);
t('ใบใหม่ได้เลขที่ใหม่ของชนิดใหม่ ไม่ใช่เลขที่ที่ค้างในฟอร์ม',
    $child->document_type === 'TDUE' && $child->order_no !== 'TNODUE-SRC-1' && strpos($child->order_no, 'TDUE') === 0);
t('ใบใหม่ลงวันที่วันนี้ ไม่ใช่วันที่ของใบเดิม', substr($child->order_date, 0, 10) === $today);
t('ใบใหม่มีลูกค้าคนเดิม', (int) $child->customer_id === $customerId);
t('ใบใหม่ชี้กลับไปหาใบเดิม', (int) $child->source_document_id === $sourceId
    && $child->reference_document_no === 'TNODUE-SRC-1');
t('ชนิดใหม่ใช้วันครบกำหนด จึงเก็บค่าที่ตั้งไว้', $child->due_date === '2026-02-15');
$source = $db->first($T('orders'), ['id' => $sourceId]);
t('ใบเดิมไม่ถูกแตะ', $source->document_type === 'TNODUE' && $source->order_no === 'TNODUE-SRC-1'
    && substr($source->order_date, 0, 10) === '2026-01-15');

// ชนิดเดิม = สำเนา ต้องได้เลขที่ใหม่เหมือนกัน แต่ห้ามผูกสาย (จะไปปลดการจองของใบต้นทาง)
$result = $postOrder([
    'id' => $sourceId, 'document_type' => 'TNODUE', 'save_and_create' => '1',
    'order_no' => 'TNODUE-SRC-1', 'order_date' => '2026-01-15', 'due_date' => '2026-01-15',
    'customer_id' => (string) $customerId
]);
$copy = $lastOrder();
t('สำเนาชนิดเดิมได้ใบใหม่ เลขที่ใหม่ วันที่วันนี้',
    !empty($result['success']) && (int) $copy->id !== (int) $child->id
    && $copy->order_no !== 'TNODUE-SRC-1' && strpos($copy->order_no, 'TNODUE') === 0
    && substr($copy->order_date, 0, 10) === $today);
t('สำเนาชนิดเดิมไม่ผูกสายเอกสาร', empty($copy->source_document_id));
t('ชนิดที่ไม่ใช้วันครบกำหนด ไม่เก็บวันครบกำหนด', $copy->due_date === null);

// ไม่ติ๊ก = แก้ใบเดิม · ช่องวันที่ว่างต้องได้วันนี้ ไม่ใช่ " HH:MM:SS" ที่ลงฐานไม่ได้
$result = $postOrder([
    'id' => $sourceId, 'document_type' => 'TNODUE', 'order_no' => 'TNODUE-SRC-1',
    'customer_id' => (string) $customerId
]);
$source = $db->first($T('orders'), ['id' => $sourceId]);
t('ไม่ติ๊กสร้างใหม่ = บันทึกทับใบเดิม เลขที่เดิม', !empty($result['success'])
    && (int) $lastOrder()->id === (int) $copy->id && $source->order_no === 'TNODUE-SRC-1');
t('ช่องวันที่ว่างได้วันนี้', substr((string) $source->order_date, 0, 10) === $today);

// บรรทัดสินค้าของเอกสารรุ่นก่อนที่ไม่ได้เก็บรหัส (ตัวแปลงทะเบียนคงรหัสว่างไว้ให้หน้าพิมพ์เหมือนเดิม)
// บันทึกซ้ำต้องไม่ทำบรรทัดหาย — เดิมตัวรับฟอร์มข้ามทุกบรรทัดที่ไม่มีรหัส
$result = $postOrder([
    'id' => $sourceId, 'document_type' => 'TNODUE', 'order_no' => 'TNODUE-SRC-1', 'customer_id' => (string) $customerId,
    'items' => [
        ['inventory_id' => $service, 'code' => '', 'topic' => 'บรรทัดเดิมที่ไม่มีรหัส', 'quantity' => '1', 'price' => '100', 'total' => '100'],
        ['code' => '', 'topic' => '', 'quantity' => '1', 'price' => '0', 'total' => '0']
    ]
]);
$_noCode = \Inventory\Document\Model::items($sourceId);
t('บันทึกซ้ำ : บรรทัดสินค้าที่ไม่มีรหัสยังอยู่ (ได้ sku จากทะเบียน) · แถวว่างของตารางถูกข้าม',
    !empty($result['success']) && count($_noCode) === 1 && $_noCode[0]['topic'] === 'บรรทัดเดิมที่ไม่มีรหัส'
    && $_noCode[0]['sku'] === 'SAC01');

$orderTpl = file_get_contents(ROOT_PATH.'templates/inventory/order-edit.html');
t('ช่องชื่อลูกค้าผูกกับ customer_option ไม่ใช่ชื่ออย่างเดียว',
    strpos($orderTpl, 'data-attr="value:customer_option"') !== false
    && strpos($orderTpl, 'data-attr="value:customer"') === false);
t('ตัวเลือกบันทึกแล้วสร้างใหม่ไม่จำค่าข้ามการเปิดหน้า (ค่าเริ่มต้น = ไม่เลือก)',
    preg_match('/<input[^>]*id="save_and_create"[^>]*>/', $orderTpl, $match) === 1
    && strpos($match[0], 'data-persist') === false && strpos($match[0], 'checked') === false);

// -----------------------------------------------------------------------------
group('ผู้มีอำนาจลงนาม — สมาชิกในระบบ ชื่อและลายเซ็นมาจากโปรไฟล์');

// ⚠️ DATA_FOLDER ของชุดนี้คือ datas/ ของโปรเจ็คจริง (มีรูปลายเซ็นบริษัทของจริงอยู่)
// โมเดลจึงถูกทับให้อ่าน/ย้ายรูปในโฟลเดอร์ว่างของกลุ่มนี้เท่านั้น
$companySandbox = 'datas/cache/nowtest-company-'.getmypid().'/';
$company = new class extends \Index\Company\Model {
    public static $folder = '';

    protected static function dataFolder()
    {
        return static::$folder;
    }

    public static function config()
    {
        return self::$cfg;
    }
};
$company::$folder = $companySandbox;
$siteCfg = $company::config();
$companyBefore = isset($siteCfg->company) ? $siteCfg->company : null;
$flatBefore = isset($siteCfg->authorized) ? $siteCfg->authorized : null;
unset($siteCfg->authorized);
$memberImagesBefore = $siteCfg->member_images;
$img = $siteCfg->stored_img_type;
$putImage = function ($path) use ($root) {
    @mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, 'image:'.$path);
};

$signer = (int) $db->insert('user', ['username' => 'signer@test', 'password' => '', 'name' => 'ผู้ลงนาม ทดสอบ', 'status' => 1, 'active' => 1]);
$staff = (int) $db->insert('user', ['username' => 'staff@test', 'password' => '', 'name' => 'พนักงาน ทดสอบ', 'status' => 2, 'active' => 1]);
$member = (int) $db->insert('user', ['username' => 'member@test', 'password' => '', 'name' => 'ลูกค้า ทดสอบ', 'status' => 0, 'active' => 1]);
$retired = (int) $db->insert('user', ['username' => 'retired@test', 'password' => '', 'name' => 'ลาออกแล้ว', 'status' => 2, 'active' => 0]);
$db->insert('user', ['username' => 'twin1@test', 'password' => '', 'name' => 'ชื่อซ้ำ', 'status' => 2, 'active' => 1]);
$db->insert('user', ['username' => 'twin2@test', 'password' => '', 'name' => 'ชื่อซ้ำ', 'status' => 2, 'active' => 1]);

t('ลายเซ็นเป็นรูปของสมาชิก (หน้าข้อมูลส่วนตัวบันทึก/แสดง/ลบได้)', isset($memberImagesBefore['signature']));

$values = function ($options) {
    return array_map(function ($o) {
        return $o['value'];
    }, $options);
};
// ⚠️ ห้ามใช้ชื่อ $options — ตัวรันใช้ชื่อนี้เก็บตัวเลือก --db/--keep
$signerOptions = $company::authorizedOptions();
t('ตัวเลือกแรกคือ "ไม่ได้ระบุ" (ค่าว่าง) จาก API', $signerOptions[0]['value'] === '');
t('เลือกได้เฉพาะสมาชิกที่ใช้งานอยู่และไม่ใช่สมาชิกทั่วไป',
    in_array((string) $signer, $values($signerOptions), true) && in_array((string) $staff, $values($signerOptions), true)
    && !in_array((string) $member, $values($signerOptions), true) && !in_array((string) $retired, $values($signerOptions), true));
t('คนที่เลือกไว้แล้วอยู่ในรายการเสมอ แม้จะถูกปิดใช้งานไปแล้ว',
    in_array((string) $retired, $values($company::authorizedOptions($retired)), true));

// ไซต์ที่เลือกสมาชิกแล้ว
$siteCfg->company = ['authorized_id' => $signer];
$putImage($companySandbox.'signature/'.$signer.$img);
$authorized = $company::authorized();
t('ชื่อบนเอกสารมาจากสมาชิกที่เลือก', $authorized['id'] === $signer && $authorized['name'] === 'ผู้ลงนาม ทดสอบ');
t('ลายเซ็นบนเอกสารมาจากโปรไฟล์ของสมาชิกนั้น',
    substr($authorized['signature'], -strlen($companySandbox.'signature/'.$signer.$img)) === $companySandbox.'signature/'.$signer.$img);
$siteCfg->company = ['authorized_id' => $staff];
t('สมาชิกที่ไม่มีลายเซ็นและไม่มีรูปแบบเดิม ได้ลายเซ็นว่าง', $company::authorized()['signature'] === '');

// ไซต์ที่ยังเป็นแบบเดิม (ชื่อที่พิมพ์เอง + รูปลายเซ็นบริษัท)
$siteCfg->company = ['authorized' => 'พนักงาน ทดสอบ'];
$putImage($companySandbox.'images/company_signature'.$img);
t('ชื่อเดิมตรงกับสมาชิกคนเดียว = สมาชิกคนนั้น', $company::authorizedId() === $staff);
t('ยังไม่ได้ย้ายรูป ใช้รูปลายเซ็นบริษัทเดิมไปก่อน (เอกสารพิมพ์ออกมาเหมือนเดิม)',
    substr($company::authorized()['signature'], -strlen('images/company_signature'.$img)) === 'images/company_signature'.$img);
$siteCfg->company = ['authorized' => 'ชื่อซ้ำ'];
t('ชื่อเดิมซ้ำหลายคน ไม่เดา', $company::authorizedId() === 0);
$authorized = $company::authorized();
t('หาสมาชิกไม่ได้ พิมพ์ชื่อเดิมไปก่อน', $authorized['id'] === 0 && $authorized['name'] === 'ชื่อซ้ำ');
$siteCfg->company = [];
$siteCfg->authorized = 'พนักงาน ทดสอบ';
t('คีย์แบน authorized ของระบบเดิมก็อ่านได้', $company::authorizedId() === $staff);
unset($siteCfg->authorized);
t('ไม่ได้ระบุเลย = ไม่มีผู้มีอำนาจลงนาม', $company::authorized() === null);

// ย้ายรูปลายเซ็นบริษัทเข้าโปรไฟล์ตอนบันทึกหน้าตั้งค่า
t('ยังไม่ได้เลือกสมาชิก ไม่แตะรูปเดิม', $company::adoptLegacySignature(0) === false
    && is_file($root.'/'.$companySandbox.'images/company_signature'.$img));
t('สมาชิกที่ยังไม่มีลายเซ็น รับรูปเดิมไปเป็นของตัวเอง', $company::adoptLegacySignature($staff) === true
    && file_get_contents($root.'/'.$companySandbox.'signature/'.$staff.$img) === 'image:'.$companySandbox.'images/company_signature'.$img
    && !is_file($root.'/'.$companySandbox.'images/company_signature'.$img));
$putImage($companySandbox.'images/company_signature'.$img);
t('สมาชิกที่มีลายเซ็นอยู่แล้ว เก็บของตัวเองไว้ แล้วลบรูปเดิมที่ถูกแทนแล้ว', $company::adoptLegacySignature($signer) === false
    && file_get_contents($root.'/'.$companySandbox.'signature/'.$signer.$img) === 'image:'.$companySandbox.'signature/'.$signer.$img
    && !is_file($root.'/'.$companySandbox.'images/company_signature'.$img));

// หน้าตั้งค่าบริษัท — บันทึกเป็น id และเลิกเก็บชื่อแบบเดิม
// (ใช้เฉพาะ id ที่ไม่มีอยู่จริง เพราะตัวควบคุมจริงย้ายรูปใน datas/ ของโปรเจ็คจริง)
$parse = new \ReflectionMethod(\Index\Settings\Controller::class, 'parseCompanySettings');
$parse->setAccessible(true);
$saved = (object) ['company' => ['authorized' => 'ชื่อเดิม']];
$parse->invoke(new \Index\Settings\Controller(), ['company_authorized_id' => '999999'], $saved);
t('บันทึกสมาชิกที่ไม่มีอยู่จริง = ไม่ได้ระบุ และชื่อแบบเดิมถูกตัดทิ้ง',
    $saved->company['authorized_id'] === 0 && !array_key_exists('authorized', $saved->company));
$companyTpl = file_get_contents(ROOT_PATH.'templates/settings/company.html');
t('หน้าตั้งค่าบริษัทเลือกผู้มีอำนาจลงนามจากสมาชิก ไม่มีช่องพิมพ์ชื่อหรืออัปโหลดลายเซ็นบริษัท',
    strpos($companyTpl, 'name="company_authorized_id"') !== false && strpos($companyTpl, 'data-options-key="company_authorized_id"') !== false
    && strpos($companyTpl, 'name="company_authorized"') === false && strpos($companyTpl, 'company_signature') === false);
t('หน้าข้อมูลส่วนตัวลบลายเซ็นได้ (ปุ่มลบของช่องลายเซ็นมีปลายทางจริง)',
    method_exists(\Index\Profile\Controller::class, 'removeSignature')
    && strpos(file_get_contents(ROOT_PATH.'templates/profile.html'), 'api/index/profile/remove-signature') !== false);

// คืนค่ากำหนดและลบโฟลเดอร์ของกลุ่มนี้
if ($companyBefore === null) {
    unset($siteCfg->company);
} else {
    $siteCfg->company = $companyBefore;
}
if ($flatBefore !== null) {
    $siteCfg->authorized = $flatBefore;
}
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$companySandbox, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($root.'/'.$companySandbox);

// -----------------------------------------------------------------------------
// ยกจาก oms.in.th (แกนเดียวกัน · DESIGN.md ข้อ 7) — ห้ามแก้ให้ต่างจากต้นทาง
group('FIFO เมื่อบันทึกเอกสารซ้ำ T1–T6 (ยกจาก erp install/cli-testfifo.php · Phase 2.6)');

// ⚠️ เอกสารที่แก้แล้วบันทึกใหม่ถูกถอนผลเดิมทั้งใบแล้วลงใหม่ (Document::write) — ชุดนี้พิสูจน์ว่า
// ชั้นต้นทุนยังตรงกับสมุดบัญชี ต้นทุนของใบขายไม่หาย และไม่มี allocation ที่ชี้ชั้นที่ไม่มีแล้ว
$fA = product('F2601', 'สินค้า FIFO ก');
$fB = product('F2602', 'สินค้า FIFO ข');
$fLine = function ($productId, $code, $qty, $price, $id = 0) {
    $row = ['inventory_id' => $productId, 'code' => $code, 'topic' => $code,
        'quantity' => $qty, 'unit' => 'ชิ้น', 'price' => $price, 'total' => $qty * $price];
    if ($id > 0) {
        $row['id'] = $id;
    }

    return $row;
};
$fDoc = function ($type, $no, $date, array $lines, $id = 0) {
    return \Inventory\Document\Model::save(['document_type' => $type, 'order_no' => $no, 'order_date' => $date, 'total' => 0], $lines, $id, 1);
};
$fLineId = function ($orderId) {
    return (int) \Inventory\Document\Model::items($orderId)[0]['id'];
};
// ต้นทุนของใบขาย = allocation ของการเคลื่อนไหวที่ยังไม่ถูกกลับรายการ
$fCost = function ($orderId) {
    $rows = \Kotchasan\Model::createQuery()
        ->selectRaw('SUM(A.`quantity`) AS `qty`, SUM(A.`total_cost`) AS `cost`')
        ->from('inventory_cost_allocation A')
        ->join('inventory_stock_movement M', ['M.id', 'A.movement_id'], 'INNER')
        ->where([['M.reference_type', 'order'], ['M.reference_id', (int) $orderId], ['M.source_movement_id', null]])
        ->whereRaw('NOT EXISTS (SELECT 1 FROM `'.\Inventory\Base\Model::table('inventory_stock_movement').'` R WHERE R.`source_movement_id` = M.`id`)')
        ->execute(null, 'array')->fetchAll();

    return [(float) $rows[0]['qty'], (float) $rows[0]['cost']];
};
$fSame = function ($productId) {
    $ledger = (float) \Inventory\Posting\Model::balance($productId);
    $layers = (float) \Inventory\Fifo\Model::value($productId)[0];
    $stock = (float) \Kotchasan\Model::createDB()->first(\Inventory\Base\Model::table('inventory'), ['id' => $productId])->stock;

    return [$ledger, $layers, $stock];
};
$fOrphans = function () {
    return count(\Kotchasan\Model::createQuery()->select('A.id')->from('inventory_cost_allocation A')
        ->join('inventory_cost_layer L', ['L.id', 'A.layer_id'], 'LEFT')->where([['L.id', null]])->execute(null, 'array')->fetchAll());
};
$fRejected = function ($label, callable $fn) use ($fA, $fB, $fSame) {
    $before = [$fSame($fA), $fSame($fB)];
    tthrows($label.' — ถูกปฏิเสธ', $fn);
    t($label.' — สต๊อกและชั้นต้นทุนไม่เปลี่ยน', [$fSame($fA), $fSame($fB)] === $before);
};

// T1
$fIn = $fDoc('IN', 'IN-2601', '2026-06-01 09:00:00', [$fLine($fA, 'F2601', 5, 10)]);
$fOut = $fDoc('OUT', 'OUT-2601', '2026-06-02 09:00:00', [$fLine($fA, 'F2601', 3, 50)]);
t('T1 ก่อนบันทึกซ้ำ: สต๊อก 2 ตรงกันทั้งสมุดบัญชี ชั้นต้นทุน และ inventory.stock', $fSame($fA) === [2.0, 2.0, 2.0]);
$fDoc('IN', 'IN-2601', '2026-06-01 09:00:00', [$fLine($fA, 'F2601', 5, 10, $fLineId($fIn))], $fIn);
t('T1 บันทึกใบรับซ้ำโดยไม่แก้อะไร: สต๊อก 2 ตรงกันทุกที่', $fSame($fA) === [2.0, 2.0, 2.0]);
t('T1 ต้นทุนของใบขายยังเป็น 3 ชิ้น 30', $fCost($fOut) === [3.0, 30.0]);
t('T1 ไม่มี allocation ที่ชี้ชั้นต้นทุนที่ไม่มีแล้ว', $fOrphans() === 0);

// T2
$fDoc('IN', 'IN-2601', '2026-06-01 09:00:00', [$fLine($fA, 'F2601', 8, 12, $fLineId($fIn))], $fIn);
t('T2 แก้ใบรับเป็น 8 ชิ้น: สต๊อก 5 ตรงกันทุกที่', $fSame($fA) === [5.0, 5.0, 5.0]);
t('T2 ใบขายเก็บต้นทุนเดิม 30', $fCost($fOut) === [3.0, 30.0]);
t('T2 ชั้นที่เหลือคิดต้นทุนใหม่ 12 (มูลค่า 60)', (float) \Inventory\Fifo\Model::value($fA)[1] === 60.0);

// T3 · T4
$fRejected('T3 แก้ใบรับให้เหลือ 2 ซึ่งน้อยกว่าที่ขายไป 3', function () use ($fDoc, $fLine, $fLineId, $fIn, $fA) {
    $fDoc('IN', 'IN-2601', '2026-06-01 09:00:00', [$fLine($fA, 'F2601', 2, 12, $fLineId($fIn))], $fIn);
});
t('T3 จำนวนในใบรับยังเป็น 8', (float) \Inventory\Document\Model::items($fIn)[0]['quantity'] === 8.0);
$fRejected('T4 เปลี่ยนสินค้าในใบรับที่ขายไปแล้ว', function () use ($fDoc, $fLine, $fIn, $fB) {
    $fDoc('IN', 'IN-2601', '2026-06-01 09:00:00', [$fLine($fB, 'F2602', 8, 12)], $fIn);
});

// T5
$fOld = $fDoc('IN', 'IN-2602', '2026-06-03 09:00:00', [$fLine($fB, 'F2602', 3, 10)]);
$fNew = $fDoc('IN', 'IN-2603', '2026-06-04 09:00:00', [$fLine($fB, 'F2602', 3, 20)]);
$fDoc('IN', 'IN-2602', '2026-06-03 09:00:00', [$fLine($fB, 'F2602', 3, 10, $fLineId($fOld))], $fOld);
$fSell = $fDoc('OUT', 'OUT-2602', '2026-06-05 09:00:00', [$fLine($fB, 'F2602', 3, 50)]);
t('T5 บันทึกใบรับเก่าซ้ำแล้ว ยังอยู่หน้าคิว — ใบขายตัดจากใบรับเก่าทั้ง 3 ชิ้น ต้นทุน 30', $fCost($fSell) === [3.0, 30.0]);

// T6
$fDoc('OUT', 'OUT-2601', '2026-06-02 09:00:00', [$fLine($fA, 'F2601', 3, 50, $fLineId($fOut))], $fOut);
t('T6 บันทึกใบขายซ้ำ: ต้นทุนครบ 3 ชิ้น', $fCost($fOut)[0] === 3.0);
t('T6 สต๊อก 5 ตรงกันทุกที่', $fSame($fA) === [5.0, 5.0, 5.0]);
t('T6 ไม่มี allocation ที่ชี้ชั้นต้นทุนที่ไม่มีแล้ว', $fOrphans() === 0);

// ต่อจาก erp: ยกเลิก/ลบใบรับที่ของถูกขายไปแล้ว — กติกาเดียวกับ T4 (สมุดบัญชีจะติดลบเท่าที่ขายไป)
$fRejected('T7 ยกเลิกใบรับที่ของถูกขายไปแล้ว', function () use ($fIn) {
    \Inventory\Document\Model::cancel($fIn, 1, 'ทดสอบ');
});
$fRejected('T7 ลบใบรับที่ของถูกขายไปแล้ว', function () use ($fIn) {
    \Inventory\Document\Model::remove($fIn, 1);
});
\Inventory\Document\Model::cancel($fOut, 1, 'ทดสอบ');
\Inventory\Document\Model::cancel($fIn, 1, 'ทดสอบ');
t('T8 ยกเลิกใบขายก่อน แล้วยกเลิกใบรับได้ — สต๊อก 0 ตรงกันทุกที่', $fSame($fA) === [0.0, 0.0, 0.0]);
t('T8 ไม่มี allocation ที่ชี้ชั้นต้นทุนที่ไม่มีแล้ว', $fOrphans() === 0);

// -----------------------------------------------------------------------------
group('คลังเก็บของ — ของทุกชิ้นต้องอยู่ที่ไหนสักที่จริง ๆ');

// ฐานที่ติดตั้งใหม่ต้องมีคลังหลักตั้งแต่วินาทีแรก
$mainWh = \Inventory\Warehouse\Model::getDefault();
t('ตัวติดตั้งสร้างคลังหลักให้', $mainWh !== null && (int) $mainWh['is_default'] === 1);
$mainId = (int) $mainWh['id'];
t('ไซต์ที่มีคลังเดียวไม่ถูกถามเรื่องคลัง', !\Inventory\Warehouse\Model::isMultiple());

// ⚠️ ของเดิมทั้งหมดที่ลงบัญชีมาก่อนหน้านี้ในชุดทดสอบ ต้องอยู่ที่คลังหลักทุกแถว
// ไม่มีแถวไหน "ไม่มีคลัง" (warehouse_id = 0) หลุดมาได้
tnum('ไม่มีรายการในสมุดบัญชีที่ไม่มีคลัง', 0,
    $db->count($T('inventory_stock_movement'), ['warehouse_id', 0]));
tnum('ไม่มียอดคงเหลือที่ไม่มีคลัง', 0, $db->count($T('inventory_stock'), ['warehouse_id', 0]));
tnum('ไม่มีชั้นต้นทุนที่ไม่มีคลัง', 0, $db->count($T('inventory_cost_layer'), ['warehouse_id', 0]));

// เพิ่มคลังสาขา
$branchId = \Inventory\Warehouse\Model::save(0, ['code' => 'BR1', 'name' => 'คลังสาขา 1', 'is_active' => 1]);
t('เพิ่มคลังใหม่ได้', $branchId > 0);
t('ตอนนี้ไซต์มีหลายคลังแล้ว', \Inventory\Warehouse\Model::isMultiple());
tthrows('รหัสคลังซ้ำไม่ได้', function () {
    \Inventory\Warehouse\Model::save(0, ['code' => 'br1', 'name' => 'ซ้ำ', 'is_active' => 1]);
}, 'ถูกใช้ไปแล้ว');

$whp = product('WH01', 'สินค้าทดสอบคลัง');
\Inventory\Posting\Model::opening($whp, 0, 100, 10, $mainId);
\Inventory\Posting\Model::opening($whp, 0, 30, 12, $branchId);
tnum('ยอดคลังหลัก', 100, \Inventory\Posting\Model::balance($whp, 0, $mainId));
tnum('ยอดคลังสาขา', 30, \Inventory\Posting\Model::balance($whp, 0, $branchId));
tnum('ยอดรวมทุกคลัง (ผู้เรียกเดิมที่ไม่รู้เรื่องคลัง)', 130, \Inventory\Posting\Model::balance($whp));
tnum('cache ในทะเบียนสินค้าเป็นยอดรวมทุกคลัง', 130,
    (float) $db->first($T('inventory'), ['id' => $whp])->stock);
tnum('แยกรายคลังได้', 2, count(\Inventory\Posting\Model::balanceByWarehouse($whp)));

// ⚠️ ข้อสำคัญที่สุด : ตัดจากคลังที่ของไม่พอ ต้องถูกปฏิเสธ แม้ยอดรวมทั้งระบบพอ
tthrows('ตัดจากคลังสาขา 50 ทั้งที่มี 30 ต้องถูกปฏิเสธ (แม้ยอดรวม 130)', function () use ($whp, $branchId) {
    \Inventory\Posting\Model::issue([
        'inventory_id' => $whp, 'warehouse_id' => $branchId, 'quantity' => 50, 'reference_type' => 'adjustment'
    ]);
}, 'ไม่พอ');
tnum('และยอดต้องไม่ขยับ', 30, \Inventory\Posting\Model::balance($whp, 0, $branchId));

// ต้นทุนต้องตามคลัง — ขายจากสาขาต้องได้ต้นทุน 12 ไม่ใช่ 10 ของคลังหลัก
$saleId = \Inventory\Posting\Model::issue([
    'inventory_id' => $whp, 'warehouse_id' => $branchId, 'quantity' => 10, 'reference_type' => 'adjustment'
]);
$saleRow = $db->first($T('inventory_stock_movement'), ['id' => $saleId]);
tnum('ขายจากสาขาได้ต้นทุนของสาขา ไม่ใช่ของคลังหลัก', 12, (float) $saleRow->unit_cost);
tnum('ชั้นต้นทุนของคลังหลักไม่ถูกแตะ', 100,
    (float) $db->first($T('inventory_cost_layer'), ['inventory_id' => $whp, 'warehouse_id' => $mainId])->remaining_qty);

// ผู้เรียกที่ไม่ระบุคลัง ต้องลงคลังตั้งต้น ไม่ใช่คลัง 0
$noWh = \Inventory\Posting\Model::receipt([
    'inventory_id' => $whp, 'quantity' => 5, 'unit_cost' => 11, 'reference_type' => 'adjustment'
]);
tnum('ไม่ระบุคลัง = คลังตั้งต้น', $mainId,
    (int) $db->first($T('inventory_stock_movement'), ['id' => $noWh])->warehouse_id);

// -----------------------------------------------------------------------------
group('หน้าจอสินค้า — ทะเบียน · หน้าสินค้า · แบบ · หน่วยขาย/เครื่อง (DESIGN.md ข้อ 9 ขั้น 4)');

// ผ่านคอนโทรลเลอร์จริง ข้ามแค่ด่านเข้าระบบกับ CSRF (แบบเดียวกับกลุ่มฟอร์มเอกสาร)
$asAdmin = (object) ['id' => 1, 'status' => 1, 'permission' => '', 'active' => 1, 'social' => 'user'];
$productApi = new class extends \Inventory\Product\Controller {
    public static $login;

    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        return self::$login;
    }

    protected function validateCsrfToken(\Kotchasan\Http\Request $request)
    {
    }
};
$variantsApi = new class extends \Inventory\Variants\Controller {
    public static $login;

    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        return self::$login;
    }

    protected function validateCsrfToken(\Kotchasan\Http\Request $request)
    {
    }
};
$variantApi = new class extends \Inventory\Variant\Controller {
    public static $login;

    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        return self::$login;
    }

    protected function validateCsrfToken(\Kotchasan\Http\Request $request)
    {
    }
};
$itemsApi = new class extends \Inventory\Items\Controller {
    public static $login;

    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        return self::$login;
    }

    protected function validateCsrfToken(\Kotchasan\Http\Request $request)
    {
    }
};
$productApi::$login = $variantsApi::$login = $variantApi::$login = $itemsApi::$login = $asAdmin;
$call = function ($api, $method, array $body = [], array $query = [], $httpMethod = 'POST') {
    // ⚠️ Request::getMethod() อ่านจาก $_SERVER ทุกครั้ง withMethod() ไม่มีผล
    $before = $_SERVER['REQUEST_METHOD'];
    $_SERVER['REQUEST_METHOD'] = $httpMethod;
    $request = new \Kotchasan\Http\Request();
    if (!empty($query)) {
        $request = $request->withQueryParams($query);
    }
    if (!empty($body)) {
        $request = $request->withParsedBody($body);
    }
    $response = $api->$method($request);
    $_SERVER['REQUEST_METHOD'] = $before;

    return json_decode($response->getContent(), true);
};
$redirectTo = function ($json) {
    foreach (isset($json['data']['actions']) ? $json['data']['actions'] : [] as $action) {
        if ($action['type'] === 'redirect') {
            return $action['url'];
        }
    }

    return null;
};

// สินค้าใหม่จากหน้าสินค้า = สินค้า + แบบตั้งต้น + หน่วยขายฐาน + ยอดยกมา (ทุน วันที่)
$json = $call($productApi, 'save', [
    'id' => '0', 'product_code' => 'UI4', 'topic' => 'เสื้อโปโล', 'unit' => 'ตัว', 'count_stock' => '1', 'vat' => '0',
    'option1_name' => 'สี', 'option2_name' => '', 'option3_name' => '', 'category_id' => '', 'allow_negative' => '0',
    'is_active' => '1', 'sku' => '', 'barcode' => '', 'price' => '250', 'stock' => '6', 'cost' => '120', 'create_date' => '2026-09-01'
]);
$polo = (int) $db->first($T('inventory'), ['sku' => 'UI4'])->id;
t('บันทึกสินค้าใหม่แล้วไปหน้าของสินค้านั้น (เพิ่มแบบ/หน่วยขายต่อได้)', $redirectTo($json) === '/inventory-write?id='.$polo);
$poloProduct = (int) $db->first($T('inventory'), ['id' => $polo])->product_id;
$layer = $db->first($T('inventory_cost_layer'), ['inventory_id' => $polo]);
t('ยอดยกมาเป็นชั้นต้นทุนแรกพร้อมทุนและวันที่ที่กรอก (ไม่ไปแก้วันที่ของสมุดบัญชีทีหลัง)',
    $layer && (float) $layer->received_qty === 6.0 && (float) $layer->unit_cost === 120.0 && $layer->received_at === '2026-09-01 00:00:00');
tthrows('หน่วยฐานต้องกรอก (หน่วยที่นับสต๊อก)', function () use ($call, $productApi) {
    $json = $call($productApi, 'save', ['id' => '0', 'topic' => 'ไม่มีหน่วย', 'unit' => '', 'count_stock' => '1']);
    if (isset($json['errors']['unit'])) {
        throw new \Exception('unit '.$json['errors']['unit']);
    }
}, 'unit');

$json = $call($productApi, 'get', [], ['id' => $polo], 'GET');
$form = $json['data']['data'];
t('ฟอร์มสินค้าจากแบบที่เปิดอยู่ได้ค่าของสินค้า (ใช้ร่วมทุกแบบ) และรู้ว่าล็อกวิธีนับแล้ว',
    $form['id'] === $polo && $form['product_id'] === $poloProduct && $form['topic'] === 'เสื้อโปโล'
    && $form['option1_name'] === 'สี' && $form['sku'] === 'UI4' && $form['has_movement'] === true);
$json = $call($productApi, 'save', ['id' => (string) $polo, 'product_code' => 'UI4', 'topic' => 'เสื้อโปโล', 'unit' => 'ตัว',
    'count_stock' => '2', 'vat' => '0', 'is_active' => '1']);
t('เปลี่ยนวิธีนับของสินค้าที่มีความเคลื่อนไหวแล้วถูกปฏิเสธ', $json['success'] === false && mb_strpos($json['message'], 'วิธีนับสต๊อก') !== false);
$json = $call($productApi, 'save', ['id' => (string) $polo, 'product_code' => 'UI4', 'topic' => 'เสื้อโปโลแขนสั้น', 'unit' => 'ตัว',
    'vat' => '0', 'option1_name' => 'สี', 'is_active' => '1']);
t('ช่องที่ล็อก (disabled) ไม่ถูกส่งมา = ไม่เปลี่ยน · ชื่อแบบตามชื่อสินค้าใหม่',
    $json['success'] === true && $db->first($T('inventory'), ['id' => $polo])->topic === 'เสื้อโปโลแขนสั้น');

// แบบสินค้า : เปิดหน้าต่างจากปุ่มเพิ่ม (variant = แบบที่เปิดอยู่) แล้วบันทึก
$json = $call($variantsApi, 'action', ['action' => 'edit', 'id' => '0', 'variant' => (string) $polo]);
$modal = $json['data'];
t('หน้าต่างเพิ่มแบบได้ชื่อตัวเลือกของสินค้าเป็นป้าย และสินค้าที่สังกัด',
    $modal['product_id'] === $poloProduct && $modal['option1_name'] === 'สี' && $modal['actions'][0]['template'] === 'inventory/variant-modal.html');
t('ข้อมูลของฟอร์มแนบไปกับ action ด้วย (ปุ่มนอกตารางไม่มี context.data)',
    isset($modal['actions'][0]['data']['product_id']) && $modal['actions'][0]['data']['product_id'] === $poloProduct);
$json = $call($variantApi, 'save', ['id' => '0', 'product_id' => (string) $poloProduct, 'sku' => 'UI4-RED', 'option1' => 'แดง',
    'price' => '260', 'stock' => '3', 'cost' => '130', 'create_date' => '2026-09-02', 'is_active' => '1']);
$poloRed = (int) $db->first($T('inventory'), ['sku' => 'UI4-RED'])->id;
t('บันทึกแบบแล้วรีโหลดเฉพาะตารางแบบและปิดหน้าต่าง', $json['success'] === true
    && in_array(['type' => 'redirect', 'url' => 'reload', 'target' => 'inventoryVariants'], $json['data']['actions'], true));
tnum('แบบใหม่มียอดยกมาของตัวเอง', 3, (float) $db->first($T('inventory'), ['id' => $poloRed])->stock);
$json = $call($variantApi, 'save', ['id' => '0', 'product_id' => (string) $poloProduct, 'sku' => 'UI4-RED2', 'option1' => 'แดง', 'price' => '1']);
t('ค่าตัวเลือกซ้ำกับแบบที่มีอยู่ถูกปฏิเสธ', $json['success'] === false && mb_strpos($json['message'], 'อยู่แล้ว') !== false);
$call($variantApi, 'save', ['id' => '0', 'product_id' => (string) $poloProduct, 'sku' => 'UI4-BLU', 'option1' => 'น้ำเงิน', 'price' => '250', 'is_active' => '1']);
$poloBlue = (int) $db->first($T('inventory'), ['sku' => 'UI4-BLU'])->id;
$rows = \Inventory\Inventories\Model::toDataTable(['search' => 'UI4'])->execute(null, 'array')->fetchAll();
t('ทะเบียนสินค้าบรรทัดละสินค้า — จำนวนแบบ ช่วงราคา ยอดรวม และแบบแรกให้ปุ่มแก้ไข',
    count($rows) === 1 && (int) $rows[0]['variants'] === 3 && (float) $rows[0]['price'] === 250.0
    && (float) $rows[0]['price_max'] === 260.0 && (float) $rows[0]['stock'] === 9.0 && (int) $rows[0]['variant_id'] === $polo);
$json = $call($variantsApi, 'action', ['action' => 'delete', 'id' => (string) $poloRed]);
t('ลบแบบที่มีความเคลื่อนไหวไม่ได้ (ปิดการใช้งานแทน)', $json['success'] === false && mb_strpos($json['message'], 'ความเคลื่อนไหว') !== false);
$json = $call($variantsApi, 'action', ['action' => 'delete', 'id' => (string) $poloBlue]);
t('ลบแบบที่ยังไม่เคยใช้ได้', $json['success'] === true && !$db->first($T('inventory'), ['id' => $poloBlue]));
$call($variantsApi, 'action', ['action' => 'active', 'id' => (string) $poloRed]);
t('ปิดการขายแบบหนึ่งจากปุ่มในแถว', (int) $db->first($T('inventory'), ['id' => $poloRed])->is_active === 0);

// หน่วยขาย : ตารางแก้ไขทั้งตาราง — แถวที่ปุ่ม + คัดลอกมา (id ซ่อนซ้ำ) คือแถวใหม่
$json = $call($itemsApi, 'get', [], ['id' => $polo], 'GET');
$packFields = array_column($json['data']['data']['packs']['columns'], 'field');
t('ตารางหน่วยขายไม่มีฟิลด์ชื่อ id (ชนกับ id ของฟอร์ม แถวเดิมหายทั้งตาราง)', !in_array('id', $packFields, true) && in_array('pack_id', $packFields, true));
$basePack = (int) $json['data']['data']['packs']['data'][0]['pack_id'];
$json = $call($itemsApi, 'save', [
    'id' => (string) $polo,
    'pack_id' => ['row_1' => (string) $basePack, 'row_2' => (string) $basePack],
    'barcode' => ['row_1' => 'UI4', 'row_2' => 'UI4-3'],
    'unit' => ['row_1' => 'ตัว', 'row_2' => 'แพ็ค'],
    'cut_stock' => ['row_1' => '1', 'row_2' => '3'],
    'price' => ['row_1' => '', 'row_2' => '700'],
    'is_active' => ['row_1' => '1', 'row_2' => '1']
]);
$packs = \Inventory\Product\Model::items($polo);
t('แถวที่คัดลอกมา (id ซ้ำ) กลายเป็นหน่วยขายใหม่ แถวเดิมคงอยู่', $json['success'] === true && count($packs) === 2
    && $packs[0]['barcode'] === 'UI4' && $packs[0]['price'] === null && $packs[1]['barcode'] === 'UI4-3' && (float) $packs[1]['price'] === 700.0);
$json = $call($itemsApi, 'save', ['id' => (string) $polo, 'pack_id' => ['row_1' => (string) $basePack], 'barcode' => ['row_1' => 'UI4'],
    'unit' => ['row_1' => 'ตัว'], 'cut_stock' => ['row_1' => '1'], 'price' => ['row_1' => ''], 'is_active' => ['row_1' => '1']]);
t('แถวที่ไม่ถูกส่งกลับมา = ลบ (หน่วยขายที่ยังไม่เคยใช้)', $json['success'] === true && count(\Inventory\Product\Model::items($polo)) === 1);
$json = $call($itemsApi, 'save', ['id' => (string) $polo, 'pack_id' => ['row_1' => (string) $basePack], 'barcode' => ['row_1' => 'UI4-X'],
    'unit' => ['row_1' => 'ตัว'], 'cut_stock' => ['row_1' => '2'], 'price' => ['row_1' => ''], 'is_active' => ['row_1' => '1']]);
t('หน่วยขายที่ถูกใช้แล้วเปลี่ยนบาร์โค้ด/ตัวคูณไม่ได้ — บอกที่ช่องของแถวนั้น ทั้งชุดไม่ถูกบันทึก',
    $json['success'] === false && isset($json['errors']['inventoryPacks_barcode_row_1'])
    && $db->first($T('inventory_items'), ['id' => $basePack])->barcode === 'UI4');
$json = $call($itemsApi, 'save', ['id' => (string) $poloRed]);
t('แบบที่ไม่มีหน่วยขายเหลือเลยถูกปฏิเสธ', $json['success'] === false && isset($json['errors']['items']));

// เครื่อง : สินค้านับรายชิ้น ลงทะเบียนเครื่องจากตาราง (ไม่กรอก = ระบบออกเลข)
$json = $call($productApi, 'save', ['id' => '0', 'product_code' => 'UI4-NB', 'topic' => 'โน้ตบุ๊กหน้าจอ', 'unit' => 'เครื่อง',
    'count_stock' => '2', 'vat' => '0', 'is_active' => '1', 'price' => '20000', 'stock' => '5']);
$uiNb = (int) $db->first($T('inventory'), ['sku' => 'UI4-NB'])->id;
tnum('สินค้ารายชิ้นไม่ตั้งยอดยกมาเป็นตัวเลข (รับเข้าเป็นเครื่องด้วยใบรับ)', 0, (float) $db->first($T('inventory'), ['id' => $uiNb])->stock);
$nbPack = (int) $db->first($T('inventory_items'), ['inventory_id' => $uiNb])->id;
$json = $call($itemsApi, 'save', [
    'id' => (string) $uiNb,
    'pack_id' => ['row_1' => (string) $nbPack], 'barcode' => ['row_1' => 'UI4-NB'], 'unit' => ['row_1' => 'เครื่อง'],
    'price' => ['row_1' => ''], 'is_active' => ['row_1' => '1'],
    'serial_id' => ['row_1' => '', 'row_2' => ''], 'serial_no' => ['row_1' => '', 'row_2' => 'SN-UI4'],
    'serial_active' => ['row_1' => '1', 'row_2' => '1']
]);
$json = $call($itemsApi, 'get', [], ['id' => $uiNb], 'GET');
$serials = $json['data']['data']['serials']['data'];
t('ลงทะเบียนเครื่องจากตาราง — ไม่กรอกได้เลขจากระบบ · สถานะยังไม่อยู่ในคลังจนกว่าจะรับเข้า',
    count($serials) === 2 && $serials[0]['serial_no'] === 'UI4-NB-0001' && $serials[1]['serial_no'] === 'SN-UI4'
    && $serials[0]['status'] === 'Not in stock');
t('สถานะเครื่องเป็นคีย์ภาษาที่ตารางแปลผ่าน template', in_array('{LNG_${status}}',
    array_column($json['data']['data']['serials']['columns'], 'template'), true));

// สินค้าที่ถูกอ้างในเอกสาร (ใบเสนอราคา · ไม่มีความเคลื่อนไหว) ลบไม่ได้
$quoteOnly = product('UI4-Q', 'สินค้าที่อยู่ในใบเสนอราคา');
\Inventory\Document\Model::save(['document_type' => 'TDUE', 'order_no' => 'UI4-Q-1', 'total' => 100], [[
    'inventory_id' => $quoteOnly, 'code' => 'UI4-Q', 'topic' => 'สินค้าที่อยู่ในใบเสนอราคา', 'quantity' => 1, 'price' => 100, 'total' => 100
]], 0, 1);
tthrows('ลบสินค้าที่ถูกใช้ในเอกสารไม่ได้ (บรรทัดเอกสารต้องอ่านความหมายเดิมได้)', function () use ($db, $T, $quoteOnly) {
    \Inventory\Product\Model::remove((int) $db->first($T('inventory'), ['id' => $quoteOnly])->product_id);
}, 'ถูกใช้ในเอกสาร');

// สัญญาของเทมเพลตที่พลาดแล้วข้อมูลหายเงียบ ๆ
$orderForm = file_get_contents(ROOT_PATH.'templates/inventory/order-edit.html');
t('ช่องล็อต/วันหมดอายุซ่อนด้วย class เมื่อปิดค่าตั้ง (คอลัมน์ยังวนกลับกับฟอร์ม)',
    strpos($orderForm, 'data-class="lot-off:!lot_tracking"') !== false
    && (bool) preg_match('/data-field="lot_no"[^>]*col-lot/', $orderForm) && (bool) preg_match('/data-field="expiry_date"[^>]*col-lot/', $orderForm));
t('วันหมดอายุไม่ใช้ data-type="date" (ตัวเลือกวันที่ในตารางรายการไม่ตั้งชื่อ input)',
    !preg_match('/data-field="expiry_date"[^>]*data-type="date"/', $orderForm));
$itemsForm = file_get_contents(ROOT_PATH.'templates/inventory/product-items.html');
t('ตารางแก้ไขของหน้าหน่วยขายไม่อ่าน ?id ของหน้าเป็นตัวกรอง (เหลือแถวเดียว)', substr_count($itemsForm, 'data-url-params="false"') === 2);
t('ฟอร์มเอกสารได้ค่าตั้ง lot_tracking', array_key_exists('lot_tracking', $loaded));
t('cache ของกลุ่มนี้ตรงกับข้อมูลดิบ', \Inventory\Posting\Model::rebuild() === []);

// -----------------------------------------------------------------------------
group('ตัวแปลงทะเบียนสินค้าแบบแบน — modules/inventory/install/legacy.php (ใช้ร่วม oas · oms.in.th)');

// ฐานแยกของกลุ่มนี้ : ตารางแบบแบนของรุ่นก่อน (โครงของ oms.in.th ก่อนแบบสี่ชั้น · ผู้เช่าจริงยังเป็นแบบนี้)
include_once ROOT_PATH.'install/common.php';
include_once ROOT_PATH.'install/db.php';
include_once ROOT_PATH.'modules/inventory/install/legacy.php';
$legacyName = $dbname.'_legacy';
$_lcfg = (include $root.'/settings/database.php')['mysql'];
$_lpdo = new PDO('mysql:host='.$_lcfg['hostname'].';charset=utf8mb4', $_lcfg['username'], $_lcfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$_lpdo->exec("DROP DATABASE IF EXISTS `$legacyName`");
$_lpdo->exec("CREATE DATABASE `$legacyName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(function () use ($_lpdo, $legacyName) {
    $_lpdo->exec("DROP DATABASE IF EXISTS `$legacyName`");
});
foreach ([
    "CREATE TABLE `app_inventory` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `product_code` varchar(150) DEFAULT NULL, `product_no` varchar(150) DEFAULT NULL,
      `topic` varchar(150) NOT NULL, `description` text DEFAULT NULL, `last_update` int(11) unsigned NOT NULL DEFAULT 0, `created_at` datetime DEFAULT NULL,
      `updated_at` datetime DEFAULT NULL, `price` decimal(14,4) NOT NULL DEFAULT 0.0000, `vat` double NOT NULL DEFAULT 0, `cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
      `unit` varchar(20) DEFAULT NULL, `category_id` varchar(10) DEFAULT NULL, `model_id` varchar(10) DEFAULT NULL, `type_id` varchar(10) DEFAULT NULL,
      `count_stock` tinyint(1) NOT NULL DEFAULT 1, `stockable` tinyint(1) NOT NULL DEFAULT 1, `allow_negative` tinyint(1) NOT NULL DEFAULT 0,
      `stock` decimal(16,4) NOT NULL DEFAULT 0.0000, `is_active` tinyint(1) NOT NULL DEFAULT 1, `inuse` tinyint(1) DEFAULT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `product_code` (`product_code`), KEY `product_no` (`product_no`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE `app_inventory_items` (`id` int(11) NOT NULL AUTO_INCREMENT, `sku` varchar(150) NOT NULL, `product_no` varchar(150) DEFAULT NULL,
      `barcode` varchar(150) DEFAULT NULL, `inventory_id` int(11) NOT NULL, `topic` varchar(150) DEFAULT NULL, `price` decimal(14,4) DEFAULT NULL,
      `cut_stock` decimal(16,4) NOT NULL DEFAULT 1.0000, `stock` decimal(16,4) NOT NULL DEFAULT 0.0000, `unit` varchar(50) DEFAULT NULL,
      `instock` tinyint(1) NOT NULL DEFAULT 0, `url` varchar(255) DEFAULT NULL, `last_update` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`), UNIQUE KEY `sku` (`sku`), UNIQUE KEY `product_no` (`product_no`), KEY `barcode` (`barcode`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE `app_inventory_meta` (`inventory_id` int(11) NOT NULL, `name` varchar(20) NOT NULL, `value` mediumtext NOT NULL,
      KEY `idx_inventory_meta` (`inventory_id`,`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE `app_order_items` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `order_id` int(11) NOT NULL, `source_item_id` int(11) DEFAULT NULL,
      `root_item_id` int(11) DEFAULT NULL, `inventory_id` int(11) unsigned NOT NULL DEFAULT 0, `inventory_item_id` int(11) DEFAULT NULL,
      `product_code` varchar(150) DEFAULT NULL, `product_no` varchar(50) DEFAULT NULL, `topic` varchar(255) DEFAULT NULL,
      `quantity` decimal(16,4) NOT NULL DEFAULT 1.0000, `unit` varchar(50) DEFAULT NULL, `price` decimal(14,4) NOT NULL DEFAULT 0.0000,
      `cost_price` decimal(14,4) DEFAULT NULL, `discount` decimal(10,2) NOT NULL DEFAULT 0.00, `vat` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total` decimal(14,4) NOT NULL DEFAULT 0.0000, `cut_stock` decimal(16,4) NOT NULL DEFAULT 0.0000, `note` text DEFAULT NULL,
      `member_id` int(11) unsigned DEFAULT NULL, `create_date` datetime DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE `app_inventory_template` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `document_type` varchar(10) DEFAULT NULL, `status` varchar(3) DEFAULT NULL,
      `mode` enum('sell','buy','borrow','repair') NOT NULL, `topic` varchar(150) DEFAULT NULL, `comment` text NOT NULL, `due_date` tinyint(1) NOT NULL DEFAULT 0,
      `published` tinyint(1) NOT NULL DEFAULT 1, `in_stock` tinyint(1) NOT NULL, `cut_stock` tinyint(1) NOT NULL, `reserve_stock` tinyint(1) NOT NULL DEFAULT 0,
      `movement_type` varchar(20) DEFAULT NULL, `prefix` varchar(20) NOT NULL DEFAULT '', `number_format` varchar(20) NOT NULL DEFAULT '',
      `columns` varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE `app_inventory_stock_movement` (`id` int(11) NOT NULL AUTO_INCREMENT, `inventory_id` int(11) NOT NULL, `inventory_item_id` int(11) NOT NULL DEFAULT 0,
      `quantity` decimal(16,4) NOT NULL DEFAULT 0.0000, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
] as $_ddl) {
    $_lpdo->exec("USE `$legacyName`");
    $_lpdo->exec($_ddl);
}
$_lpdo->exec("INSERT INTO `app_inventory` (`id`, `product_code`, `product_no`, `topic`, `price`, `unit`, `count_stock`, `stockable`, `is_active`) VALUES
    (1, 'DOM', 'DOM', 'จดโดเมน', 0, 'ปี', 0, 0, 1),
    (2, 'EGG', 'EGG', 'ไข่ไก่', 4, 'ฟอง', 1, 1, 1),
    (3, 'NB', 'NB', 'โน้ตบุ๊ก', 25000, 'เครื่อง', 2, 1, 1),
    (4, NULL, 'S-OLD', 'ค่าบริการเดิม', 300, '', 0, 0, 1),
    (5, 'MIX', 'MIX', 'สินค้าที่แบ่งยอดไม่ได้', 10, 'ชิ้น', 1, 1, 1)");
$_lpdo->exec("INSERT INTO `app_inventory_items` (`id`, `sku`, `product_no`, `barcode`, `inventory_id`, `topic`, `price`, `cut_stock`, `unit`) VALUES
    (1, 'DOM-COM', 'DOM-COM', NULL, 1, '.com', 500, 1, 'ปี'),
    (2, 'DOM-NET', 'DOM-NET', NULL, 1, '.net', 600, 1, 'ปี'),
    (3, 'EGG-1', 'EGG-1', '8850000000011', 2, NULL, NULL, 1, 'ฟอง'),
    (4, 'EGG-12', 'EGG-12', NULL, 2, 'โหล', 40, 12, 'โหล'),
    (5, 'NB-A', 'NB-A', NULL, 3, NULL, NULL, 1, 'เครื่อง'),
    (6, 'NB-B', 'NB-B', NULL, 3, NULL, NULL, 1, 'เครื่อง'),
    (7, 'MIX-A', 'MIX-A', NULL, 5, 'ก', NULL, 1, 'ชิ้น'),
    (8, 'MIX-B', 'MIX-B', NULL, 5, 'ข', NULL, 1, 'ชิ้น')");
$_lpdo->exec("INSERT INTO `app_inventory_meta` VALUES (2, 'description', 'ไข่สด')");
$_lpdo->exec("INSERT INTO `app_inventory_stock_movement` (`inventory_id`, `quantity`) VALUES (2, 60), (5, 10)");
$_lpdo->exec("INSERT INTO `app_order_items` (`id`, `order_id`, `inventory_id`, `inventory_item_id`, `product_code`, `product_no`, `topic`, `quantity`, `cut_stock`) VALUES
    (1, 1, 1, 2, '', 'DOM-NET', 'จดโดเมน .net', 1, 1),
    (2, 1, 2, 4, 'EGG-12', 'EGG-12', 'ไข่ไก่ โหล', 2, 24),
    (3, 1, 2, NULL, 'EGG', 'EGG', 'ไข่ไก่', 3, 3),
    (4, 2, 2, 4, 'EGG-12', 'EGG-12', 'ไข่ไก่ โหล', 1, 0),
    (5, 2, 0, 0, 'DEP:1', 'DEP:1', 'หักมัดจำ', 1, 0),
    (6, 2, 4, NULL, '', '', 'ค่าบริการเดิม', 1, 0)");
$_lpdo->exec("INSERT INTO `app_inventory_template` (`document_type`, `status`, `mode`, `topic`, `comment`, `in_stock`, `cut_stock`, `movement_type`) VALUES
    ('IN', 'IN', 'buy', 'ใบรับสินค้า', '', 1, 0, 'purchase'), ('OUT', 'OUT', 'sell', 'ใบเสร็จรับเงิน', '', 0, 1, NULL),
    ('RET', 'RET', 'buy', 'ใบคืนสินค้า', '', 1, 0, 'return_in')");

$_ldb = new Db(array_merge($_lcfg, ['dbname' => $legacyName, 'prefix' => 'app']));
$_lcontent = [];
t('ตรวจพบทะเบียนแบบแบน', inventoryLegacyDetect($_ldb, 'app'));
tthrows('สินค้านับจำนวนที่มีหน่วยย่อย ×1 หลายตัวและเคยเดินสต๊อก — ปฏิเสธ (ยอดเดิมแบ่งให้แต่ละแบบไม่ได้)', function () use ($_ldb, &$_lcontent) {
    inventoryConvertLegacy($_ldb, 'app', $_lcontent);
}, 'MIX');
t('…ปฏิเสธก่อนแตะฐาน (ยังไม่มีตารางสินค้าใหม่ คอลัมน์เดิมอยู่ครบ)', !$_ldb->tableExists('app_inventory_product')
    && $_ldb->fieldExists('app_inventory', 'product_code') && !$_ldb->fieldExists('app_inventory', 'product_id'));
$_lpdo->exec("DELETE FROM `app_inventory_stock_movement` WHERE `inventory_id` = 5");
$_plan = inventoryConvertLegacy($_ldb, 'app', $_lcontent, true);
t('--dry-run บอกแผนโดยไม่แตะฐาน', $_plan['products'] === 5 && $_plan['variants'] === 7 && !$_ldb->tableExists('app_inventory_product'));
$_rep = inventoryConvertLegacy($_ldb, 'app', $_lcontent);
$_lq = function ($sql) use ($_ldb) {
    return $_ldb->customQuery($sql, true);
};
$_variants = array_column($_lq('SELECT * FROM `app_inventory` ORDER BY `id`'), null, 'sku');
$_items = array_column($_lq('SELECT * FROM `app_inventory_items` ORDER BY `id`'), null, 'id');
$_lines = array_column($_lq('SELECT * FROM `app_order_items` ORDER BY `id`'), null, 'id');
t('สินค้าเดิมหนึ่งแถว = สินค้าหนึ่งตัว id เดิม', count($_lq('SELECT `id` FROM `app_inventory_product`')) === 5
    && $_lq('SELECT `product_code` FROM `app_inventory_product` WHERE `id` = 4')[0]['product_code'] === 'S-OLD');
t('หน่วยย่อย ×1 สองตัว (.com .net) = สองแบบ · แบบแรกคง id เดิม · ราคาของหน่วยย่อย',
    (int) $_variants['DOM-COM']['id'] === 1 && $_variants['DOM-COM']['option1'] === '.com'
    && (int) $_variants['DOM-NET']['product_id'] === 1 && (float) $_variants['DOM-NET']['price'] === 600.0
    && $_variants['DOM-NET']['topic'] === 'จดโดเมน .net');
t('บรรทัดที่อ้างหน่วยย่อย .net ย้ายไปอยู่กับแบบ .net', (int) $_lines[1]['inventory_id'] === (int) $_variants['DOM-NET']['id']
    && (int) $_items[2]['inventory_id'] === (int) $_variants['DOM-NET']['id']);
t('ไข่ : หน่วยย่อย ×1 = หน่วยขายฐาน (บาร์โค้ดเดิม) · โหล ×12 = หน่วยขาย (บาร์โค้ด = sku เดิม)',
    $_items[3]['barcode'] === '8850000000011' && (float) $_items[3]['cut_stock'] === 1.0
    && $_items[4]['barcode'] === 'EGG-12' && (float) $_items[4]['cut_stock'] === 12.0 && (int) $_items[4]['inventory_id'] === 2);
t('โน้ตบุ๊ก (นับรายชิ้น) : หน่วยย่อย = เครื่อง · หน่วยขายฐานใหม่ = บาร์โค้ดรุ่น',
    $_items[5]['serial_no'] === 'NB-A' && $_items[5]['barcode'] === null && $_items[6]['serial_no'] === 'NB-B'
    && count($_lq("SELECT `id` FROM `app_inventory_items` WHERE `inventory_id` = 3 AND `barcode` = 'NB'")) === 1);
t('บรรทัด : code = product_code (ว่าง = product_no) · ตัวคูณจาก cut_stock ÷ quantity',
    $_lines[1]['code'] === 'DOM-NET' && $_lines[2]['code'] === 'EGG-12' && (float) $_lines[2]['unit_factor'] === 12.0
    && (float) $_lines[2]['base_qty'] === 24.0 && $_lines[2]['sku'] === 'EGG-1');
t('…บรรทัดที่ไม่ได้ตัดสต๊อก (ใบเสนอราคา) ได้ตัวคูณจากหน่วยขายที่อ้าง', (float) $_lines[4]['unit_factor'] === 12.0
    && (float) $_lines[4]['base_qty'] === 12.0);
t('…บรรทัดที่ไม่ใช่สินค้า (หักมัดจำ) ไม่มีหน่วยขาย · รหัสเดิมยังพิมพ์ได้', $_lines[5]['inventory_item_id'] === null && $_lines[5]['code'] === 'DEP:1');
t('…บรรทัดสินค้าที่รุ่นก่อนไม่ได้เก็บรหัส คงรหัสว่าง (หน้าพิมพ์เหมือนเดิม) แต่ได้ sku', $_lines[6]['code'] === '' && $_lines[6]['sku'] === 'S-OLD');
t('รายละเอียดผูกกับสินค้า', $_lq('SELECT `product_id` FROM `app_inventory_meta`')[0]['product_id'] == 2);
$_tpl = array_column($_lq('SELECT * FROM `app_inventory_template`'), null, 'document_type');
t('แม่แบบ : ธง stock_in/stock_out · RET เป็นใบคืนสินค้าจากลูกค้าฝั่งขาย · ไม่เติม RTS (มีเฉพาะ oms.in.th)',
    (int) $_tpl['IN']['stock_in'] === 1 && (int) $_tpl['OUT']['stock_out'] === 1 && $_tpl['RET']['mode'] === 'sell'
    && $_tpl['RET']['topic'] === 'ใบคืนสินค้าจากลูกค้า' && !isset($_tpl['RTS']));
t('คอลัมน์เดิมที่ย้ายค่าแล้วถูกตัดออก', !$_ldb->fieldExists('app_inventory', 'product_code') && !$_ldb->fieldExists('app_inventory_items', 'sku')
    && !$_ldb->fieldExists('app_order_items', 'cut_stock') && !$_ldb->fieldExists('app_inventory_meta', 'inventory_id'));
t('รายงานสิ่งที่ต้องตรวจเอง (ชื่อตัวเลือกของแบบที่แตกจากหน่วยย่อย)', !empty($_rep['review'])
    && strpos(implode(' ', $_rep['review']), 'DOM') !== false);
t('รันซ้ำ = ไม่มีอะไรต้องแปลง', !inventoryLegacyDetect($_ldb, 'app') && inventoryConvertLegacy($_ldb, 'app', $_lcontent)['products'] === 0);
t('ตัวแปลงอยู่ในไฟล์เดียว ใช้ร่วม oas · oms.in.th', is_file(ROOT_PATH.'modules/inventory/install/legacy.php'));

// -----------------------------------------------------------------------------
group('สวิตช์ "แสดงในร้านค้าออนไลน์" (inventory_product.published)');

$productTpl = file_get_contents(ROOT_PATH.'templates/inventory/product-edit.html');
t('ฟอร์มสินค้ามีสวิตช์ published · ถอดออกเมื่อไซต์ไม่มีร้าน (data-if="shop")',
    strpos($productTpl, 'name="published"') !== false && strpos($productTpl, 'data-if="shop"') !== false);
t('ไซต์นี้ไม่มีร้านค้าออนไลน์ (ไม่มีโมดูล ecommerce) — สวิตช์ไม่แสดง', \Inventory\Product\Controller::hasShop() === false);
$pubVariant = product('PUB01', 'สินค้าทดสอบสวิตช์ร้าน');
$pubProduct = (int) $db->first($T('inventory'), ['id' => $pubVariant])->product_id;
t('สินค้าใหม่ไม่แสดงในร้านจนกว่าจะเปิดเอง (DESIGN.md ข้อ 3.1)',
    (int) $db->first($T('inventory_product'), ['id' => $pubProduct])->published === 0);
\Inventory\Product\Model::saveProduct($pubProduct, ['published' => 1]);
t('เปิดสวิตช์แล้วบันทึกที่ระดับสินค้า', (int) \Inventory\Product\Model::get($pubVariant)['published'] === 1);

// -----------------------------------------------------------------------------
group('งานหลังปรับรุ่น — ตัวปรับรุ่นตั้งธง inventory_rebuild ให้แอปคำนวณ cache ใหม่ครั้งเดียว');

// ค่ากำหนดของชุดนี้อยู่ใน APP_PATH ชั่วคราว (ไม่แตะ settings/ ของโปรเจ็ค) — rebuildPending เขียนแฟ้มที่ค่ามาจริง
$upgradeCfg = APP_PATH.'settings/config.php';
file_put_contents($upgradeCfg, "<?php\nreturn ".var_export(['inventory_rebuild' => 1], true).";\n");
\Kotchasan\Config::create()->inventory_rebuild = 1;
$upgVariant = product('UPG01', 'สินค้าหลังปรับรุ่น');
\Inventory\Posting\Model::opening($upgVariant, 0, 7, 10);
// cache ค้างค่าจากระบบเดิม (ตัวปรับรุ่นเขียน SQL ตรง ไม่ผ่าน Posting)
$db->update($T('inventory'), ['id', $upgVariant], ['stock' => 999]);
$upgDiff = \Inventory\Upgrade\Model::rebuildPending(1);
t('ธงตั้งอยู่ — คำนวณ cache ใหม่และรายงานแถวที่แก้', is_array($upgDiff) && count($upgDiff) >= 1);
tnum('ยอดของแบบกลับมาตรงสมุดบัญชี', 7, (float) $db->first($T('inventory'), ['id' => $upgVariant])->stock);
$upgSaved = include $upgradeCfg;
t('ล้างธงในแฟ้มค่ากำหนดที่ค่ามาจริง (APP_PATH) แล้ว', !isset($upgSaved['inventory_rebuild']));
t('เรียกซ้ำ = ไม่มีงานค้าง', \Inventory\Upgrade\Model::rebuildPending(1) === null);
@unlink($upgradeCfg);
t('ตัวปรับรุ่นตั้งธงไว้ท้ายการปรับรุ่น',
    strpos(file_get_contents(ROOT_PATH.'modules/inventory/install/upgrade.php'), "\$config['inventory_rebuild'] = 1;") !== false);
t('เมนูของโมดูลเรียกงานหลังปรับรุ่นเมื่อมีผู้เข้าระบบ',
    strpos(file_get_contents(ROOT_PATH.'modules/inventory/controllers/init.php'), 'Upgrade\\Model::rebuildPending') !== false);

// -----------------------------------------------------------------------------
group('ไฟล์สินค้าตัวอย่าง — นำเข้าแล้วได้สินค้าพร้อมยอดยกมาในสมุดบัญชี (sample-products.csv)');

// ทางเดียวกับหน้านำเข้า (Import\Controller::importRow → Import\Model::importRow) ทีละแถวของไฟล์
$categoryCount = count($db->select($T('category'), ['type', ['category_id', 'unit']]));
$sampleResult = [];
foreach ($sampleRows as $row) {
    $sampleResult[] = \Inventory\Import\Model::importRow('product', $row);
}
tnum('ทุกแถวของไฟล์เป็นแบบสินค้าใหม่', count($sampleRows), count(array_keys($sampleResult, 'new', true)));
tnum('ไม่สร้างหมวดหมู่/หน่วยเพิ่ม — ไฟล์ใช้ชื่อจากข้อมูลตั้งต้น', $categoryCount,
    count($db->select($T('category'), ['type', ['category_id', 'unit']])));
$sampleCodes = array_values(array_unique(array_column($sampleRows, 'product_code')));
tnum('สินค้าตามรหัสสินค้าที่ไม่ซ้ำในไฟล์', count($sampleCodes),
    count($db->select($T('inventory_product'), ['product_code', $sampleCodes])));
$shirt = $db->first($T('inventory_product'), ['product_code', 'TSHIRT']);
t('เสื้อยืดเป็นสินค้าเดียว ตัวเลือก สี / ขนาด มี 6 แบบ', $shirt && $shirt->option1_name === 'สี'
    && $shirt->option2_name === 'ขนาด' && count($db->select($T('inventory'), ['product_id', (int) $shirt->id])) === 6);

// ยอดในไฟล์ = ยอดยกมาในสมุดบัญชีพร้อมทุน (ชั้นต้นทุนแรก) — ตรวจย้อนได้ ไม่ใช่ตัวเลขที่เขียนลงยอดตรง ๆ
$openingWrong = [];
$noMovementWrong = [];
foreach ($sampleRows as $row) {
    $variant = $db->first($T('inventory'), ['sku', $row['sku']]);
    $moves = $variant ? $db->select($T('inventory_stock_movement'), ['inventory_id', (int) $variant->id]) : [];
    if ((int) $row['count_stock'] === 1 && (float) $row['stock'] > 0) {
        $layer = $variant ? $db->first($T('inventory_cost_layer'), ['inventory_id', (int) $variant->id]) : null;
        if (count($moves) !== 1 || $moves[0]->movement_type !== 'opening'
            || abs((float) $moves[0]->quantity - (float) $row['stock']) > 0.0001
            || !$layer || abs((float) $layer->unit_cost - (float) $row['cost']) > 0.0001
            || abs(\Inventory\Posting\Model::balance((int) $variant->id) - (float) $row['stock']) > 0.0001) {
            $openingWrong[] = $row['sku'];
        }
    } elseif (!$variant || !empty($moves)) {
        $noMovementWrong[] = $row['sku'];
    }
}
t('สินค้านับจำนวน : ยอดยกมาหนึ่งรายการ ทุนตามไฟล์ ยอดคงเหลือตรง'.(empty($openingWrong) ? '' : ' — ผิด: '.implode(', ', $openingWrong)),
    empty($openingWrong));
t('สินค้ารายเครื่องและบริการไม่มีการเคลื่อนไหว (รายเครื่องรับเข้าด้วยใบรับ)'.(empty($noMovementWrong) ? '' : ' — ผิด: '.implode(', ', $noMovementWrong)),
    empty($noMovementWrong));

// นำเข้าไฟล์เดิมซ้ำ = อัปเดตข้อมูลสินค้า ยอดต้องไม่เพิ่มอีกรอบ
$egg = (int) $db->first($T('inventory'), ['sku', 'EGG2'])->id;
$sampleResult = [];
foreach ($sampleRows as $row) {
    $sampleResult[] = \Inventory\Import\Model::importRow('product', $row);
}
tnum('นำเข้าซ้ำทุกแถวเป็นการอัปเดต', count($sampleRows), count(array_keys($sampleResult, 'update', true)));
tnum('นำเข้าซ้ำแล้วยอดไข่ไม่เพิ่ม', 300, \Inventory\Posting\Model::balance($egg));

// -----------------------------------------------------------------------------
group('ท้ายชุด — cache ทั้งระบบต้องตรงกับข้อมูลดิบหลังทุกกลุ่ม (DESIGN.md ข้อ 5.6)');

$diff = \Inventory\Posting\Model::rebuild();
t('Posting::rebuild() ไม่พบอะไรต้องแก้', $diff === []);
foreach ($diff as $row) {
    echo '         '.$row['table'].' #'.$row['key'].' '.$row['field'].' ('.$row['sku'].') : '
        .var_export($row['cached'], true).' → '.var_export($row['actual'], true)."\n";
}

// -----------------------------------------------------------------------------
echo "\n".str_repeat('-', 60)."\n";
echo 'ผ่าน '.$ok.' / ล้มเหลว '.$fail."\n";
if ($fail > 0) {
    echo "ข้อที่ไม่ผ่าน:\n";
    foreach ($failed as $label) {
        echo '  - '.$label."\n";
    }
}
if ($options['keep'] !== true) {
    $cfg = include $root.'/settings/database.php';
    $cfg = $cfg['mysql'];
    $pdo = new PDO('mysql:host='.$cfg['hostname'].';charset=utf8mb4', $cfg['username'], $cfg['password']);
    $pdo->exec('DROP DATABASE IF EXISTS `'.$dbname.'`');
}
exit($fail > 0 ? 1 : 0);
