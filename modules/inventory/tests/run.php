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
    return (int) \Kotchasan\Model::createDB()->insert(\Inventory\Base\Model::table('inventory'), [
        'product_code' => $code, 'product_no' => $code, 'topic' => $topic,
        'count_stock' => $countStock, 'stockable' => $countStock > 0 ? 1 : 0,
        'allow_negative' => $allowNegative, 'is_active' => 1, 'inuse' => 1,
        'price' => 100, 'cost' => 0, 'unit' => 'ชิ้น', 'last_update' => time()
    ]);
}

// -----------------------------------------------------------------------------
group('ทะเบียนชนิดการเคลื่อนไหว (หัวข้อ 4.6 ของแผน)');

$types = \Inventory\Base\Model::movementTypes();
t('ทะเบียนมีครบ 11 ชนิด', count($types) === 11);
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
    'reference_type' => 'order', 'reference_id' => 1, 'reference_no' => 'IN-001',
    'occurred_at' => '2026-01-01 09:00:00'
]);
tnum('รับเข้า 10 แล้วคงเหลือ', 10, \Inventory\Posting\Model::balance($p1));

\Inventory\Posting\Model::issue([
    'inventory_id' => $p1, 'quantity' => 4,
    'reference_type' => 'order', 'reference_id' => 2, 'reference_no' => 'OUT-001',
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
    'occurred_at' => '2026-03-01 09:00:00', 'reference_type' => 'order', 'reference_id' => 10]);
\Inventory\Posting\Model::issue(['inventory_id' => $p5, 'quantity' => 4,
    'occurred_at' => '2026-03-05 09:00:00', 'reference_type' => 'order', 'reference_id' => 11]);
tnum('ก่อนยกเลิก คงเหลือ', 6, \Inventory\Posting\Model::balance($p5));

t('ยกเลิกใบขาย คืนได้ 1 รายการ', \Inventory\Posting\Model::reverse('order', 11) === 1);
tnum('หลังยกเลิกใบขาย คงเหลือกลับเป็น', 10, \Inventory\Posting\Model::balance($p5));
list($qty, $amount) = \Inventory\Fifo\Model::value($p5);
tnum('ของคืนกลับเข้าชั้นต้นทุนเดิม ไม่ใช่ชั้นใหม่', 10, $qty);
tnum('มูลค่าคงเหลือกลับมาเท่าเดิม', 150, $amount);

t('ยกเลิกซ้ำไม่ทำให้ยอดเพี้ยน', \Inventory\Posting\Model::reverse('order', 11) === 0);
tnum('ยอดหลังยกเลิกซ้ำ', 10, \Inventory\Posting\Model::balance($p5));

$count = \Inventory\Posting\Model::createQuery()->select('id')->from('inventory_stock_movement')
    ->where(['reference_id', 11])->execute(null, 'array')->fetchAll();
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
group('คำนวณยอดใหม่จากสมุดบัญชี (เครื่องมือซ่อมยอดที่เพี้ยน)');

$db->update($T('inventory_stock'), ['inventory_id', $p3], ['qty' => 999]);
tnum('ยอดที่ถูกแก้มั่วอ่านได้ 999', 999, \Inventory\Posting\Model::balance($p3));
\Inventory\Posting\Model::recalculate($p3);
tnum('คำนวณใหม่แล้วกลับมาตรงกับสมุดบัญชี', 5, \Inventory\Posting\Model::balance($p3));

$written = \Inventory\Posting\Model::recalculate();
t('คำนวณใหม่ทั้งระบบครอบคลุมทุกสินค้าที่มีการเคลื่อนไหว', $written >= 5);

// -----------------------------------------------------------------------------
group('หน่วยย่อยรายชิ้น (count_stock = 2)');

$p7 = product('P007', 'สินค้านับแยกรายชิ้น', 2);
$i1 = (int) $db->insert($T('inventory_items'), ['sku' => 'P007-A', 'product_no' => 'P007-A',
    'inventory_id' => $p7, 'unit' => 'เครื่อง', 'price' => 500, 'cut_stock' => 1]);
$i2 = (int) $db->insert($T('inventory_items'), ['sku' => 'P007-B', 'product_no' => 'P007-B',
    'inventory_id' => $p7, 'unit' => 'เครื่อง', 'price' => 500, 'cut_stock' => 1]);
\Inventory\Posting\Model::receipt(['inventory_id' => $p7, 'inventory_item_id' => $i1, 'quantity' => 3, 'unit_cost' => 400]);
\Inventory\Posting\Model::receipt(['inventory_id' => $p7, 'inventory_item_id' => $i2, 'quantity' => 2, 'unit_cost' => 450]);
tnum('ยอดของหน่วยย่อย A', 3, \Inventory\Posting\Model::balance($p7, $i1));
tnum('ยอดของหน่วยย่อย B', 2, \Inventory\Posting\Model::balance($p7, $i2));
$row = $db->first($T('inventory'), ['id' => $p7]);
tnum('ยอดรวมของสินค้าเท่ากับผลรวมของหน่วยย่อย', 5, (float) $row->stock);
$row = $db->first($T('inventory_items'), ['id' => $i1]);
tnum('inventory_items.stock (cache ของตารางเดิม) ตามยอดจริง', 3, (float) $row->stock);
t('sku ถูกเติมให้เองจากหน่วยย่อย',
    $db->first($T('inventory_stock_movement'), ['inventory_item_id' => $i1])->sku === 'P007-A');

