<?php
/**
 * install/cli-testsecurity.php — ชุดทดสอบความปลอดภัยระดับเฟรมเวิร์ก (แก้เมื่อ 7.0.4)
 *
 * พิสูจน์ว่าปัญหาที่พบจากการตรวจ e-signature (kb #10438) ถูกแก้ที่แกนแล้ว และไม่กลับมาอีก:
 *   U1 รหัสผ่านเก็บเป็น bcrypt (ของ HMAC(password_key)) · รหัสผ่านรูปแบบของรุ่นก่อน (sha1 · bcrypt ไม่มี pepper) เข้าระบบไม่ได้
 *   U4 session ที่ API เปิดเองมี HttpOnly/SameSite/use_strict_mode
 *   U5 X-Forwarded-For / Client-IP เชื่อเฉพาะจาก TRUSTED_PROXIES
 *   U6 บัญชีที่ถูกระงับใช้ token เดิมไม่ได้ทันที · การระงับลบ session ทุกเครื่อง
 *   U7 index.php ส่ง security headers (เมื่อระบุ --url=)
 *   U8 X-Forwarded-Host / -Proto / -Port กำหนด WEB_URL, HTTPS และ Uri ได้เฉพาะจาก TRUSTED_PROXIES (ผ่าน php -S)
 *   U9 ลิงก์รีเซ็ตรหัสผ่านในอีเมลชี้เว็บนี้เสมอ: ฐานจาก config web_url ไม่ใช่ header ของคำขอ (ผ่าน php -S + ฐานทดสอบ)
 *   U12 refresh token ใช้ได้เฉพาะ session ที่ยังเปิด (logout · รหัสผ่านใหม่ · ระงับ ปิด session) · รายการ token ที่ถูกเพิกถอน
 *       ไม่หายเมื่อเขียนพร้อมกันหรือล้างแคช · หน้าตั้งค่าของผู้ดูแลสูงสุด (API SMS LINE Telegram AI) บันทึกและทดสอบได้เฉพาะ
 *       ผู้ดูแลสูงสุด · auth/update รับเฉพาะ access token ของ session ที่เปิดอยู่ · controller ไม่ส่งข้อความ exception ออกไป
 *       · ApiController::validateToken/validateTokenBearer/validateSign ไม่รับค่าว่าง (token ว่าง · api_secret ว่าง)
 *       · เปลี่ยนรหัสผ่านของตัวเอง (auth/update · profile/save) ต้องกรอกรหัสผ่านปัจจุบัน (ยกเว้นบัญชีโซเชียล)
 *       · รหัสยืนยันอีเมลสุ่มจริง · ตัวจำกัดอัตราเปิด session ด้วย Request::startSecureSession()
 *       · handleException(): ปิด debug ซ่อนข้อผิดพลาดของระบบ (PDO · ฐานข้อมูล · \Error) เปิด debug คืน error จริง
 *   U13 LINE login: line/callback.php แลก code เฉพาะเมื่อ nonce ใน state ตรงกับ cookie line_login_state (กัน login CSRF)
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
echo "\n== U1 Kotchasan\\Password: bcrypt ที่ผสม password_key เท่านั้น ==\n";
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
$ok = \Kotchasan\Password::verify('Secret-1234', $legacy, $salt, $key, $outdated);
check('verify() sha1(key.password.salt) รุ่นเก่า → false แต่ $outdated = true (รหัสถูก) · รหัสผิด → $outdated = false',
    !$ok && $outdated === true && !\Kotchasan\Password::verify('wrong', $legacy, $salt, $key, $outdated2) && $outdated2 === false);
$ok = \Kotchasan\Password::verify('Secret-1234', $older, $salt, $key, $outdated);
check('verify() sha1(password.salt) รุ่นเก่ากว่า (ไม่มี key) → false · $outdated = true', !$ok && $outdated === true);
$ok = \Kotchasan\Password::verify('Secret-1234', password_hash('Secret-1234', PASSWORD_BCRYPT), '', $key, $outdated);
check('verify() bcrypt ที่ไม่มี password_key → false · $outdated = true', !$ok && $outdated === true);
$ok = \Kotchasan\Password::verify('Secret-1234', $hash, '', $key, $outdated);
check('verify() รูปแบบปัจจุบัน → true · $outdated = false', $ok && $outdated === false);
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
// datas/cache/revoked_tokens.json เคยเปิดจากเว็บได้ (FilesMatch ปิดแค่ .cache .log ...)
check('.htaccess ปิด datas/cache และ datas/logs ทุกระดับ (รวม datas/users/<ไซต์>/...)', !is_file($appRoot.'.htaccess')
    || strpos((string) file_get_contents($appRoot.'.htaccess'), 'RewriteRule ^datas/(.+/)?(cache|logs)(/|$) - [F,L]') !== false);

/**
 * เปิด php -S หนึ่งคำขอ: $router รับทุกคำขอ รากเว็บคือ $docRoot · ส่ง $headers แล้วปิดเซิร์ฟเวอร์
 * $mailFile = ไฟล์ที่ mail() เขียนอีเมลลงแทนการส่ง (sendmail_path) · $path = path + query ของคำขอ
 * $http = ตัวเลือกของ stream context (เช่น follow_location) · คืน [body, port]
 * (CLI ตั้ง HOST เป็น localhost เสมอ header ของคำขอจึงทดสอบได้ผ่านเซิร์ฟเวอร์จริงเท่านั้น)
 */
function serveOnce($router, $docRoot, array $headers, $mailFile = null, $path = '/', array $http = [])
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $cmd = [PHP_BINARY, '-d', 'session.save_path='.sys_get_temp_dir()];
    if ($mailFile !== null) {
        // mail() ต่อ -f<ผู้ส่ง> ท้ายคำสั่ง — สคริปต์นี้ไม่สนอาร์กิวเมนต์ เขียนอีเมลลงไฟล์อย่างเดียว
        $sendmail = $mailFile.'.sh';
        file_put_contents($sendmail, "#!/bin/sh\ncat >> ".escapeshellarg($mailFile)."\n");
        chmod($sendmail, 0755);
        array_push($cmd, '-d', 'sendmail_path='.$sendmail);
    }
    array_push($cmd, '-S', '127.0.0.1:'.$port, '-t', $docRoot, $router);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen('127.0.0.1', $port);
        if ($probe) {
            fclose($probe);
            break;
        }
        usleep(100000);
    }
    // {port} ใน header = port ของเซิร์ฟเวอร์นี้ (เช่น Referer ของเว็บนี้เอง)
    $headers = str_replace('{port}', (string) $port, $headers);
    $context = stream_context_create(['http' => $http + ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30]]);
    $body = (string) @file_get_contents('http://127.0.0.1:'.$port.$path, false, $context);
    proc_terminate($proc);
    proc_close($proc);
    return [$body, $port];
}

// ---------------------------------------------------------------------------
echo "\n== U11 แคชไฟล์ของ Kotchasan ถอดได้เฉพาะ array กับ stdClass ==\n";
// คลาสที่ปลุกแล้วทิ้งร่องรอย — ถ้า unserialize() ยอมคลาสนี้ __wakeup จะทำงาน
class SecTestWakeup
{
    public static $woke = false;
    public function __wakeup()
    {
        self::$woke = true;
    }
}
include_once $appRoot.'Kotchasan/Cache/CacheInterface.php';
include_once $appRoot.'Kotchasan/Cache/FileCache.php';
$cacheDir = sys_get_temp_dir().'/sectest-cache-'.getmypid().'/';
$cleanup[] = function () use ($cacheDir) {
    foreach (glob($cacheDir.'{,*/,*/*/}*', GLOB_BRACE) ?: [] as $file) {
        is_file($file) ? @unlink($file) : null;
    }
    foreach (array_reverse(glob($cacheDir.'{*/*/,*/}', GLOB_BRACE | GLOB_ONLYDIR) ?: []) as $dir) {
        @rmdir($dir);
    }
    @rmdir($cacheDir);
};
$fileCache = new \Kotchasan\Cache\FileCache($cacheDir, 3600, false);
$fileCache->set('rows', ['data' => [(object) ['id' => 1, 'name' => 'a']], 'format' => 'object']);
$rows = $fileCache->get('rows');
check('แถวแบบ stdClass ยังอ่านกลับได้ครบ', is_array($rows) && ($rows['data'][0] ?? null) instanceof \stdClass && $rows['data'][0]->name === 'a', $rows);
// เขียนทับไฟล์ของคีย์เดิมด้วยค่าที่มี object ของคลาสอื่น (คนที่เขียนแคชได้ทำแบบนี้ได้)
$planted = null;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS)) as $file) {
    $stored = @unserialize((string) file_get_contents($file->getPathname()), ['allowed_classes' => ['stdClass']]);
    if (is_array($stored) && isset($stored['value']['format'])) {
        $stored['value'] = ['data' => [new SecTestWakeup()], 'format' => 'object'];
        file_put_contents($file->getPathname(), serialize($stored));
        $planted = $file->getPathname();
    }
}
SecTestWakeup::$woke = false;
$rows = $fileCache->get('rows');
check('object ของคลาสอื่นในไฟล์แคชไม่ถูกปลุก (__wakeup ไม่ทำงาน)', $planted !== null && SecTestWakeup::$woke === false
    && !(($rows['data'][0] ?? null) instanceof SecTestWakeup), $planted === null ? 'ไม่พบไฟล์แคช' : get_class($rows['data'][0] ?? new \stdClass()));

