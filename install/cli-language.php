<?php
/**
 * install/cli-language.php — ตรวจแฟ้มภาษาของโปรเจ็ค **ตอนออกแบบ**
 *
 * ใช้:  php install/cli-language.php [--lang=th]
 *   (ไม่ใส่อะไร)  รายงานว่าโค้ดเรียกคีย์อะไรที่แฟ้มภาษายังไม่มี — ไม่แก้ไฟล์
 *   --lang=th     ภาษาที่ตรวจ (ค่าปริยาย th)
 *
 * ⚠️ เครื่องมือนี้เป็นของ "ฝั่งเรา" ไม่ใช่ของผู้ใช้
 *
 * โมดูลทุกตัวของโปรเจ็คนี้เป็น core — คำแปลที่โมดูลใช้อยู่ใน
 * language/<ภาษา>.php ของโปรเจ็คเสมอ โมดูลจึงไม่พกคำแปลติดตัว
 * คีย์ที่ขาดต้องเขียนเข้าแฟ้มภาษาของโปรเจ็คโดยตรง
 *
 * แฟ้มภาษาของรุ่นที่ปล่อยออกไปต้องครบ 100% อยู่แล้วตั้งแต่ก่อนปล่อย เพราะเรา
 * ทดสอบก่อนเสมอ — **ตัวปรับรุ่นจึงไม่ต้องยุ่งกับแฟ้มภาษาเลย** สิ่งเดียวที่
 * install/upgrade2.php ทำคือ include install/language.php เพื่อนำแฟ้มที่ครบแล้ว
 * เข้าตาราง language ให้หน้าแก้ภาษาใช้
 *
 * การเพิ่ม/ลบคำแปลเกิดตอนเราออกแบบ ไม่ใช่ตอนผู้ใช้เอาไปใช้ — จึงต้องรันไฟล์นี้
 * ให้ผ่าน (ขาด 0 คีย์) ก่อนปล่อยรุ่นทุกครั้ง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

define('ROOT_PATH', dirname(__DIR__).'/');

$options = ['lang' => 'th', 'sync' => false];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--sync') {
        $options['sync'] = true;
    } elseif (preg_match('/^--lang=([a-z]{2})$/', $arg, $m)) {
        $options['lang'] = $m[1];
    } else {
        fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
        exit(1);
    }
}

$lang = $options['lang'];
$phpFile = ROOT_PATH.'language/'.$lang.'.php';
if (!is_file($phpFile)) {
    fwrite(STDERR, "ไม่พบ language/$lang.php\n");
    exit(1);
}

/**
 * คีย์ภาษาทั้งหมดที่โค้ดในโฟลเดอร์หนึ่งเรียกใช้
 *
 * ⚠️ ข้าม MONTH_LONG / MONTH_SHORT / YEAR_OFFSET / CURRENCY_UNITS — สี่ตัวนี้
 * เป็นค่าคงที่ของแฟ้มภาษาแกน (สองตัวแรกเป็นอาเรย์) ไม่ใช่ข้อความที่ต้องแปล
 *
 * @param string $dir
 *
 * @return array
 */
