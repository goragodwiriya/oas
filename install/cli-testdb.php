<?php
/**
 * install/cli-testdb.php — สร้างฐานทดสอบ F1-F7 แล้วรันตัวปรับรุ่นตัวจริงลงไป
 *
 * ทุกฐานสร้างจาก "ตัวติดตั้งของจริง" (install/cli-fresh.php) แล้วดัดแปลงต่อ
 * ไม่ใช่ SQL ที่เขียนขึ้นเอง เพราะเส้นทางที่ต้องพิสูจน์คือเส้นทางเดียวกับที่
 * ไซต์ปลายทางใช้ ไม่ใช่เส้นทางที่เราสร้างขึ้นมาให้ตัวเองผ่าน
 *
 * ชุดทดสอบ
 *   F1  ฐานรุ่นปัจจุบัน                    ต้องปรับรุ่นผ่าน
 *   F2  F1 + ข้อมูลทุกเส้นทาง               ต้องผ่านและข้อมูลต้องไม่ลดลงสักแถว
 *   F3  ฐานหน้าตาแบบรุ่นก่อน (MyISAM/utf8mb3/ชื่อคอลัมน์เก่า/ตารางใหม่ยังไม่มี)
 *   F4  ฐานที่มีคนแก้เอง (คอลัมน์แปลกปลอม ดัชนีหาย collation เพี้ยน ค่าว่างเป็น '')
 *   F4b ฐานที่มีค่าซ้ำในคอลัมน์ที่จะเป็น UNIQUE   ต้องถูก "ปฏิเสธ" พร้อมบอกวิธีแก้
 *   F5  ฐานข้อมูลเยอะ (logs 100,000 แถว)     ต้องผ่านและไม่หมดเวลา
 *   F6  ฐานที่ปรับรุ่นค้างครึ่งทาง            รันซ้ำแล้วต้องเดินต่อจนจบ
 *   F7  prefix ที่ไม่ใช่ app                 ต้องผ่านและต้องไม่แตะตารางของ prefix อื่น
 *
 * ใช้:  php install/cli-testdb.php [ชุด ...] [ตัวเลือก]
 *
 *   ไม่ระบุชุด = ทำทุกชุด · ระบุได้เช่น  php install/cli-testdb.php f1 f4b
 *   --db-prefix=<name>  คำนำหน้าชื่อฐานทดสอบ (ค่าเริ่มต้น nowtest)
 *   --keep              ไม่ลบฐานทดสอบทิ้งเมื่อจบ (ค่าเริ่มต้นคือเก็บไว้อยู่แล้ว)
 *   --drop              ลบฐานทดสอบทั้งหมดทิ้งแล้วจบ
 *   --quiet             แสดงเฉพาะผลสรุปของแต่ละชุด
 *
 * exit code : 0 = ผ่านทุกชุด, 1 = มีชุดที่ไม่ผ่าน
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

define('ROOT_PATH', str_replace(['\\', 'install/cli-testdb.php'], ['/', ''], __FILE__));
include_once ROOT_PATH.'install/common.php';
include_once ROOT_PATH.'install/db.php';

$ALL = ['f1', 'f2', 'f3', 'f4', 'f4b', 'f5', 'f6', 'f7'];
$want = [];
$options = ['db-prefix' => 'nowtest', 'keep' => false, 'drop' => false, 'quiet' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $match)) {
        if (!array_key_exists($match[1], $options)) {
            fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
            exit(1);
        }
        $options[$match[1]] = isset($match[2]) ? $match[2] : true;
    } elseif (in_array(strtolower($arg), $ALL, true)) {
        $want[] = strtolower($arg);
    } else {
        fwrite(STDERR, "ไม่รู้จักชุดทดสอบ $arg (มี: ".implode(' ', $ALL).")\n");
        exit(1);
    }
}
if (empty($want)) {
    $want = $ALL;
}

$work = sys_get_temp_dir().'/nowjs-testdb-'.md5(ROOT_PATH);
if (!is_dir($work)) {
    mkdir($work, 0700, true);
}
$pdo = testdbConnect();

if ($options['drop'] === true) {
    foreach (array_merge($ALL, ['fresh', 'fresh7']) as $name) {
        $pdo->exec('DROP DATABASE IF EXISTS `'.$options['db-prefix'].'_'.$name.'`');
    }
    echo "ลบฐานทดสอบทั้งหมดแล้ว\n";
    exit(0);
}

// ฐานอ้างอิงสำหรับเทียบสคีมา — "ติดตั้งใหม่" ของ prefix app และ prefix rp
run('cli-fresh', [$options['db-prefix'].'_fresh', 'app', '--no-admin']);
if (in_array('f7', $want, true)) {
    run('cli-fresh', [$options['db-prefix'].'_fresh7', 'rp', '--no-admin']);
}

$results = [];
foreach ($want as $fixture) {
    $results[$fixture] = runFixture($fixture, $pdo, $options, $work);
}

echo "\n".str_repeat('=', 70)."\n";
$failed = 0;
$skipped = 0;
foreach ($results as $fixture => $ok) {
    if ($ok === 'skip') {
        echo '[ข้าม] '.strtoupper($fixture)."\n";
        ++$skipped;
        continue;
    }
    echo ($ok ? '[ok]   ' : '[FAIL] ').strtoupper($fixture)."\n";
    if (!$ok) {
        ++$failed;
    }
}
$counted = count($results) - $skipped;
echo ($failed === 0 ? 'ผ่านทุกชุด '.$counted.' ชุด' : 'ไม่ผ่าน '.$failed.' จาก '.$counted.' ชุด')
    .($skipped > 0 ? ' (ข้าม '.$skipped.' ชุด)' : '')."\n";
exit($failed === 0 ? 0 : 1);

// =============================================================================

/**
 * เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูล (ไม่เจาะจงฐาน) ด้วยค่าจาก settings/database.php
 *
 * @return PDO
 */