\Inventory\Posting\Model::issue(['inventory_id' => $p7, 'inventory_item_id' => $i1, 'quantity' => 3]);
tnum('ตัดหน่วยย่อย A จนหมด', 0, \Inventory\Posting\Model::balance($p7, $i1));
tnum('หน่วยย่อย B ไม่ถูกกระทบ', 2, \Inventory\Posting\Model::balance($p7, $i2));
$row = $db->first($T('inventory_items'), ['id' => $i1]);
t('instock ของหน่วยย่อยที่หมดกลายเป็น 0', (int) $row->instock === 0);

// -----------------------------------------------------------------------------
group('ขายยกลัง/ยกโหล — หน่วยขายที่มีตัวคูณ (count_stock = 1)');

// ⚠️ กติกา: count_stock เป็นตัวตัดสินว่ายอดอยู่ระดับไหน ไม่ใช่ว่าบรรทัดเอกสาร
// อ้างหน่วยย่อยหรือไม่ — สินค้า "นับรวม" มีของกองเดียว หน่วยย่อยเป็นแค่หน่วยขาย
// ที่มีราคาและตัวคูณของตัวเอง ขายปลีกกับขายยกลังจึงกินของกองเดียวกัน
$pbox = product('BOX01', 'น้ำดื่มขวดเล็ก', 1);
$itemPiece = (int) $db->insert($T('inventory_items'), [
    'sku' => 'BOX01-P', 'product_no' => 'BOX01-P', 'inventory_id' => $pbox,
    'topic' => 'ขายปลีก', 'unit' => 'ขวด', 'price' => 10, 'cut_stock' => 1
]);
$itemBox = (int) $db->insert($T('inventory_items'), [
    'sku' => 'BOX01-B', 'product_no' => 'BOX01-B', 'inventory_id' => $pbox,
    'topic' => 'ยกลัง', 'unit' => 'ลัง', 'price' => 100, 'cut_stock' => 12
]);
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

// หน่วยขายที่ใช้จริงยังตรวจย้อนหลังได้จาก sku บนแถวสมุดบัญชี
$skus = [];
foreach ($db->select($T('inventory_stock_movement'), ['inventory_id', $pbox]) as $row) {
    $skus[(string) $row->sku] = true;
}
t('สมุดบัญชียังบอกได้ว่ารายการไหนขายเป็นลัง รายการไหนขายเป็นขวด',
    isset($skus['BOX01-B']) && isset($skus['BOX01-P']));

// ⚠️ แถวยอดคงเหลือระดับสินค้าเป็นยอดของ "ตัวสินค้า" (หน่วยหลัก) ไม่ใช่ของหน่วยขาย
// ที่บังเอิญขายเป็นรายการล่าสุด — ถ้าเก็บ sku ของหน่วยขายไว้ แถวจะอ่านได้ว่า
// "90 · BOX01-B (ลัง)" ทั้งที่ยอดนั้นเป็นขวด และสลับไปมาทุกครั้งที่ขายคนละหน่วย
t('แถวยอดรวมของสินค้าถือรหัสสินค้า ไม่ใช่รหัสหน่วยขายที่ขายล่าสุด',
    (string) $db->first($T('inventory_stock'), ['inventory_id' => $pbox, 'inventory_item_id' => 0])->sku === 'BOX01');

// ขายเกินของที่มีต้องถูกปฏิเสธ โดยนับเป็นหน่วยหลัก
tthrows('ขายยกลังเกินของที่มีถูกปฏิเสธ', function () use ($pbox, $itemBox) {
    \Inventory\Posting\Model::issue([
        'inventory_id' => $pbox, 'inventory_item_id' => $itemBox,
        'quantity' => 96, 'reference_type' => 'order'
    ]);
}, 'ไม่พอ');

// เอกสารจริง — Document::applyStock ส่ง cut_stock (จำนวน x ตัวคูณ) มาให้
$db->insert($T('inventory_template'), [
    'document_type' => 'SEL', 'status' => 'SEL', 'mode' => 'sell', 'topic' => 'ขายยกลัง',
    'comment' => '', 'in_stock' => 0, 'cut_stock' => 1, 'movement_type' => 'sale',
    'published' => 1, 'prefix' => 'SEL%Y%M-', 'number_format' => '%04d',
    'columns' => 'item,topic,quantity,price,discount,amount'
]);
\Inventory\Document\Model::clearCache();
$boxDoc = \Inventory\Document\Model::save(
    ['document_type' => 'SEL', 'order_no' => 'BOX-001', 'order_date' => '2026-07-01 09:00:00', 'total' => 300],
    [[
        'inventory_id' => $pbox, 'inventory_item_id' => $itemBox,
        'product_code' => 'BOX01-B', 'product_no' => 'BOX01-B', 'topic' => 'ยกลัง',
        'quantity' => 3, 'unit' => 'ลัง', 'price' => 100, 'total' => 300,
        // 3 ลัง x 12 ขวด = 36 ขวด
        'cut_stock' => 36
    ]], 0, 1
);
tnum('ขายผ่านเอกสาร 3 ลัง ตัด 36 ขวด', 54, \Inventory\Posting\Model::balance($pbox, 0));
tnum('ยกเลิกเอกสารแล้วของกลับมาครบ', 90, (function () use ($boxDoc, $pbox) {
    \Inventory\Document\Model::cancel($boxDoc, 1);

    return \Inventory\Posting\Model::balance($pbox, 0);
})());

