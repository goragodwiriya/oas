<?php
/**
 * install/cli-testdrivers.php — ติดตั้งและทดสอบระบบบนทุกฐานข้อมูลที่ชุดติดตั้งรองรับ
 *
 * "รองรับฐานข้อมูล X" ในโปรเจ็คนี้หมายถึงผ่านไฟล์นี้ทั้งหมด ไม่ใช่แค่ติดตั้งได้
 *
 * ทำอะไรบ้าง (ต่อหนึ่ง driver)
 *   1. ติดตั้งผ่าน "หน้าเว็บตัวติดตั้งจริง" (step0 → step4) บนสำเนาของโปรเจ็ค
 *      — ไม่แตะ settings/ และ datas/ ของโปรเจ็คนี้เลย
 *   2. เทียบสคีมาที่ได้กับสคีมาของ MySQL (ตาราง คอลัมน์ ชนิด NULL DEFAULT index)
 *      ไฟล์ core.<driver>.sql ที่ค่อย ๆ ต่างจาก core.sql จะถูกจับได้ที่นี่
 *   3. เรียก API ของแกน (modules/index) จริงผ่าน HTTP — เข้าระบบ สมาชิก ภาษา หมวดหมู่
 *      log ฯลฯ ทั้งกรณีสำเร็จและกรณีที่ต้องถูกปฏิเสธ แล้วตรวจว่าทำงานเหมือน MySQL
 *      (เช่น ชื่อผู้ใช้ไม่สนตัวพิมพ์ ค้นหาไม่สนตัวพิมพ์ ข้อความไทยไม่เพี้ยน)
 *
 * ใช้:  php install/cli-testdrivers.php [driver ...] [ตัวเลือก]
 *
 *   driver              mysql pgsql sqlite sqlsrv (ไม่ระบุ = ทุกชนิดที่ติดตั้งได้บนเครื่องนี้)
 *   --mysql=<conn>      บัญชีของเซิร์ฟเวอร์ (ไม่ระบุ = ใช้ settings/database.php ถ้าเป็น mysql)
 *   --pgsql=<conn>      หรือ env NOWTEST_PGSQL
 *   --sqlsrv=<conn>     หรือ env NOWTEST_SQLSRV
 *                       <conn> = user:password@host:port
 *   --keep              ไม่ลบฐานทดสอบและสำเนาโปรเจ็คเมื่อจบ (ไว้เปิดดูต่อ)
 *   --quiet             แสดงเฉพาะข้อที่ไม่ผ่านและผลสรุป
 *
 * driver ที่ไม่มีบัญชีให้ใช้จะถูกข้าม (ไม่นับว่าไม่ผ่าน) ส่วน sqlite ไม่ต้องใช้บัญชี
 * exit code : 0 = ผ่านทุก driver ที่ได้ทดสอบ, 1 = มีข้อที่ไม่ผ่าน
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

define('ROOT_PATH', str_replace(['\\', 'install/cli-testdrivers.php'], ['/', ''], __FILE__));
include_once ROOT_PATH.'install/common.php';
include_once ROOT_PATH.'install/db.php';

$ALL = array_keys(databaseDrivers());
$want = [];
$options = ['mysql' => '', 'pgsql' => '', 'sqlsrv' => '', 'keep' => false, 'quiet' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $match) && array_key_exists($match[1], $options)) {
        $options[$match[1]] = isset($match[2]) ? $match[2] : true;
    } elseif (in_array($arg, $ALL, true)) {
        $want[] = $arg;
    } else {
        fwrite(STDERR, "ไม่รู้จัก $arg (driver: ".implode(' ', $ALL).")\n");
        exit(1);
    }
}
$installable = installableDrivers();
if (empty($want)) {
    $want = array_keys($installable);
}
// MySQL ต้องมาก่อนเสมอ — เป็นสคีมาอ้างอิงที่ driver อื่นต้องตรงกับมัน
usort($want, function ($a, $b) {
    return ($a === 'mysql' ? 0 : 1) - ($b === 'mysql' ? 0 : 1);
});

$QUIET = $options['quiet'] === true;
$DBNAME = 'nowtest_drivers';
$PREFIX = 'app';
$ADMIN = ['admin@localhost', 'Admin#1234'];

// =============================================================================
// สำเนาโปรเจ็ค + เว็บเซิร์ฟเวอร์ของ PHP
// =============================================================================
$work = sys_get_temp_dir().'/nowjs-testdrivers-'.md5(ROOT_PATH);
removeTree($work);
copyTree(rtrim(ROOT_PATH, '/'), $work);
foreach (['settings', 'datas', 'datas/cache', 'datas/logs', 'datas/images'] as $dir) {
    if (!is_dir($work.'/'.$dir)) {
        mkdir($work.'/'.$dir, 0777, true);
    }
}
$port = freePort();
$server = proc_open(
    [PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:'.$port, '-t', $work],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $work.'/server.log', 'w']],
    $pipes,
    $work
);
$BASE = 'http://127.0.0.1:'.$port;
for ($i = 0; $i < 50 && @file_get_contents($BASE.'/install/index.php') === false; $i++) {
    usleep(100000);
}
register_shutdown_function(function () use ($server, $work, $options) {
    proc_terminate($server);
    if ($options['keep'] !== true) {
        removeTree($work);
    }
});

// =============================================================================
// ทดสอบทีละ driver
// =============================================================================
$results = [];
$reference = null;
foreach ($want as $driver) {
    echo str_repeat('=', 70)."\n".databaseDrivers()[$driver]." ($driver)\n";
    if (!isset($installable[$driver])) {
        $missing = missingSchemaFiles($driver);
        echo '[skip] '.(empty($missing) ? 'PHP ไม่มี PDO driver '.$driver : 'ไม่มีไฟล์สคีมา: '.implode(', ', $missing))."\n";
        $results[$driver] = 'skip';
        continue;
    }
    $conn = driverConnection($driver, $options);
    if ($conn === null) {
        echo "[skip] ไม่มีบัญชีของเซิร์ฟเวอร์ (ใช้ --$driver=user:password@host:port หรือ env NOWTEST_".strtoupper($driver).")\n";
        $results[$driver] = 'skip';
        continue;
    }
    $t = new Tally($QUIET);
    $log_offset = (int) @filesize($work.'/server.log');
    try {
        dropTestDatabase($driver, $conn, $DBNAME, $work);
        @unlink($work.'/settings/config.php');
        @unlink($work.'/settings/database.php');

        // 1. ติดตั้งผ่านหน้าเว็บ
        installThroughWeb($t, $BASE, $driver, $conn, $DBNAME, $PREFIX, $ADMIN);
        if ($t->failed()) {
            $results[$driver] = false;
            continue;
        }
        $settings = include $work.'/settings/database.php';
        $db = new Db($settings['mysql']);

        // 2. สคีมา
        $schema = describeSchema($db, $PREFIX);
        if ($driver === 'mysql') {
            $reference = $schema;
            $t->ok('สคีมาอ้างอิง '.count($schema).' ตาราง');
        } elseif ($reference === null) {
            $t->note('ไม่ได้เทียบสคีมา — ต้องทดสอบ mysql ในรอบเดียวกันด้วย');
        } else {
            $diff = compareSchema($reference, $schema);
            $t->check(empty($diff), 'สคีมาตรงกับ MySQL ทุกตาราง ('.count($schema).' ตาราง)', $diff);
        }

        // 3. API ของแกน
        apiTests($t, $BASE, $db, $PREFIX, $ADMIN, $work);
    } catch (\Throwable $e) {
        $t->fail('หยุดกลางทาง: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
    }
    $results[$driver] = !$t->failed();
    if ($t->failed()) {
        // ข้อผิดพลาดฝั่งเซิร์ฟเวอร์ระหว่างทดสอบ driver นี้ (API ตอบแค่ "Failed to process request")
        clearstatcache();
        $log = (string) @file_get_contents($work.'/server.log', false, null, $log_offset);
        if (preg_match_all('/\[ERROR\][^\n]*?Exception: ([^\n]*?) \{"request_id"[^\n]*?"request_uri":"([^"]*)"/', $log, $m, PREG_SET_ORDER)) {
            echo "  ข้อผิดพลาดฝั่งเซิร์ฟเวอร์:\n";
            foreach ($m as $e) {
                echo '    '.stripslashes($e[2]).': '.mb_substr($e[1], 0, 300)."\n";
            }
        }
    }
    echo $t->summary()."\n";
    if ($options['keep'] !== true) {
        dropTestDatabase($driver, $conn, $DBNAME, $work);
    }
}

echo str_repeat('=', 70)."\n";
$failed = false;
foreach ($results as $driver => $result) {
    echo ($result === 'skip' ? '[skip] ' : ($result ? '[ok]   ' : '[FAIL] ')).$driver."\n";
    $failed = $failed || $result === false;
}
if ($options['keep'] === true) {
    echo "สำเนาโปรเจ็ค: $work (ฐาน $DBNAME)\n";
}
exit($failed ? 1 : 0);

// =============================================================================
// ตัวช่วย
// =============================================================================

/**
 * นับผลและพิมพ์ทีละข้อ
 */