function testdbConnect()
{
    $cfg = include ROOT_PATH.'settings/database.php';
    $cfg = $cfg['mysql'];
    $dsn = 'mysql:host='.$cfg['hostname'].';port='.(empty($cfg['port']) ? 3306 : $cfg['port']).';charset=utf8mb4';

    return new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * เปิด Db ของ install/db.php ไปที่ฐานทดสอบหนึ่ง
 *
 * @param string $dbname
 * @param string $prefix
 *
 * @return Db
 */
function testdbDb($dbname, $prefix)
{
    $cfg = include ROOT_PATH.'settings/database.php';
    $cfg = $cfg['mysql'];
    $cfg['dbname'] = $dbname;
    $cfg['prefix'] = $prefix;

    return new Db($cfg);
}

/**
 * เรียกเครื่องมือ CLI ตัวอื่นในโฟลเดอร์ install/ แล้วคืนผลลัพธ์
 *
 * @param string $tool ชื่อไฟล์ (ไม่ต้องมี .php)
 * @param array  $args
 *
 * @return array [exit code, ข้อความที่พิมพ์ออกมา]
 */
function run($tool, array $args)
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(ROOT_PATH.'install/'.$tool.'.php');
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }
    $output = [];
    $code = 0;
    exec($command.' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

/**
 * สร้างและตรวจฐานทดสอบหนึ่งชุด
 *
 * @param string $fixture
 * @param PDO    $pdo
 * @param array  $options
 * @param string $work โฟลเดอร์เก็บไฟล์ชั่วคราวของชุดทดสอบ
 *
 * @return bool|string true = ผ่าน, false = ไม่ผ่าน, 'skip' = ข้าม (ไม่มีของจำลอง)
 */
function runFixture($fixture, $pdo, array $options, $work)
{
    $dbname = $options['db-prefix'].'_'.$fixture;
    $prefix = $fixture === 'f7' ? 'rp' : 'app';
    $fresh = $options['db-prefix'].($fixture === 'f7' ? '_fresh7' : '_fresh');
    $config = $work.'/'.$fixture.'.php';
    $counts = $work.'/'.$fixture.'.counts.json';
    $quiet = $options['quiet'] === true;
    $expect_refusal = ($fixture === 'f4b');

    echo "\n".str_repeat('-', 70)."\n".strtoupper($fixture).' → `'.$dbname.'` (prefix '.$prefix.")\n";

    // 0. ชุด F3 จะพิสูจน์ตัวปรับรุ่นของโมดูลได้ ต่อเมื่อมีของจำลอง "สภาพก่อนรับโมดูล"
    //
    // ถ้าไม่มี install/testdb-module.php ชุด F3 จะกลายเป็น "ติดตั้งใหม่แล้วปรับรุ่นทับ"
    // ซึ่งได้สคีมาถูกมาตั้งแต่ต้นอยู่แล้ว มันจึงขึ้น [ok] เสมอ **แม้จะเอาไฟล์
    // upgrade.php ของโมดูลออกไปทั้งไฟล์** — ผลที่ได้จึงไม่ใช่คำตอบ
    //
    // จึงรายงานเป็น "ข้าม" : ไม่นับว่าผ่าน และไม่นับว่าไม่ผ่าน เพราะโปรเจ็คที่ตรวจ
    // ตัวปรับรุ่นของโมดูลจนจบแล้วไม่จำเป็นต้องเก็บของจำลองไว้ตลอดไป
    if ($fixture === 'f3' && !is_file(ROOT_PATH.'install/testdb-module.php')) {
        $modules = glob(ROOT_PATH.'modules/*/install/upgrade.php') ?: [];
        if (!empty($modules)) {
            $names = [];
            foreach ($modules as $_m) {
                $names[] = trim(str_replace([ROOT_PATH.'modules/', '/install/upgrade.php'], '', $_m));
            }
            echo "  [ข้าม] ไม่มี install/testdb-module.php — ชุดนี้ไม่ได้พิสูจน์ตัวปรับรุ่นของโมดูล\n";
            echo '         โมดูลที่ดูแลสคีมาของตัวเอง : '.implode(' ', $names)."\n";
            echo "         ถ้าต้องการพิสูจน์ ให้เขียน install/testdb-module.php จำลองสภาพก่อนรับโมดูล\n";

            return 'skip';
        }
    }

    // 1. ติดตั้งใหม่ด้วยตัวติดตั้งของจริง
    list($code, $out) = run('cli-fresh', [$dbname, $prefix, '--config='.$config]);
    if ($code !== 0) {
        echo "  [FAIL] ติดตั้งฐานตั้งต้นไม่ได้\n$out\n";

        return false;
    }

    // 2. ดัดแปลงให้เป็นสภาพที่ชุดนั้นต้องการ
    $db = testdbDb($dbname, $prefix);
    $notes = fixtureMutate($fixture, $db, $prefix, $config);
    foreach ($notes as $note) {
        echo "  · $note\n";
    }

    // 3. จดจำนวนแถวก่อนปรับรุ่น
    list($code, $out) = run('cli-verify', [$dbname, $prefix, '--save-counts='.$counts]);
    if ($code !== 0) {
        echo "  [FAIL] นับจำนวนแถวก่อนปรับรุ่นไม่ได้\n$out\n";

        return false;
    }

    // 4. ปรับรุ่นด้วยตัวปรับรุ่นตัวจริง — สองรอบเสมอ (ต้องรันซ้ำได้)
    $rounds = [];
    foreach ([1, 2] as $round) {
        list($code, $out) = run('cli-upgrade', [
            'admin@localhost', 'admin', '--confirm-backup', '--db='.$dbname, '--prefix='.$prefix, '--config='.$config
        ]);
        $rounds[$round] = [$code, $out];
        if (!$quiet && ($code !== 0 || $round === 1)) {
            foreach (explode("\n", $out) as $line) {
                if (strpos($line, '[FAIL]') !== false || strpos($line, 'ยังปรับรุ่นไม่ได้') !== false) {
                    echo '  '.trim($line)."\n";
                }
            }
        }
    }

    if ($expect_refusal) {
        // ชุดนี้ต้องถูกปฏิเสธ และต้องบอกวิธีแก้ ไม่ใช่พังด้วย SQLSTATE
        if ($rounds[1][0] === 0) {
            echo "  [FAIL] ควรถูกปฏิเสธ แต่ปรับรุ่นผ่าน\n";

            return false;
        }
        if (strpos($rounds[1][1], 'ยังปรับรุ่นไม่ได้') === false) {
            echo "  [FAIL] ถูกปฏิเสธแต่ไม่ได้ใช้ข้อความ preflight (อาจพังกลางทาง)\n";

            return false;
        }
        if (strpos($rounds[1][1], 'กรุณาแก้') === false) {
            echo "  [FAIL] ปฏิเสธแล้วแต่ไม่ได้บอกวิธีแก้\n";

            return false;
        }
        // และต้องยังไม่แตะฐาน — จำนวนแถวต้องเท่าเดิมทุกตาราง
        list($code, $out) = run('cli-verify', [$dbname, $prefix, '--config='.$config, '--counts='.$counts, '--skip-preflight']);
        if ($code !== 0) {
            echo "  [FAIL] ถูกปฏิเสธแล้วแต่ข้อมูลเปลี่ยน\n$out\n";

            return false;
        }
        echo "  [ok]   ถูกปฏิเสธพร้อมบอกวิธีแก้ และยังไม่แตะฐานข้อมูล\n";

        return true;
    }

    foreach ($rounds as $round => $result) {
        if ($result[0] !== 0) {
            echo "  [FAIL] ปรับรุ่นรอบที่ $round ไม่ผ่าน\n".$result[1]."\n";

            return false;
        }
    }
    echo "  [ok]   ปรับรุ่นผ่านทั้งสองรอบ (รันซ้ำได้)\n";

    // 4b. ข้อยืนยันเพิ่มของโปรเจ็ค หลังปรับรุ่น (ถ้ามี)
    //
    // การเทียบสคีมาข้างล่างจับ "โครงสร้าง" ได้ แต่จับ "ข้อมูลที่ตัวปรับรุ่นต้องเติม"
    // ไม่ได้ (เช่น แม่แบบเอกสารใหม่ · คลังตั้งต้น) — โปรเจ็คที่ตัวปรับรุ่นเติมข้อมูลจึง
    // ประกาศ testdbAfterUpgrade($db, $prefix, $fixture) ใน install/testdb-module.php
    // คืน array ของข้อความที่ผิด (ว่าง = ผ่าน)
    if (is_file(ROOT_PATH.'install/testdb-module.php')) {
        include_once ROOT_PATH.'install/testdb-module.php';
    }
    if (function_exists('testdbAfterUpgrade')) {
        $problems = testdbAfterUpgrade($db, $prefix, $fixture);
        if (!empty($problems)) {
            echo "  [FAIL] ข้อยืนยันหลังปรับรุ่นของโปรเจ็คไม่ผ่าน ".count($problems)." ข้อ\n";
            foreach ($problems as $problem) {
                echo '         '.$problem."\n";
            }

            return false;
        }
        echo "  [ok]   ข้อยืนยันหลังปรับรุ่นของโปรเจ็คผ่าน\n";
    }

    // 5. ตรวจเกณฑ์: แถวไม่ลดลง + สคีมาเท่ากับติดตั้งใหม่ + preflight ผ่าน
    if ($fixture === 'f7') {
        // ข้อกำหนดข้อที่แข็งที่สุดของตัวปรับรุ่น: แตะเฉพาะตารางของ prefix ตัวเอง
        $row = $db->customQuery("SELECT `keepme` FROM `other_user` WHERE `id` = 1");
        if (empty($row) || $row[0]->keepme !== 'ห้ามแตะ') {
            echo "  [FAIL] ตารางของ prefix อื่นในฐานเดียวกันถูกแตะ\n";

            return false;
        }
        echo "  [ok]   ตารางของ prefix อื่นในฐานเดียวกันไม่ถูกแตะ\n";
    }

    $verify = [$dbname, $prefix, '--config='.$config, '--counts='.$counts, '--fresh='.$fresh, '--fresh-prefix='.$prefix];
    // ตารางที่ตัวปรับรุ่นของโปรเจ็คนี้ "ย้าย" แถวไปไว้ที่อื่นโดยตั้งใจ
    //
    // ⚠️ คำประกาศนี้ไม่ได้ยกเว้นการตรวจ — cli-verify ยังตรวจอยู่ แต่เปลี่ยนคำถาม
    // เป็น "แถวไปโผล่ที่ปลายทางครบไหม" ถ้าปลายทางรับไม่ครบก็ยังไม่ผ่านเหมือนเดิม
    // โปรเจ็คที่ไม่ได้ย้ายตารางไหนเลย ไม่ต้องมีฟังก์ชันนี้
    if (is_file(ROOT_PATH.'install/testdb-module.php')) {
        include_once ROOT_PATH.'install/testdb-module.php';
    }
    if (function_exists('testdbExpectedMoves')) {
        $_moves = [];
        foreach ((array) testdbExpectedMoves($prefix) as $_from => $_to) {
            foreach ((array) $_to as $_one) {
                $_moves[] = $_from.':'.$_one;
            }
        }
        if (!empty($_moves)) {
            $verify[] = '--moved='.implode(',', $_moves);
        }
    }
    if ($fixture === 'f4' || $fixture === 'f3') {
        // f4 จงใจใส่คอลัมน์แปลกปลอมไว้เอง ส่วน f3 คือไซต์รุ่นก่อนซึ่งย่อมมีตาราง
        // และคอลัมน์เดิมที่ฐานติดตั้งใหม่ไม่มี — เป็นไปตามกฎ "ห้ามลบของเดิม"
        // ของที่ "เกินมา" จึงไม่ใช่ความผิด แต่ยังรายงานเป็น [warn] ให้เห็นทุกครั้ง
        $verify[] = '--allow-extra';
    }
    list($code, $out) = run('cli-verify', $verify);
    echo preg_replace('/^/m', '  ', trim($out))."\n";

    return $code === 0;
}

/**
 * ดัดแปลงฐานที่เพิ่งติดตั้งใหม่ให้เป็นสภาพของชุดทดสอบนั้น
 *
 * โมดูลของโปรเจ็ค (install/testdb-module.php) ถูกเรียกด้วย
 * testdbSeedModule($db, $prefix, &$notes, $fixture) — พารามิเตอร์ตัวที่สี่บอกว่า
 * กำลังสร้างชุดไหน โมดูลที่ไม่สนใจก็ประกาศแค่สามตัวได้ตามเดิม
 *
 * @param string $fixture
 * @param Db     $db
 * @param string $prefix
 * @param string $config ไฟล์ค่ากำหนดของฐานนี้ (บางชุดต้องแก้เลขรุ่น)
 *
 * @return array ข้อความบรรยายสิ่งที่ทำ
 */
function fixtureMutate($fixture, $db, $prefix, $config)
{
    $notes = [];
    $user = $prefix.'_user';
    $logs = $prefix.'_logs';

    // ชุด f3 (ฐานรุ่นก่อน) ก็ต้องเรียกโมดูลด้วย เพื่อให้โมดูลจำลอง "ตารางรุ่นเก่า
    // ของตัวเอง" ได้ — เส้นทางปรับรุ่นของโมดูลจะได้ถูกเดินจริง ไม่ใช่ข้ามเพราะ
    // ตัวติดตั้งสร้างตารางรุ่นใหม่ให้ครบไปแล้วตั้งแต่ต้น
    if ($fixture === 'f3') {
        if (is_file(ROOT_PATH.'install/testdb-module.php')) {
            include_once ROOT_PATH.'install/testdb-module.php';
        }
        if (function_exists('testdbSeedModule')) {
            testdbSeedModule($db, $prefix, $notes, $fixture);
        }
    }

    if (in_array($fixture, ['f2', 'f4', 'f4b', 'f5', 'f6'], true)) {
        // ข้อมูลพื้นฐานที่ทุกระบบมี — สมาชิก หมวดหมู่ บันทึกกิจกรรม
        for ($i = 2; $i <= 20; ++$i) {
            $db->insert($user, [
                'username' => 'user'.$i.'@example.com',
                'salt' => 'salt'.$i,
                'password' => sha1('x'.$i),
                'status' => 3,
                'active' => 1,
                'permission' => '',
                'name' => 'ผู้ใช้ทดสอบ '.$i,
                'phone' => '08000000'.sprintf('%02d', $i),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        for ($i = 1; $i <= 50; ++$i) {
            $db->insert($logs, [
                'src_id' => $i,
                'module' => 'testdb',
                'action' => 'create',
                'created_at' => date('Y-m-d H:i:s'),
                'member_id' => 1,
                'topic' => 'ทดสอบรายการที่ '.$i
            ]);
        }
        $notes[] = 'ใส่ข้อมูลตั้งต้น สมาชิก 19 คน บันทึกกิจกรรม 50 รายการ';
        // ข้อมูลของโมดูลในโปรเจ็คนี้ (ถ้ามี install/testdb-module.php)
        if (is_file(ROOT_PATH.'install/testdb-module.php')) {
            include_once ROOT_PATH.'install/testdb-module.php';
        }
        if (function_exists('testdbSeedModule')) {
            testdbSeedModule($db, $prefix, $notes, $fixture);
        }
    }

    if ($fixture === 'f3') {
        // ฐานหน้าตาแบบรุ่นก่อน — ย้อนสิ่งที่ upgrade_core.php ทำไว้ทั้งหมด
        // เพื่อให้เส้นทางปรับรุ่นจริงถูกเดินครบ ไม่ใช่ข้ามเพราะฐานใหม่อยู่แล้ว
        $db->query("ALTER TABLE `$user` CHANGE `created_at` `create_date` DATETIME NULL DEFAULT NULL");
        foreach (['username', 'id_card', 'phone'] as $index) {
            if ($db->indexExists($user, $index)) {
                $db->query("ALTER TABLE `$user` DROP INDEX `$index`");
            }
        }
        $db->query("ALTER TABLE `$user` ADD `token` VARCHAR(40) NULL DEFAULT NULL");
        $db->query("ALTER TABLE `$user` CHANGE `social` `social` TINYINT(1) NOT NULL DEFAULT 0");
        $db->query("ALTER TABLE `$user` ENGINE = MyISAM");
        $db->query("ALTER TABLE `$user` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        $category = $prefix.'_category';
        if ($db->fieldExists($category, 'is_active')) {
            $db->query("ALTER TABLE `$category` CHANGE `is_active` `published` TINYINT(1) NULL DEFAULT 1");
        }
        $db->query("ALTER TABLE `$category` ENGINE = MyISAM");
        foreach (['user_session', 'timeline_idempotency', 'migration'] as $table) {
            $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
        }
        agedConfig($config);
        $notes[] = 'ทำให้เป็นรุ่นก่อน: MyISAM + utf8mb3, create_date, social เป็นตัวเลข, ไม่มี user_session/timeline_idempotency/migration';
    }

    if ($fixture === 'f4') {
        // ไซต์ที่มีคนไปแก้ฐานเอง — ต้องยังปรับรุ่นผ่าน
        $db->query("ALTER TABLE `$user` ADD `custom_note` VARCHAR(100) NULL DEFAULT NULL");
        if ($db->indexExists($user, 'idx_status')) {
            $db->query("ALTER TABLE `$user` DROP INDEX `idx_status`");
        }
        $db->query("ALTER TABLE `$logs` CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci");
        // ค่าว่างที่ควรเป็น NULL ในคอลัมน์ที่กำลังจะเป็น UNIQUE
        $db->query("ALTER TABLE `$user` DROP INDEX `id_card`");
        $db->query("UPDATE `$user` SET `id_card` = '' WHERE `id` > 1");
        $notes[] = 'ทำให้เพี้ยน: เพิ่มคอลัมน์แปลกปลอม ลบดัชนี เปลี่ยน collation ใส่ค่าว่างแทน NULL';
    }

    if ($fixture === 'f4b') {
        // เบอร์โทรซ้ำ — ต้องถูกปฏิเสธก่อนแตะฐาน (เคสจริงที่เจอบน production ของ oms)
        $db->query("ALTER TABLE `$user` DROP INDEX `phone`");
        $db->query("UPDATE `$user` SET `phone` = '0868142004' WHERE `id` IN (2, 3)");
        $notes[] = 'ใส่เบอร์โทรซ้ำสองแถว (คอลัมน์ที่กำลังจะเป็น UNIQUE)';
    }

    if ($fixture === 'f5') {
        // ข้อมูลเยอะ — วัดว่า ALTER ไม่หมดเวลา
        $db->query(
            "INSERT INTO `$logs` (`src_id`, `module`, `action`, `created_at`, `member_id`, `topic`)
             SELECT a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000, 'testdb', 'create', NOW(), 1,
                    CONCAT('รายการ ', a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000)
             FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
             CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
             CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
             CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d
             CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) e"
        );
        $row = $db->customQuery("SELECT COUNT(*) AS c FROM `$logs`");
        $notes[] = 'ใส่บันทึกกิจกรรม '.number_format((int) $row[0]->c).' แถว';
    }

    if ($fixture === 'f6') {
        // ปรับรุ่นค้างครึ่งทาง — ตารางใหม่มาแล้วบางตัว ดัชนีบางตัวยังไม่มา
        // เหมือนกรณีที่ผู้ใช้ปิดหน้าต่างกลางคัน หรือ PHP หมดเวลาระหว่าง ALTER
        $db->query("DROP TABLE IF EXISTS `{$prefix}_timeline_idempotency`");
        if ($db->indexExists($user, 'phone')) {
            $db->query("ALTER TABLE `$user` DROP INDEX `phone`");
        }
        if ($db->indexExists($user, 'idx_status')) {
            $db->query("ALTER TABLE `$user` DROP INDEX `idx_status`");
        }
        $db->query("ALTER TABLE `$user` CHANGE `created_at` `create_date` DATETIME NULL DEFAULT NULL");
        agedConfig($config);
        $notes[] = 'ทำให้ค้างครึ่งทาง: ตาราง timeline_idempotency หาย ดัชนีหาย 2 ตัว คอลัมน์ยังเป็นชื่อเก่า';
    }

    if ($fixture === 'f7') {
        $notes[] = 'ใช้ prefix rp_ แทน app_';
        // ตารางของ prefix อื่นในฐานเดียวกัน ต้องไม่ถูกแตะเลย
        $db->query("CREATE TABLE IF NOT EXISTS `other_user` (`id` int(11) NOT NULL, `keepme` varchar(10) NULL) ENGINE=MyISAM DEFAULT CHARSET=utf8");
        $db->query("INSERT INTO `other_user` (`id`, `keepme`) VALUES (1, 'ห้ามแตะ')");
        $notes[] = 'สร้างตาราง other_user ของ prefix อื่นไว้ในฐานเดียวกัน';
    }

    return $notes;
}

/**
 * ทำให้ไฟล์ค่ากำหนดของฐานทดสอบดูเหมือนของรุ่นก่อน
 * (เลขรุ่นเก่ากว่าชุดติดตั้ง เพื่อให้ตัวปรับรุ่นเห็นว่าเป็นการข้ามรุ่นจริง)
 *
 * @param string $config
 */
function agedConfig($config)
{
    $cfg = include $config;
    $cfg['version'] = '6.0.0';
    save($cfg, $config);
}