// -----------------------------------------------------------------------------
group('Product Master — ทะเบียนสินค้าและหน่วยย่อย');

$new = \Inventory\Product\Model::createProduct([
    'product_code' => 'M001', 'topic' => 'สินค้าที่สร้างผ่านโมเดล',
    'category_id' => '3', 'unit' => 'ชิ้น', 'price' => 250, 'count_stock' => 1
]);
t('สร้างสินค้าใหม่ได้ (ระบบเดิมพังทุกครั้งเพราะส่งคอลัมน์ที่ไม่มีในตาราง)', $new > 0);
$row = $db->first($T('inventory'), ['id' => $new]);
t('เขียน product_code กับ product_no คู่กัน', $row->product_code === 'M001' && $row->product_no === 'M001');
t('เขียน is_active กับ inuse คู่กัน', (int) $row->is_active === 1 && (int) $row->inuse === 1);
t('stockable คำนวณจาก count_stock ไม่ใช่สวิตช์อิสระ', (int) $row->stockable === 1);

$service = \Inventory\Product\Model::createProduct(['product_code' => 'M002', 'topic' => 'ค่าบริการ', 'count_stock' => 0]);
$row = $db->first($T('inventory'), ['id' => $service]);
t('สินค้าที่ไม่นับสต๊อกได้ stockable = 0', (int) $row->stockable === 0);

tthrows('ไม่กรอกชื่อสินค้า', function () {
    \Inventory\Product\Model::createProduct(['product_code' => 'M003']);
}, 'กรุณากรอกชื่อสินค้า');
tthrows('รหัสสินค้าซ้ำ', function () {
    \Inventory\Product\Model::createProduct(['product_code' => 'M001', 'topic' => 'ซ้ำ']);
}, 'ถูกใช้ไปแล้ว');

\Inventory\Product\Model::updateProduct($new, ['product_code' => 'M001', 'topic' => 'ชื่อใหม่', 'is_active' => 0]);
$row = $db->first($T('inventory'), ['id' => $new]);
t('แก้ไขสินค้าแล้วชื่อเปลี่ยน', $row->topic === 'ชื่อใหม่');
t('ปิดการใช้งานแล้ว is_active กับ inuse เป็น 0 ทั้งคู่', (int) $row->is_active === 0 && (int) $row->inuse === 0);

$item = \Inventory\Product\Model::saveItem($new, ['sku' => 'M001-A', 'topic' => 'หน่วยย่อย A', 'unit' => 'กล่อง', 'cut_stock' => 12]);
$row = $db->first($T('inventory_items'), ['id' => $item]);
t('หน่วยย่อยเขียน sku กับ product_no คู่กัน', $row->sku === 'M001-A' && $row->product_no === 'M001-A');
tnum('cut_stock ของหน่วยย่อย', 12, (float) $row->cut_stock);
tthrows('รหัสหน่วยย่อยซ้ำ', function () use ($new) {
    \Inventory\Product\Model::saveItem($new, ['sku' => 'M001-A', 'topic' => 'ซ้ำ']);
}, 'ถูกใช้ไปแล้ว');
t('หน่วยย่อยของสินค้าอ่านกลับมาได้', count(\Inventory\Product\Model::items($new)) === 1);

\Inventory\Product\Model::openingBalance($new, 0, 7, 100);
tnum('ตั้งยอดเปิดผ่านสมุดบัญชี', 7, \Inventory\Posting\Model::balance($new));
$product = \Inventory\Product\Model::get($new);
tnum('get() คืนยอดคงเหลือจริงจากสมุดบัญชี', 7, $product['balance']);

\Inventory\Product\Model::updateProduct($new, ['product_code' => 'M001', 'topic' => 'ชื่อใหม่', 'stock' => 999]);
tnum('แก้ไขสินค้าเขียนทับยอดคงเหลือไม่ได้', 7, \Inventory\Posting\Model::balance($new));

tthrows('ลบสินค้าที่มีประวัติการเคลื่อนไหวไม่ได้', function () use ($new) {
    \Inventory\Product\Model::remove($new);
}, 'ปิดการใช้งานสินค้าแทน');
t('ลบสินค้าที่ยังไม่เคยเคลื่อนไหวได้', \Inventory\Product\Model::remove($service) === true);

// -----------------------------------------------------------------------------
group('ชั้นเอกสาร — เอกสารสั่งเดินสต๊อกผ่าน Posting API เท่านั้น');

