<?php
/**
 * install/cli-upgrade.php — รัน install/upgrade2.php ตัวจริงจากบรรทัดคำสั่ง
 *
 * ตัวปรับรุ่นเป็นหน้าเว็บที่รับ POST username/password ไฟล์นี้จำลองสภาพนั้นให้
 * เพื่อให้ทดสอบซ้ำ ๆ ได้โดยไม่ต้องเปิดเบราว์เซอร์ และเพื่อให้ชุดทดสอบเรียก
 * "ตัวติดตั้งของจริง" ได้ ไม่ใช่สำเนาที่คัดลอกมาแล้วเพี้ยนจากกัน
 *
 * ใช้:  php install/cli-upgrade.php <username> <password> --confirm-backup [ตัวเลือก]
 *
 *   --confirm-backup   บังคับ — ยืนยันว่าสำรองฐานข้อมูลไว้แล้ว
 *   --db=<dbname>      ปรับรุ่นฐานอื่นแทนฐานใน settings/database.php (ชุดทดสอบ F1-F7)
 *   --prefix=<prefix>  คำนำหน้าตารางของฐานนั้น (ใช้คู่กับ --db)
 *   --config=<file>    ไฟล์ค่ากำหนดของฐานนั้น (จาก cli-fresh.php --config=)
 *
 * ต้องมี --confirm-backup เสมอ เพราะฝั่งหน้าเว็บก็บังคับติ๊กช่องยืนยันเหมือนกัน
 * ถ้าฝั่ง CLI ยืนยันให้เองเงียบ ๆ ชุดทดสอบจะไม่ได้ทดสอบเส้นทางเดียวกับผู้ใช้จริง
 *
 * ต้องรันจาก root ของโปรเจ็คเสมอ (ROOT_PATH คำนวณจากตำแหน่งไฟล์นี้)
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$confirmed = false;
$params = [];
$options = ['db' => '', 'prefix' => '', 'config' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--confirm-backup') {
        $confirmed = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $match) && array_key_exists($match[1], $options)) {
        $options[$match[1]] = $match[2];
    } elseif (substr($arg, 0, 2) === '--') {
        fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
        exit(1);
    } else {
        $params[] = $arg;
    }
}
$username = isset($params[0]) ? $params[0] : '';
$password = isset($params[1]) ? $params[1] : '';
if ($username === '' || $password === '') {
    fwrite(STDERR, "ใช้: php install/cli-upgrade.php <username> <password> --confirm-backup\n");
    exit(1);
}
if (!$confirmed) {
    fwrite(STDERR, "ต้องระบุ --confirm-backup เพื่อยืนยันว่าสำรองฐานข้อมูลไว้แล้ว (ตัวปรับรุ่นแก้ไขฐานข้อมูลจริง)\n");
    exit(1);
}

// ตัวปรับรุ่นเรียก session_start() ทางอ้อมผ่าน common.php ไม่ได้ใน CLI
// จึงเปิดเองก่อนด้วย save handler แบบไฟล์ในโฟลเดอร์ชั่วคราว
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    @session_start();
}

define('ROOT_PATH', str_replace(['\\', 'install/cli-upgrade.php'], ['/', ''], __FILE__));

include_once ROOT_PATH.'install/common.php';

$new_config = include ROOT_PATH.'install/settings/config.php';

// ฐานและไฟล์ค่ากำหนดที่จะปรับรุ่น — ปกติคือของโปรเจ็คนี้เอง
// ชุดทดสอบ F1-F7 ชี้ไปฐานอื่นได้ด้วย --db/--prefix/--config เพื่อรันตัวปรับรุ่น
// ตัวจริงลงหลายฐานโดยไม่ต้องแตะไฟล์ตั้งค่าของโปรเจ็ค (upgrade2.php อ่านตัวแปร
// $db_config_override / $config_file_override ที่ตั้งไว้ตรงนี้)
$config_file_override = $options['config'] === '' ? ROOT_PATH.'settings/config.php' : $options['config'];
if (!is_file($config_file_override)) {
    fwrite(STDERR, "ไม่พบไฟล์ค่ากำหนด $config_file_override\n");
    exit(1);
}
$config = include $config_file_override;
if ($options['db'] !== '' || $options['prefix'] !== '') {
    $db_config_override = include ROOT_PATH.'settings/database.php';
    if ($options['db'] !== '') {
        $db_config_override['mysql']['dbname'] = $options['db'];
    }
    if ($options['prefix'] !== '') {
        $db_config_override['mysql']['prefix'] = $options['prefix'];
    }
}

// =============================================================================
// ตาข่ายกันตัวปรับรุ่นวิ่งใส่ "ฐานผลิตจริง" ตอนที่เราสั่งให้ทดสอบ
//
// ⚠️ เคยเกิดจริง : เราสั่ง `php install/cli-testdb.php` กับโปรเจ็ค loan ซึ่ง
// upgrade2.php ของมันเขียนไว้ว่า
//     $db_config = include ROOT_PATH.'settings/database.php';
// โดยไม่อ่าน $db_config_override เลย ผลคือ --db=loantest_f1 ถูกเมิน แล้ว
// ตัวปรับรุ่นตัวจริงไปเชื่อมฐาน office_goragod ของไซต์ที่ใช้งานอยู่แทน
// รอบนั้นรอดเพราะมันหยุดที่ updateAdmin ก่อนถึงบล็อกที่แก้สคีมา — ครั้งหน้าอาจไม่รอด
//
// ตรวจแบบ "อ่านไฟล์" ก่อนเชื่อมต่ออะไรทั้งสิ้น ล้มตั้งแต่ยังไม่แตะฐานข้อมูล
// ดีกว่าตรวจหลังรันซึ่งสายไปแล้ว
if (isset($db_config_override)) {
    $_upgrade2 = ROOT_PATH.'install/upgrade2.php';
    if (strpos((string) file_get_contents($_upgrade2), 'db_config_override') === false) {
        $_real = include ROOT_PATH.'settings/database.php';
        fwrite(STDERR,
            "install/upgrade2.php ของโปรเจ็คนี้ไม่ได้อ่าน \$db_config_override\n"
            ."จึงจะเมิน --db=".$options['db']." แล้วไปปรับรุ่นฐาน `".$_real['mysql']['dbname']."` ซึ่งเป็นฐานของไซต์จริง\n"
            ."\nหยุดไว้ก่อน ยังไม่ได้เชื่อมต่อฐานข้อมูลใด ๆ\n"
            ."\nวิธีแก้ : ใน install/upgrade2.php เปลี่ยนบรรทัดที่อ่านค่าฐานข้อมูลเป็น\n"
            ."    \$db_config = isset(\$db_config_override) ? \$db_config_override : include ROOT_PATH.'settings/database.php';\n"
            ."    \$config_file = isset(\$config_file_override) ? \$config_file_override : ROOT_PATH.'settings/config.php';\n"
        );
        exit(1);
    }
}

$_POST['username'] = $username;
$_POST['password'] = $password;
$_POST['confirm_backup'] = 1;

ob_start();
include ROOT_PATH.'install/upgrade2.php';
$html = ob_get_clean();

// แปลง HTML ของตัวปรับรุ่นเป็นบรรทัดอ่านง่าย คงคำว่า correct/incorrect ไว้
// ใช้ตัวแปลงตัวเดียวกับที่เขียนไฟล์ log เพื่อให้สิ่งที่เห็นกับสิ่งที่บันทึกตรงกัน
include_once ROOT_PATH.'install/preflight.php';
$text = upgradeReportText($html);

echo $text, "\n";

// exit code สำหรับสคริปต์ทดสอบ
exit(stripos($text, 'ปรับรุ่นไม่สำเร็จ') !== false
    || stripos($text, 'ยังปรับรุ่นไม่ได้') !== false
    || stripos($text, '[FAIL]') !== false ? 1 : 0);