class Tally
{
    private $quiet;
    private $pass = 0;
    private $fail = 0;

    public function __construct($quiet)
    {
        $this->quiet = $quiet;
    }

    public function ok($text)
    {
        $this->pass++;
        if (!$this->quiet) {
            echo "  [ok]   $text\n";
        }
    }

    public function fail($text, $detail = [])
    {
        $this->fail++;
        echo "  [FAIL] $text\n";
        foreach ((array) $detail as $line) {
            echo '         '.(is_string($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE))."\n";
        }
    }

    public function note($text)
    {
        if (!$this->quiet) {
            echo "         $text\n";
        }
    }

    public function check($condition, $text, $detail = [])
    {
        $condition ? $this->ok($text) : $this->fail($text, $detail);

        return $condition;
    }

    public function failed()
    {
        return $this->fail > 0;
    }

    public function summary()
    {
        return '  ผ่าน '.$this->pass.' / ไม่ผ่าน '.$this->fail;
    }
}

/**
 * HTTP client ที่จำ cookie เอง (cookie ของระบบตั้ง Secure ไว้ ตัว cookie engine ของ curl
 * จึงไม่ส่งกลับมาทาง http)
 */
class Client
{
    public $cookies = [];
    public $csrf = '';
    public $status = 0;
    private $base;

    public function __construct($base)
    {
        $this->base = $base;
    }