// ⚠️ ฐานที่ติดตั้งใหม่มีแม่แบบตั้งต้น 9 ชนิดมาแล้วจาก database.sql
// ชุดทดสอบต้องคุมแม่แบบเองทั้งหมด (มีตัวที่ตั้งค่าผิดไว้ทดสอบการปฏิเสธด้วย)
// จึงล้างของเดิมก่อน — ฐานนี้เป็นฐานทดสอบของชุดนี้เอง ไม่กระทบใคร
$db->delete($T('inventory_template'), ['id', '>', 0], 0);

// แม่แบบเอกสารตามทะเบียนกลาง (หัวข้อ 4.6) — เท่าที่ชุดนี้ใช้
foreach ([
    ['QUO', 'sell', 'ใบเสนอราคา', 0, 0, null],
    ['IN', 'buy', 'ใบรับสินค้า', 1, 0, 'purchase'],
    ['OUT', 'sell', 'ใบเสร็จรับเงิน', 0, 1, 'sale'],
    ['RET', 'buy', 'ใบคืนสินค้า', 1, 0, 'return_in'],
    ['BAD', 'sell', 'แม่แบบที่ตั้งค่าผิด', 0, 1, 'purchase'],
    // ใบสั่งขาย — จองของ ไม่ตัด (คอลัมน์ที่ 7)
    ['SO', 'sell', 'ใบสั่งขาย', 0, 0, null, 1]
] as $tpl) {
    $db->insert($T('inventory_template'), [
        'document_type' => $tpl[0], 'status' => $tpl[0], 'mode' => $tpl[1], 'topic' => $tpl[2],
        'comment' => '', 'in_stock' => $tpl[3], 'cut_stock' => $tpl[4],
        'reserve_stock' => isset($tpl[6]) ? $tpl[6] : 0,
        'movement_type' => $tpl[5], 'published' => 1,
        'prefix' => $tpl[0].'%Y%M-', 'number_format' => '%04d',
        'columns' => 'item,topic,quantity,price,discount,amount'
    ]);
}
\Inventory\Document\Model::clearCache();
t('อ่านแม่แบบเอกสารได้ครบ', count(\Inventory\Document\Model::templates()) === 6);

