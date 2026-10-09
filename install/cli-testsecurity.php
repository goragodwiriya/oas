<?php
/**
 * install/cli-testsecurity.php — ชุดทดสอบความปลอดภัยระดับเฟรมเวิร์ก (แก้เมื่อ 7.0.4)
 *
 * พิสูจน์ว่าปัญหาที่พบจากการตรวจ e-signature (kb #10438) ถูกแก้ที่แกนแล้ว และไม่กลับมาอีก:
 *   U1 รหัสผ่านเก็บเป็น bcrypt (ของ HMAC(password_key)) · sha1 รุ่นเก่ายังล็อกอินได้และถูกเก็บใหม่ทันที
 *   U4 session ที่ API เปิดเองมี HttpOnly/SameSite/use_strict_mode
 *   U5 X-Forwarded-For / Client-IP เชื่อเฉพาะจาก TRUSTED_PROXIES
 *   U6 บัญชีที่ถูกระงับใช้ token เดิมไม่ได้ทันที · การระงับลบ session ทุกเครื่อง
 *   U7 index.php ส่ง security headers (เมื่อระบุ --url=)
 *   (U2/U3 อยู่ฝั่ง Now.js — nowjs/tests/table-cell-data-safety.mjs)
 *
 * ใช้:  php install/cli-testsecurity.php [--url=http://host/path]
 *
 * ต้องมี MySQL ตามค่าใน settings/database.php — สร้างฐาน `<dbname>_sectest` แล้วลบทิ้งเมื่อจบ (แม้ล้มกลางทาง)
 * โหมดหลายไซต์ที่ mysql ไม่มี dbname ใช้ชื่อฐานกลาง (wsr) แทน
 * exit code 0 = ผ่านทั้งหมด
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$options = ['url' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--url=(.+)$/', $arg, $m)) {
        $options['url'] = rtrim($m[1], '/');
    } else {
        fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
        exit(2);
    }
}

$passed = 0;
$failed = 0;
// งานเก็บกวาด (ลบฐานทดสอบ) และด่านสุดท้าย — ทำในฟังก์ชันเดียว เพราะ exit() ในฟังก์ชัน shutdown ตัวหนึ่ง
// ทำให้ตัวที่เหลือไม่ถูกเรียก · ชุดทดสอบที่หยุดกลางทาง (exception ที่ตัวจัดการของ Kotchasan กลืนไป)
// ต้องได้ exit code 1 ไม่ใช่ 0 เหมือนผ่าน
$finished = false;
$cleanup = [];
register_shutdown_function(function () use (&$finished, &$cleanup) {
    foreach ($cleanup as $task) {
        $task();
    }
    if (!$finished) {
        echo "\n  ✗ ชุดทดสอบหยุดกลางทาง — ดูข้อผิดพลาดด้านบน\n";
        exit(1);
    }
});
function check($label, $condition, $detail = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n";
        if ($detail !== '') {
            echo '      '.str_replace("\n", "\n      ", is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))."\n";
        }
    }
}

$appRoot = dirname(__DIR__).'/';
define('ROOT_PATH', $appRoot);

// ---------------------------------------------------------------------------
echo "\n== U1 Kotchasan\\Password: bcrypt + รองรับ sha1 รุ่นเก่า ==\n";
include_once $appRoot.'Kotchasan/Password.php';
$key = 'site-password-key';
$hash = \Kotchasan\Password::hash('Secret-1234', $key);
check('hash() คืน bcrypt ($2y$, 60 ตัว) ไม่ใช่ sha1', strpos($hash, '$2y$12$') === 0 && strlen($hash) === 60, $hash);
check('hash() สองครั้งได้ค่าต่างกัน (salt ใน bcrypt)', \Kotchasan\Password::hash('Secret-1234', $key) !== $hash);
check('verify() รหัสถูก → true · ผิด → false · key อื่น → false', \Kotchasan\Password::verify('Secret-1234', $hash, 'ignored', $key)
    && !\Kotchasan\Password::verify('Secret-1235', $hash, 'ignored', $key) && !\Kotchasan\Password::verify('Secret-1234', $hash, 'ignored', 'other-key'));
$salt = 'abc123';
$legacy = sha1($key.'Secret-1234'.$salt);
$older = sha1('Secret-1234'.$salt);
check('verify() sha1(key.password.salt) รุ่นเก่ายังผ่าน', \Kotchasan\Password::verify('Secret-1234', $legacy, $salt, $key) && !\Kotchasan\Password::verify('wrong', $legacy, $salt, $key));
check('verify() sha1(password.salt) รุ่นเก่ากว่า (ไม่มี key) ยังผ่าน', \Kotchasan\Password::verify('Secret-1234', $older, $salt, $key));
check('needsRehash(): sha1 → true · bcrypt ปัจจุบัน → false', \Kotchasan\Password::needsRehash($legacy) && !\Kotchasan\Password::needsRehash($hash));
check('รหัสผ่านยาว 200 ตัวยังใช้ได้ (HMAC ก่อน bcrypt ตัด 72 ไบต์ไม่ได้)', \Kotchasan\Password::verify(str_repeat('x', 200), \Kotchasan\Password::hash(str_repeat('x', 200), $key), '', $key)
    && !\Kotchasan\Password::verify(str_repeat('x', 199), \Kotchasan\Password::hash(str_repeat('x', 200), $key), '', $key));
check('รหัสผ่านว่าง / hash ว่าง → false', !\Kotchasan\Password::verify('', $hash, '', $key) && !\Kotchasan\Password::verify('Secret-1234', '', '', $key));
$src = file_get_contents($appRoot.'install/common.php').file_get_contents($appRoot.'modules/index/models/auth.php')
    .file_get_contents($appRoot.'modules/index/models/forgot.php').file_get_contents($appRoot.'modules/index/models/register.php')
    .file_get_contents($appRoot.'modules/index/controllers/profile.php');
check('ไม่มี sha1( ที่ใช้เก็บรหัสผ่านเหลือใน installer/auth/forgot/register/profile', preg_match('/sha1\(\$password_key|sha1\(\$passwordKey|sha1\(\$password\b/', $src) === 0);

// ---------------------------------------------------------------------------
echo "\n== U5 client IP: เชื่อ X-Forwarded-For เฉพาะจาก TRUSTED_PROXIES ==\n";
$ipProbe = function ($trusted, array $server) use ($appRoot) {
    $code = '<?php define("TRUSTED_PROXIES", '.var_export($trusted, true).'); '
        .'foreach ('.var_export($server, true).' as $k => $v) { $_SERVER[$k] = $v; } '
        .'define("ROOT_PATH", '.var_export($appRoot, true).'); '
        .'include ROOT_PATH."Kotchasan/load.php"; '
        .'$r = new \Kotchasan\Http\Request(); echo $r->getClientIp(), "|", \Kotchasan\Http\Request::getCurrentClientIp();';
    $tmp = tempnam(sys_get_temp_dir(), 'sec');
    file_put_contents($tmp, $code);
    $out = trim((string) shell_exec('php '.escapeshellarg($tmp).' 2>&1'));
    unlink($tmp);
    return $out;
};
$spoof = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CLIENT_IP' => '5.6.7.8', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9'];
check('ไม่มี proxy: header ปลอมทั้ง XFF/Client-IP/CF ถูกละเลย → REMOTE_ADDR', $ipProbe('', $spoof) === '203.0.113.10|203.0.113.10', $ipProbe('', $spoof));
check('มี proxy แต่คำขอไม่ได้มาจาก proxy → REMOTE_ADDR', $ipProbe('10.0.0.5', $spoof) === '203.0.113.10|203.0.113.10', $ipProbe('10.0.0.5', $spoof));
$viaProxy = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 10.0.0.5'];
check('คำขอผ่าน trusted proxy → hop ขวาสุดที่ไม่ใช่ proxy (198.51.100.7)', $ipProbe('10.0.0.5', $viaProxy) === '198.51.100.7|198.51.100.7', $ipProbe('10.0.0.5', $viaProxy));
check('ผ่าน proxy แต่ header ไม่ใช่ IP → REMOTE_ADDR', $ipProbe('10.0.0.5', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'evil']) === '10.0.0.5|10.0.0.5');
check('load.php ประกาศ TRUSTED_PROXIES (ค่าปริยายว่าง)', preg_match("/define\\('TRUSTED_PROXIES',\\s*''\\)/", (string) file_get_contents($appRoot.'load.php')) === 1);

// ---------------------------------------------------------------------------
echo "\n== U4 session ที่ API เปิดเอง ==\n";
$sessProbe = function ($https) use ($appRoot) {
    $code = '<?php $_SERVER["HTTPS"] = '.var_export($https, true).'; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; '
        .'define("ROOT_PATH", '.var_export($appRoot, true).'); include ROOT_PATH."Kotchasan/load.php"; '
        .'ini_set("session.save_path", sys_get_temp_dir()); '
        .'$ok = \Kotchasan\Http\Request::startSecureSession(); $p = session_get_cookie_params(); '
        .'echo json_encode(["ok" => $ok, "active" => session_status() === PHP_SESSION_ACTIVE, "strict" => ini_get("session.use_strict_mode"), "httponly" => $p["httponly"], "samesite" => $p["samesite"], "secure" => $p["secure"], "twice" => \Kotchasan\Http\Request::startSecureSession()]);';
    $tmp = tempnam(sys_get_temp_dir(), 'sec');
    file_put_contents($tmp, $code);
    $out = json_decode(trim((string) shell_exec('php '.escapeshellarg($tmp).' 2>/dev/null')), true);
    unlink($tmp);
    return $out ?: [];
};
$s = $sessProbe('off');
check('startSecureSession(): เปิด session พร้อม use_strict_mode + HttpOnly + SameSite=Lax', !empty($s['ok']) && !empty($s['active']) && (string) $s['strict'] === '1' && $s['httponly'] === true && $s['samesite'] === 'Lax' && $s['secure'] === false, $s);
check('เรียกซ้ำเมื่อ session เปิดแล้ว → true ไม่ error', !empty($s['twice']));
$s = $sessProbe('on');
check('HTTPS → cookie Secure', !empty($s['ok']) && $s['secure'] === true, $s);
$api = (string) file_get_contents($appRoot.'Kotchasan/ApiController.php');
check('ApiController ไม่เรียก session_start() ตรง ๆ อีก', preg_match('/^\s*session_start\(\);/m', $api) === 0 && substr_count($api, 'Request::startSecureSession()') >= 2);

// ---------------------------------------------------------------------------
echo "\n== U1/U6 กับฐานข้อมูลจริง: login → rehash · token ของบัญชีที่ถูกระงับ ==\n";
$dbcfg = include $appRoot.'settings/database.php';
$mysql = $dbcfg['mysql'];
// โหมดหลายไซต์ (gcms.in.th · oms.in.th) ไม่มี dbname ใน mysql — ฐานของไซต์ถูกเลือกตอนรันตามโฮสต์
// ใช้ชื่อฐานกลาง (wsr) หรือชื่อโฟลเดอร์ของโปรเจ็คแทน ไม่งั้นได้ฐานชื่อ "_sectest" ที่ทุกโปรเจ็คใช้ชนกัน
$baseName = $mysql['dbname'] ?? ($dbcfg['wsr']['dbname'] ?? basename(rtrim($appRoot, '/')));
$dbName = preg_replace('/[^a-z0-9_]/i', '', (string) $baseName).'_sectest';
$prefix = 'st';
$scratch = sys_get_temp_dir().'/sectest-'.getmypid().'/';
$pdo = new PDO('mysql:host='.$mysql['hostname'].';port='.(empty($mysql['port']) ? 3306 : $mysql['port']).';charset=utf8mb4', $mysql['username'], $mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
$pdo->exec('CREATE DATABASE `'.$dbName.'` DEFAULT CHARACTER SET utf8mb4');
// ลบฐานและโฟลเดอร์ชั่วคราวเสมอ แม้ชุดทดสอบล้มกลางทาง (ของเดิมลบตอนจบเท่านั้น ล้มแล้วฐานค้าง)
$cleanup[] = function () use ($pdo, $dbName, $scratch) {
    try {
        $pdo->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    } catch (\Throwable $e) {
        fwrite(STDERR, 'ลบฐาน '.$dbName.' ไม่ได้: '.$e->getMessage()."\n");
    }
    foreach (glob($scratch.'settings/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch.'settings');
    @rmdir($scratch);
};
$pdo->exec('USE `'.$dbName.'`');
// โหมดเดียวกับตัวติดตั้ง (install/db.php) — ตาราง user ของบางโปรเจ็คมีคอลัมน์ NOT NULL ที่ไม่มีค่าปริยาย
// ข้อมูลตั้งต้นของชุดทดสอบใส่เฉพาะคอลัมน์ของแกน
$pdo->exec("SET SESSION sql_mode = ''");
include_once $appRoot.'install/common.php';
// drop = true แบบเดียวกับตัวติดตั้ง (install/step4.php) — โปรเจ็คที่ database.sql ประกาศตารางแกนซ้ำกับ core.sql
// (gcms.in.th · ai) ติดตั้งผ่านเพราะตัวติดตั้งลบแล้วสร้างใหม่ ชุดทดสอบต้องได้ฐานเดียวกัน ไม่ใช่ล้มก่อนเริ่ม
foreach (schemaCommands($prefix, true) as $command) {
    $pdo->exec($command);
}
@mkdir($scratch.'settings', 0777, true);
$database = $dbcfg;
// ชุดนี้ทดสอบแกนของไซต์เดียว — ไม่ส่งการเชื่อมต่อฐานกลาง (wsr) ของโหมดหลายไซต์ต่อไป
// ไม่งั้นโค้ดที่ทดสอบอาจแตะฐานกลางตัวจริง
unset($database['wsr']);
$database['mysql']['dbname'] = $dbName;
$database['mysql']['prefix'] = $prefix;
file_put_contents($scratch.'settings/database.php', "<?php\nreturn ".var_export($database, true).";\n");
$config = include $appRoot.'install/settings/config.php';
$config['jwt_secret'] = str_repeat('s', 64);
$config['password_key'] = $key;
file_put_contents($scratch.'settings/config.php', "<?php\nreturn ".var_export($config, true).";\n");

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api';
$_SERVER['SCRIPT_NAME'] = '/api.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'cli-testsecurity';
define('APP_PATH', $scratch);
// load.php ของโปรเจ็ค define ROOT_PATH ซ้ำ — กรองคำเตือนนั้น (ค่าเดียวกัน)
set_error_handler(function ($errno, $message) {
    return strpos($message, 'already defined') !== false;
}, E_WARNING);
include $appRoot.'load.php';
restore_error_handler();
Kotchasan::createWebApplication('Gcms\Config');

// ผู้ใช้ 2: รหัสผ่าน sha1 รุ่นเก่า · ผู้ใช้ 3: bcrypt
$legacySalt = 'oldsalt';
$pdo->exec("INSERT INTO `{$prefix}_user` (`id`,`username`,`salt`,`password`,`status`,`active`,`permission`,`name`,`created_at`) VALUES
    (2, 'legacy@test.local', '$legacySalt', '".sha1($key.'Legacy-Pass-1'.$legacySalt)."', 0, 1, '', 'legacy', NOW()),
    (3, 'modern@test.local', 'x', '".\Kotchasan\Password::hash('Modern-Pass-1', $key)."', 0, 1, '', 'modern', NOW())");
$login = \Index\Auth\Model::authenticate('legacy@test.local', 'Legacy-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('ล็อกอินด้วยรหัสผ่านที่เก็บแบบ sha1 เดิม → สำเร็จ', !empty($login['success']), $login);
$stored = $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 2")->fetchColumn();
check('หลังล็อกอิน hash ในฐานถูกเปลี่ยนเป็น bcrypt ทันที (โปร่งใสต่อผู้ใช้)', strpos((string) $stored, '$2y$') === 0, $stored);
check('ล็อกอินซ้ำด้วย hash ใหม่ → สำเร็จ · รหัสผิด → ไม่สำเร็จ', !empty(\Index\Auth\Model::authenticate('legacy@test.local', 'Legacy-Pass-1', '127.0.0.1', ['user_agent' => 'x'])['success'])
    && empty(\Index\Auth\Model::authenticate('legacy@test.local', 'Legacy-Pass-2', '127.0.0.2', ['user_agent' => 'x'])['success']));
$login3 = \Index\Auth\Model::authenticate('modern@test.local', 'Modern-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('ล็อกอินบัญชี bcrypt → สำเร็จ และ hash ไม่ถูกเขียนซ้ำ', !empty($login3['success']) && $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn() !== $stored);
$token = $login3['token'] ?? ($login3['access_token'] ?? '');
check('login คืน token', $token !== '', array_keys($login3));
$user = \Index\Auth\Model::getUserByToken($token);
check('getUserByToken: บัญชีปกติ → ได้ผู้ใช้', $user && (int) $user->id === 3);
$pdo->exec("UPDATE `{$prefix}_user` SET `active` = 0 WHERE `id` = 3");
check('ระงับบัญชี (active=0) → token เดิมใช้ไม่ได้ทันที', \Index\Auth\Model::getUserByToken($token) === null);
check('และ session ของบัญชีถูกลบทั้งหมด', (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}_user_session` WHERE `member_id` = 3")->fetchColumn() === 0);
$pdo->exec("UPDATE `{$prefix}_user` SET `active` = 1 WHERE `id` = 3");
check('เปิดใช้กลับ: token เก่ายังใช้ไม่ได้ (session ถูกลบแล้ว) ต้องล็อกอินใหม่', \Index\Auth\Model::getUserByToken($token) === null
    && !empty(\Index\Auth\Model::authenticate('modern@test.local', 'Modern-Pass-1', '127.0.0.1', ['user_agent' => 'x'])['success']));
// ผู้ดูแลสูงสุด id 1 ไม่ถูกล็อกด้วย active (กฎเดียวกับ login)
$pdo->exec("INSERT INTO `{$prefix}_user` (`id`,`username`,`salt`,`password`,`status`,`active`,`permission`,`name`,`created_at`) VALUES
    (1, 'root@test.local', 'x', '".\Kotchasan\Password::hash('Root-Pass-1', $key)."', 1, 0, '', 'root', NOW())");
$login1 = \Index\Auth\Model::authenticate('root@test.local', 'Root-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('id 1 ที่ active=0 ยังล็อกอินและใช้ token ได้ (ไม่ล็อกตัวเองออกจากระบบ)', !empty($login1['success']) && \Index\Auth\Model::getUserByToken($login1['token'] ?? '') !== null);
// installer: createAdmin / updateAdmin
$db = \Kotchasan\DB::create();
$dbObj = null;
include_once $appRoot.'install/db.php';
$dbObj = new Db($database['mysql']);
createAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Admin-Pass-1', $key);
$adminHash = $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 1")->fetchColumn();
check('createAdmin() ของตัวติดตั้งเก็บ bcrypt', strpos((string) $adminHash, '$2y$') === 0, $adminHash);
$pdo->exec("UPDATE `{$prefix}_user` SET `salt` = 's1', `password` = '".sha1('Admin-Pass-1'.'s1')."' WHERE `id` = 1");
updateAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Admin-Pass-1', $key);
check('updateAdmin() ยอมรับ sha1 รุ่นเก่าสุด (ไม่มี key) และเก็บใหม่เป็น bcrypt', strpos((string) $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 1")->fetchColumn(), '$2y$') === 0);
try {
    updateAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Wrong-Pass', $key);
    check('updateAdmin() รหัสผิด → Exception', false);
} catch (\Exception $e) {
    check('updateAdmin() รหัสผิด → Exception', true);
}
$col = $pdo->query("SHOW COLUMNS FROM `{$prefix}_user` LIKE 'password'")->fetch(PDO::FETCH_ASSOC);
check('คอลัมน์ password ใน core.sql กว้างพอสำหรับอัลกอริทึมอนาคต (varchar(255))', stripos((string) $col['Type'], 'varchar(255)') !== false, $col);

// ---------------------------------------------------------------------------
if ($options['url'] !== '') {
    echo "\n== U7 security headers ของหน้า HTML: ".$options['url']." ==\n";
    $h = strtolower((string) shell_exec('curl -sI '.escapeshellarg($options['url'].'/').' 2>/dev/null'));
    check('X-Frame-Options: SAMEORIGIN', strpos($h, 'x-frame-options: sameorigin') !== false, $h);
    check("Content-Security-Policy: frame-ancestors 'self'", strpos($h, "frame-ancestors 'self'") !== false);
    check('X-Content-Type-Options: nosniff', strpos($h, 'x-content-type-options: nosniff') !== false);
    check('Referrer-Policy', strpos($h, 'referrer-policy:') !== false);
    $h2 = strtolower((string) shell_exec('curl -sI '.escapeshellarg($options['url'].'/api/index/auth/csrf-token').' 2>/dev/null'));
    if (strpos($h2, 'phpsessid') !== false) {
        check('PHPSESSID ที่ API ออกให้มี HttpOnly + SameSite', preg_match('/set-cookie: phpsessid=[^\n]*httponly/', $h2) === 1 && preg_match('/set-cookie: phpsessid=[^\n]*samesite/', $h2) === 1, $h2);
    }
}

$finished = true;
echo "\nผ่าน $passed / ล้มเหลว $failed\n";
exit($failed === 0 ? 0 : 1);