    public function request($method, $path, array $data = [], array $headers = [])
    {
        $url = $this->base.$path;
        $ch = curl_init();
        if ($method === 'GET' && !empty($data)) {
            $url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($data);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        if ($this->csrf !== '') {
            $headers[] = 'X-CSRF-TOKEN: '.$this->csrf;
        }
        if (!empty($this->cookies)) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k.'='.$v;
            }
            $headers[] = 'Cookie: '.implode('; ', $pairs);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60
        ]);
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (preg_match_all('/^Set-Cookie:\s*([^=;]+)=([^;]*)/mi', substr($raw, 0, $size), $m, PREG_SET_ORDER)) {
            foreach ($m as $c) {
                if ($c[2] === '' || $c[2] === 'deleted') {
                    unset($this->cookies[$c[1]]);
                } else {
                    $this->cookies[$c[1]] = $c[2];
                }
            }
        }

        return substr($raw, $size);
    }

    public function api($method, $path, array $data = [])
    {
        $body = $this->request($method, '/api.php/'.$path, $data);
        $json = json_decode($body, true);

        return is_array($json) ? $json : ['success' => false, 'message' => 'ไม่ใช่ JSON: '.mb_substr(trim(strip_tags($body)), 0, 200)];
    }

    public function refreshCsrf()
    {
        $this->csrf = '';
        $json = $this->api('GET', 'index/auth/csrf-token');
        $this->csrf = (string) ($json['data']['csrf_token'] ?? '');

        return $this->csrf;
    }
}

function copyTree($src, $dst)
{
    $skip = ['/node_modules', '/.git', '/datas', '/settings'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $it->setMaxDepth(-1);
    mkdir($dst, 0777, true);
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($src));
        foreach ($skip as $s) {
            if ($rel === $s || strpos($rel, $s.'/') === 0) {
                continue 2;
            }
        }
        if ($item->isDir()) {
            @mkdir($dst.$rel, 0777, true);
        } else {
            copy($item->getPathname(), $dst.$rel);
        }
    }
}

function removeTree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function freePort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * บัญชีของเซิร์ฟเวอร์ฐานข้อมูล [username, password, hostname, port]
 *
 * @return array|null null = ไม่มีบัญชีให้ใช้
 */
function driverConnection($driver, array $options)
{
    if ($driver === 'sqlite') {
        return ['', '', '', ''];
    }
    $spec = (string) $options[$driver];
    if ($spec === '' && getenv('NOWTEST_'.strtoupper($driver))) {
        $spec = getenv('NOWTEST_'.strtoupper($driver));
    }
    if ($spec === '' && $driver === 'mysql' && is_file(ROOT_PATH.'settings/database.php')) {
        $cfg = include ROOT_PATH.'settings/database.php';
        $cfg = $cfg['mysql'];
        if (($cfg['dbdriver'] ?? 'mysql') === 'mysql') {
            return [$cfg['username'], $cfg['password'], $cfg['hostname'] ?? 'localhost', $cfg['port'] ?? ''];
        }
    }
    if ($spec === '') {
        return null;
    }
    $at = strrpos($spec, '@');
    $auth = $at === false ? $spec : substr($spec, 0, $at);
    $host = $at === false ? 'localhost' : substr($spec, $at + 1);
    list($user, $pass) = array_pad(explode(':', $auth, 2), 2, '');
    list($host, $port) = array_pad(explode(':', $host, 2), 2, '');

    return [$user, $pass, $host === '' ? 'localhost' : $host, $port];
}

function dropTestDatabase($driver, array $conn, $dbname, $work)
{
    if ($driver === 'sqlite') {
        @unlink($work.'/datas/'.$dbname.'.sqlite');

        return;
    }
    $cfg = ['dbdriver' => $driver, 'username' => $conn[0], 'password' => $conn[1], 'hostname' => $conn[2], 'port' => $conn[3]];
    if ($driver === 'pgsql') {
        $db = new Db($cfg + ['dbname' => 'postgres']);
        $db->query('DROP DATABASE IF EXISTS "'.$dbname.'" WITH (FORCE)');
    } elseif ($driver === 'sqlsrv') {
        $db = new Db($cfg + ['dbname' => 'master', 'trust_server_certificate' => true]);
        if ($db->databaseExists($dbname)) {
            $db->query('ALTER DATABASE ['.$dbname.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            $db->query('DROP DATABASE ['.$dbname.']');
        }
    } else {
        $db = new Db($cfg + ['dbname' => 'INFORMATION_SCHEMA']);
        $db->query('DROP DATABASE IF EXISTS `'.$dbname.'`');
    }
}

/**
 * เดินหน้าเว็บตัวติดตั้งทุกขั้นตอนเหมือนผู้ใช้จริง
 */
function installThroughWeb(Tally $t, $base, $driver, array $conn, $dbname, $prefix, array $admin)
{
    $web = new Client($base);
    $html = $web->request('GET', '/install/index.php');
    if (!$t->check(strpos($html, 'ติดตั้ง !') !== false && preg_match('/PDO Support <b>[^<]*'.preg_quote(databaseDrivers()[$driver], '/').'/', $html), 'step0 เซิร์ฟเวอร์พร้อม และเสนอ '.databaseDrivers()[$driver])) {
        return;
    }
    $html = $web->request('GET', '/install/index.php?step=1');
    $t->check(strpos($html, 'ดำเนินการต่อ') !== false, 'step1 โฟลเดอร์เขียนได้');
    $html = $web->request('POST', '/install/index.php', ['step' => 3, 'username' => $admin[0], 'password' => $admin[1]]);
    if (!$t->check(preg_match('/name=dbdriver[^>]*value="'.$driver.'"|<option value="'.$driver.'"/', $html), 'step3 เลือก '.$driver.' ได้')) {
        return;
    }
    $html = $web->request('POST', '/install/index.php', [
        'step' => 4,
        'dbdriver' => $driver,
        'db_username' => $conn[0],
        'db_password' => $conn[1],
        'db_server' => $conn[2],
        'db_port' => $conn[3],
        'db_name' => $dbname,
        'prefix' => $prefix
    ]);
    $red = [];
    if (preg_match_all('/<li class="?incorrect"?>(.*?)<\/li>/s', $html, $m)) {
        $red = array_map('strip_tags', $m[1]);
    }
    if (strpos($html, 'ติดตั้งเรียบร้อย') === false && empty($red)) {
        $red[] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 400);
    }
    $t->check(strpos($html, 'ติดตั้งเรียบร้อย') !== false && empty($red), 'step4 ติดตั้งเรียบร้อย ไม่มีรายการสีแดง', $red);
}

