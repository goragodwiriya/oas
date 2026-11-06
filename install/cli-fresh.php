<?php
/**
 * install/cli-fresh.php — ติดตั้งใหม่ลงฐานข้อมูลเปล่าจากบรรทัดคำสั่ง
 *
 * มีไว้เทียบกับผลของ install/cli-upgrade.php: ฐานที่ "ปรับรุ่นมา" กับฐานที่
 * "ติดตั้งใหม่" ต้องได้สคีมาเหมือนกันทุกตัวอักษร ถ้าต่างกันแปลว่า database.sql
 * กับ upgrade2.php เริ่มเพี้ยนจากกันแล้ว
 *
 * ทำเหมือน install/step4.php ทุกขั้นตอน (สคีมา → ผู้ดูแลสูงสุด → นำเข้าภาษา)
 * ฐานทดสอบจึงเป็นผลของ "ตัวติดตั้งของจริง" ไม่ใช่สำเนา SQL ที่คัดลอกมาแล้วเพี้ยน
 *
 * ใช้:  php install/cli-fresh.php <dbname> [prefix] [ตัวเลือก]
 *
 *   --admin=<username>   ผู้ดูแลสูงสุด (ค่าเริ่มต้น admin@localhost)
 *   --password=<pass>    รหัสผ่านของผู้ดูแลสูงสุด (ค่าเริ่มต้น admin)
 *   --config=<file>      เขียนไฟล์ค่ากำหนดของฐานนี้ไว้ (ต้องใช้คู่กับ cli-upgrade --config=)
 *   --no-admin           สร้างแต่ตาราง ไม่สร้างผู้ดูแลและไม่นำเข้าภาษา (ไว้เทียบสคีมาล้วน ๆ)
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$params = [];
$options = ['admin' => 'admin@localhost', 'password' => 'admin', 'config' => '', 'no-admin' => false];
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
    fwrite(STDERR, "ใช้: php install/cli-fresh.php <dbname> [prefix] [--admin= --password= --config= --no-admin]\n");
    exit(1);
}

define('ROOT_PATH', str_replace(['\\', 'install/cli-fresh.php'], ['/', ''], __FILE__));
include_once ROOT_PATH.'install/common.php';
include_once ROOT_PATH.'install/db.php';

$db_config = include ROOT_PATH.'settings/database.php';
$db_config = $db_config['mysql'];

$dsn = 'mysql:host='.$db_config['hostname'].';port='.($db_config['port'] ?? 3306).';charset=utf8mb4';
$pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$pdo->exec("DROP DATABASE IF EXISTS `$dbname`");
$pdo->exec("CREATE DATABASE `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbname`");

$count = 0;
foreach (schemaCommands($prefix) as $command) {
    $pdo->exec($command);
    $count++;
}

echo "ติดตั้งใหม่ลง `$dbname` (prefix $prefix) สำเร็จ — รัน $count คำสั่ง\n";
foreach (schemaFiles() as $file) {
    echo '  จาก '.basename($file)."\n";
}

if ($options['no-admin'] === true) {
    exit(0);
}

// ต่อจากนี้ทำเหมือน step4.php — ผู้ดูแลสูงสุด, ค่ากำหนด, นำเข้าภาษา
$db_config['dbname'] = $dbname;
$db_config['prefix'] = $prefix;
$db = new Db($db_config);

$password_key = uniqid();
createAdmin($db, $prefix.'_user', $options['admin'], $options['password'], $password_key);
echo '  ผู้ดูแลสูงสุด '.$options['admin'].' / '.$options['password']."\n";

// นำเข้าภาษา (language.php ต้องการ $db, $db_config['prefix'] และ $content)
$content = [];
include ROOT_PATH.'install/language.php';

// บันทึกรุ่นลงฐานข้อมูลของไซต์เอง เหมือนที่ตัวปรับรุ่นทำ
include_once ROOT_PATH.'install/preflight.php';
$new_config = include ROOT_PATH.'install/settings/config.php';
stampMigration($db, $prefix, 'core', $new_config['version'], 'cli-fresh.php');

if ($options['config'] !== '' && $options['config'] !== true) {
    // ไฟล์ค่ากำหนดของฐานนี้ ใช้คู่กับ cli-upgrade.php --config= เพื่อให้ทดสอบ
    // ปรับรุ่นหลายฐานได้โดยไม่ต้องแตะ settings/config.php ของโปรเจ็ค
    $cfg = ensureConfigDefaults(include ROOT_PATH.'install/settings/config.php', $new_config);
    $cfg['password_key'] = $password_key;
    if (save($cfg, $options['config'])) {
        echo '  ค่ากำหนด '.$options['config']."\n";
    } else {
        fwrite(STDERR, 'เขียนไฟล์ค่ากำหนด '.$options['config']." ไม่ได้\n");
        exit(1);
    }
}