$p8 = product('P008', 'สินค้าที่ขายผ่านเอกสาร');
$line = function ($qty, $price) use ($p8) {
    return ['inventory_id' => $p8, 'product_code' => 'P008', 'product_no' => 'P008',
        'topic' => 'สินค้าที่ขายผ่านเอกสาร', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => $price, 'total' => $qty * $price, 'cut_stock' => $qty];
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
    return ['inventory_id' => $pdraft, 'product_code' => 'DRAFT01', 'product_no' => 'DRAFT01',
        'topic' => 'สินค้าสำหรับทดสอบเอกสารร่าง', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => 20, 'total' => $qty * 20, 'cut_stock' => $qty];
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
    return ['inventory_id' => $pdel, 'product_code' => 'DEL01', 'product_no' => 'DEL01',
        'topic' => 'สินค้าสำหรับทดสอบการลบเอกสาร', 'quantity' => $qty, 'unit' => 'ชิ้น',
        'price' => 10, 'total' => $qty * 10, 'cut_stock' => $qty];
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
    return ['inventory_id' => $p9, 'product_code' => 'P009', 'topic' => 'สินค้าคิดต้นทุนผ่านเอกสาร',
        'quantity' => $qty, 'price' => $price, 'total' => $qty * $price, 'cut_stock' => $qty];
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
        ['inventory_id', 'product_code', 'topic', 'quantity', 'unit', 'price', 'discount', 'vat', 'total', 'cut_stock'],
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
    '\\Inventory\\Order\\Controller' => 'order-edit.html',
    '\\Inventory\\Template\\Controller' => 'template-edit.html'
];
$notNested = [];
foreach ($formControllers as $class => $template) {
    $source = file_get_contents((new \ReflectionClass($class))->getFileName());
    // ต้องมี successResponse ที่ห่อด้วย 'data' => ... อยู่ในเมธอด get()
    if (strpos($source, "'data' => ") === false || strpos($source, "'options' => ") === false) {
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
$searchOk = preg_match('/<input[^>]*name="product_code"[^>]*data-role="product_code"/', $orderForm)
    || preg_match('/<input[^>]*data-role="product_code"[^>]*name="product_code"/', $orderForm);
t('ช่องค้นหาสินค้ามีทั้ง name และ data-role', (bool) $searchOk);
t('ตารางฟังช่องค้นหาและส่งพารามิเตอร์จาก data-role',
    strpos($orderForm, "data-listen-select=\"[data-role='product_code']\"") !== false
    && strpos($orderForm, 'data-api-params="[data-role]"') !== false);

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
    empty(array_diff(glob($templateDir.'*.html') ?: [], [
        $templateDir.'envelope.html', $templateDir.'email.html',
    ])));
$defaultColumns = \Inventory\Export\Controller::defaultColumns();
t('มีชุดคอลัมน์เริ่มต้นของเอกสาร ('.implode(', ', $defaultColumns).')', !empty($defaultColumns));
t('แม่แบบซองจดหมายและอีเมลมาด้วย',
    is_file($templateDir.'envelope.html') && is_file($templateDir.'email.html'));

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
t('ไฟล์ CSV ของสินค้าใช้ชื่อคอลัมน์กลาง',
    $spec !== null && in_array('product_code', $spec['columns'], true)
    && in_array('is_active', $spec['columns'], true)
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
$imported = $db->first($T('inventory'), ['product_code' => 'IMP001']);
t('สินค้าที่นำเข้าเขียนชื่อกลางกับชื่อเดิมคู่กัน',
    $imported && $imported->product_no === 'IMP001' && (int) $imported->is_active === 1);
tnum('ยอดตั้งต้นจากไฟล์เข้าสมุดบัญชีแล้ว', 12, \Inventory\Posting\Model::balance((int) $imported->id));
$row = $db->first($T('inventory_stock_movement'), ['inventory_id' => (int) $imported->id]);
t('ยอดจากไฟล์ใช้ชนิด opening ไม่ใช่เขียนทับคอลัมน์ stock', $row && $row->movement_type === 'opening');

// นำเข้าไฟล์เดิมซ้ำต้องไม่ทำให้ยอดกระโดด
\Inventory\Import\Model::importRow('product', [
    'product_code' => 'IMP001', 'topic' => 'สินค้านำเข้าจากไฟล์', 'count_stock' => 1,
    'price' => 95, 'cost' => 40, 'stock' => 999, 'unit' => 'ชิ้น', 'is_active' => 1
]);
tnum('นำเข้าไฟล์เดิมซ้ำ ยอดคงเหลือไม่เปลี่ยน', 12, \Inventory\Posting\Model::balance((int) $imported->id));
$imported = $db->first($T('inventory'), ['product_code' => 'IMP001']);
tnum('แต่ราคาที่แก้ในไฟล์ถูกอัปเดต', 95, (float) $imported->price);

// -----------------------------------------------------------------------------
group('หน้าย่อยของสินค้า — หน่วยย่อย/บาร์โค้ด (Inventory\\Items)');

// สินค้าที่นับสต๊อกแยกรายชิ้น หนึ่งรหัส = หนึ่งชิ้นในสต๊อก
$pi = product('ITM01', 'สินค้านับแยกรายชิ้น', 2);
$piRow = \Inventory\Product\Model::get($pi);

$errs = \Inventory\Items\Model::save($piRow, [
    ['product_no' => 'SN-001', 'topic' => 'เครื่องที่ 1', 'price' => 500, 'unit' => 'เครื่อง'],
    ['product_no' => 'SN-002', 'topic' => 'เครื่องที่ 2', 'price' => 500, 'unit' => 'เครื่อง']
], 1);
t('บันทึกหน่วยย่อยสองรหัสสำเร็จ', empty($errs));
tnum('ยอดคงเหลือของสินค้า = 2 ชิ้น', 2, \Inventory\Posting\Model::totalBalance($pi));

// id ของหน่วยย่อยต้องคงที่เมื่อกดบันทึกซ้ำ ไม่งั้นสมุดบัญชีชี้ไปหาแถวที่ไม่มีแล้ว
$before = $db->select($T('inventory_items'), ['inventory_id', $pi]);
$idsBefore = [];
foreach ($before as $row) {
    $idsBefore[$row->sku] = (int) $row->id;
}
$errs = \Inventory\Items\Model::save($piRow, [
    ['product_no' => 'SN-001', 'topic' => 'เครื่องที่ 1 (แก้ชื่อ)', 'price' => 550, 'unit' => 'เครื่อง'],
    ['product_no' => 'SN-002', 'topic' => 'เครื่องที่ 2', 'price' => 500, 'unit' => 'เครื่อง']
], 1);
$after = $db->select($T('inventory_items'), ['inventory_id', $pi]);
$idsAfter = [];
foreach ($after as $row) {
    $idsAfter[$row->sku] = (int) $row->id;
}
t('กดบันทึกซ้ำแล้ว id ของหน่วยย่อยไม่เปลี่ยน', $idsBefore === $idsAfter && !empty($idsAfter));
tnum('กดบันทึกซ้ำแล้วยอดไม่ถูกนับซ้ำ ยังเป็น 2', 2, \Inventory\Posting\Model::totalBalance($pi));

// ลบหน่วยย่อยออกหนึ่งรหัส ยอดต้องลดตาม
$errs = \Inventory\Items\Model::save($piRow, [
    ['product_no' => 'SN-001', 'topic' => 'เครื่องที่ 1', 'price' => 550, 'unit' => 'เครื่อง']
], 1);
tnum('ลบหน่วยย่อยออกหนึ่งรหัส ยอดเหลือ 1', 1, \Inventory\Posting\Model::totalBalance($pi));

// รหัสซ้ำภายในชุดเดียวกันต้องไม่ผ่าน
$errs = \Inventory\Items\Model::save($piRow, [
    ['product_no' => 'SN-009', 'topic' => 'ก', 'price' => 1, 'unit' => 'เครื่อง'],
    ['product_no' => 'SN-009', 'topic' => 'ข', 'price' => 1, 'unit' => 'เครื่อง']
], 1);
t('รหัสหน่วยย่อยซ้ำกันในชุดเดียวถูกปฏิเสธ', !empty($errs));

// รหัสที่เป็นของสินค้าตัวอื่นอยู่แล้ว ใช้ซ้ำไม่ได้
$pi2 = product('ITM02', 'สินค้านับแยกอีกตัว', 2);
$pi2Row = \Inventory\Product\Model::get($pi2);
$errs = \Inventory\Items\Model::save($pi2Row, [
    ['product_no' => 'SN-001', 'topic' => 'แย่งรหัส', 'price' => 1, 'unit' => 'เครื่อง']
], 1);
t('รหัสหน่วยย่อยที่เป็นของสินค้าอื่นถูกปฏิเสธ', !empty($errs));

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
$ia = (int) $db->insert($T('inventory_items'), ['sku' => 'ADJ02-A', 'product_no' => 'ADJ02-A',
    'inventory_id' => $pb, 'unit' => 'เครื่อง', 'price' => 100, 'cut_stock' => 1]);
$ib = (int) $db->insert($T('inventory_items'), ['sku' => 'ADJ02-B', 'product_no' => 'ADJ02-B',
    'inventory_id' => $pb, 'unit' => 'เครื่อง', 'price' => 100, 'cut_stock' => 1]);
\Inventory\Posting\Model::receipt(['inventory_id' => $pb, 'inventory_item_id' => $ia, 'quantity' => 4, 'unit_cost' => 80]);
\Inventory\Stocks\Model::adjust($pb, $ia, 6, 'นับได้เกินสองเครื่อง', 1);
tnum('ปรับยอดของหน่วยย่อยที่เลือก', 6, \Inventory\Posting\Model::balance($pb, $ia));
tnum('หน่วยย่อยอื่นไม่ถูกกระทบ', 0, \Inventory\Posting\Model::balance($pb, $ib));

t('หน่วยย่อยของสินค้าตัวเองผ่านการตรวจ', \Inventory\Stocks\Model::ownsItem($pb, $ia));
t('หน่วยย่อยของสินค้าตัวอื่นถูกปฏิเสธ', !\Inventory\Stocks\Model::ownsItem($pa, $ia));
t('ยอดระดับสินค้า (id 0) ผ่านเสมอ', \Inventory\Stocks\Model::ownsItem($pa, 0));

t('สินค้านับรวมไม่มีตัวเลือกหน่วยย่อยให้เลือก',
    \Inventory\Stocks\Model::unitOptions($pa, 1) === []);
$units = \Inventory\Stocks\Model::unitOptions($pb, 2);
t('สินค้านับแยกรายชิ้นได้ตัวเลือกครบทุกหน่วย', count($units) === 2);
t('ตัวเลือกหน่วยย่อยบอกยอดคงเหลือของหน่วยนั้นมาด้วย',
    strpos($units[0]['text'], '6') !== false && (int) $units[0]['value'] === $ia);

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

$pd = product('DTL01', 'สินค้ามีรายละเอียด');
\Inventory\Detail\Model::save($pd, ['description' => 'คำโปรย', 'detail' => 'รายละเอียดยาว ๆ']);
$metas = [];
foreach ($db->select($T('inventory_meta'), ['inventory_id', $pd]) as $row) {
    $metas[$row->name] = $row->value;
}
t('บันทึก description และ detail ลง inventory_meta',
    ($metas['description'] ?? '') === 'คำโปรย' && ($metas['detail'] ?? '') === 'รายละเอียดยาว ๆ');
$prod = $db->first($T('inventory'), ['id' => $pd]);
t('เขียนคอลัมน์ inventory.description ให้ตรงกันด้วย', $prod->description === 'คำโปรย');

// บันทึกซ้ำต้องไม่เกิดแถว meta ซ้อน
\Inventory\Detail\Model::save($pd, ['description' => 'คำโปรยใหม่', 'detail' => 'รายละเอียดยาว ๆ']);
$count = $db->count($T('inventory_meta'), [['inventory_id', $pd], ['name', 'description']]);
tnum('บันทึกซ้ำแล้ว meta ไม่ซ้อนกัน', 1, $count);

// ค่าว่างต้องลบแถวทิ้ง ไม่ใช่เก็บสตริงว่างไว้
\Inventory\Detail\Model::save($pd, ['description' => '', 'detail' => '']);
tnum('บันทึกค่าว่างแล้วแถว meta ถูกลบ', 0, $db->count($T('inventory_meta'), ['inventory_id', $pd]));

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
    'modules/inventory/controllers/customer.php' => 'inventory/customer.html'
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
group('ใบสั่งขายจองของ — ของยังอยู่ในคลังแต่ขายให้คนอื่นไม่ได้');

\Inventory\Document\Model::clearCache();
$soTpl = \Inventory\Document\Model::template('SO');
t('แม่แบบใบสั่งขายเป็นใบที่จองของ ไม่ตัดของ',
    $soTpl !== null && (int) $soTpl['reserve_stock'] === 1 && (int) $soTpl['cut_stock'] === 0);
// ชุดนี้ล้างแม่แบบแล้วใส่เองข้างบน — ต้องตรวจแยกว่าตัวติดตั้งจริงก็ให้ SO มาด้วย
// คอลัมน์: document_type, status, mode, topic, comment, due_date, published,
//          in_stock, cut_stock, reserve_stock, movement_type, ... — เช็คตำแหน่ง
// in_stock=0, cut_stock=0, reserve_stock=1, movement_type=NULL
t('ตัวติดตั้ง (database.sql) ให้แม่แบบใบสั่งขายที่จองของมาตั้งแต่แรก',
    preg_match("/\\('SO',\\s*'SO',\\s*'sell',[^)]*,\\s*0,\\s*0,\\s*1,\\s*NULL,/",
        file_get_contents(ROOT_PATH.'modules/inventory/install/database.sql')) === 1);
t('ปลายทางของใบที่จองคือใบที่ตัดของจริง (OUT)',
    \Inventory\Document\Model::cutStockType('sell') === 'OUT');

$rsv = product('RSV01', 'สินค้าทดสอบการจอง');
\Inventory\Posting\Model::opening($rsv, 0, 10, 50);
tnum('เริ่มต้น คงเหลือ 10', 10, \Inventory\Posting\Model::balance($rsv));
tnum('ยังไม่มีการจอง', 0, \Inventory\Posting\Model::reserved($rsv));

// ลูกค้าออนไลน์สั่ง 7 ชิ้น → ใบสั่งขาย
$soId = \Inventory\Document\Model::save(
    ['document_type' => 'SO', 'document_status' => 'issued', 'order_no' => 'SO-001',
        'order_date' => date('Y-m-d H:i:s'), 'vat_status' => 0, 'total' => 700, 'subtotal' => 700],
    \Inventory\Cart\Model::buildLines([['product_code' => 'RSV01', 'quantity' => 7]]), 0, 1
);
tnum('ใบสั่งขายไม่ตัดของ — คงเหลือยัง 10', 10, \Inventory\Posting\Model::balance($rsv));
tnum('แต่จองไว้ 7', 7, \Inventory\Posting\Model::reserved($rsv));
tnum('ขายได้จริงเหลือ 3', 3, \Inventory\Posting\Model::available($rsv));
tnum('ใบสั่งขายไม่สร้างแถวในสมุดบัญชี', 0,
    $db->count($T('inventory_stock_movement'), [['reference_type', 'order'], ['reference_id', $soId]]));

// ⚠️ หัวใจของเรื่อง : หน้าร้านจะขาย 5 ชิ้นทั้งที่ของที่ไม่ถูกจองเหลือ 3 ต้องถูกปฏิเสธ
tthrows('ขายหน้าร้าน 5 ทั้งที่ของที่ไม่ถูกจองเหลือ 3 ต้องถูกปฏิเสธ', function () use ($rsv) {
    \Inventory\Posting\Model::issue(['inventory_id' => $rsv, 'quantity' => 5, 'reference_type' => 'adjustment']);
}, 'จองไว้แล้ว');
$walkIn = \Inventory\Posting\Model::issue(['inventory_id' => $rsv, 'quantity' => 3, 'reference_type' => 'adjustment']);
t('ขายหน้าร้าน 3 (เท่าที่ไม่ถูกจอง) ได้', $walkIn > 0);
tnum('คงเหลือ 7 = ของที่จองไว้พอดี', 7, \Inventory\Posting\Model::balance($rsv));

// ⚠️ ส่วนนี้ต้องมีโมดูล payment (ผลิตภัณฑ์ที่รับเงินได้) — oas ออกเอกสารกับสต๊อกอย่างเดียว
// จึงข้าม แต่ยังต้องตรวจส่วนที่เหลือของการจองให้ครบ
$outId = 0;
if (class_exists('\\Payment\\Record\\Model')) {
    // เงินมา → ใบสั่งขายกลายเป็นใบเสร็จ ของถูกตัด การจองหลุด — โดยไม่ต้องบอกอะไร Payment
    $paidLines = \Payment\Record\Model::record($soId, [['method' => 'cash', 'amount' => 700, 'tendered_amount' => 700]], 1);
    $outId = (int) $paidLines[0]['order_id'];
    t('รับเงินบนใบสั่งขาย → เงินไปลงบนใบใหม่ ไม่ใช่ใบสั่งขาย', $outId !== $soId);
    $out = $db->first($T('orders'), ['id' => $outId]);
    t('ใบใหม่เป็นใบเสร็จ (ชนิดที่ตัดของ)', $out->document_type === 'OUT');
    tnum('และชี้กลับไปหาใบสั่งขายต้นทาง', $soId, (int) $out->source_document_id);
    if (class_exists('\\Shipping\\Methods\\Model')) {
        // หน้าที่จัดส่งย้ายไปใบเสร็จ — ใบสั่งขายต้องหลุดจากคิวจัดส่ง ไม่งั้นส่งของสองรอบ
        t('ใบสั่งขายที่แปลงแล้วหลุดจากคิวจัดส่ง (shipping_status ว่าง)',
            $db->first($T('orders'), ['id' => $soId])->shipping_status === null);
    }
    t('ใบเสร็จอ้างเลขที่ใบสั่งขาย', $out->reference_document_no === 'SO-001');
    t('ใบเสร็จจ่ายครบ', $out->payment_status === 'paid');
    tnum('ของถูกตัดจริงแล้ว — คงเหลือ 0', 0, \Inventory\Posting\Model::balance($rsv));
    tnum('การจองหลุดแล้ว', 0, \Inventory\Posting\Model::reserved($rsv));
    tnum('ใบสั่งขายเองไม่มีเงิน', 0, (float) $db->first($T('orders'), ['id' => $soId])->paid);
    t('ใบสั่งขายยังอยู่เป็นต้นสาย ไม่ถูกลบ',
        $db->first($T('orders'), ['id' => $soId])->document_status === 'issued');
    $docsBefore = $db->count($T('orders'));
    tthrows('รับเงินซ้ำบนใบสั่งขายที่แปลงไปแล้วต้องถูกชี้ไปที่ใบเสร็จ ไม่ใช่ออกซ้ำ', function () use ($soId) {
        \Payment\Record\Model::record($soId, [['method' => 'cash', 'amount' => 1, 'tendered_amount' => 1]], 1);
    }, 'กรุณารับเงินที่ใบเสร็จใบนั้น');
    tnum('และต้องไม่มีใบเสร็จซ้ำโผล่มา', $docsBefore, $db->count($T('orders')));
}

// ⚠️ เอกสารที่ล้มตอนเดินสต๊อก (ของไม่พอ) ต้องไม่เหลือหัวเอกสารค้างไว้ — ก่อน 2026-09-12
// save() ไม่ได้อยู่ใน transaction ใบที่ล้มจึงกลายเป็น "ออกแล้ว" โดยไม่มีของเดิน
$docsBefore = $db->count($T('orders'));
tthrows('ใบเสร็จที่ของไม่พอต้องถูกปฏิเสธ', function () {
    \Inventory\Document\Model::save(
        ['document_type' => 'OUT', 'document_status' => 'issued', 'order_no' => 'FAIL-001',
            'order_date' => date('Y-m-d H:i:s'), 'vat_status' => 0, 'total' => 1, 'subtotal' => 1],
        \Inventory\Cart\Model::buildLines([['product_code' => 'RSV01', 'quantity' => 99999]]), 0, 1
    );
}, 'ไม่พอ');
tnum('และต้องไม่เหลือหัวเอกสารค้างไว้ในตาราง', $docsBefore, $db->count($T('orders')));

// ยกเลิกใบเสร็จ → ของกลับ และใบสั่งขายกลับมาจองอีก (ลูกค้ายังสั่งอยู่ แค่ใบเสร็จผิด)
if ($outId > 0) {
    \Inventory\Document\Model::cancel($outId, 1);
    tnum('ยกเลิกใบเสร็จแล้วของกลับเข้าคลัง', 7, \Inventory\Posting\Model::balance($rsv));
    tnum('และใบสั่งขายกลับมาจองอีกครั้ง', 7, \Inventory\Posting\Model::reserved($rsv));
}

// ยกเลิกใบสั่งขาย → ของถูกปล่อย
\Inventory\Document\Model::cancel($soId, 1);
tnum('ยกเลิกใบสั่งขายแล้วการจองหลุด', 0, \Inventory\Posting\Model::reserved($rsv));
tnum('ขายได้ทั้ง 7', 7, \Inventory\Posting\Model::available($rsv));

// ยอดจองเป็นผลสรุป — ซ่อมได้ด้วยการคำนวณใหม่ เหมือนยอดคงเหลือ
$db->update($T('inventory_stock'), ['inventory_id', $rsv], ['reserved_qty' => 999]);
\Inventory\Posting\Model::syncReserved($rsv, 0);
tnum('ยอดจองที่เพี้ยนซ่อมได้จากเอกสารจริง', 0, \Inventory\Posting\Model::reserved($rsv));

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
tnum('หน้า "ทั้งหมด" เห็นครบ', 3, count($rows([])));

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
    && strpos($customerTpl, 'name="contact"') !== false
    && strpos($customerTpl, 'name="idcard"') !== false
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
        'comment' => '', 'due_date' => $tpl[2], 'in_stock' => 0, 'cut_stock' => 0, 'reserve_stock' => 0,
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
    'inventory_id' => $service, 'product_code' => 'SAC01', 'product_no' => 'SAC01',
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
            'inventory_id' => $service, 'product_code' => 'SAC01', 'topic' => 'ค่าบริการทดสอบสร้างใหม่',
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
$saved = (object) ['company' => ['authorized' => 'ชื่อเดิม', 'allowno' => 'ค่าที่ไม่มีใครใช้']];
$parse->invoke(new \Index\Settings\Controller(), ['company_authorized_id' => '999999'], $saved);
t('บันทึกสมาชิกที่ไม่มีอยู่จริง = ไม่ได้ระบุ และชื่อแบบเดิมถูกตัดทิ้ง',
    $saved->company['authorized_id'] === 0 && !array_key_exists('authorized', $saved->company));
t('allowno ที่ไม่มีที่ใดใช้ถูกตัดออกจากค่ากำหนดและจากฟอร์ม',
    !array_key_exists('allowno', $saved->company) && strpos(file_get_contents(ROOT_PATH.'templates/settings/company.html'), 'allowno') === false);
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