/**
 * การทดสอบ API ของแกน — ทุกข้อต้องให้ผลเหมือนกันทุกฐานข้อมูล
 */
function apiTests(Tally $t, $base, Db $db, $prefix, array $admin, $work)
{
    $web = new Client($base);
    $q = function ($name) use ($db, $prefix) {
        return $db->quote($prefix.'_'.$name);
    };
    $count = function ($table, $where = '', $values = null) use ($db, $q) {
        $rows = $db->customQuery('SELECT COUNT(*) AS c FROM '.$q($table).($where === '' ? '' : ' WHERE '.$where), false, $values);

        return (int) $rows[0]->c;
    };

    // ----- ระบบ
    $r = $web->api('GET', 'index/system/health');
    $t->check(!empty($r['success']), 'system/health', $r['message'] ?? '');

    // ----- เข้าระบบ
    $t->check($web->refreshCsrf() !== '', 'auth/csrf-token');
    $before = $count('login_attempt');
    $r = $web->api('POST', 'index/auth/login', ['username' => $admin[0], 'password' => 'wrong-password']);
    $t->check(empty($r['success']) && $count('login_attempt') > $before, 'login รหัสผิด → ถูกปฏิเสธ และบันทึก login_attempt', $r['message'] ?? '');
    $r = $web->api('POST', 'index/auth/login', ['username' => strtoupper($admin[0]), 'password' => $admin[1]]);
    $t->check(!empty($r['success']), 'login ด้วยชื่อผู้ใช้ตัวพิมพ์ใหญ่ (ไม่สนตัวพิมพ์เหมือน MySQL)', $r['message'] ?? '');
    $web->cookies = [];
    $web->refreshCsrf();
    $r = $web->api('POST', 'index/auth/login', ['username' => $admin[0], 'password' => $admin[1]]);
    if (!$t->check(!empty($r['success']) && isset($web->cookies['auth_token']), 'login สำเร็จ ได้ auth_token', $r['message'] ?? '')) {
        return;
    }
    $t->check($count('user_session', $db->quote('member_id').' = 1') >= 1, 'user_session บันทึกเซสชันแล้ว');
    $r = $web->api('GET', 'index/auth/me');
    $t->check(!empty($r['success']) && (int) ($r['data']['id'] ?? 0) === 1, 'auth/me = ผู้ดูแลสูงสุด', $r['message'] ?? '');
    $r = $web->api('POST', 'index/auth/refresh');
    $t->check(!empty($r['success']), 'auth/refresh (upsert เซสชันเดิม)', $r['message'] ?? '');
    $web->refreshCsrf();

    // ----- สมาชิก
    // นับแบบก่อน/หลัง — ข้อมูลตั้งต้นของแต่ละโปรเจ็ค (สมาชิก แผนก) ไม่เท่ากัน
    $users = $count('user');
    $inDepartment = function ($value) use ($count, $db) {
        return $count('user_meta', $db->quote('name')." = 'department' AND ".$db->quote('value')." = '$value'");
    };
    $department = $inDepartment('2');
    $r = $web->api('GET', 'index/profile/get', ['id' => 1]);
    $t->check(!empty($r['success']) && ($r['data']['data']['username'] ?? $r['data']['username'] ?? '') === $admin[0], 'profile/get id=1', $r['message'] ?? '');
    $member = [
        'id' => 0,
        'username' => 'member1@localhost',
        'name' => 'Tester สมาชิก 😀',
        'password' => 'Member#1234',
        'repassword' => 'Member#1234',
        'status' => 0,
        'active' => 1,
        'phone' => '0812345678',
        'birthday' => '2000-01-31',
        'department' => '2'
    ];
    $r = $web->api('POST', 'index/profile/save', $member);
    $row = $db->first($prefix.'_user', ['username' => 'member1@localhost']);
    $t->check(!empty($r['success']) && $row && $row->name === 'Tester สมาชิก 😀', 'profile/save สร้างสมาชิกใหม่ (ไทย + อีโมจิ ไม่เพี้ยน)', $r['errors'] ?? ($r['message'] ?? ''));
    $member_id = $row ? (int) $row->id : 0;
    $t->check($member_id > 1, 'id ของสมาชิกใหม่ต่อจาก 1 (sequence ไม่ชนกับ id ที่ตัวติดตั้งใส่เอง)', 'id='.$member_id);
    $r = $web->api('POST', 'index/profile/save', ['username' => 'MEMBER1@localhost', 'phone' => '0899999999'] + $member);
    $t->check(empty($r['success']) && $count('user') === $users + 1, 'ชื่อผู้ใช้ซ้ำ (ต่างตัวพิมพ์) → ถูกปฏิเสธ', $r['message'] ?? '');
    $r = $web->api('POST', 'index/profile/save', ['username' => 'member2@localhost', 'phone' => '0812345678'] + $member);
    $t->check(empty($r['success']) && $count('user') === $users + 1, 'เบอร์โทรซ้ำ → ถูกปฏิเสธ', $r['message'] ?? '');
    $r = $web->api('POST', 'index/profile/save', ['id' => $member_id, 'name' => 'Tester แก้ไข', 'password' => '', 'repassword' => ''] + $member);
    $row = $db->first($prefix.'_user', ['id' => $member_id]);
    $t->check(!empty($r['success']) && $row && $row->name === 'Tester แก้ไข', 'profile/save แก้ไขสมาชิก', $r['errors'] ?? ($r['message'] ?? ''));
    $r = $web->api('GET', 'index/users', ['search' => 'แก้ไข']);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) === 1, 'users ค้นหาภาษาไทย', $r['message'] ?? '');
    $r = $web->api('GET', 'index/users', ['search' => 'MEMBER1']);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) === 1, 'users ค้นหาชื่อผู้ใช้ไม่สนตัวพิมพ์ (เหมือน MySQL)', $r['message'] ?? '');
    $r = $web->api('GET', 'index/users', ['search' => 'tESTER']);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) === 1, 'users ค้นหาชื่อไม่สนตัวพิมพ์ (เหมือน MySQL)', $r['message'] ?? '');
    $r = $web->api('GET', 'index/users', ['sort' => 'name desc', 'page' => 1, 'pageSize' => 1]);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) === 1 && (int) ($r['data']['meta']['total'] ?? 0) === $count('user'), 'users เรียงลำดับ + แบ่งหน้า', $r['message'] ?? '');
    $r = $web->api('GET', 'index/users', ['department' => '2']);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) === $department + 1 && $inDepartment('2') === $department + 1, 'users กรองแผนก (user_meta)', $r['message'] ?? '');
    // action active สลับสถานะ (ระงับ ↔ เปิดใช้) ของสมาชิกหนึ่งคนด้วย id
    $r = $web->api('POST', 'index/users/action', ['action' => 'active', 'id' => $member_id]);
    $row = $db->first($prefix.'_user', ['id' => $member_id]);
    $t->check(!empty($r['success']) && $row && (int) $row->active === 0, 'users/action ระงับสมาชิก', $r['message'] ?? '');
    $r = $web->api('POST', 'index/users/action', ['action' => 'active', 'id' => $member_id]);
    $row = $db->first($prefix.'_user', ['id' => $member_id]);
    $t->check(!empty($r['success']) && $row && (int) $row->active === 1, 'users/action เปิดใช้สมาชิก', $r['message'] ?? '');

    // ----- สมาชิกใหม่เข้าระบบได้ (meta ผ่าน GROUP_CONCAT)
    $other = new Client($base);
    $other->refreshCsrf();
    $r = $other->api('POST', 'index/auth/login', ['username' => 'member1@localhost', 'password' => 'Member#1234']);
    $t->check(!empty($r['success']), 'สมาชิกที่สร้างใหม่เข้าระบบได้', $r['message'] ?? '');
    $r = $other->api('GET', 'index/auth/me');
    $t->check(!empty($r['success']) && (int) ($r['data']['id'] ?? 0) === $member_id, 'auth/me ของสมาชิกใหม่', $r['message'] ?? '');

    // ----- หมวดหมู่
    // บันทึกแผนกที่โปรเจ็คมีอยู่กลับไปพร้อมแผนกใหม่หนึ่งรายการ (ข้อมูลตั้งต้นแต่ละโปรเจ็คไม่เหมือนกัน)
    // แผนกแบบหลายภาษา (category_id เดียวกันหลายแถวตาม language) ฟอร์มหมวดหมู่ของแกนไม่รองรับ — ข้าม
    $multilingual = $count('category', $db->quote('type')." = 'department' AND ".$db->quote('language')." <> ''") > 0;
    $ids = [];
    $topics = [];
    foreach ($db->customQuery('SELECT '.$db->quote('category_id').' AS id, '.$db->quote('topic').' AS topic FROM '.$q('category')
        .' WHERE '.$db->quote('type')." = 'department' ORDER BY ".$db->quote('category_id')) as $item) {
        $ids[] = (string) $item->id;
        $topics[] = $item->topic;
    }
    if ($multilingual) {
        $t->note('ข้าม category/save — แผนกของโปรเจ็คนี้เก็บแบบหลายภาษา');
    } else {
        $before = count($ids);
        $ids[] = (string) (max(array_map('intval', $ids ?: ['0'])) + 1);
        $topics[] = 'บัญชี ทดสอบ';
        $r = $web->api('POST', 'index/category/save', ['type' => 'department', 'id' => $ids, 'topic' => $topics]);
        $t->check(!empty($r['success']) && $count('category', $db->quote('type')." = 'department'") === $before + 1, 'category/save เพิ่มแผนก (ภาษาไทย)', $r['message'] ?? '');
        $r = $web->api('GET', 'index/category/get', ['type' => 'department']);
        $t->check(!empty($r['success']) && strpos(json_encode($r['data'], JSON_UNESCAPED_UNICODE), 'บัญชี ทดสอบ') !== false, 'category/get แผนก', $r['message'] ?? '');
    }

    // ----- ภาษา
    $r = $web->api('GET', 'index/languages', ['search' => 'Save']);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) > 0, 'languages ค้นหา', $r['message'] ?? '');
    $r = $web->api('POST', 'index/language/save', ['id' => 0, 'language_type' => 'text', 'language_key' => 'Driver test key', 'th' => ['ทดสอบฐานข้อมูล'], 'en' => ['']]);
    $row = $db->first($prefix.'_language', ['key' => 'Driver test key']);
    $t->check(!empty($r['success']) && $row && $row->th === 'ทดสอบฐานข้อมูล', 'language/save เพิ่มคีย์', $r['errors'] ?? ($r['message'] ?? ''));
    $r = $web->api('POST', 'index/language/save', ['id' => 0, 'language_type' => 'text', 'language_key' => 'DRIVER TEST KEY', 'th' => ['x'], 'en' => ['']]);
    $t->check(empty($r['success']), 'language/save คีย์ซ้ำต่างตัวพิมพ์ → ถูกปฏิเสธ', $r['message'] ?? '');
    if ($row) {
        $r = $web->api('POST', 'index/languages/action', ['action' => 'delete', 'ids' => [$row->id]]);
        $t->check(!empty($r['success']) && !$db->first($prefix.'_language', ['id' => $row->id]), 'languages/action ลบคีย์', $r['message'] ?? '');
    }

    // ----- นำเข้าภาษาใหม่จากไฟล์ = เพิ่มคอลัมน์ภาษาให้ตาราง language (ALTER TABLE ของแต่ละฐาน)
    file_put_contents($work.'/language/la.json', json_encode(['Save' => 'ບັນທຶກ'], JSON_UNESCAPED_UNICODE));
    $r = $web->api('POST', 'index/languages/action', ['action' => 'import']);
    $row = $db->first($prefix.'_language', ['key' => 'Save']);
    $t->check(!empty($r['success']) && $row && isset($row->la) && $row->la === 'ບັນທຶກ', 'languages/action import เพิ่มภาษาใหม่ (คอลัมน์ la)', $r['message'] ?? '');
    foreach (['json', 'php', 'js'] as $ext) {
        @unlink($work.'/language/la.'.$ext);
    }

    // ----- ตารางอื่น ๆ ของแกน
    foreach (['usage' => [], 'loginattempts' => [], 'permission' => []] as $table => $params) {
        $r = $web->api('GET', 'index/'.$table, $params);
        $t->check(!empty($r['success']), $table.' (ตาราง)', $r['message'] ?? '');
    }
    $r = $web->api('GET', 'index/usage', ['from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d')]);
    $t->check(!empty($r['success']) && count($r['data']['data'] ?? []) > 0, 'usage กรองช่วงวันที่ (logs)', $r['message'] ?? '');
    $r = $web->api('GET', 'index/dashboard');
    $t->check(!empty($r['success']), 'dashboard', $r['message'] ?? '');

    // ----- คอลัมน์ NOT NULL ที่ไม่มี DEFAULT ซึ่งโมดูลอื่นเพิ่มให้ตาราง user
    // (เช่นกะทำงานของระบบลา) ต้องไม่ขวางการเพิ่มสมาชิกของแกน — Index\UserRepository เติมค่าว่างให้
    $user = $q('user');
    $driver = $db->driver();
    if ($driver === 'pgsql') {
        $db->query("ALTER TABLE $user ADD COLUMN \"shift\" varchar(10) NOT NULL DEFAULT ''");
        $db->query("ALTER TABLE $user ALTER COLUMN \"shift\" DROP DEFAULT");
        $db->query("ALTER TABLE $user ADD COLUMN \"checked_at\" timestamp(0) NOT NULL DEFAULT now()");
        $db->query("ALTER TABLE $user ALTER COLUMN \"checked_at\" DROP DEFAULT");
    } elseif ($driver === 'sqlite') {
        // SQLite เพิ่ม NOT NULL ได้เฉพาะเมื่อมี DEFAULT และลบ DEFAULT ทีหลังไม่ได้
        $db->query("ALTER TABLE $user ADD COLUMN \"shift\" varchar(10) NOT NULL DEFAULT ''");
        $db->query("ALTER TABLE $user ADD COLUMN \"checked_at\" datetime NOT NULL DEFAULT '2000-01-01 00:00:00'");
    } else {
        $db->query("ALTER TABLE $user ADD `shift` varchar(10) NOT NULL");
        $db->query("ALTER TABLE $user ADD `checked_at` datetime NOT NULL");
    }
    $r = $web->api('POST', 'index/profile/save', ['username' => 'member2@localhost', 'phone' => '0822222222'] + $member);
    $row = $db->first($prefix.'_user', ['username' => 'member2@localhost']);
    $t->check(!empty($r['success']) && $row && $row->shift === '', 'เพิ่มสมาชิกได้แม้โมดูลเพิ่มคอลัมน์ NOT NULL ที่ไม่มี DEFAULT ให้ตาราง user', $r['errors'] ?? ($r['message'] ?? ''));
    if ($row) {
        $web->api('POST', 'index/users/action', ['action' => 'delete', 'ids' => [$row->id]]);
    }
    foreach (['shift', 'checked_at'] as $column) {
        $db->query("ALTER TABLE $user DROP COLUMN ".$db->quote($column));
    }

    // ----- ลบสมาชิก + ออกจากระบบ
    $r = $web->api('POST', 'index/users/action', ['action' => 'delete', 'ids' => [$member_id]]);
    $t->check(!empty($r['success']) && $count('user') === $users, 'users/action ลบสมาชิก', $r['message'] ?? '');
    $sessions = $count('user_session', $db->quote('member_id').' = 1');
    $r = $web->api('POST', 'index/auth/logout');
    $t->check(!empty($r['success']), 'auth/logout', $r['message'] ?? '');
    // auth/me ตอบได้แม้ยังไม่เข้าระบบ (ข้อมูลผู้เยี่ยมชม) ต้องตรวจด้วย auth/verify
    $r = $web->api('GET', 'index/auth/verify');
    $t->check(empty($r['success']) && $count('user_session', $db->quote('member_id').' = 1') === $sessions - 1, 'หลัง logout token ใช้ไม่ได้และเซสชันของเครื่องนี้ถูกลบ', $r['message'] ?? '');
}

