<?php
/**
 * install/cli-verify.php — ตรวจว่าฐานข้อมูลผ่านเกณฑ์ของการปรับรุ่นหรือยัง
 *
 * เกณฑ์ที่ตรวจให้ (ตามข้อกำหนดของกระบวนการรักษาข้อมูล)
 *   1. จำนวนแถวจริงทุกตารางต้องไม่ลดลง   → --save-counts= ก่อนปรับรุ่น แล้ว --counts= หลังปรับรุ่น
 *   2. ฐานที่ปรับรุ่นมา ต้องได้สคีมาเท่ากับฐานที่ติดตั้งใหม่ → --fresh=
 *   3. preflight ของตัวปรับรุ่นต้องไม่มีข้อทักท้วง          → ทำให้เสมอ
 *
 * ใช้:  php install/cli-verify.php <dbname> [prefix] [ตัวเลือก]
 *
 *   --config=<file>       ไฟล์ค่ากำหนดของฐานนี้ (ค่าเริ่มต้น settings/config.php)
 *   --save-counts=<file>  บันทึกจำนวนแถวจริงทุกตารางลงไฟล์ แล้วจบ
 *   --counts=<file>       เทียบจำนวนแถวกับไฟล์ที่บันทึกไว้ — ลดลงหรือตารางหาย = ไม่ผ่าน
 *   --fresh=<dbname>      เทียบสคีมากับฐานที่ติดตั้งใหม่ (สร้างด้วย cli-fresh.php)
 *   --fresh-prefix=<p>    prefix ของฐานที่ติดตั้งใหม่ (ค่าเริ่มต้น = prefix ของฐานที่ตรวจ)
 *   --skip-preflight      ข้ามการตรวจ preflight (ใช้ตอนตั้งใจทดสอบฐานที่ยังไม่พร้อม)
 *   --allow-extra         ตาราง/คอลัมน์ที่ "เกินมา" จากฐานติดตั้งใหม่ ไม่ถือว่าไม่ผ่าน
 *                         (ไซต์ที่มีคนเพิ่มคอลัมน์เอง — ยังต้องอัปเกรดได้ แต่ยังรายงานให้เห็น)
 *   --moved=<เก่า:ใหม่,...> ตารางที่ตัวปรับรุ่น "ย้าย" แถวไปไว้ที่อื่นโดยตั้งใจ
 *                         เช่น --moved=app_ierecord:app_omsin_ierecord
 *                         ⚠️ ไม่ใช่การยกเว้น — ยังตรวจอยู่ แต่เปลี่ยนคำถามจาก
 *                         "ตารางเดิมยังมีแถวครบไหม" เป็น "แถวไปโผล่ที่ปลายทางครบไหม"
 *                         ถ้าปลายทางไม่มีหรือรับไม่ครบ ยังถือว่าไม่ผ่านเหมือนเดิม
 *
 * exit code : 0 = ผ่านทุกข้อ, 1 = มีข้อที่ไม่ผ่าน
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$params = [];
$options = [
    'config' => '',
    'save-counts' => '',
    'counts' => '',
    'fresh' => '',
    'fresh-prefix' => '',
    'skip-preflight' => false,
    'allow-extra' => false,
    'moved' => ''
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $match)) {
        if (!array_key_exists($match[1], $options)) {
            fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
            exit(1);
        }
        $options[$match[1]] = isset($match[2]) ? $match[2] : true;
    } else {
        $params[] = $arg;
    }
}
$dbname = isset($params[0]) ? $params[0] : '';
$prefix = isset($params[1]) ? $params[1] : 'app';
if ($dbname === '') {
    fwrite(STDERR, "ใช้: php install/cli-verify.php <dbname> [prefix] [--config= --save-counts= --counts= --fresh=]\n");
    exit(1);
}

define('ROOT_PATH', str_replace(['\\', 'install/cli-verify.php'], ['/', ''], __FILE__));
include_once ROOT_PATH.'install/common.php';
include_once ROOT_PATH.'install/db.php';
include_once ROOT_PATH.'install/preflight.php';

$db_config = include ROOT_PATH.'settings/database.php';
$db_config = $db_config['mysql'];
$db_config['dbname'] = $dbname;
$db_config['prefix'] = $prefix;
$db = new Db($db_config);

// ตารางที่ถูกย้ายโดยตั้งใจ  ['ตารางเดิม' => ['ตารางปลายทาง', ...]]
$moved_to = [];
if ($options['moved'] !== '' && $options['moved'] !== true) {
    foreach (explode(',', $options['moved']) as $_pair) {
        $_parts = explode(':', trim($_pair));
        if (count($_parts) !== 2 || $_parts[0] === '' || $_parts[1] === '') {
            fwrite(STDERR, "รูปแบบ --moved ต้องเป็น เก่า:ใหม่ คั่นด้วยจุลภาค — ได้รับ '$_pair'\n");
            exit(1);
        }
        $moved_to[$_parts[0]][] = $_parts[1];
    }
}

$failed = [];
$tables = prefixTables($db, $prefix);
if (empty($tables)) {
    fwrite(STDERR, "ไม่พบตารางที่ขึ้นต้นด้วย {$prefix}_ ในฐาน $dbname\n");
    exit(1);
}
$counts = countRows($db, $tables);

// -----------------------------------------------------------------------------
// บันทึกจำนวนแถวไว้เทียบทีหลัง แล้วจบ
// -----------------------------------------------------------------------------
if ($options['save-counts'] !== '' && $options['save-counts'] !== true) {
    file_put_contents($options['save-counts'], json_encode($counts, JSON_PRETTY_PRINT));
    echo 'บันทึกจำนวนแถวของ '.count($counts).' ตาราง ลง '.$options['save-counts']."\n";
    exit(0);
}

echo "ตรวจฐาน `$dbname` (prefix $prefix)\n";

// -----------------------------------------------------------------------------
// เกณฑ์ 1 — จำนวนแถวจริงต้องไม่ลดลง
// -----------------------------------------------------------------------------
if ($options['counts'] !== '' && $options['counts'] !== true) {
    $before = json_decode((string) file_get_contents($options['counts']), true);
    if (!is_array($before)) {
        fwrite(STDERR, 'อ่านไฟล์จำนวนแถว '.$options['counts']." ไม่ได้\n");
        exit(1);
    }
    $problems = [];
    $moved_notes = [];
    foreach ($before as $table => $count) {
        $now = isset($counts[$table]) ? $counts[$table] : null;
        $short = $count - ($now === null ? 0 : $now);
        if ($short <= 0) {
            continue;
        }
        // แถวที่หายไปจากตารางเดิม ต้องไปโผล่ที่ปลายทางที่ประกาศไว้ให้ครบ
        // ประกาศอย่างเดียวไม่พอ ต้องเห็นของอยู่ปลายทางจริง ไม่งั้นก็แค่ปิดปากสัญญาณเตือน
        $landed = 0;
        $dest = [];
        foreach (isset($moved_to[$table]) ? $moved_to[$table] : [] as $_to) {
            if (isset($counts[$_to])) {
                $landed += $counts[$_to] - (isset($before[$_to]) ? $before[$_to] : 0);
                $dest[] = $_to;
            }
        }
        if ($landed >= $short && !empty($dest)) {
            $moved_notes[] = "       $table ย้าย $short แถว ไป ".implode(', ', $dest)." ครบแล้ว";
            continue;
        }
        if ($now === null) {
            $problems[] = "  $table หายไปทั้งตาราง (เคยมี $count แถว)";
        } else {
            $problems[] = "  $table ลดลงจาก $count เหลือ ".$now.' แถว';
        }
        if (!empty($dest)) {
            $problems[] = "    ประกาศว่าย้ายไป ".implode(', ', $dest)." แต่ปลายทางรับไปแค่ $landed จาก $short แถว";
        }
    }
    if (empty($problems)) {
        echo '[ok]   จำนวนแถวไม่ลดลงเลยทั้ง '.count($before)." ตาราง\n";
        echo empty($moved_notes) ? '' : implode("\n", $moved_notes)."\n";
    } else {
        $failed[] = 'จำนวนแถวลดลง';
        echo "[FAIL] จำนวนข้อมูลลดลง\n".implode("\n", $problems)."\n";
    }
    foreach ($counts as $table => $count) {
        if (!isset($before[$table])) {
            echo "       + $table เป็นตารางใหม่ ($count แถว)\n";
        }
    }
}

// -----------------------------------------------------------------------------
// เกณฑ์ 2 — ปรับรุ่นแล้วต้องได้สคีมาเท่ากับติดตั้งใหม่
// -----------------------------------------------------------------------------
if ($options['fresh'] !== '' && $options['fresh'] !== true) {
    $fresh_db = $options['fresh'];
    $fresh_prefix = ($options['fresh-prefix'] === '' || $options['fresh-prefix'] === true) ? $prefix : $options['fresh-prefix'];
    list($diff, $extra, $order) = schemaDiff($db, $dbname, $prefix, $fresh_db, $fresh_prefix);
    if ($options['allow-extra'] !== true) {
        // ค่าเริ่มต้นเข้มงวด: ฐานที่ปรับรุ่นมาต้องเหมือนฐานที่ติดตั้งใหม่เป๊ะ
        $diff = array_merge($diff, $extra);
        $extra = [];
    }
    if (empty($diff)) {
        echo "[ok]   สคีมาตรงกับฐานที่ติดตั้งใหม่ (`$fresh_db`)"
            .(empty($extra) ? " ทุกตาราง\n" : ' (ยกเว้นของที่ไซต์เพิ่มเองอีก '.count($extra)." รายการ)\n");
    } else {
        $failed[] = 'สคีมาไม่ตรงกับการติดตั้งใหม่';
        echo '[FAIL] สคีมาต่างจากฐานที่ติดตั้งใหม่ '.count($diff)." รายการ\n";
        foreach ($diff as $line) {
            echo "  $line\n";
        }
    }
    foreach ($extra as $line) {
        echo "[warn] $line\n";
    }
    if (!empty($order)) {
        echo '[warn] ลำดับคอลัมน์ต่างจากฐานที่ติดตั้งใหม่ '.count($order)." คอลัมน์ (ไม่กระทบการทำงาน)\n";
    }
}

// -----------------------------------------------------------------------------
// เกณฑ์ 3 — preflight ของตัวปรับรุ่นต้องไม่มีข้อทักท้วง
// -----------------------------------------------------------------------------
if ($options['skip-preflight'] !== true) {
    $config_file = ($options['config'] === '' || $options['config'] === true) ? ROOT_PATH.'settings/config.php' : $options['config'];
    if (!is_file($config_file)) {
        fwrite(STDERR, "ไม่พบไฟล์ค่ากำหนด $config_file\n");
        exit(1);
    }
    $config = include $config_file;
    $new_config = include ROOT_PATH.'install/settings/config.php';
    $result = preflight($db, $db_config, $config, $new_config, $config_file);
    if (empty($result['errors'])) {
        echo "[ok]   preflight ผ่าน\n";
    } else {
        $failed[] = 'preflight ไม่ผ่าน';
        echo '[FAIL] preflight ทักท้วง '.count($result['errors'])." ข้อ\n";
        foreach ($result['errors'] as $message) {
            echo '  '.upgradeReportText($message)."\n";
        }
    }
    foreach ($result['warnings'] as $message) {
        echo '[warn] '.upgradeReportText($message)."\n";
    }
    foreach ($result['notes'] as $message) {
        echo '       '.upgradeReportText($message)."\n";
    }
}

// -----------------------------------------------------------------------------
// สรุป
// -----------------------------------------------------------------------------
$total = 0;
foreach ($counts as $count) {
    $total += $count;
}
echo 'ตาราง '.count($tables).' ตาราง รวม '.number_format($total)." แถว\n";
if (empty($failed)) {
    echo "ผ่านทุกเกณฑ์\n";
    exit(0);
}
echo 'ไม่ผ่าน: '.implode(', ', $failed)."\n";
exit(1);

/**
 * เทียบโครงสร้างตารางของสองฐาน โดยเทียบ "ชื่อตารางหลัง prefix" เข้าหากัน
 * จึงเทียบข้ามฐานที่ใช้ prefix คนละตัวได้ (ชุดทดสอบ F7)
 *
 * เทียบให้ครบทั้งชนิดคอลัมน์ การยอมรับ NULL ค่า DEFAULT ลำดับคอลัมน์ ดัชนี
 * เอนจิน และ collation เพราะสิ่งที่ทำให้ "ติดตั้งใหม่" ต่างจาก "ปรับรุ่นมา"
 * มักเป็นรายละเอียดพวกนี้ ไม่ใช่ตารางหาย
 *
 * @param Db     $db           ตัวเชื่อมต่อ (ใช้ query information_schema ได้ทั้งสองฐาน)
 * @param string $schema       ฐานที่ตรวจ
 * @param string $prefix       prefix ของฐานที่ตรวจ
 * @param string $fresh_schema ฐานที่ติดตั้งใหม่
 * @param string $fresh_prefix prefix ของฐานที่ติดตั้งใหม่
 *
 * @return array [ของที่ขาด/ไม่ตรง, ของที่ไซต์เพิ่มเข้ามาเอง, ลำดับคอลัมน์ที่ต่างกัน]
 *               แยกกันเพราะสองอย่างนี้ต่างกันโดยสิ้นเชิง — ของที่ขาดคือตัวปรับรุ่น
 *               ทำงานไม่ครบ ส่วนของที่เกินมาคือคนที่ไซต์เพิ่มเอง ซึ่งเราห้ามลบ
 */