// ---------------------------------------------------------------------------
echo "\n== U8 X-Forwarded-Host / -Proto / -Port: เชื่อเฉพาะจาก TRUSTED_PROXIES ==\n";
$u8 = sys_get_temp_dir().'/sectest-u8-'.getmypid();
@mkdir($u8, 0777, true);
$cleanup[] = function () use ($u8) {
    @unlink($u8.'/router.php');
    @unlink($u8.'/probe.json');
    @rmdir($u8);
};
file_put_contents($u8.'/router.php', '<?php
$p = json_decode((string) file_get_contents(__DIR__."/probe.json"), true);
if ($p["trusted"] !== null) {
    define("TRUSTED_PROXIES", $p["trusted"]);
}
define("ROOT_PATH", $p["appRoot"]);
define("APP_PATH", $p["appRoot"]);
include ROOT_PATH."Kotchasan/load.php";
$uri = \Kotchasan\Http\Uri::createFromGlobals();
header("Content-Type: application/json");
echo json_encode(["web_url" => WEB_URL, "https" => HTTPS, "uri" => $uri->getScheme()."://".$uri->getHost().":".$uri->getPort()]);
');
$urlProbe = function ($trusted, array $headers) use ($u8, $appRoot) {
    file_put_contents($u8.'/probe.json', json_encode(['trusted' => $trusted, 'appRoot' => $appRoot]));
    list($body, $port) = serveOnce($u8.'/router.php', $appRoot, $headers);
    $out = json_decode($body, true) ?: ['body' => $body];
    $out['port'] = $port;
    return $out;
};
$forged = ['X-Forwarded-Host: evil.example', 'X-Forwarded-Proto: https', 'X-Forwarded-Port: 8443'];
$r = $urlProbe(null, $forged);
check('ไม่มี proxy: X-Forwarded-Host/-Proto ปลอมไม่เปลี่ยน WEB_URL และ HTTPS', isset($r['web_url']) && strpos($r['web_url'], 'http://127.0.0.1:'.$r['port'].'/') === 0 && $r['https'] === false, $r);
check('ไม่มี proxy: Uri::createFromGlobals() ไม่เชื่อ X-Forwarded-Host/-Proto/-Port', ($r['uri'] ?? '') === 'http://127.0.0.1:'.$r['port'], $r);
$r = $urlProbe('10.0.0.5', $forged);
check('มี proxy แต่คำขอไม่ได้มาจาก proxy → header ไม่มีผล', isset($r['web_url']) && strpos($r['web_url'], 'http://127.0.0.1:') === 0 && $r['https'] === false && strpos($r['uri'], 'http://127.0.0.1:') === 0, $r);
$r = $urlProbe('127.0.0.1', $forged);
check('คำขอจาก TRUSTED_PROXIES → WEB_URL ใช้ X-Forwarded-Host/-Proto และ HTTPS = true', isset($r['web_url']) && strpos($r['web_url'], 'https://evil.example/') === 0 && $r['https'] === true, $r);
check('คำขอจาก TRUSTED_PROXIES → Uri ใช้ scheme/host/port ที่ proxy ส่งมา', ($r['uri'] ?? '') === 'https://evil.example:8443', $r);
$r = $urlProbe('127.0.0.1', ['X-Forwarded-Proto: javascript', 'X-Forwarded-Host: proxied.example']);
check('X-Forwarded-Proto ที่ไม่ใช่ https จาก proxy → http (ไม่ใช่ javascript://)', isset($r['web_url']) && strpos($r['web_url'], 'http://proxied.example/') === 0 && $r['https'] === false && strpos($r['uri'], 'http://proxied.example') === 0, $r);
$r = $urlProbe(null, ['Host: other.example']);
check('header Host ยังเลือกโฮสต์ของ WEB_URL (เว็บหลายไซต์ใช้) — ลิงก์ในอีเมลจึงใช้ config web_url (U9)', isset($r['web_url']) && strpos($r['web_url'], 'http://other.example/') === 0, $r);

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

// ผู้ใช้ 2: sha1 ของรุ่นก่อน · ผู้ใช้ 4: bcrypt ที่ไม่มี pepper ของรุ่นก่อน · ผู้ใช้ 3: bcrypt ปัจจุบัน
// เข้าระบบรับเฉพาะรูปแบบปัจจุบัน — บัญชีที่ยังเป็นรูปแบบเก่าต้องตั้งรหัสใหม่ด้วยลืมรหัสผ่าน
$legacySalt = 'oldsalt';
$legacyHash = sha1($key.'Legacy-Pass-1'.$legacySalt);
$modernHash = \Kotchasan\Password::hash('Modern-Pass-1', $key);
// ข้อมูลตั้งต้นของบางโปรเจ็ค (database.sql) มีสมาชิก id 2-5 อยู่แล้ว — ฐานนี้เป็นฐานทดสอบ ลบทิ้งได้
$pdo->exec("DELETE FROM `{$prefix}_user` WHERE `id` IN (1, 2, 3, 4, 5)");
$pdo->exec("INSERT INTO `{$prefix}_user` (`id`,`username`,`salt`,`password`,`status`,`active`,`permission`,`name`,`created_at`) VALUES
    (2, 'legacy@test.local', '$legacySalt', '$legacyHash', 0, 1, '', 'legacy', NOW()),
    (3, 'modern@test.local', 'x', '$modernHash', 0, 1, '', 'modern', NOW()),
    (4, 'plain@test.local', 'x', '".password_hash('Plain-Pass-1', PASSWORD_BCRYPT)."', 0, 1, '', 'plain', NOW())");
$login = \Index\Auth\Model::authenticate('legacy@test.local', 'Legacy-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('รหัสผ่านที่เก็บแบบ sha1 ของรุ่นก่อน → เข้าระบบไม่ได้ และบอกให้ขอรหัสผ่านใหม่ (PASSWORD_RESET_REQUIRED)',
    empty($login['success']) && ($login['code'] ?? '') === 'PASSWORD_RESET_REQUIRED', $login);
check('และ hash sha1 ในฐานไม่ถูกแตะ', $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 2")->fetchColumn() === $legacyHash);
$login = \Index\Auth\Model::authenticate('legacy@test.local', 'Legacy-Pass-2', '127.0.0.1', ['user_agent' => 'x']);
check('sha1 รุ่นก่อน รหัสผิด → AUTH_FAILED (ไม่บอกว่าต้องขอรหัสใหม่)', empty($login['success']) && ($login['code'] ?? '') === 'AUTH_FAILED', $login);
$login = \Index\Auth\Model::authenticate('plain@test.local', 'Plain-Pass-1', '127.0.0.3', ['user_agent' => 'x']);
check('bcrypt ที่ไม่มี pepper ของรุ่นก่อน → เข้าระบบไม่ได้ (PASSWORD_RESET_REQUIRED)', empty($login['success']) && ($login['code'] ?? '') === 'PASSWORD_RESET_REQUIRED', $login);
// hash ของรหัสที่ Text::password() รุ่นก่อนตัดอักขระออก — รหัสเต็มเข้าไม่ได้ ต้องขอรหัสใหม่
$pdo->exec("INSERT INTO `{$prefix}_user` (`id`,`username`,`salt`,`password`,`status`,`active`,`permission`,`name`,`created_at`) VALUES
    (5, 'short@test.local', 'x', '".\Kotchasan\Password::hash(\Kotchasan\Password::legacyInput('Short Pass 1'), $key)."', 0, 1, '', 'short', NOW())");
$login = \Index\Auth\Model::authenticate('short@test.local', 'Short Pass 1', '127.0.0.4', ['user_agent' => 'x']);
check('hash ของรหัสที่ Text::password() รุ่นก่อนตัด → รหัสเต็มเข้าไม่ได้ (PASSWORD_RESET_REQUIRED)', empty($login['success']) && ($login['code'] ?? '') === 'PASSWORD_RESET_REQUIRED', $login);
// id 5 ใช้อีกครั้งด้านล่าง (manager)
$pdo->exec("DELETE FROM `{$prefix}_user` WHERE `id` = 5");
check('checkCurrentPassword(): รหัสเก็บแบบรุ่นก่อน → ข้อความให้ขอรหัสผ่านใหม่',
    \Index\Auth\Model::checkCurrentPassword((object) ['username' => 'plain@test.local', 'password' => password_hash('Plain-Pass-1', PASSWORD_BCRYPT), 'salt' => 'x'], 'Plain-Pass-1', '127.0.0.5') === \Index\Auth\Model::passwordResetMessage());
$login3 = \Index\Auth\Model::authenticate('modern@test.local', 'Modern-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('ล็อกอินบัญชี bcrypt → สำเร็จ และ hash ไม่ถูกเขียนซ้ำ', !empty($login3['success']) && $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn() === $modernHash);
check('bcrypt ปัจจุบัน รหัสผิด → ไม่สำเร็จ', empty(\Index\Auth\Model::authenticate('modern@test.local', 'Modern-Pass-2', '127.0.0.2', ['user_agent' => 'x'])['success']));
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
$upgraded = (string) $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 1")->fetchColumn();
check('updateAdmin() (การปรับรุ่น) ยอมรับ sha1 รุ่นเก่าสุด (ไม่มี key) และเก็บใหม่เป็น bcrypt ที่ผสม password_key',
    strpos($upgraded, '$2y$') === 0 && \Kotchasan\Password::verify('Admin-Pass-1', $upgraded, '', $key));
$pdo->exec("UPDATE `{$prefix}_user` SET `password` = '".\Kotchasan\Password::hash(\Kotchasan\Password::legacyInput('Admin Pass 1'), $key)."' WHERE `id` = 1");
check('verifyAdmin() (การปรับรุ่น) ยอมรับ hash ของรหัสที่ Text::password() รุ่นก่อนตัด', verifyAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Admin Pass 1', $key));
updateAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Admin Pass 1', $key);
check('updateAdmin() เก็บรหัสเต็มใหม่ — รหัสเต็มเข้าระบบได้หลังปรับรุ่น',
    \Kotchasan\Password::verify('Admin Pass 1', (string) $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 1")->fetchColumn(), '', $key));
$pdo->exec("UPDATE `{$prefix}_user` SET `password` = '".\Kotchasan\Password::hash('Admin-Pass-1', $key)."' WHERE `id` = 1");
try {
    updateAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Wrong-Pass', $key);
    check('updateAdmin() รหัสผิด → Exception', false);
} catch (\Exception $e) {
    check('updateAdmin() รหัสผิด → Exception', true);
}
// ตัวปรับรุ่นบนเว็บ: หน้านี้เปิดได้โดยไม่ต้องล็อกอิน
if (function_exists('verifyAdmin')) {
    check('verifyAdmin(): ผู้ดูแลสูงสุด + รหัสถูก → true · รหัสผิด / ผู้ใช้อื่น → false (ไม่แก้ฐาน)',
        verifyAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Admin-Pass-1', $key)
        && !verifyAdmin($dbObj, $prefix.'_user', 'admin@test.local', 'Wrong-Pass', $key)
        && !verifyAdmin($dbObj, $prefix.'_user', 'modern@test.local', 'Modern-Pass-1', $key));
}
$messages = [];
foreach ([['admin@test.local', 'Wrong-Pass'], ['nobody@test.local', 'Wrong-Pass'], ['modern@test.local', 'Modern-Pass-1']] as $try) {
    try {
        updateAdmin($dbObj, $prefix.'_user', $try[0], $try[1], $key);
        $messages[] = 'no exception';
    } catch (\Exception $e) {
        $messages[] = $e->getMessage();
    }
}
check('ชื่อผิด รหัสผิด และไม่ใช่ผู้ดูแลสูงสุด ได้ข้อความเดียวกัน (ไม่บอกว่าชื่อไหนเป็นผู้ดูแล)', count(array_unique($messages)) === 1 && $messages[0] === adminCredentialsError(), $messages);
check('ข้อความยืนยันไม่สำเร็จไม่มีค่า password_key (รุ่นก่อนแสดงค่าให้ทุกคนที่กรอกผิด)', strpos($messages[0], $key) === false);
$attempts = ['ip' => 3, 'all' => 5, 'window' => 60, 'file' => $scratch.'attempts.json'];
$waits = [];
for ($i = 0; $i < 3; $i++) {
    $waits[] = adminAttempts('198.51.100.1', 'fail', $attempts);
}
check('ยืนยันผิดครบจำนวนจาก IP เดียว → ถูกปิดชั่วคราว', $waits[0] === 0 && $waits[1] === 0 && $waits[2] > 0 && adminAttempts('198.51.100.1', 'check', $attempts) > 0, $waits);
check('IP อื่นยังยืนยันได้', adminAttempts('198.51.100.2', 'check', $attempts) === 0);
adminAttempts('198.51.100.2', 'fail', $attempts);
adminAttempts('198.51.100.3', 'fail', $attempts);
check('ผิดรวมทุก IP ครบจำนวน → ปิดทุก IP (กันการเดาจากหลายเครื่อง)', adminAttempts('198.51.100.9', 'check', $attempts) > 0);
@unlink($scratch.'attempts.json');
// ตัวปรับรุ่นของโปรเจ็คที่ยังใช้ upgrade2.php (บางโปรเจ็คใช้ install/setup.php แทน)
if (is_file($appRoot.'install/upgrade2.php')) {
    $upgrade = (string) file_get_contents($appRoot.'install/upgrade2.php');
    $verifyAt = strpos($upgrade, 'verifyAdmin(');
    $preflightAt = strpos($upgrade, 'preflight($db');
    $updateAt = strpos($upgrade, 'updateAdmin($db');
    check('upgrade2.php ยืนยันผู้ดูแลก่อน preflight และก่อนแตะฐาน', $verifyAt !== false
        && ($preflightAt === false || $verifyAt < $preflightAt) && ($updateAt === false || $verifyAt < $updateAt));
}

$col = $pdo->query("SHOW COLUMNS FROM `{$prefix}_user` LIKE 'password'")->fetch(PDO::FETCH_ASSOC);
check('คอลัมน์ password ใน core.sql กว้างพอสำหรับอัลกอริทึมอนาคต (varchar(255))', stripos((string) $col['Type'], 'varchar(255)') !== false, $col);

// ---------------------------------------------------------------------------
echo "\n== U12 refresh token ผูกกับ session · รายการเพิกถอน · สิทธิ์ของหน้าตั้งค่า ==\n";
$auth = '\Index\Auth\Model';
$signIn = function () use ($auth) {
    return $auth::authenticate('modern@test.local', 'Modern-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
};
$sessions = function ($id) use ($pdo, $prefix) {
    return (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}_user_session` WHERE `member_id` = ".(int) $id)->fetchColumn();
};
$auth::logoutAllSessions(3);
$a = $signIn();
$rotated = $auth::refreshAccessToken($a['refresh_token'], []);
check('refresh ของ session ที่ยังเปิด → ได้ token ชุดใหม่', !empty($rotated['success']) && $auth::getUserByToken($rotated['token']) !== null, $rotated);
check('refresh token ที่หมุนแล้วใช้ซ้ำไม่ได้', empty($auth::refreshAccessToken($a['refresh_token'], [])['success']));
$auth::logoutAllSessions(3);
$again = $auth::refreshAccessToken($rotated['refresh_token'], []);
check('ออกจากระบบทุกเครื่อง (เปลี่ยนรหัสผ่าน · ระงับ) → refresh token เดิมขอ token ใหม่ไม่ได้', empty($again['success']), $again);
check('และไม่มี session ถูกเขียนกลับ', $sessions(3) === 0);
$b = $signIn();
$auth::closeTokenSession($b['refresh_token']);
check('logout ด้วย refresh token (access token หมดอายุแล้ว) → session ปิด access token ใช้ไม่ได้', $auth::getUserByToken($b['token']) === null && $sessions(3) === 0
    && empty($auth::refreshAccessToken($b['refresh_token'], [])['success']));
$c = $signIn();
$d = $signIn();
$auth::logoutOtherSessions(3, $c['token']);
check('รหัสผ่านใหม่: เครื่องที่เปลี่ยนยังอยู่ เครื่องอื่นหลุด', $auth::getUserByToken($c['token']) !== null && $auth::getUserByToken($d['token']) === null);
$auth::logoutOtherSessions(3, $login1['token'] ?? '');
check('token ของบัญชีอื่นรักษา session ไม่ได้ → ปิดทุกเครื่อง', $auth::getUserByToken($c['token']) === null && $sessions(3) === 0);
// ผู้ดูแลสูงสุดของชุดทดสอบ: รหัสผ่านรูปแบบปัจจุบันเสมอ (ส่วนตัวติดตั้งด้านบนของบางโปรเจ็คปล่อยไว้เป็น sha1)
$adminPassword = function () use ($pdo, $prefix, $key) {
    $pdo->exec("UPDATE `{$prefix}_user` SET `password` = ".$pdo->quote(\Kotchasan\Password::hash('Admin-Pass-1', $key))." WHERE `id` = 1");
    \Gcms\LoginAttempt::clear('admin@test.local', '127.0.0.1');
};
$adminPassword();
$pdo->exec("UPDATE `{$prefix}_user` SET `active` = 0 WHERE `id` = 1");
$root = $auth::authenticate('admin@test.local', 'Admin-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
check('id 1 ที่ active=0 refresh ได้ (กฎเดียวกับ login)', !empty($root['success']) && !empty($auth::refreshAccessToken($root['refresh_token'], [])['success']), $root);
$pdo->exec("UPDATE `{$prefix}_user` SET `active` = 1 WHERE `id` = 1");

// รายการเพิกถอน: การเขียนพร้อมกันไม่ทับกัน · ล้างแคชไม่ลบ
$store = $auth::getRevokedStorePath();
$revoke = new ReflectionMethod($auth, 'revokeTokenPayload');
$revoke->setAccessible(true);
$isRevoked = new ReflectionMethod($auth, 'isTokenRevoked');
$isRevoked->setAccessible(true);
$worker = $scratch.'revoke.php';
// คนงานแยก process เขียน jti ของตัวเอง 40 ครั้ง พร้อมกัน 4 ตัว — ทุก jti ต้องอยู่ครบ
file_put_contents($worker, '<?php
$store = $argv[1];
$lock = fopen($store.".lock", "c");
for ($i = 0; $i < 40; $i++) {
    flock($lock, LOCK_EX);
    $data = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
    $data["w".$argv[2]."-".$i] = time() + 600;
    $temp = $store.".".getmypid().".tmp";
    file_put_contents($temp, json_encode($data));
    rename($temp, $store);
    flock($lock, LOCK_UN);
}
');
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, $worker, $store, (string) $i], [], $pipes);
}
// ระหว่างนั้น process นี้เพิกถอนด้วยโค้ดจริง
$mine = [];
for ($i = 0; $i < 40; $i++) {
    $mine[] = $jti = 'sectest-'.$i;
    $revoke->invoke(null, ['jti' => $jti, 'exp' => time() + 600]);
}
foreach ($procs as $proc) {
    proc_close($proc);
}
$list = json_decode((string) file_get_contents($store), true) ?: [];
$missing = array_diff($mine, array_keys($list));
$others = count(array_filter(array_keys($list), function ($k) {
    return strpos((string) $k, 'w') === 0;
}));
check('เพิกถอนพร้อมกันหลาย process → ไม่มีรายการหาย', empty($missing) && $others === 160, ['missing' => count($missing), 'workers' => $others]);
$revoke->invoke(null, ['jti' => 'sectest-old', 'exp' => time() - 5]);
check('รายการที่หมดอายุไม่นับว่าเพิกถอนและถูกเก็บกวาด', $isRevoked->invoke(null, ['jti' => 'sectest-old']) === false
    && $isRevoked->invoke(null, ['jti' => 'sectest-1']) === true);
$cacheSource = (string) file_get_contents($appRoot.'modules/index/controllers/cache.php');
check('ล้างแคชไม่ลบรายการ token ที่ถูกเพิกถอน', strpos($cacheSource, 'REVOKED_STORE') !== false);
// คืนรายการของชุดทดสอบ (ไฟล์จริงของไซต์)
$lockHandle = fopen($store.'.lock', 'c');
flock($lockHandle, LOCK_EX);
$list = json_decode((string) file_get_contents($store), true) ?: [];
foreach (array_keys($list) as $k) {
    if (strpos((string) $k, 'sectest-') === 0 || preg_match('/^w\d-\d+$/', (string) $k)) {
        unset($list[$k]);
    }
}
file_put_contents($store, json_encode($list), LOCK_EX);
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// ตัวตรวจ token / ลายเซ็นของ API ภายนอก (Kotchasan\ApiController) — Timeline\Guard และ ecommerce guard ใช้
$cfgProp = new ReflectionProperty('\Kotchasan\KBase', 'cfg');
$cfgProp->setAccessible(true);
$cfgObj = $cfgProp->getValue();
$savedApi = [$cfgObj->api_tokens ?? null, $cfgObj->api_secret ?? null];
$passes = function (callable $fn) {
    try {
        return $fn() === true;
    } catch (\Kotchasan\ApiException $e) {
        return false;
    }
};
$bearer = function ($header) {
    $_SERVER['HTTP_AUTHORIZATION'] = $header;
    $request = new \Kotchasan\Http\Request();
    unset($_SERVER['HTTP_AUTHORIZATION']);

    return $request;
};
$cfgObj->api_tokens = ['external' => ''];
check('api_tokens ที่บันทึกเป็นค่าว่าง → token ว่างไม่ผ่าน validateToken()', !$passes(function () {
    return \Kotchasan\ApiController::validateToken('');
}));
$cfgObj->api_tokens = ['external' => 'Tok-123456'];
check('token ถูก → ผ่าน · ผิด / ว่าง / ไม่ใช่ string → ไม่ผ่าน', $passes(function () {
    return \Kotchasan\ApiController::validateToken('Tok-123456');
}) && !$passes(function () {
    return \Kotchasan\ApiController::validateToken('Tok-12345');
}) && !$passes(function () {
    return \Kotchasan\ApiController::validateToken('');
}) && !$passes(function () {
    return \Kotchasan\ApiController::validateToken(['Tok-123456']);
}));
check('validateTokenBearer(): Bearer ถูก → ผ่าน · ผิด → ไม่ผ่าน', $passes(function () use ($bearer) {
    return \Kotchasan\ApiController::validateTokenBearer($bearer('Bearer Tok-123456'));
}) && !$passes(function () use ($bearer) {
    return \Kotchasan\ApiController::validateTokenBearer($bearer('Bearer Tok-1234567'));
}));
$signed = ['id' => 7, 'timestamp' => time()];
$cfgObj->api_secret = '';
$signed['sign'] = \Kotchasan\Password::generateSign(['id' => 7, 'timestamp' => $signed['timestamp']], '');
check('api_secret ว่าง → ลายเซ็นที่ใครก็คำนวณได้ไม่ผ่าน validateSign()', !$passes(function () use ($signed) {
    return \Kotchasan\ApiController::validateSign($signed);
}));
$cfgObj->api_secret = 'Secret-xyz';
$signed['sign'] = \Kotchasan\Password::generateSign(['id' => 7, 'timestamp' => $signed['timestamp']], 'Secret-xyz');
check('ลายเซ็นถูก → ผ่าน · แก้พารามิเตอร์ / sign เป็น array → ไม่ผ่าน', $passes(function () use ($signed) {
    return \Kotchasan\ApiController::validateSign($signed);
}) && !$passes(function () use ($signed) {
    return \Kotchasan\ApiController::validateSign(['id' => 8] + $signed);
}) && !$passes(function () use ($signed) {
    return \Kotchasan\ApiController::validateSign(['sign' => [$signed['sign']]] + $signed);
}));
list($cfgObj->api_tokens, $cfgObj->api_secret) = $savedApi;

// endpoint: เรียก controller ตรง ๆ ด้วยคำขอจำลอง (CSRF token วางใน $_SESSION เอง — CLI เปิด session ไม่ได้หลังมี output)
$callApi = function ($class, $method, array $post, $accessToken) {
    $csrf = str_repeat('ab', 32);
    $_SESSION[$csrf] = ['times' => 0, 'expired' => time() + 600, 'created' => time()];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_POST = $_REQUEST = $post;
    $_COOKIE = ['auth_token' => $accessToken];
    $response = @(new $class())->$method(new \Kotchasan\Http\Request());
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = $_REQUEST = $_COOKIE = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);

    return [$response->getStatusCode(), (string) $response->getContent()];
};
// ผู้ใช้ 5: แอดมิน (status 1) มี can_config แต่ไม่ใช่ผู้ดูแลสูงสุด
$pdo->exec("INSERT INTO `{$prefix}_user` (`id`,`username`,`salt`,`password`,`status`,`active`,`permission`,`name`,`created_at`) VALUES
    (5, 'manager@test.local', 'x', '".\Kotchasan\Password::hash('Manager-Pass-1', $key)."', 1, 1, ',can_config,', 'manager', NOW())");
$manager = $auth::authenticate('manager@test.local', 'Manager-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity']);
$member = $signIn();
foreach (['api', 'sms', 'line', 'telegram', 'ai'] as $module) {
    list($status) = $callApi('\Index\Settings\Controller', 'save', ['module' => $module], $manager['token']);
    check("บันทึกหน้าตั้งค่า $module ด้วยแอดมินที่ไม่ใช่ผู้ดูแลสูงสุด → 403 (เหมือนตอนเปิดหน้า)", $status === 403, $status);
}
list($status) = $callApi('\Index\Settings\Controller', 'testAi', ['ai_provider' => 'openai', 'ai_api_url' => 'https://evil.example/v1'], $manager['token']);
check('ทดสอบ AI (ส่งคีย์ที่เก็บไว้ไป api_url ที่กำหนด) ด้วยแอดมินทั่วไป → ไม่ทำงาน', $status === 404, $status);
foreach (['testTelegram', 'setTelegramWebhook', 'deleteTelegramWebhook'] as $method) {
    list($status) = $callApi('\Index\Settings\Controller', $method, ['bot_token' => 'x', 'chat_id' => '1', 'webhook_url' => 'https://example.com/'], $manager['token']);
    check("$method ด้วยแอดมินทั่วไป → 403", $status === 403, $status);
}
list($status) = $callApi('\Index\Permission\Controller', 'action', ['action' => 'update', 'ids' => [3], 'field' => 'can_config', 'value' => 1], $manager['token']);
check('แก้สิทธิ์จากตารางสิทธิ์ด้วยแอดมินทั่วไป → 403 และสิทธิ์ไม่เปลี่ยน', $status === 403
    && $pdo->query("SELECT `permission` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn() === '', $status);
// รหัสผ่าน SMTP ที่เก็บไว้ (ไม่แสดงบนหน้าจอ) ไม่ถูกส่งตามไปเซิร์ฟเวอร์ใหม่
$parseEmail = new ReflectionMethod('\Index\Settings\Controller', 'parseEmailSettings');
$parseEmail->setAccessible(true);
$mailForm = function ($host, $password = '') use ($parseEmail) {
    $stored = (object) ['email_Host' => 'smtp.old.example', 'email_Username' => 'mailer', 'email_Password' => 'stored-secret'];
    $errors = $parseEmail->invoke(new \Index\Settings\Controller(), ['noreply_email' => '', 'email_Host' => $host, 'email_Port' => '587',
        'email_use_phpMailer' => '1', 'email_Password' => $password], $stored);

    return [$errors, $stored];
};
list($errors) = $mailForm('smtp.evil.example');
check('เปลี่ยนเซิร์ฟเวอร์อีเมลโดยไม่กรอกรหัสผ่านใหม่ → ไม่บันทึก (รหัสผ่านเดิมไม่ถูกส่งไปเซิร์ฟเวอร์ใหม่)', isset($errors['email_Password']), $errors);
list($errors, $stored) = $mailForm('smtp.old.example');
check('เซิร์ฟเวอร์เดิม เว้นรหัสผ่านว่าง → บันทึกได้ รหัสผ่านเดิมคงอยู่', empty($errors) && $stored->email_Password === 'stored-secret', $errors);
list($errors, $stored) = $mailForm('smtp.new.example', 'New-Secret-1');
check('เซิร์ฟเวอร์ใหม่พร้อมรหัสผ่านใหม่ → บันทึกได้', empty($errors) && $stored->email_Password === 'New-Secret-1', $errors);
// auth/update: รับเฉพาะ access token ของ session ที่ยังเปิด
$before = $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn();
$newPassword = ['name' => 'modern', 'password' => 'Changed-Pass-9', 'repassword' => 'Changed-Pass-9'];
list($status) = $callApi('\Index\Auth\Controller', 'update', $newPassword, $member['refresh_token']);
check('auth/update ด้วย refresh token → 401', $status === 401, $status);
$auth::logoutAllSessions(3);
list($status) = $callApi('\Index\Auth\Controller', 'update', $newPassword, $member['token']);
check('auth/update ด้วย token ของ session ที่ปิดแล้ว → 401 และรหัสผ่านไม่เปลี่ยน', $status === 401
    && $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn() === $before, $status);
$e = $signIn();
$f = $signIn();
$hash3 = function () use ($pdo, $prefix) {
    return $pdo->query("SELECT `password` FROM `{$prefix}_user` WHERE `id` = 3")->fetchColumn();
};
list($status) = $callApi('\Index\Auth\Controller', 'update', $newPassword, $e['token']);
check('auth/update เปลี่ยนรหัสผ่านโดยไม่กรอกรหัสผ่านปัจจุบัน → 422 รหัสผ่านไม่เปลี่ยน', $status === 422 && $hash3() === $before, $status);
list($status) = $callApi('\Index\Auth\Controller', 'update', $newPassword + ['current_password' => 'Wrong-Pass-0'], $e['token']);
check('auth/update รหัสผ่านปัจจุบันผิด → 422 รหัสผ่านไม่เปลี่ยน', $status === 422 && $hash3() === $before, $status);
\Gcms\LoginAttempt::clear('modern@test.local', '127.0.0.1');
list($status, $body) = $callApi('\Index\Auth\Controller', 'update', $newPassword + ['current_password' => 'Modern-Pass-1'], $e['token']);
check('auth/update รหัสผ่านปัจจุบันถูก → เปลี่ยนแล้ว เครื่องนี้ยังอยู่ เครื่องอื่นหลุด', $status === 200 && $hash3() !== $before
    && $auth::getUserByToken($e['token']) !== null && $auth::getUserByToken($f['token']) === null, [$status, $body]);
$pdo->exec("UPDATE `{$prefix}_user` SET `password` = ".$pdo->quote($before)." WHERE `id` = 3");
// profile/save: ของตัวเองต้องกรอกรหัสผ่านปัจจุบัน · แอดมินตั้งให้คนอื่นไม่ต้อง · บัญชีโซเชียลไม่ต้อง
$profileForm = ['id' => 3, 'username' => 'modern@test.local', 'name' => 'modern', 'password' => 'Changed-Pass-9', 'repassword' => 'Changed-Pass-9'];
list($status, $body) = $callApi('\Index\Profile\Controller', 'save', $profileForm, $e['token']);
check('profile/save รหัสผ่านของตัวเองโดยไม่กรอกรหัสผ่านปัจจุบัน → ไม่บันทึก', $status === 400 && strpos($body, 'current_password') !== false && $hash3() === $before, [$status, $body]);
\Gcms\LoginAttempt::clear('modern@test.local', '127.0.0.1');
list($status, $body) = $callApi('\Index\Profile\Controller', 'save', $profileForm + ['current_password' => 'Modern-Pass-1'], $e['token']);
check('profile/save รหัสผ่านของตัวเอง + รหัสผ่านปัจจุบันถูก → บันทึก', $status === 200 && $hash3() !== $before, [$status, $body]);
$pdo->exec("UPDATE `{$prefix}_user` SET `password` = ".$pdo->quote($before)." WHERE `id` = 3");
$adminPassword();
$rootToken = $auth::authenticate('admin@test.local', 'Admin-Pass-1', '127.0.0.1', ['user_agent' => 'cli-testsecurity'])['token'] ?? '';
list($status, $body) = $callApi('\Index\Profile\Controller', 'save', $profileForm, $rootToken);
check('ผู้ดูแลตั้งรหัสผ่านให้สมาชิกอื่น → ไม่ต้องกรอกรหัสผ่านของสมาชิก', $status === 200 && $hash3() !== $before, [$status, $body]);
$pdo->exec("UPDATE `{$prefix}_user` SET `password` = ".$pdo->quote($before)." WHERE `id` = 3");
check('บัญชีโซเชียล (รหัสผ่านสุ่มที่เจ้าของไม่รู้) ไม่ต้องกรอกรหัสผ่านปัจจุบัน · บัญชีปกติต้องกรอก',
    !$auth::needsCurrentPassword((object) ['social' => 'google']) && $auth::needsCurrentPassword((object) ['social' => 'user']));
// ฟอร์มโปรไฟล์อยู่ต่างที่กันตามโปรเจ็ค (templates/ · admin/templates/ · app/templates/ · ธีม)
$profileForms = array_filter(array_merge(
    [$appRoot.'templates/profile.html', $appRoot.'admin/templates/profile.html', $appRoot.'app/templates/profile.html'],
    glob($appRoot.'themes/*/profile.html') ?: []
), function ($file) {
    return is_file($file) && preg_match('#(index/profile|index/adminprofile)/save#', (string) file_get_contents($file))
        && strpos((string) file_get_contents($file), 'name="password"') !== false;
});
$noField = array_values(array_filter($profileForms, function ($file) {
    return strpos((string) file_get_contents($file), 'name="current_password"') === false;
}));
check('ฟอร์มโปรไฟล์ทุกฟอร์มที่เปลี่ยนรหัสผ่านได้มีช่อง current_password', !empty($profileForms) && empty($noField), array_map('basename', array_map('dirname', $noField)));
// รหัสยืนยันอีเมลสุ่มจริง · ตัวจำกัดอัตราไม่เปิด session แบบไม่มี flag
$codes = [\Index\Register\Model::newActivationCode(), \Index\Register\Model::newActivationCode()];
check('รหัสยืนยันอีเมลเป็นค่าสุ่ม 32 ตัวฐาน 16 (ไม่ใช่ md5 ของชื่อ + เวลา)', preg_match('/^[0-9a-f]{32}$/', $codes[0]) === 1 && $codes[0] !== $codes[1]
    && !\Index\Forgot\Model::isLegacyResetHash($codes[0]));
$bare = [];
foreach (['modules/index/models/forgot.php', 'modules/index/models/register.php', 'modules/index/controllers/users.php'] as $file) {
    $source = (string) file_get_contents($appRoot.$file);
    if (preg_match('/^\s*session_start\(\);/m', $source) || preg_match('/md5\([^;]*uniqid\(\)/', $source)) {
        $bare[] = $file;
    }
}
check('ไม่มี session_start() เปล่า และรหัสจาก md5(uniqid) ในลืมรหัสผ่าน/สมัครสมาชิก/จัดการสมาชิก', empty($bare), $bare);
// handleException(): ปิด debug = ข้อผิดพลาดของระบบเป็นข้อความกลาง · เปิด debug = error จริง
// \Exception ที่โมดูลโยนเองยังส่งข้อความ (และ status 4xx ของมัน) เหมือนเดิม
$exceptionCases = 'return [
    "pdo" => new \PDOException("SQLSTATE[42S02]: Table app_user doesn\'t exist"),
    "db" => new \Kotchasan\Exception\DatabaseException("Failed to execute statement: SELECT * FROM app_user"),
    "error" => new \TypeError("Argument 1 passed in /var/www/x.php"),
    "plain" => new \Exception("Please select a file", 400),
    "plain0" => new \Exception("Please fill in"),
    "api" => new \Kotchasan\ApiException("Forbidden", 403)
];';
$probeSource = '<?php
$appRoot = $argv[1];
define("DEBUG", (int) $argv[2]);
define("ROOT_PATH", $appRoot);
set_error_handler(function ($errno, $message) {
    return strpos($message, "already defined") !== false;
}, E_WARNING);
include $appRoot."load.php";
restore_error_handler();
$probe = new class extends \Kotchasan\ApiController {
    public function answer(\Throwable $e)
    {
        $r = $this->handleException($e);
        $body = json_decode($r->getContent(), true);
        return [$r->getStatusCode(), (string) $body["message"], isset($body["trace"]), self::exceptionMessage($e)];
    }
    public function status($code)
    {
        return $this->errorResponse("x", $code)->getStatusCode();
    }
};
$out = [];
foreach ((function () { '.$exceptionCases.' })() as $k => $e) {
    $out[$k] = $probe->answer($e);
}
$out["status"] = [$probe->status("42S02"), $probe->status(23000), $probe->status(0), $probe->status(404)];
echo json_encode($out);
';
file_put_contents($scratch.'exception-probe.php', $probeSource);
$cleanup[] = function () use ($scratch) {
    @unlink($scratch.'exception-probe.php');
};
$answers = [];
foreach ([0, 1, 2] as $level) {
    $answers[$level] = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scratch.'exception-probe.php').' '.escapeshellarg($appRoot).' '.$level.' 2>/dev/null'), true) ?: [];
}
$hidden = function ($a) {
    return isset($a[1]) && $a[0] === 500 && $a[1] === 'Failed to process request';
};
check('ปิด debug: PDOException · DatabaseException · \Error → ข้อความกลาง (ไม่มี SQL / ชื่อตาราง / พาธ)',
    $hidden($answers[0]['pdo'] ?? null) && $hidden($answers[0]['db'] ?? null) && $hidden($answers[0]['error'] ?? null), $answers[0]);
check('ปิด debug: \Exception ที่โมดูลโยนเอง → ข้อความเดิม status ตาม code (ไม่มี code = 500) · ApiException ตาม code',
    array_slice($answers[0]['plain'] ?? [], 0, 3) === [400, 'Please select a file', false] && array_slice($answers[0]['plain0'] ?? [], 0, 3) === [500, 'Please fill in', false]
    && array_slice($answers[0]['api'] ?? [], 0, 3) === [403, 'Forbidden', false], $answers[0]);
check('exceptionMessage() (controller ที่กำหนด status เอง): ปิด debug ซ่อน PDO/ฐานข้อมูล/\Error คืนข้อความของ \Exception ที่โมดูลโยน',
    ($answers[0]['pdo'][3] ?? '') === 'Failed to process request' && ($answers[0]['db'][3] ?? '') === 'Failed to process request'
    && ($answers[0]['error'][3] ?? '') === 'Failed to process request' && ($answers[0]['plain'][3] ?? '') === 'Please select a file', $answers[0]);
check('exceptionMessage() เปิด debug: คืน error จริงพร้อมชนิด', strpos($answers[1]['pdo'][3] ?? '', 'PDOException: SQLSTATE[42S02]') === 0, $answers[1]);
check('errorResponse(): code ที่ไม่ใช่ HTTP status (SQLSTATE 42S02 · 23000 · 0) เป็น 500 · 404 คงเดิม', ($answers[0]['status'] ?? null) === [500, 500, 500, 404], $answers[0]['status'] ?? null);
check('เปิด debug (DEBUG = 1): ข้อผิดพลาดของระบบคืน error จริงพร้อมชนิด',
    strpos($answers[1]['pdo'][1] ?? '', 'PDOException: SQLSTATE[42S02]') === 0 && strpos($answers[1]['error'][1] ?? '', 'TypeError: ') === 0
    && strpos($answers[1]['db'][1] ?? '', 'Failed to execute statement') !== false, $answers[1]);
check('DEBUG = 2: แนบ trace ด้วย', !empty($answers[2]['pdo'][2]), $answers[2]);
// ข้อผิดพลาดของฐานข้อมูลไม่ออกไปถึงผู้เรียก: ในคำตอบใช้ self::exceptionMessage($e) หรือ handleException($e)
// ไม่ใช่ $e->getMessage() ตรง ๆ (ทั้งแบบข้อความล้วนและแบบต่อท้ายคำนำหน้า) · log เก็บข้อความจริงได้ตามเดิม
$leaks = [];
$sources = glob($appRoot.'modules/*/controllers/*.php') ?: [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot.'Gcms', FilesystemIterator::SKIP_DOTS)) as $item) {
    if ($item->isFile() && substr($item->getFilename(), -4) === '.php') {
        $sources[] = $item->getPathname();
    }
}
foreach ($sources as $file) {
    $source = (string) file_get_contents($file);
    // \RuntimeException ด้วย — \PDOException เป็นคลาสลูกของมัน
    if (preg_match('/catch \(\\\\(?:Exception|Throwable|RuntimeException) \$(\w+)\) \{[^}]*?\$this->(?:errorResponse|formErrorResponse|redirectResponse|validationErrorResponse)\([^;]*\$\1->getMessage\(\)/', $source)) {
        $leaks[] = substr($file, strlen($appRoot));
    }
}
check('controller และ Gcms ไม่ส่งข้อความของ exception ทั่วไป (SQL · ชื่อตาราง · พาธ) ออกไปเมื่อปิด debug — ใช้ exceptionMessage() / handleException()', empty($leaks), $leaks);
// ข้อความของ Kotchasan ที่ถึงผู้ใช้ (error ของการอัปโหลด · exceptionMessage()) ไม่มีพาธเต็มของเซิร์ฟเวอร์
$pathMessages = [];
foreach ([
    function () use ($appRoot) {
        @\Kotchasan\Image::loadImageResource($appRoot.'datas/no-such-dir/x.jpg');
    },
    function () use ($appRoot) {
        \Kotchasan\Template::createFromFile($appRoot.'templates/no-such-file.html');
    }
] as $run) {
    try {
        $run();
        $pathMessages[] = '(no exception)';
    } catch (\Throwable $e) {
        if (strpos($e->getMessage(), $appRoot) !== false || strpos($e->getMessage(), '/no-such') !== false) {
            $pathMessages[] = $e->getMessage();
        }
    }
}
check('ข้อความ exception ของ Image / Template มีแค่ชื่อไฟล์ ไม่มีพาธเต็ม', empty($pathMessages), $pathMessages);
$htaccess = is_file($appRoot.'.htaccess') ? (string) file_get_contents($appRoot.'.htaccess') : null;
check('.htaccess ไม่เสิร์ฟสคริปต์ใน datas/ (ไฟล์ที่ผู้ใช้อัปโหลด)', $htaccess === null
    || strpos($htaccess, 'RewriteRule ^datas/.+\.(php\d*|phtml|phar|pht|shtml|cgi|pl|py|sh)(\.|$) - [F,L,NC]') !== false);

// ---------------------------------------------------------------------------
echo "\n== U9 ลิงก์รีเซ็ตรหัสผ่านในอีเมล: ฐานจาก config web_url ไม่ใช่ header ของคำขอ ==\n";
// เส้นทางเดียวกับ auth.php (ลืมรหัสผ่าน) และ users.php (ส่งลิงก์ให้ผู้ใช้):
// refererBaseUrl($request) → Forgot::execute(..., $baseUrl) → อีเมล · mail() เขียนลงไฟล์แทนการส่ง
file_put_contents($u8.'/reset.php', '<?php
$p = json_decode((string) file_get_contents(__DIR__."/reset.json"), true);
define("ROOT_PATH", $p["appRoot"]);
define("APP_PATH", $p["scratch"]);
set_error_handler(function ($errno, $message) {
    return strpos($message, "already defined") !== false;
}, E_WARNING);
include ROOT_PATH."load.php";
restore_error_handler();
// เว็บหลายไซต์ (oms.in.th, profito, ...) เลือกไซต์จากโฮสต์ใน Gcms\\\\Config::init() และอ่าน settings/ ตัวจริง
// ชุดทดสอบใช้ฐานและ config ของตัวเอง จึงข้ามขั้นนั้น (Kotchasan เรียก init ของคลาสที่ส่งให้)
class SecTestConfig extends \\Gcms\\Config
{
    public static function init(\\Kotchasan\\Config $cfg)
    {
    }
}
Kotchasan::createWebApplication("SecTestConfig");
$request = \Kotchasan\Http\Request::createFromGlobals();
$base = \Gcms\Api::refererBaseUrl($request);
$err = \Index\Forgot\Model::execute($p["uid"], $p["email"], $base);
header("Content-Type: application/json");
echo json_encode(["base" => $base, "err" => $err]);
');
$cleanup[] = function () use ($u8) {
    @unlink($u8.'/reset.php');
    @unlink($u8.'/reset.json');
    @unlink($u8.'/mail.eml');
    @unlink($u8.'/mail.eml.sh');
};
$resetProbe = function ($webUrl, array $headers) use ($u8, $appRoot, $scratch, $config) {
    $cfg = $config;
    $cfg['noreply_email'] = 'noreply@test.local';
    $cfg['email_use_phpMailer'] = 0;
    // ตั้งเสมอ: Kotchasan รวม settings/config.php ของไซต์จริงเข้ากับของฐานทดสอบ — web_url ของไซต์จริงจะชนะถ้าไม่ตั้ง
    $cfg['web_url'] = $webUrl === null ? '' : $webUrl;
    file_put_contents($scratch.'settings/config.php', "<?php\nreturn ".var_export($cfg, true).";\n");
    file_put_contents($u8.'/reset.json', json_encode(['appRoot' => $appRoot, 'scratch' => $scratch, 'uid' => 3, 'email' => 'modern@test.local']));
    @unlink($u8.'/mail.eml');
    list($body, $port) = serveOnce($u8.'/reset.php', $appRoot, $headers, $u8.'/mail.eml');
    $out = json_decode($body, true) ?: ['body' => substr($body, 0, 300)];
    $out['port'] = $port;
    // เนื้ออีเมลเข้ารหัส base64 — ถอดทุกส่วนแล้วหาลิงก์รีเซ็ต
    $mail = (string) @file_get_contents($u8.'/mail.eml');
    $text = $mail;
    if (preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $mail, $parts)) {
        foreach ($parts[1] as $part) {
            $text .= base64_decode(preg_replace('/\s+/', '', $part));
        }
    }
    $out['link'] = preg_match('#(https?://[^"\'<>\s]*?)reset-password\?token=#', $text, $m) ? $m[1] : null;
    return $out;
};
$r = $resetProbe(null, ['X-Forwarded-Host: evil.example', 'X-Forwarded-Proto: https', 'Referer: https://evil.example/admin/forgot']);
check('ไม่ได้ตั้ง web_url: X-Forwarded-Host + Referer ของเว็บอื่น → ลิงก์ในอีเมลชี้เว็บนี้', $r['link'] === 'http://127.0.0.1:'.$r['port'].'/', $r);
$r = $resetProbe(null, ['Referer: http://127.0.0.1:{port}/admin/forgot']);
check('ไม่ได้ตั้ง web_url: Referer ใต้ WEB_URL → กลับไปหน้าแอดมิน (แบบเดิม)', $r['link'] === 'http://127.0.0.1:'.$r['port'].'/admin/', $r);
$r = $resetProbe(null, []);
check('ไม่ได้ตั้ง web_url และไม่มี Referer → ฐานคือ WEB_URL (แบบเดิม)', $r['link'] === 'http://127.0.0.1:'.$r['port'].'/', $r);
$site = 'https://site.test/app/';
$r = $resetProbe($site, ['Host: evil2.example', 'Referer: http://evil2.example/admin/forgot']);
check('ตั้ง web_url: header Host ปลอม → ลิงก์ใช้ web_url และยังกลับไปหน้าแอดมินตาม Referer', $r['link'] === $site.'admin/', $r);
$r = $resetProbe($site, ['Referer: https://site.test/app/admin/users']);
check('ตั้ง web_url: Referer ใต้ web_url → ฐาน + path ของ Referer', $r['link'] === $site.'admin/', $r);
$r = $resetProbe($site, ['Referer: https://evil.example/app/admin/']);
check('ตั้ง web_url: Referer ของเว็บอื่น → web_url', $r['link'] === $site, $r);
$r = $resetProbe('https://site.test/app', []);
check('web_url ที่ไม่ลงท้ายด้วย / ถูกเติมให้', $r['link'] === $site, $r);
$r = $resetProbe('javascript:alert(1)//', ['Referer: http://127.0.0.1/admin/forgot']);
check('web_url ที่ไม่ใช่ http(s) ไม่ถูกใช้ → WEB_URL', is_string($r['link']) && strpos($r['link'], 'http://127.0.0.1:'.$r['port'].'/') === 0, $r);
// คืน config ตั้งต้นของชุดทดสอบ
file_put_contents($scratch.'settings/config.php', "<?php\nreturn ".var_export($config, true).";\n");

// ---------------------------------------------------------------------------
echo "\n== U13 LINE login: callback แลก code เฉพาะคำขอที่เบราว์เซอร์นี้เริ่มเอง (state = cookie) ==\n";
// Kotchasan\Curl ปลอม (ประกาศก่อน autoload) — จดคำขอที่ callback ส่งไป LINE แทนการยิงจริง
file_put_contents($u8.'/fakecurl.php', '<?php
namespace Kotchasan;
class Curl
{
    public function setOptions($o) { return $this; }
    public function post($url, $params = [])
    {
        $log = __DIR__."/line-calls.json";
        $calls = is_file($log) ? json_decode(file_get_contents($log), true) : [];
        $calls[] = ["url" => $url, "params" => $params];
        file_put_contents($log, json_encode($calls));
        return strpos($url, "/token") !== false ? "{\"id_token\":\"fake\"}" : "{\"sub\":\"Usectest\",\"name\":\"sectest\"}";
    }
    public function get($url, $params = []) { return ""; }
    public function error() { return 0; }
    public function errorMessage() { return ""; }
    public function httpStatus() { return 200; }
}
');
file_put_contents($u8.'/line.php', '<?php
$p = json_decode((string) file_get_contents(__DIR__."/reset.json"), true);
define("ROOT_PATH", $p["appRoot"]);
define("APP_PATH", $p["scratch"]);
include __DIR__."/fakecurl.php";
set_error_handler(function ($errno, $message) {
    return strpos($message, "already defined") !== false;
}, E_WARNING);
include ROOT_PATH."load.php";
restore_error_handler();
class SecTestConfig extends \\Gcms\\Config
{
    public static function init(\\Kotchasan\\Config $cfg)
    {
    }
}
register_shutdown_function(function () {
    file_put_contents(__DIR__."/line-out.json", json_encode(headers_list()));
});
Kotchasan::createWebApplication("SecTestConfig");
(new \\Index\\Linecallback\\Controller())->index(\\Kotchasan\\Http\\Request::createFromGlobals());
');
$cleanup[] = function () use ($u8) {
    foreach (['fakecurl.php', 'line.php', 'line-calls.json', 'line-out.json'] as $f) {
        @unlink($u8.'/'.$f);
    }
};
$lineProbe = function ($state, $cookie) use ($u8, $appRoot) {
    @unlink($u8.'/line-calls.json');
    @unlink($u8.'/line-out.json');
    $headers = $cookie === null ? [] : ['Cookie: line_login_state='.$cookie];
    serveOnce($u8.'/line.php', $appRoot, $headers, null, '/?code=attacker-code&state='.rawurlencode($state), ['follow_location' => 0]);
    $calls = is_file($u8.'/line-calls.json') ? json_decode((string) file_get_contents($u8.'/line-calls.json'), true) : [];
    $sent = json_decode((string) @file_get_contents($u8.'/line-out.json'), true) ?: [];

    return [
        'calls' => $calls,
        'failed' => (bool) preg_grep('/^Set-Cookie: social_login=failed/i', $sent),
        'cleared' => (bool) preg_grep('/^Set-Cookie: line_login_state=(deleted|;)/i', $sent),
        'headers' => $sent
    ];
};
$nonce = bin2hex(random_bytes(16));
$target = base64_encode('http://127.0.0.1/');
$r = $lineProbe($nonce.'.'.$target, null);
check('ลิงก์ callback ที่มี code แต่เบราว์เซอร์ไม่มี cookie ของ state → ไม่แลก code', empty($r['calls']) && $r['failed'], $r);
$r = $lineProbe($nonce.'.'.$target, bin2hex(random_bytes(16)));
check('cookie ไม่ตรงกับ nonce ใน state → ไม่แลก code', empty($r['calls']) && $r['failed'], $r);
$r = $lineProbe($target, $target);
check('state แบบเก่า (base64 ของ URL อย่างเดียว) → ไม่แลก code', empty($r['calls']) && $r['failed'], $r);
$r = $lineProbe($nonce.'.'.$target, $nonce);
$verify = $r['calls'][1]['params'] ?? [];
check('state ตรงกับ cookie → แลก code และส่ง nonce เดียวกันไปตรวจ ID token', count($r['calls']) === 2 && ($verify['nonce'] ?? '') === $nonce, $r['calls']);
check('cookie ของ state ใช้ได้ครั้งเดียว (callback ลบทิ้ง)', $r['cleared'], $r['headers']);
$social = (string) file_get_contents($appRoot.'js/components/SocialLogin.js');
check('SocialLogin.js ส่ง state = nonce.URL และตั้ง cookie line_login_state ก่อนไป LINE', strpos($social, 'state: `${nonce}.${btoa(redirectAfterLogin)}`') !== false
    && strpos($social, "stateCookie: 'line_login_state'") !== false && strpos($social, 'crypto.getRandomValues') !== false);

// ---------------------------------------------------------------------------
echo "\n== U10 PDF ด้วย headless Chrome: หน้าพิมพ์อ่านไฟล์ในเครื่อง รันสคริปต์ หรือยิงเครือข่ายภายในไม่ได้ ==\n";
if (!is_file($appRoot.'modules/export/controllers/export.php') || !method_exists('\Export\Export\Controller', 'browserBinary') || \Export\Export\Controller::browserBinary() === null || !is_executable('/usr/bin/pdftotext')) {
    echo "  - ข้าม (ไม่มีโมดูล export, Chrome หรือ pdftotext)\n";
} else {
    // เครือข่ายภายใน = php -S ที่จดทุกคำขอลงไฟล์
    $internalDir = $u8.'/internal';
    @mkdir($internalDir, 0777, true);
    file_put_contents($internalDir.'/router.php', '<?php file_put_contents(__DIR__."/hits.log", $_SERVER["REQUEST_URI"]."\n", FILE_APPEND); http_response_code(204);');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $internalPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $internal = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$internalPort, $internalDir.'/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(500000);
    $secret = $u8.'/local-secret.txt';
    file_put_contents($secret, 'LOCALFILE-'.bin2hex(random_bytes(4)));
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>p</title></head><body><p>PRINTPAGE</p>'
        .'<iframe src="file://'.$secret.'" style="width:600px;height:80px"></iframe>'
        .'<img src="http://127.0.0.1:'.$internalPort.'/img" alt=""><link rel="stylesheet" href="http://127.0.0.1:'.$internalPort.'/css">'
        .'<script>document.body.insertAdjacentHTML("beforeend", "<p>SCRIPTRAN</p>"); fetch("http://127.0.0.1:'.$internalPort.'/fetch");</script>'
        .'</body></html>';
    $pdf = (string) \Export\Export\Controller::toPdf((new \Kotchasan\Http\Response())->withContent($html), 'probe')->getBody();
    usleep(300000);
    proc_terminate($internal);
    proc_close($internal);
    $text = '';
    if (strncmp($pdf, '%PDF', 4) === 0) {
        file_put_contents($u8.'/probe.pdf', $pdf);
        $text = (string) shell_exec('pdftotext '.escapeshellarg($u8.'/probe.pdf').' - 2>/dev/null');
    }
    $hits = trim((string) @file_get_contents($internalDir.'/hits.log'));
    check('ยังได้ PDF ของหน้าพิมพ์', strpos($text, 'PRINTPAGE') !== false, substr($pdf, 0, 40));
    check('<iframe src="file://..."> ไม่ได้อ่านไฟล์ในเครื่องลง PDF', strpos($text, (string) file_get_contents($secret)) === false);
    check('<script> ในหน้าพิมพ์ไม่ทำงาน', strpos($text, 'SCRIPTRAN') === false);
    check('ไม่มีคำขอไปโฮสต์อื่น (img, stylesheet, fetch)', $hits === '', $hits);
    $locked = \Export\Export\Controller::lockDocument('<html><head><meta charset="utf-8">');
    check('lockDocument() ใส่ CSP ไว้แรกสุดใน <head>', strpos($locked, '<html><head><meta http-equiv="Content-Security-Policy" content="default-src &#039;none&#039;;') === 0, $locked);
    @unlink($secret);
    @unlink($u8.'/probe.pdf');
    @unlink($internalDir.'/router.php');
    @unlink($internalDir.'/hits.log');
    @rmdir($internalDir);
}

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