// =============================================================================
// อ่านและเทียบสคีมา
// =============================================================================

/**
 * สคีมาของทุกตารางที่ขึ้นต้นด้วย prefix ในรูปที่เทียบข้ามฐานได้
 *
 * @return array [ตาราง => ['columns' => [ชื่อ => ข้อความ], 'pk' => '...', 'indexes' => [ชื่อ => ข้อความ]]]
 */
function describeSchema(Db $db, $prefix)
{
    $driver = $db->driver();
    $schema = [];
    foreach ($db->tables() as $table) {
        if (strpos($table, $prefix.'_') !== 0) {
            continue;
        }
        $columns = [];
        $pk = [];
        $indexes = [];
        if ($driver === 'mysql') {
            foreach ($db->customQuery('SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', false, [$table]) as $c) {
                $type = strtolower($c->COLUMN_TYPE);
                if (strpos($type, 'enum(') === 0) {
                    preg_match_all("/'([^']*)'/", $type, $m);
                    $type = 'varchar('.max(array_map('strlen', $m[1])).')';
                }
                $columns[$c->COLUMN_NAME] = [$type, $c->IS_NULLABLE === 'YES', $c->COLUMN_DEFAULT, stripos($c->EXTRA, 'auto_increment') !== false];
            }
            foreach ($db->customQuery('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', false, [$table]) as $i) {
                if ($i->INDEX_NAME === 'PRIMARY') {
                    $pk[] = $i->COLUMN_NAME;
                } else {
                    $indexes[$i->INDEX_NAME]['unique'] = (int) $i->NON_UNIQUE === 0;
                    $indexes[$i->INDEX_NAME]['cols'][] = $i->COLUMN_NAME;
                }
            }
        } elseif ($driver === 'pgsql') {
            // citext + CHECK (char_length(x) <= n) คือ varchar(n) ที่ไม่สนตัวพิมพ์ (แบบ _ci ของ MySQL)
            $lengths = [];
            foreach ($db->customQuery("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE contype = 'c' AND conrelid = (quote_ident(current_schema()) || '.' || quote_ident(?))::regclass", false, [$table]) as $check) {
                if (preg_match('/char_length\(\(?"?(\w+)"?\)?(?:::text)?\)\s*<=\s*(\d+)/', $check->def, $m)) {
                    $lengths[$m[1]] = $m[2];
                }
            }
            foreach ($db->customQuery('SELECT * FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', false, [$table]) as $c) {
                $type = $c->data_type;
                if ($c->udt_name === 'citext' && isset($lengths[$c->column_name])) {
                    $type = 'varchar('.$lengths[$c->column_name].')';
                } elseif ($c->character_maximum_length !== null) {
                    $type = ($type === 'character' ? 'char' : 'varchar').'('.$c->character_maximum_length.')';
                } elseif ($c->data_type === 'numeric') {
                    $type = 'decimal('.$c->numeric_precision.','.$c->numeric_scale.')';
                }
                $columns[$c->column_name] = [$type, $c->is_nullable === 'YES', $c->column_default, $c->is_identity === 'YES' || strpos((string) $c->column_default, 'nextval(') === 0];
            }
            $sql = 'SELECT i.relname AS name, ix.indisunique AS uniq, ix.indisprimary AS prim, a.attname AS col
                FROM pg_class t JOIN pg_index ix ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                WHERE t.relname = ? AND t.relnamespace = current_schema()::regnamespace
                ORDER BY i.relname, array_position(ix.indkey::int2[], a.attnum)';
            foreach ($db->customQuery($sql, false, [$table]) as $i) {
                if ($i->prim === true || $i->prim === 't' || $i->prim === 1) {
                    $pk[] = $i->col;
                } else {
                    $name = strpos($i->name, $table.'_') === 0 ? substr($i->name, strlen($table) + 1) : $i->name;
                    $indexes[$name]['unique'] = $i->uniq === true || $i->uniq === 't' || $i->uniq === 1;
                    $indexes[$name]['cols'][] = $i->col;
                }
            }
        } elseif ($driver === 'sqlite') {
            $create = $db->customQuery("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", false, [$table]);
            $autoincrement = stripos((string) $create[0]->sql, 'AUTOINCREMENT') !== false;
            $pkcols = [];
            foreach ($db->customQuery('SELECT * FROM pragma_table_info(?) ORDER BY cid', false, [$table]) as $c) {
                if ((int) $c->pk > 0) {
                    $pkcols[(int) $c->pk] = $c->name;
                }
                $identity = (int) $c->pk === 1 && $autoincrement;
                $columns[$c->name] = [strtolower($c->type), (int) $c->notnull === 0 && (int) $c->pk === 0, $c->dflt_value, $identity];
            }
            ksort($pkcols);
            $pk = array_values($pkcols);
            foreach ($db->customQuery("SELECT name, \"unique\" AS uniq FROM pragma_index_list(?) WHERE origin = 'c'", false, [$table]) as $i) {
                $name = strpos($i->name, $table.'_') === 0 ? substr($i->name, strlen($table) + 1) : $i->name;
                $indexes[$name]['unique'] = (int) $i->uniq === 1;
                foreach ($db->customQuery('SELECT name FROM pragma_index_info(?) ORDER BY seqno', false, [$i->name]) as $ic) {
                    $indexes[$name]['cols'][] = $ic->name;
                }
            }
        } else {
            throw new \Exception('ยังไม่มีตัวอ่านสคีมาของ '.$driver.' ใน cli-testdrivers.php');
        }
        $normalized = [];
        foreach ($columns as $name => $c) {
            $normalized[$name] = normalizeType($c[0]).($c[1] ? ' NULL' : ' NOT NULL')
                .($c[3] ? ' AUTO_INCREMENT' : normalizeDefault($c[2]));
        }
        ksort($indexes);
        $idx = [];
        foreach ($indexes as $name => $i) {
            $idx[$name] = ($i['unique'] ? 'UNIQUE ' : '').'('.implode(',', $i['cols']).')';
        }
        $schema[substr($table, strlen($prefix) + 1)] = ['columns' => $normalized, 'pk' => implode(',', $pk), 'indexes' => $idx];
    }
    ksort($schema);

    return $schema;
}