function schemaDiff($db, $schema, $prefix, $fresh_schema, $fresh_prefix)
{
    $a = schemaSnapshot($db, $schema, $prefix);
    $b = schemaSnapshot($db, $fresh_schema, $fresh_prefix);
    $diff = [];
    $extra = [];
    // ลำดับคอลัมน์ไม่ใช่ความหมาย — ไม่มีโค้ดไหนในระบบพึ่งลำดับ (Db::insert ระบุชื่อ
    // คอลัมน์เสมอ) และการบังคับให้เรียงเหมือนติดตั้งใหม่คือการ rebuild ทั้งตาราง
    // ซึ่งบนตารางเอกสารของไซต์จริงแพงมากโดยไม่ได้อะไรกลับมา จึงรายงานเป็นข้อสังเกต
    $order = [];
    foreach ($b as $table => $fresh) {
        if (!isset($a[$table])) {
            $diff[] = "ไม่มีตาราง {$prefix}_$table (ฐานที่ติดตั้งใหม่มี)";
            continue;
        }
        $mine = $a[$table];
        if ($mine['engine'] !== $fresh['engine']) {
            $diff[] = "{$prefix}_$table เอนจิน ".$mine['engine'].' ควรเป็น '.$fresh['engine'];
        }
        if ($mine['collation'] !== $fresh['collation']) {
            $diff[] = "{$prefix}_$table collation ".$mine['collation'].' ควรเป็น '.$fresh['collation'];
        }
        foreach ($fresh['columns'] as $column => $definition) {
            if (!isset($mine['columns'][$column])) {
                $diff[] = "{$prefix}_$table ไม่มีคอลัมน์ $column";
            } elseif ($mine['columns'][$column] !== $definition) {
                $diff[] = "{$prefix}_$table คอลัมน์ $column = ".$mine['columns'][$column].' ควรเป็น '.$definition;
            } elseif (isset($mine['positions'][$column], $fresh['positions'][$column])
                && $mine['positions'][$column] !== $fresh['positions'][$column]) {
                $order[] = "{$prefix}_$table คอลัมน์ $column อยู่ลำดับที่ ".$mine['positions'][$column]
                    .' ส่วนฐานที่ติดตั้งใหม่อยู่ลำดับที่ '.$fresh['positions'][$column];
            }
        }
        foreach ($mine['columns'] as $column => $definition) {
            if (!isset($fresh['columns'][$column])) {
                // คอลัมน์แปลกปลอมไม่ใช่ความผิดของตัวปรับรุ่น ไซต์ที่มีคนแก้ DB เอง
                // ก็ยังต้องอัปเกรดได้ และเราห้ามลบของเขา — แต่ต้องรายงานให้เห็น
                $extra[] = "{$prefix}_$table มีคอลัมน์เกินมา $column (ฐานที่ติดตั้งใหม่ไม่มี)";
            }
        }
        foreach ($fresh['indexes'] as $index => $definition) {
            if (!isset($mine['indexes'][$index])) {
                $diff[] = "{$prefix}_$table ไม่มีดัชนี $index ($definition)";
            } elseif ($mine['indexes'][$index] !== $definition) {
                $diff[] = "{$prefix}_$table ดัชนี $index = ".$mine['indexes'][$index].' ควรเป็น '.$definition;
            }
        }
        foreach ($mine['indexes'] as $index => $definition) {
            if (!isset($fresh['indexes'][$index])) {
                $extra[] = "{$prefix}_$table มีดัชนีเกินมา $index ($definition)";
            }
        }
    }
    foreach ($a as $table => $mine) {
        if (!isset($b[$table])) {
            $extra[] = "{$prefix}_$table มีเกินมา (ฐานที่ติดตั้งใหม่ไม่มี)";
        }
    }

    return [$diff, $extra, $order];
}