function languageKeysIn($dir)
{
    static $skip = ['MONTH_LONG', 'MONTH_SHORT', 'YEAR_OFFSET', 'CURRENCY_UNITS'];
    $keys = [];
    if (!is_dir($dir)) {
        return $keys;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || !preg_match('/\.(php|html|js)$/', $file->getFilename())) {
            continue;
        }
        $src = file_get_contents($file->getPathname());
        // ⚠️ ห้ามใส่ Language::trans() ลงในรายการนี้ — trans() รับ "ข้อความ" ที่มี
        // ตัวยึด {LNG_xxx} ปนอยู่ ไม่ได้รับคีย์ ถ้านับทั้งอาร์กิวเมนต์เป็นคีย์
        // จะได้คีย์ปลอมอย่าง '{LNG_Reset} ID : ' ซึ่งไม่มีวันมีในแฟ้มภาษา
        // แล้วเครื่องมือนี้จะฟ้อง [FAIL] ตลอดกาลจนไม่มีใครเชื่อมันอีก
        // ตัวยึดข้างในถูกเก็บด้วยแพทเทิร์น {LNG_...} ตัวสุดท้ายอยู่แล้ว
        foreach ([
            "/Language::get\('([^']+)'/",
            "/Language::replace\('([^']+)'/",
            '/\{LNG_([^}]+)\}/'
        ] as $pattern) {
            if (preg_match_all($pattern, $src, $matches)) {
                foreach ($matches[1] as $key) {
                    // ยุบช่องว่างให้เหมือน Language::get() เป๊ะ ๆ
                    //
                    // ⚠️ ตัวยึด {LNG_...} ในเทมเพลตขึ้นบรรทัดใหม่ได้ (ข้อความยาว ๆ
                    // ที่ตัดบรรทัดในไฟล์ .html) ตอนรันจริง Language::get() ทำ
                    // preg_replace('/\s+/', ' ', trim($key)) ก่อนค้นเสมอ จึงหาเจอ
                    // ถ้าที่นี่ไม่ยุบด้วย จะได้คีย์ที่มีขึ้นบรรทัดปนแล้วรายงานว่า
                    // "ไม่มีคำแปล" ทั้งที่แฟ้มภาษามีอยู่ — timeline เจอจริง
                    $key = preg_replace('/\s+/', ' ', trim($key));
                    // ตัดค่าที่เป็นตัวแปร/มีอัญประกาศคู่ — แกะไม่ได้ด้วย regex
                    if (strpos($key, '"') !== false || strpos($key, '$') !== false) {
                        continue;
                    }
                    // ตัดเศษที่ได้จากการ match "ตัว regex เอง" ในซอร์ส — ไฟล์ที่มี
                    // แพทเทิร์นค้นหาคีย์เขียนอยู่ จะถูกอ่านเป็นคีย์ชื่อ "([^"
                    // คีย์ภาษาจริงไม่มีวงเล็บเหลี่ยม แบ็กสแลช หรือขึ้นต้นด้วยวงเล็บ
                    // ตัวยึดที่เขียนอธิบายใน comment ("{LNG_...}" / "{LNG_…}") ก็ไม่ใช่คีย์ —
                    // จุดไข่ปลาแบบอักขระเดียว (U+2026) ถูกนับเป็นคีย์ "…" ที่ขาดอยู่ตลอดใน e-signature
                    if (strpbrk($key, '[]\\') !== false || substr($key, 0, 1) === '(' || $key === '...' || $key === '…') {
                        continue;
                    }
                    if (in_array($key, $skip, true)) {
                        continue;
                    }
                    $keys[$key] = true;
                }
            }
        }
    }

    return $keys;
}

// ---- คีย์ที่โค้ดของโปรเจ็คนี้เรียกใช้จริง ----
$used = [];
foreach (['modules', 'templates', 'Gcms', 'Kotchasan', 'js'] as $dir) {
    $used += languageKeysIn(ROOT_PATH.$dir);
}
$used = array_keys($used);
sort($used, SORT_STRING);

$current = include $phpFile;
if (!is_array($current)) {
    fwrite(STDERR, "language/$lang.php อ่านไม่ได้\n");
    exit(1);
}