/**
 * ชนิดของคอลัมน์ในรูปกลาง — ตัวเลขจำนวนเต็มทุกขนาดเป็น int, ข้อความยาวเป็น text,
 * datetime/timestamp เป็น datetime (ความกว้างของ int และ unsigned ไม่ได้เทียบ)
 */
function normalizeType($type)
{
    $type = strtolower(trim($type));
    $type = preg_replace('/\s+unsigned$/', '', $type);
    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type)) {
        return 'int';
    }
    if (preg_match('/^(tinytext|text|mediumtext|longtext)$/', $type)) {
        return 'text';
    }
    if (preg_match('/^(datetime|timestamp)/', $type)) {
        return 'datetime';
    }
    if (preg_match('/^(character varying|varchar)\((\d+)\)$/', $type, $m)) {
        return 'varchar('.$m[2].')';
    }
    if (preg_match('/^(character|char)\((\d+)\)$/', $type, $m)) {
        return 'char('.$m[2].')';
    }
    if (preg_match('/^(numeric|decimal)\((\d+),\s*(\d+)\)$/', $type, $m)) {
        return 'decimal('.$m[2].','.$m[3].')';
    }
    if (preg_match('/^(float|double|real|double precision)/', $type)) {
        return 'float';
    }

    return $type;
}