/**
 * อ่านโครงสร้างของทุกตารางใน prefix หนึ่ง ออกมาเป็นแอเรย์ที่เทียบกันตรง ๆ ได้
 *
 * @param Db     $db
 * @param string $schema
 * @param string $prefix
 *
 * @return array [ชื่อตารางหลัง prefix => ['engine'=>, 'collation'=>, 'columns'=>[], 'indexes'=>[]]]
 */
function schemaSnapshot($db, $schema, $prefix)
{
    $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'\\_%';
    $cut = strlen($prefix) + 1;
    $snapshot = [];
    $rows = $db->customQuery(
        "SELECT `TABLE_NAME` AS `t`, `ENGINE` AS `e`, `TABLE_COLLATION` AS `c`
         FROM `information_schema`.`TABLES`
         WHERE `TABLE_SCHEMA` = '$schema' AND `TABLE_TYPE` = 'BASE TABLE' AND `TABLE_NAME` LIKE '$like'"
    );
    foreach ((array) $rows as $row) {
        $snapshot[substr($row->t, $cut)] = [
            'engine' => (string) $row->e,
            'collation' => (string) $row->c,
            'columns' => [],
            'positions' => [],
            'indexes' => []
        ];
    }
    $rows = $db->customQuery(
        "SELECT `TABLE_NAME` AS `t`, `COLUMN_NAME` AS `c`, `COLUMN_TYPE` AS `ct`, `IS_NULLABLE` AS `n`,
                `COLUMN_DEFAULT` AS `d`, `EXTRA` AS `x`, `ORDINAL_POSITION` AS `p`, `COLLATION_NAME` AS `cl`
         FROM `information_schema`.`COLUMNS`
         WHERE `TABLE_SCHEMA` = '$schema' AND `TABLE_NAME` LIKE '$like'
         ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`"
    );
    foreach ((array) $rows as $row) {
        $table = substr($row->t, $cut);
        if (!isset($snapshot[$table])) {
            continue;
        }
        $snapshot[$table]['columns'][$row->c] = $row->ct
            .' '.($row->n === 'YES' ? 'NULL' : 'NOT NULL')
            .' DEFAULT '.($row->d === null ? 'NULL' : $row->d)
            .($row->x === '' ? '' : ' '.$row->x)
            .($row->cl === null ? '' : ' '.$row->cl);
        $snapshot[$table]['positions'][$row->c] = (int) $row->p;
    }
    $rows = $db->customQuery(
        "SELECT `TABLE_NAME` AS `t`, `INDEX_NAME` AS `i`, `NON_UNIQUE` AS `nu`,
                `SEQ_IN_INDEX` AS `s`, `COLUMN_NAME` AS `c`, `SUB_PART` AS `sp`
         FROM `information_schema`.`STATISTICS`
         WHERE `TABLE_SCHEMA` = '$schema' AND `TABLE_NAME` LIKE '$like'
         ORDER BY `TABLE_NAME`, `INDEX_NAME`, `SEQ_IN_INDEX`"
    );
    foreach ((array) $rows as $row) {
        $table = substr($row->t, $cut);
        if (!isset($snapshot[$table])) {
            continue;
        }
        $key = $row->i;
        $part = $row->c.($row->sp === null ? '' : '('.$row->sp.')');
        if (isset($snapshot[$table]['indexes'][$key])) {
            $snapshot[$table]['indexes'][$key] .= ', '.$part;
        } else {
            $snapshot[$table]['indexes'][$key] = ($row->nu ? 'INDEX' : 'UNIQUE').' '.$part;
        }
    }

    return $snapshot;
}