// ⚠️ เบราว์เซอร์ไม่ได้อ่าน language/<ภาษา>.php — มันโหลด language/<ภาษา>.json
// (Now/js/I18nManager.js : loadTranslations) ตัวยึด {LNG_...} ในเทมเพลตและข้อความ
// ที่ JS เรียกผ่าน Now.translate() จึงแปลจากไฟล์ json เท่านั้น
//
// เติมคำแปลใน .php อย่างเดียวแล้วเครื่องมือนี้ขึ้น [ok] ได้ ทั้งที่หน้าเว็บยังโชว์
// ภาษาอังกฤษอยู่ทั้งหน้า — เจอจริงกับหน้าร้านออนไลน์ 2026-09-12
$jsonFile = ROOT_PATH.'language/'.$lang.'.json';
$json = is_file($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : null;

// ⚠️ ภาษาอังกฤษใช้ธรรมเนียม "คีย์คือข้อความ" — Language::get() คืนตัวคีย์เอง
// เมื่อไม่พบในแฟ้ม แฟ้ม en.php จึงมีแค่คีย์ที่ข้อความแสดงผลต่างจากตัวคีย์
// (adminframework มี 33 คีย์จากที่โค้ดเรียก 230) การนับคีย์ที่ "ขาด" แบบเดียว
// กับภาษาไทยจะฟ้อง [FAIL] ทุกโปรเจ็คตลอดกาลทั้งที่ไม่มีอะไรผิด
$key_is_text = ($lang === 'en');
$missing = [];
$missingJson = [];
foreach ($used as $key) {
    if (!array_key_exists($key, $current)) {
        if (!$key_is_text) {
            $missing[] = $key;
        }
    } elseif ($current[$key] === '') {
        // คีย์ที่มีอยู่แต่ค่าว่าง = ตั้งใจใส่ไว้แล้วลืมเขียน ผิดทุกภาษา
        $missing[] = $key;
    }
    // ฝั่งเบราว์เซอร์ — ตรวจเฉพาะคีย์ที่ .php แปลไว้แล้ว (คีย์ที่ยังไม่แปลรายงานข้างบนแล้ว)
    if (is_array($json) && !$key_is_text
        && isset($current[$key]) && $current[$key] !== ''
        && (!array_key_exists($key, $json) || $json[$key] === '')) {
        $missingJson[] = $key;
    }
}

// --sync : คัดคำแปลที่ .php มีแต่ .json ยังไม่มี ลงไฟล์ json ให้ตรงกัน
// (ไม่ลบของเดิมใน json และไม่ทับคำแปลที่ json มีอยู่แล้ว — ของเดิมอาจถูกแก้จากหน้าเว็บ)
if ($options['sync'] === true && is_array($json) && !empty($missingJson)) {
    foreach ($missingJson as $key) {
        $json[$key] = $current[$key];
    }
    ksort($json, SORT_STRING);
    file_put_contents($jsonFile, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo 'เติม language/'.$lang.'.json '.count($missingJson)." คีย์จากแฟ้ม php\n";
    $missingJson = [];
}

echo 'โปรเจ็ค  : '.ROOT_PATH."\n";
echo 'แฟ้มภาษา : language/'.$lang.'.php — '.count($current)." คีย์\n";
echo 'โค้ดเรียก : '.count($used)." คีย์\n";
if ($key_is_text) {
    echo "หมายเหตุ : ภาษานี้ใช้คีย์เป็นข้อความ ตรวจเฉพาะคีย์ที่มีอยู่แต่ค่าว่าง\n";
}

if (empty($missing) && empty($missingJson)) {
    echo "\n[ok]   ไม่มีคีย์ที่ขาด — แฟ้มภาษาครบ พร้อมปล่อยรุ่น\n";
    exit(0);
}

if (!empty($missing)) {
    echo "\n[FAIL] ขาดคำแปล ".count($missing)." คีย์ ต้องเขียนเข้า language/$lang.php เอง:\n";
    foreach ($missing as $key) {
        echo '  '.$key."\n";
    }
}

if (!empty($missingJson)) {
    echo "\n[FAIL] language/$lang.json ขาด ".count($missingJson)." คีย์ที่หน้าเว็บใช้\n";
    echo "       (เบราว์เซอร์อ่านไฟล์ json ไม่ใช่ php — ตัวยึด {LNG_...} จะโชว์ภาษาอังกฤษ)\n";
    echo "       แก้ด้วย: php install/cli-language.php --lang=$lang --sync\n";
    foreach (array_slice($missingJson, 0, 40) as $key) {
        echo '  '.$key."\n";
    }
    if (count($missingJson) > 40) {
        echo '  ... อีก '.(count($missingJson) - 40)." คีย์\n";
    }
}

exit(1);