/**
 * ค่า DEFAULT ในรูปกลาง — ตัด quote, cast ของ PostgreSQL (::character varying) และวงเล็บ
 */
function normalizeDefault($default)
{
    if ($default === null) {
        return '';
    }
    $default = trim((string) $default);
    $default = preg_replace('/::[a-z ]+(\(\d+\))?$/i', '', $default);
    $default = trim($default, '()');
    if (strcasecmp($default, 'NULL') === 0) {
        return '';
    }
    if (preg_match('/^(current_timestamp|now)/i', $default)) {
        return ' DEFAULT CURRENT_TIMESTAMP';
    }
    if (preg_match("/^N?'(.*)'$/s", $default, $m)) {
        $default = str_replace("''", "'", $m[1]);
    }

    return " DEFAULT '".$default."'";
}

/**
 * ความต่างของสคีมาเทียบกับสคีมาอ้างอิง (MySQL)
 *
 * @return array ข้อความบรรยายความต่าง (ว่าง = ตรงกัน)
 */
function compareSchema(array $want, array $have)
{
    $diff = [];
    foreach (array_diff(array_keys($want), array_keys($have)) as $table) {
        $diff[] = "ขาดตาราง $table";
    }
    foreach (array_diff(array_keys($have), array_keys($want)) as $table) {
        $diff[] = "เกินตาราง $table";
    }
    foreach (array_intersect(array_keys($want), array_keys($have)) as $table) {
        $w = $want[$table];
        $h = $have[$table];
        foreach ($w['columns'] as $col => $def) {
            if (!isset($h['columns'][$col])) {
                $diff[] = "$table: ขาดคอลัมน์ $col";
            } elseif ($h['columns'][$col] !== $def) {
                $diff[] = "$table.$col: MySQL = $def / ที่นี่ = ".$h['columns'][$col];
            }
        }
        foreach (array_diff(array_keys($h['columns']), array_keys($w['columns'])) as $col) {
            $diff[] = "$table: เกินคอลัมน์ $col";
        }
        if ($w['pk'] !== $h['pk']) {
            $diff[] = "$table: PRIMARY KEY MySQL = ({$w['pk']}) / ที่นี่ = ({$h['pk']})";
        }
        foreach ($w['indexes'] as $name => $def) {
            if (!isset($h['indexes'][$name])) {
                $diff[] = "$table: ขาด index $name $def";
            } elseif ($h['indexes'][$name] !== $def) {
                $diff[] = "$table: index $name MySQL = $def / ที่นี่ = ".$h['indexes'][$name];
            }
        }
        foreach (array_diff(array_keys($h['indexes']), array_keys($w['indexes'])) as $name) {
            $diff[] = "$table: เกิน index $name ".$h['indexes'][$name];
        }
    }

    return $diff;
}
