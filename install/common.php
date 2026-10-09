<?php
/**
 * install/common.php
 *
 * ส่วนกลางของตัวติดตั้งและตัวปรับรุ่น ไฟล์นี้ต้อง "เหมือนกันทุกไบต์" ในทุกโปรเจ็ค
 * ของ now.js สิ่งที่เป็นของเฉพาะโปรเจ็คมีแค่ install/database.sql กับส่วนตาราง
 * ของโปรเจ็คใน install/upgrade2.php เท่านั้น
 *
 * เหตุผลที่ต้องรวมไว้ที่เดียว — ก่อนหน้านี้ step1.php/upgrade0.php และ
 * step2.php/upgrade1.php เป็นไฟล์คนละไฟล์ที่เนื้อหาเหมือนกัน ต่างกันแค่ลิงก์
 * ปุ่ม ส่วน save() กับ makeDirectory() ถูกนิยามซ้ำสองที่ พอแก้ที่หนึ่งแล้วลืม
 * อีกที่ ตัวติดตั้งกับตัวปรับรุ่นก็ทำงานคนละอย่างโดยไม่มีอะไรฟ้อง
 */
if (!defined('ROOT_PATH')) {
    exit;
}

// =============================================================================
// ระบบไฟล์และค่ากำหนด
// =============================================================================

/**
 * สร้างไดเร็คทอรี่ถ้ายังไม่มี แล้วปรับ chmod ให้เขียนได้
 *
 * @param string $dir
 * @param int    $mode
 *
 * @return bool
 */
function makeDirectory($dir, $mode = 0755)
{
    if (!is_dir($dir)) {
        $old = umask(0);
        @mkdir($dir, $mode, true);
        umask($old);
    }
    $old = umask(0);
    $f = @chmod($dir, $mode);
    umask($old);

    return $f;
}

/**
 * บันทึกไฟล์ค่ากำหนดในรูปแบบ PHP array
 *
 * @param array  $config
 * @param string $file
 *
 * @return bool
 */
function save($config, $file)
{
    $f = @fopen($file, 'wb');
    if ($f === false) {
        return false;
    }
    if (!preg_match('/^.*\/([^\/]+)\.php?/', $file, $match)) {
        $match[1] = 'config';
    }
    fwrite($f, '<'."?php\n/* $match[1].php */\nreturn ".var_export((array) $config, true).';');
    fclose($f);

    return true;
}

/**
 * เติมค่ากำหนดที่ระบบต้องใช้ให้ครบ
 *
 * ตัวติดตั้งใหม่กับตัวปรับรุ่นต้องเรียกฟังก์ชันเดียวกัน ไม่งั้นเครื่องที่ติดตั้ง
 * ใหม่จะได้ settings/config.php คนละหน้าตากับเครื่องที่ปรับรุ่นมา (ของเดิม
 * ตัวปรับรุ่นบังคับ stored_img_type เป็น .jpg เมื่อ GD ไม่มี WebP แต่ตัวติดตั้ง
 * ไม่เขียนค่านี้เลย จึงตกไปใช้ค่าปริยาย .webp แล้วรูปพังทั้งระบบ)
 *
 * @param array $config     ค่ากำหนดปัจจุบัน (ตอนติดตั้งใหม่คือค่าจากแม่แบบ)
 * @param array $new_config ค่ากำหนดของชุดติดตั้งใหม่ (install/settings/config.php)
 *
 * @return array
 */
function ensureConfigDefaults($config, $new_config)
{
    include_once ROOT_PATH.'Kotchasan/Password.php';
    $config = (array) $config;
    // เวอร์ชั่นและเวลาแก้ไขล่าสุด
    if (isset($new_config['version'])) {
        $config['version'] = $new_config['version'];
    }
    $config['reversion'] = time();
    if (isset($new_config['default_icon'])) {
        $config['default_icon'] = $new_config['default_icon'];
    }
    // ชนิดของรูปที่จัดเก็บ — เลือก .webp ได้เฉพาะเมื่อ GD รองรับจริง
    //
    // ⚠️ กิ่งที่สองเคยตั้ง '.jpg' ทั้งที่เซิร์ฟเวอร์รองรับ webp ซึ่งขัดกับเจตนา
    // ของบรรทัดข้างบนเอง ผลคือไซต์ติดตั้งใหม่ได้ .jpg เสมอ แล้วโค้ดที่ประกอบ
    // ชื่อไฟล์จากค่านี้ (logo.jpg / <id>.jpg) ไปหาไฟล์ที่ไม่มีอยู่จริง
    // เพราะไฟล์ที่เก็บไว้เป็น .webp — รูปหายทั้งเว็บโดยไม่มีข้อผิดพลาดให้เห็น
    //
    // ค่าที่ไซต์ตั้งไว้แล้วไม่ถูกแตะ (isset) เพราะไฟล์เดิมของเขาใช้นามสกุลนั้นอยู่
    if (!function_exists('imagewebp')) {
        $config['stored_img_type'] = '.jpg';
    } elseif (!isset($config['stored_img_type'])) {
        $config['stored_img_type'] = '.webp';
    }
    // กุญแจของ API
    if (empty($config['api_tokens']['internal']) || empty($config['api_tokens']['external'])) {
        $config['api_tokens'] = [
            'internal' => \Kotchasan\Password::uniqid(40),
            'external' => \Kotchasan\Password::uniqid(40)
        ];
    }
    if (empty($config['api_secret'])) {
        $config['api_secret'] = \Kotchasan\Password::uniqid();
    }
    if (empty($config['jwt_secret'])) {
        $config['jwt_secret'] = \Kotchasan\Password::uniqid(64);
    }
    if (!isset($config['api_ips'])) {
        $config['api_ips'] = ['0.0.0.0'];
    }
    if (!isset($config['api_cors'])) {
        $config['api_cors'] = '*';
    }
    // Timeline Provider — เว้น slug ว่างไว้โดยตั้งใจ ค่าว่างแปลว่ายังไม่เปิดใช้
    // และ Guard จะปฏิเสธทุกคำขอ การเปิดใช้ต้องเป็นการตัดสินใจของคน
    // ไม่ใช่ผลข้างเคียงของการติดตั้งหรือปรับรุ่น
    if (!isset($config['timeline_slug'])) {
        $config['timeline_slug'] = '';
    }
    if (!isset($config['timeline_name'])) {
        $config['timeline_name'] = '';
    }
    if (!isset($config['timeline_mappers']) || !is_array($config['timeline_mappers'])) {
        $config['timeline_mappers'] = [];
    }

    return $config;
}

/**
 * รายชื่อไฟล์ SQL ที่ใช้สร้างฐานข้อมูลของระบบ เรียงตามลำดับที่ต้องรัน
 *
 * core.sql = ตารางแกนของ Gcms ที่ทุกโปรเจ็คมีเหมือนกัน (เนื้อหาต้องเหมือนกันทุกไบต์)
 * database.sql = ตารางและข้อมูลตัวอย่างของโปรเจ็คนี้
 * timeline.sql = ตารางของ Timeline Provider
 *
 * ทั้งการติดตั้งใหม่และการปรับรุ่นอ่านจากรายการเดียวกัน จะได้ไม่มีนิยามตาราง
 * เดียวกันสองชุดที่ค่อย ๆ ต่างกันจนเครื่องที่ติดตั้งใหม่กับเครื่องที่ปรับรุ่น
 * มีสคีมาคนละหน้าตา
 *
 * @return array
 */
function schemaFiles()
{
    $files = [];
    foreach (['core.sql', 'database.sql', 'timeline.sql'] as $name) {
        if (is_file(ROOT_PATH.'install/'.$name)) {
            $files[] = ROOT_PATH.'install/'.$name;
        }
    }
    // โมดูลที่มีตารางของตัวเอง เก็บนิยามไว้กับตัวโมดูล ไม่ใช่ในไฟล์ของโปรเจ็ค
    //
    // ทำให้ "ถอดโมดูลออก = ไม่มีตารางของมัน" และ "วางโมดูลลงไป = ได้ตารางครบ"
    // โดยไม่ต้องแก้ install/database.sql ของทุกโปรเจ็คที่รับโมดูลนั้นไป ซึ่งเป็น
    // เงื่อนไขที่ทำให้โมดูล inventory กลางชุดเดียวใช้ได้ทุกผลิตภัณฑ์จริง ๆ
    foreach (glob(ROOT_PATH.'modules/*/install/database.sql') ?: [] as $file) {
        $files[] = $file;
    }

    return $files;
}

/**
 * คำสั่ง SQL ของสคีมาทั้งโปรเจ็ค เรียงให้ปลอดภัยแล้ว
 *
 * ⚠️ ทำไมต้องเรียงใหม่ ไม่ใช่รันไล่ไฟล์ตรง ๆ
 *
 * โมดูลเสริมได้รับอนุญาตให้ **ขยายตารางของโมดูลอื่น** ด้วย `ALTER TABLE ... ADD`
 * ในไฟล์ database.sql ของตัวเอง (นโยบาย 2026-09-12) แต่ schemaFiles() เรียงไฟล์
 * โมดูลตามชื่อโฟลเดอร์ (glob เรียงตามตัวอักษร) — `modules/billing` จึงมาก่อน
 * `modules/inventory` แล้ว ALTER จะวิ่งใส่ตารางที่ยังไม่ถูกสร้าง ติดตั้งใหม่ล้มทันที
 * โดยที่ชื่อโมดูลเป็นตัวตัดสิน ซึ่งเป็นความบังเอิญล้วน ๆ
 *
 * เรียงเป็นสามชั้นแทน: สร้างตารางทั้งหมด → ขยายตารางทั้งหมด → ใส่ข้อมูลทั้งหมด
 * ลำดับภายในแต่ละชั้นยังเป็นลำดับเดิมของไฟล์ จึงไม่กระทบของเดิมสักไฟล์
 *
 * @param string $prefix
 * @param bool   $drop   true = ใส่ DROP TABLE IF EXISTS ก่อนทุก CREATE TABLE
 *
 * @return array คำสั่ง SQL ที่พร้อมรันตามลำดับ
 */
function schemaCommands($prefix, $drop = false)
{
    $creates = [];
    $alters = [];
    $others = [];
    foreach (schemaFiles() as $file) {
        foreach (sqlCommands($file, $prefix, $drop) as $command) {
            $head = ltrim($command);
            if (preg_match('/^ALTER\s+TABLE/i', $head)) {
                $alters[] = $command;
            } elseif (preg_match('/^(CREATE|DROP)\s/i', $head)) {
                $creates[] = $command;
            } else {
                $others[] = $command;
            }
        }
    }

    return array_merge($creates, $alters, $others);
}

/**
 * คอลัมน์ที่สคีมาของโปรเจ็คประกาศไว้ แยกตามตาราง
 *
 * อ่านจากนิยามเดียวกับที่ตัวติดตั้งใช้ (schemaCommands) ทั้ง CREATE TABLE และ
 * ALTER TABLE ... ADD ที่โมดูลใช้ขยายตารางของโมดูลอื่น
 *
 * @param string $prefix
 *
 * @return array [ชื่อตารางเต็ม => [ชื่อคอลัมน์ => true]]
 */
function declaredColumns($prefix)
{
    $declared = [];
    foreach (schemaCommands($prefix) as $command) {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\((.*)\)/is', $command, $match)) {
            $table = $match[1];
            if (!isset($declared[$table])) {
                $declared[$table] = [];
            }
            // บรรทัดนิยามคอลัมน์ขึ้นต้นด้วย `ชื่อ` ตามด้วยชนิด ส่วน PRIMARY KEY / KEY /
            // UNIQUE KEY ขึ้นต้นด้วยคำสงวน จึงไม่ถูกนับเป็นคอลัมน์
            if (preg_match_all('/^\s*`([^`]+)`\s+[a-z]/im', $match[2], $columns)) {
                foreach ($columns[1] as $column) {
                    $declared[$table][$column] = true;
                }
            }
        } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`([^`]+)`(.*)$/is', $command, $match)) {
            // ADD INDEX `x` (...) มีคำสงวนคั่นก่อนชื่อ จึงไม่ถูกนับเป็นคอลัมน์
            if (preg_match_all('/\bADD\s+(?:COLUMN\s+)?`([^`]+)`\s+[a-z]/i', $match[2], $columns)) {
                foreach ($columns[1] as $column) {
                    $declared[$match[1]][$column] = true;
                }
            }
        }
    }

    return $declared;
}

/**
 * ให้คอลัมน์ที่สคีมาไม่รู้จัก มีค่าเริ่มต้น — ไม่ขวางการเขียนของโค้ดที่ไม่รู้จักมัน
 *
 * ⚠️ ไซต์ที่ย้ายมาจากระบบเดิมมีคอลัมน์ของโมดูลที่ระบบใหม่ไม่มี ติดมากับตารางแกน
 * เช่น `user`.`shift` (กะทำงานของโมดูล eleave ใน e-OMS) เป็น NOT NULL ไม่มี
 * DEFAULT — ใต้ STRICT_TRANS_TABLES ทุก INSERT ที่ไม่ใส่คอลัมน์นั้นล้มหมด
 * โค้ดของแกนไม่มีทางรู้จักคอลัมน์ของโมดูลที่ไม่ได้ติดตั้ง ผลคือ **สมัครสมาชิก
 * เพิ่มผู้ใช้ และเข้าระบบด้วยโซเชียลพังทั้งหมด** โดยตัวปรับรุ่นรายงานว่าสำเร็จ
 *
 * ทำเฉพาะคอลัมน์ที่ "ไม่ได้ประกาศ" บนตารางที่ "ประกาศไว้" เท่านั้น — คอลัมน์ที่
 * ประกาศเป็น NOT NULL ไม่มี DEFAULT ตั้งใจให้บังคับกรอก (user.password, name)
 * ต้องไม่ถูกแตะ ไม่งั้นไซต์ที่ปรับรุ่นจะต่างจากติดตั้งใหม่ถาวร
 *
 * ไม่ลบคอลัมน์และไม่แตะข้อมูลสักแถว (ข้อมูลกะทำงานเดิมยังอยู่ครบ) แค่เพิ่ม DEFAULT
 * ชนิดที่ตั้งค่าเริ่มต้นให้อย่างปลอดภัยไม่ได้ (text/date/enum) จะถูกรายงานให้แก้เอง
 *
 * @param Db     $db
 * @param string $prefix
 * @param array  $content
 */
function ensureForeignColumnsDefault($db, $prefix, array &$content)
{
    foreach (declaredColumns($prefix) as $table => $columns) {
        if (empty($columns) || !$db->tableExists($table)) {
            continue;
        }
        foreach ($db->customQuery("SHOW COLUMNS FROM `$table`") as $info) {
            if (isset($columns[$info->Field]) || $info->Null !== 'NO' || $info->Default !== null
                || stripos((string) $info->Extra, 'auto_increment') !== false) {
                continue;
            }
            $type = strtolower((string) $info->Type);
            if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)\b/', $type)) {
                $default = '0';
            } elseif (preg_match('/^(char|varchar)\b/', $type)) {
                $default = '';
            } else {
                $content[] = '<li class="warning">'.$table.': คอลัมน์ <code>'.$info->Field.'</code> ('.$type.') '
                    .'ไม่ได้อยู่ในสคีมาของระบบนี้ และเป็น NOT NULL ที่ไม่มีค่าเริ่มต้น การเพิ่มข้อมูลลงตารางนี้จะล้ม '
                    .'กรุณากำหนดค่าเริ่มต้นหรือให้เป็น NULL ได้</li>';
                continue;
            }
            $db->query("ALTER TABLE `$table` MODIFY `{$info->Field}` {$info->Type} NOT NULL DEFAULT '$default'");
            $content[] = '<li class="correct">'.$table.': ให้คอลัมน์ '.$info->Field
                .' ที่ระบบนี้ไม่ได้ใช้ มีค่าเริ่มต้น (ข้อมูลเดิมอยู่ครบ)</li>';
        }
    }
}

/**
 * แยกไฟล์ SQL ออกเป็นคำสั่งย่อย พร้อมแทนที่ {prefix}
 *
 * @param string $file   ไฟล์ SQL
 * @param string $prefix คำนำหน้าตาราง
 * @param bool   $drop   true = ใส่ DROP TABLE IF EXISTS ก่อนทุก CREATE TABLE (ใช้ตอนติดตั้งใหม่)
 *
 * @return array
 */
function sqlCommands($file, $prefix, $drop = false)
{
    $commands = '';
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 2) === '--') {
            continue;
        }
        if ($drop && preg_match('/CREATE TABLE `\{prefix\}_([a-z0-9_\-]+)`/i', $line, $match)) {
            $commands .= 'DROP TABLE IF EXISTS `'.$prefix.'_'.$match[1]."`;\n";
        }
        $commands .= $line."\n";
    }
    $result = [];
    foreach (explode(";\n", $commands) as $command) {
        if (trim($command) !== '') {
            $result[] = str_replace('{prefix}', $prefix, $command);
        }
    }

    return $result;
}

/**
 * สร้างตารางจากไฟล์ SQL ไฟล์เดียว ใช้ตอนปรับรุ่นเมื่อพบว่ายังไม่มีตารางนั้น
 *
 * @param Db     $db
 * @param string $file
 * @param string $prefix
 */
function runSqlFile($db, $file, $prefix)
{
    foreach (sqlCommands($file, $prefix) as $command) {
        $db->query($command);
    }
}

// =============================================================================
// หน้าจอที่ตัวติดตั้งกับตัวปรับรุ่นใช้ร่วมกัน
// =============================================================================

/**
 * ตรวจไฟล์และโฟลเดอร์ที่ต้องเขียนได้ แล้วแสดงผล
 *
 * @param int $next_step เลข step ถัดไป (ติดตั้ง = 2, ปรับรุ่น = 1)
 * @param int $this_step เลข step ของหน้านี้ (ติดตั้ง = 1, ปรับรุ่น = 0)
 */
function checkFolders($next_step, $this_step)
{
    echo '<h2>ตรวจสอบไฟล์และโฟลเดอร์ที่จำเป็นสำหรับการติดตั้ง</h2>';
    echo '<p>ไฟล์และโฟลเดอร์ทั้งหมดตามรายการด้านล่างต้องถูกสร้างขึ้น และกำหนดค่าให้สามารถเขียนได้ <a href="https://www.kotchasan.com/index.php?module=knowledge&id=91" target=_blank class="icon-help notext"></a></p>';
    echo '<ul>';
    $error = false;
    $folders = [
        ROOT_PATH.'datas/',
        ROOT_PATH.'settings/',
        ROOT_PATH.'datas/cache/',
        ROOT_PATH.'datas/logs/',
        ROOT_PATH.'datas/images/'
    ];
    foreach ($folders as $folder) {
        makeDirectory($folder, 0755);
        if (is_writable($folder)) {
            echo '<li class=correct>โฟลเดอร์ <strong>'.str_replace(ROOT_PATH, '', $folder).'</strong> <i>สามารถใช้งานได้</i></li>';
        } else {
            $error = true;
            echo '<li class=incorrect>โฟลเดอร์ <strong>'.str_replace(ROOT_PATH, '', $folder).'</strong> <em>ไม่สามารถเขียนหรือสร้างได้</em> กรุณาสร้างและปรับ chmod ให้สามารถเขียนได้</li>';
        }
    }
    $files = [
        ROOT_PATH.'settings/config.php',
        ROOT_PATH.'settings/database.php'
    ];
    foreach ($files as $file) {
        if (!is_file($file)) {
            $f = @fopen($file, 'wb');
            if ($f) {
                fclose($f);
            }
        }
        if (is_writable($file)) {
            echo '<li class=correct>ไฟล์ <strong>'.str_replace(ROOT_PATH, '', $file).'</strong> <i>สามารถใช้งานได้</i></li>';
        } else {
            $error = true;
            echo '<li class=incorrect>ไฟล์ <strong>'.str_replace(ROOT_PATH, '', $file).'</strong> <em>ไม่สามารถเขียนหรือสร้างได้</em> กรุณาสร้างไฟล์นี้และปรับ chmod ให้เป็น 755 ด้วยตัวเอง</li>';
        }
    }
    echo '</ul>';
    // ของเดิมตั้ง $error ไว้แต่ไม่เคยเอาไปใช้ ปุ่ม "ดำเนินการต่อ" จึงขึ้นเสมอ
    // แล้วผู้ใช้ก็เดินหน้าต่อไปตายตอนเขียน settings/config.php ไม่ได้ ซึ่งอ่านไม่ออก
    // ว่าสาเหตุคือ chmod ที่เพิ่งบอกไปเมื่อหน้าที่แล้ว
    echo '<p class="submit"><a href="index.php'.($this_step > 0 ? '?step='.$this_step : '').'" class="btn large btn-secondary">ตรวจสอบใหม่</a>';
    if ($error) {
        echo '</p>';
        echo '<p class=warning>ยังมีไฟล์หรือโฟลเดอร์ที่เขียนไม่ได้ กรุณาปรับ chmod ตามรายการ<span class=incorrect>สีแดง</span>ด้านบนให้เรียบร้อยก่อน แล้วกด "ตรวจสอบใหม่"</p>';
    } else {
        echo '&nbsp;<a href="index.php?step='.$next_step.'" class="btn large btn-primary">ดำเนินการต่อ</a></p>';
    }
}

/**
 * ฟอร์มกรอกสมาชิกผู้ดูแลระบบสูงสุด
 *
 * @param int  $next_step  เลข step ถัดไป (ติดตั้ง = 4, ปรับรุ่น = 2)
 * @param bool $is_upgrade true = ข้อความแบบปรับรุ่น (ยืนยันตัวตน ไม่ใช่ตั้งค่าใหม่)
 *                          และแสดงช่องยืนยันว่าสำรองฐานข้อมูลแล้ว
 * @param string $warning   ข้อความเตือนเหนือฟอร์ม (ว่าง = ไม่แสดง)
 */
function adminForm($next_step, $is_upgrade, $warning = '')
{
    // ค่าที่เพิ่งกรอกมาต้องชนะค่าเริ่มต้นเสมอ ไม่งั้นการกดปรับรุ่นโดยลืมติ๊ก
    // ช่องยืนยันการสำรองข้อมูล จะทำให้ชื่อผู้ใช้ที่กรอกไว้หายกลับไปเป็นค่าตั้งต้น
    $username = isset($_POST['username']) ? $_POST['username'] : (isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'admin@localhost');
    $password = isset($_POST['password']) ? $_POST['password'] : (isset($_SESSION['admin_password']) ? $_SESSION['admin_password'] : 'admin');
    if ($is_upgrade) {
        $intro = 'คุณจะต้องระบุข้อมูลสมาชิกผู้ดูแลระบบสูงสุด เพื่อยืนยันว่าคุณมีสิทธิปรับรุ่นระบบนี้';
        $comment_user = 'กรุณากรอกชื่อผู้ใช้ของผู้ดูแลระบบสูงสุด';
        $comment_pass = 'กรุณากรอกรหัสผ่านของผู้ดูแลระบบสูงสุด';
    } else {
        $intro = 'คุณจะต้องระบุข้อมูลสมาชิกผู้ดูแลระบบ ซึ่งจะมีสิทธิสูงสุดในระบบ <em>ห้ามลืม ห้ามหาย</em>';
        $comment_user = 'กรุณากรอกชื่อผู้ใช้ที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด';
        $comment_pass = 'กรุณากรอกรหัสผ่านที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด';
    }
    echo '<form method=post action=index.php autocomplete=off>';
    echo '<h2>สมาชิกผู้ดูแลระบบ</h2>';
    if ($warning !== '') {
        echo '<p class=warning>'.$warning.'</p>';
    }
    echo '<p>'.$intro.'</p>';
    // ของเดิมพ่นค่าลงใน value="..." ตรง ๆ ชื่อผู้ใช้หรือรหัสผ่านที่มีเครื่องหมาย "
    // จะทำให้แท็กพัง และค่าที่กรอกไว้หายไปเงียบ ๆ
    echo '<p class=item><label for=username>ชื่อผู้ใช้</label><span class="form-control icon-user"><input type=text size=50 maxlength=50 id=username name=username value="'.htmlspecialchars($username, ENT_QUOTES).'"></span></p>';
    echo '<p class=comment>'.(empty($username) ? '<em>'.$comment_user.'</em>' : $comment_user).'</p>';
    echo '<p class=item><label for=password>รหัสผ่าน</label><span class="form-control icon-password"><input type=password size=50 maxlength=20 id=password name=password value="'.htmlspecialchars($password, ENT_QUOTES).'"></span></p>';
    echo '<p class=comment>'.(empty($password) ? '<em>'.$comment_pass.'</em>' : $comment_pass).'</p>';
    if ($is_upgrade) {
        // เราเข้าถึงไซต์ปลายทางไม่ได้ กู้ข้อมูลให้ไม่ได้ และดู log ให้ไม่ได้
        // ผู้ดูแลไซต์จึงต้องมีสำเนาของตัวเองก่อนเสมอ และต้องเป็นการติ๊กด้วยมือ
        // ห้ามติ๊กมาให้ล่วงหน้า (checked) เพราะจะกลายเป็นแค่ข้อความประดับ
        echo '<p class=item><label class="item" for=confirm_backup>';
        echo '<input type=checkbox id=confirm_backup name=confirm_backup value=1> ';
        echo 'ข้าพเจ้าได้สำรองฐานข้อมูลและไฟล์ของระบบนี้ไว้แล้ว</label></p>';
        echo '<p class=comment>ถ้าการปรับรุ่นมีปัญหา ทางแก้เดียวคือกู้คืนจากไฟล์สำรองของคุณเอง '
            .'กรุณาสำรองฐานข้อมูลทั้งฐาน (เช่นด้วย phpMyAdmin &rsaquo; Export หรือ mysqldump) ก่อนดำเนินการต่อ</p>';
    }
    echo '<input type=hidden name=step value='.$next_step.'>';
    echo '<p class="submit"><button class="btn large btn-primary" type=submit>ดำเนินการต่อ</button></p>';
    echo '</form>';
}

// =============================================================================
// เครื่องมือสำหรับการปรับรุ่นฐานข้อมูล
//
// หลักการเดียวของทั้งหมวดนี้ — เงื่อนไขต้องถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้
// เป็นอะไร" เพราะเงื่อนไขแบบหลัง (เช่น if (isColumnType($t, $c, 'text'))) ยัง
// เป็นจริงหลังแก้เสร็จ ตารางจึงถูก ALTER ซ้ำทุกครั้งที่ปรับรุ่น = rebuild ทั้ง
// ตารางฟรี ๆ ซึ่งบนตาราง logs หรือ user ขนาดใหญ่คือหลายนาทีต่อรอบ
// =============================================================================

/**
 * อ่านรายละเอียดของคอลัมน์ (Type, Null, Default, Extra)
 * ใช้ตรวจค่า DEFAULT และการยอมรับ NULL ซึ่ง isColumnType() ไม่ได้ดูให้
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $column
 *
 * @return object|null
 */
function columnInfo($db, $table_name, $column)
{
    $result = $db->customQuery("SHOW COLUMNS FROM `$table_name` LIKE '$column'");

    return empty($result) ? null : $result[0];
}

/**
 * ตรวจว่าคอลัมน์ตรงกับที่ต้องการแล้วหรือยัง ทั้งชนิดและการยอมรับ NULL
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $column
 * @param string $type     ชนิดที่ต้องการ เช่น 'text', 'int(11)'
 * @param bool   $nullable true = ต้องยอมรับ NULL ได้
 *
 * @return bool true เมื่อตรงแล้ว (ไม่ต้องแก้อะไร)
 */
function columnMatches($db, $table_name, $column, $type, $nullable)
{
    $info = columnInfo($db, $table_name, $column);
    if (!$info) {
        return false;
    }
    if (normalizeColumnType($info->Type) !== normalizeColumnType($type)) {
        return false;
    }

    return ($info->Null === 'YES') === (bool) $nullable;
}

/**
 * ตัด display width ของ integer ออกก่อนเทียบชนิดคอลัมน์
 *
 * MySQL 8.0.19 ขึ้นไปไม่รายงาน int(11) แล้ว ในขณะที่ MariaDB ยังรายงานอยู่
 * ถ้าไม่ตัด คอลัมน์จะถูกมองว่า "ไม่ตรง" แล้ว ALTER ซ้ำทุกครั้งที่ปรับรุ่น
 *
 * @param string $type
 *
 * @return string
 */
function normalizeColumnType($type)
{
    return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower(trim($type)));
}

/**
 * ตรวจว่า index เป็นแบบ UNIQUE หรือไม่
 * indexExists() บอกได้แค่ว่ามีอยู่ ไม่ได้บอกว่าเป็นชนิดที่ต้องการแล้วหรือยัง
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $index
 *
 * @return bool
 */
function indexIsUnique($db, $table_name, $index)
{
    $result = $db->customQuery(
        "SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name' AND INDEX_NAME = '$index'
         LIMIT 1"
    );

    return empty($result) ? false : (int) $result[0]->NON_UNIQUE === 0;
}

/**
 * อ่านรายชื่อคอลัมน์ของ index เรียงตามลำดับที่อยู่ใน index
 * ไม่มี index นี้คืนค่าแอเรย์ว่าง
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $index
 *
 * @return array
 */
function indexColumns($db, $table_name, $index)
{
    $result = $db->customQuery(
        "SELECT `COLUMN_NAME` FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name' AND INDEX_NAME = '$index'
         ORDER BY SEQ_IN_INDEX"
    );
    $columns = [];
    foreach ($result as $item) {
        $columns[] = $item->COLUMN_NAME;
    }

    return $columns;
}

/**
 * ตรวจว่าคอลัมน์เป็น AUTO_INCREMENT หรือไม่
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $column
 *
 * @return bool
 */
function isAutoIncrement($db, $table_name, $column)
{
    $info = columnInfo($db, $table_name, $column);

    return $info ? stripos($info->Extra, 'auto_increment') !== false : false;
}

/**
 * แปลง engine ของตารางเป็น InnoDB คืนค่า true ถ้ามีการแปลงจริง
 *
 * @param Db     $db
 * @param string $table_name
 *
 * @return bool
 */
function convertToInnoDB($db, $table_name)
{
    $result = $db->customQuery(
        "SELECT `ENGINE` FROM `INFORMATION_SCHEMA`.`TABLES`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '$table_name'"
    );
    if (!empty($result) && strcasecmp((string) $result[0]->ENGINE, 'InnoDB') !== 0) {
        $db->query("ALTER TABLE `$table_name` ENGINE=InnoDB");

        return true;
    }

    return false;
}

/**
 * แปลงตารางเป็น utf8mb4 ถ้ายังไม่ใช่ คืนค่า true ถ้ามีการแปลงจริง
 *
 * ตารางที่ยังเป็น utf8mb3 เก็บอักขระ 4 ไบต์ (อีโมจิ) ไม่ได้ และเทียบข้อความ
 * กับตารางที่เป็น utf8mb4 ได้ผลไม่ตรงกัน ต้องแปลงก่อนปรับคอลัมน์เสมอ เพราะ
 * CONVERT TO CHARACTER SET เลื่อนชนิด TEXT เป็น MEDIUMTEXT
 *
 * @param Db     $db
 * @param string $table_name
 *
 * @return bool
 */
function convertToUtf8mb4($db, $table_name)
{
    $result = $db->customQuery(
        "SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name'"
    );
    if (empty($result) || $result[0]->TABLE_COLLATION === 'utf8mb4_general_ci') {
        return false;
    }
    $db->query("ALTER TABLE `$table_name` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    return true;
}

/**
 * บังคับให้คอลัมน์ตรงตามคำนิยามที่ต้องการ ถ้ายังไม่มีก็เพิ่มใหม่
 * โดยระบุตำแหน่งด้วย AFTER เพื่อให้ลำดับคอลัมน์ตรงกับที่ติดตั้งใหม่
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $column
 * @param string $type     ชนิดของคอลัมน์ เช่น 'varchar(100)', 'int(11)'
 * @param bool   $nullable true = ยอมรับ NULL (DEFAULT NULL)
 * @param mixed  $default  ค่า DEFAULT เมื่อไม่ยอมรับ NULL (null = ไม่มี DEFAULT)
 * @param string $comment  หมายเหตุของคอลัมน์ (ว่าง คือไม่มี)
 * @param string $after    ชื่อคอลัมน์ที่ต้องการให้อยู่ถัดจาก (ใช้เฉพาะตอนเพิ่มใหม่)
 *
 * @return bool true ถ้ามีการเพิ่มหรือแก้ไข
 */
function ensureColumn($db, $table_name, $column, $type, $nullable, $default = null, $comment = '', $after = '')
{
    // NULL/NOT NULL และ DEFAULT ต้องแยกจากกัน — คอลัมน์แบบ "NULL ได้ แต่มี
    // DEFAULT 0" (เช่น user.status) มีจริงใน core.sql ถ้าเขียนรวมกันไม่ได้
    // คอลัมน์กลุ่มนี้จะถูกข้ามไปตลอด แล้วระบบที่ปรับรุ่นก็ไม่เหมือนติดตั้งใหม่สักที
    $definition = $type.($nullable ? ' NULL' : ' NOT NULL');
    if ($default !== null) {
        $definition .= " DEFAULT '".str_replace("'", "''", $default)."'";
    } elseif ($nullable) {
        $definition .= ' DEFAULT NULL';
    }
    if ($comment != '') {
        $definition .= " COMMENT '".str_replace("'", "''", $comment)."'";
    }
    $info = columnInfo($db, $table_name, $column);
    if (!$info) {
        // ระบุ AFTER ได้เฉพาะเมื่อคอลัมน์ที่อ้างถึงมีอยู่จริงเท่านั้น ถ้าอ้างถึง
        // คอลัมน์ที่ไม่มี MySQL จะหยุดด้วย Unknown column แล้วการปรับรุ่นค้างกลางคัน
        // ตำแหน่งของคอลัมน์เป็นแค่ความสวยงาม ไม่คุ้มที่จะทำให้ปรับรุ่นไม่ผ่าน
        $position = empty($after) || !$db->fieldExists($table_name, $after) ? '' : " AFTER `$after`";
        $db->query("ALTER TABLE `$table_name` ADD `$column` $definition".$position);

        return true;
    }
    $type_ok = normalizeColumnType($info->Type) === normalizeColumnType($type);
    $null_ok = ($info->Null === 'YES') === (bool) $nullable;
    if ($default === null) {
        $default_ok = $info->Default === null;
    } else {
        // คอลัมน์ที่ "ไม่มี DEFAULT" คืนค่า Default เป็น null ซึ่ง (string) null คือ ''
        // เท่ากับค่าที่ต้องการพอดี ทำให้คอลัมน์แบบ NOT NULL เฉย ๆ ถูกมองว่าตรงแล้ว
        // ทั้งที่ยังขาด DEFAULT '' ต้องแยกสองกรณีออกจากกัน
        $default_ok = $info->Default !== null && (string) $info->Default === (string) $default;
    }
    if ($type_ok && $null_ok && $default_ok) {
        return false;
    }
    if (!$nullable) {
        // เติมค่าให้แถวที่เป็น NULL ก่อนสั่ง NOT NULL ไม่เช่นนั้นแถวเหล่านั้น
        // จะได้ค่าปริยายตามชนิดของคอลัมน์แทนที่จะเป็น DEFAULT ที่กำหนด
        $fill = $default === null ? '' : $default;
        $db->query("UPDATE `$table_name` SET `$column` = '".str_replace("'", "''", $fill)."' WHERE `$column` IS NULL");
    }
    $db->query("ALTER TABLE `$table_name` CHANGE `$column` `$column` $definition");

    return true;
}

/**
 * บังคับชนิดของคอลัมน์ AUTO_INCREMENT ให้ตรงกับ core.sql
 *
 * ⚠️ ใช้ ensureColumn() กับคอลัมน์แบบนี้ไม่ได้ เพราะคำสั่ง CHANGE ที่มันสร้าง
 * ไม่มีคำว่า AUTO_INCREMENT ติดไปด้วย ผลคือคอลัมน์เสียคุณสมบัตินับเลขอัตโนมัติ
 * แล้วการเพิ่มข้อมูลครั้งถัดไปจะล้มด้วย "Field 'id' doesn't have a default value"
 *
 * ระบบรุ่นเก่าหลายชุดประกาศเป็น int(10) unsigned ขณะที่ core.sql ใช้ int(11)
 * ถ้าไม่ปรับ ไซต์ที่ปรับรุ่นจะมีคอลัมน์คนละชนิดกับไซต์ที่ติดตั้งใหม่ตลอดไป
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $column
 * @param string $type เช่น int(11)
 *
 * @return bool true ถ้าเพิ่งแก้
 */
function ensureAutoIncrement($db, $table_name, $column, $type)
{
    $info = columnInfo($db, $table_name, $column);
    if (!$info) {
        return false;
    }
    // ต้องแก้เมื่อชนิดไม่ตรง **หรือ** เมื่อคอลัมน์ยังไม่ได้เป็น AUTO_INCREMENT
    //
    // ⚠️ กรณีหลังเจอบ่อยมาก : ตัวติดตั้งรุ่นเก่าประกาศ `id` เป็น NOT NULL เฉย ๆ
    // แล้วค่อยเติม AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่งตัวปรับรุ่น
    // ไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
    // ("Field 'id' doesn't have a default value")
    if (normalizeColumnType($info->Type) === normalizeColumnType($type)
        && stripos($info->Extra, 'auto_increment') !== false) {
        return false;
    }
    // AUTO_INCREMENT ต้องมี key คลุมอยู่ก่อนเสมอ ไม่งั้น MySQL ปฏิเสธ
    if (!$db->indexExists($table_name, 'PRIMARY')) {
        $db->query("ALTER TABLE `$table_name` ADD PRIMARY KEY (`$column`)");
    }
    $db->query("ALTER TABLE `$table_name` CHANGE `$column` `$column` $type NOT NULL AUTO_INCREMENT");

    return true;
}

/**
 * เพิ่มคอลัมน์ถ้ายังไม่มี โดยรับคำนิยามเป็นข้อความ SQL ตรง ๆ
 *
 * ต่างจาก ensureColumn() ตรงที่ "ไม่แตะคอลัมน์ที่มีอยู่แล้ว" ใช้กับคอลัมน์ที่
 * แต่ละระบบอาจปรับแต่งเองได้ หรือคอลัมน์ที่คำนิยามซับซ้อนเกินกว่าจะแยกเป็น
 * ชนิด/NULL/DEFAULT (เช่น ENUM, FLOAT, คอลัมน์ที่มี COMMENT ยาว)
 * ส่วนคอลัมน์ที่ต้อง "เหมือนกับที่ติดตั้งใหม่เป๊ะ ๆ" ให้ใช้ ensureColumn()
 *
 * @param Db $db
 * @param string $table_name
 * @param string $column
 * @param string $definition เช่น "INT(11) NOT NULL DEFAULT 0"
 * @param string $after ชื่อคอลัมน์ที่ต้องการให้อยู่ถัดจาก (ว่าง = ต่อท้าย)
 *
 * @return bool true ถ้าเพิ่งเพิ่ม
 */
function addColumn($db, $table_name, $column, $definition, $after = '')
{
    if ($db->fieldExists($table_name, $column)) {
        return false;
    }
    // ระบุ AFTER ได้เฉพาะเมื่อคอลัมน์ที่อ้างถึงมีอยู่จริง ไม่งั้น MySQL หยุดด้วย
    // Unknown column แล้วการปรับรุ่นค้างกลางคัน — ตำแหน่งคอลัมน์เป็นแค่ความสวยงาม
    $position = empty($after) || !$db->fieldExists($table_name, $after) ? '' : " AFTER `$after`";
    $db->query("ALTER TABLE `$table_name` ADD `$column` $definition".$position);

    return true;
}

/**
 * เพิ่ม index ตามรายการที่กำหนด ถ้ามีอยู่แล้วแต่คลุมคอลัมน์ไม่ตรง จะสร้างใหม่ให้
 *
 * indexExists() บอกได้แค่ว่ามี index ชื่อนี้ ไม่ได้บอกว่าคลุมคอลัมน์ไหน ระบบเดิม
 * ที่มี room_id(room_id) แต่ที่ต้องการคือ idx_room_meta(room_id, name) ถ้าดูแค่ชื่อ
 * จะข้ามไปตลอด แล้ว index ที่ได้ก็ไม่เหมือนระบบที่ติดตั้งใหม่
 *
 * @param Db     $db
 * @param string $table_name
 * @param array  $indexes รูปแบบ [ชื่อ index => คอลัมน์ที่อยู่ใน index]
 *
 * @return array ชื่อ index ที่เพิ่งสร้างหรือสร้างใหม่
 */
function ensureIndexes($db, $table_name, array $indexes)
{
    $changed = [];
    foreach ($indexes as $name => $columns) {
        $want = explode(',', str_replace(['`', ' '], '', $columns));
        $exists = indexColumns($db, $table_name, $name);
        if ($exists === $want) {
            continue;
        }
        if (!empty($exists)) {
            $db->query("ALTER TABLE `$table_name` DROP INDEX `$name`");
        }
        $db->query("ALTER TABLE `$table_name` ADD INDEX `$name` ($columns)");
        $changed[] = $name;
    }

    return $changed;
}

/**
 * ลบ index ที่ "ชื่อตรงแต่หน้าตาไม่ตรง" ทิ้ง เพื่อให้สร้างใหม่ให้ถูกได้
 *
 * ⚠️ ทำไมต้องมีตัวนี้แยกจาก ensureIndexes()
 *
 * ensureIndexes() เทียบเฉพาะ "คอลัมน์ในดัชนี" ไม่ได้เทียบว่าเป็น UNIQUE หรือไม่
 * และไม่ได้เทียบกรณีที่ "ชื่อดัชนีตรงแต่ครอบคนละคอลัมน์" — ของจริงมีทั้งสองแบบ
 * (oas มี `UNIQUE KEY customer_no (code)` คือชื่อหนึ่งแต่ครอบอีกคอลัมน์หนึ่ง
 *  และ `UNIQUE KEY barcode` ที่สคีมากลางต้องการเป็นดัชนีธรรมดา)
 * ดัชนีพวกนี้จึงรอดสายตา ensureIndexes() ไปได้ตลอด แล้วไซต์ที่ปรับรุ่นมาจะ
 * ต่างจากไซต์ที่ติดตั้งใหม่ถาวรโดยไม่มีอะไรฟ้อง
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $index   ชื่อดัชนี
 * @param string $columns คอลัมน์ที่ต้องการ (รูปแบบเดียวกับ ensureIndexes)
 * @param bool   $unique  ต้องการให้เป็น UNIQUE หรือไม่
 *
 * @return bool true = ลบทิ้งแล้ว (ผู้เรียกต้องสร้างใหม่เอง)
 */
function dropMismatchedIndex($db, $table_name, $index, $columns, $unique)
{
    $exists = indexColumns($db, $table_name, $index);
    if (empty($exists)) {
        return false;
    }
    $want = explode(',', str_replace(['`', ' '], '', $columns));
    if ($exists === $want && indexIsUnique($db, $table_name, $index) === (bool) $unique) {
        return false;
    }
    $db->query("ALTER TABLE `$table_name` DROP INDEX `$index`");

    return true;
}

/**
 * ลบ index ที่ไม่ต้องการแล้วออก (เช่นตัวที่ถูกแทนด้วย index รวมตัวใหม่)
 *
 * @param Db     $db
 * @param string $table_name
 * @param array  $indexes ชื่อ index ที่ต้องการลบ
 *
 * @return array ชื่อ index ที่ลบไปจริง
 */
function dropIndexes($db, $table_name, array $indexes)
{
    $dropped = [];
    foreach ($indexes as $name) {
        if ($db->indexExists($table_name, $name)) {
            $db->query("ALTER TABLE `$table_name` DROP INDEX `$name`");
            $dropped[] = $name;
        }
    }

    return $dropped;
}

/**
 * สร้างตารางจากไฟล์ SQL ถ้ายังไม่มีตารางนั้น
 *
 * ใช้แทนการเขียน CREATE TABLE ซ้ำไว้ในตัวปรับรุ่นอีกชุด ซึ่งวันหนึ่งจะต่างจาก
 * ที่อยู่ในไฟล์ SQL แล้วเครื่องที่ติดตั้งใหม่กับเครื่องที่ปรับรุ่นจะมีตาราง
 * คนละหน้าตาโดยไม่มีอะไรฟ้อง
 *
 * @param Db     $db
 * @param string $prefix     คำนำหน้าตาราง
 * @param string $table_name ชื่อตารางเต็ม (มี prefix แล้ว)
 *
 * @return bool true ถ้าเพิ่งสร้าง
 */
function ensureTable($db, $prefix, $table_name)
{
    if ($db->tableExists($table_name)) {
        return false;
    }
    $created = false;
    foreach (schemaFiles() as $file) {
        foreach (sqlCommands($file, $prefix) as $command) {
            // ⚠️ ต้องรับ "CREATE TABLE IF NOT EXISTS" ด้วย — บางโปรเจ็ค (documents)
            // เขียนแบบนั้นทั้งไฟล์ ถ้าไม่รับ ensureTable จะหานิยามไม่เจอแล้วโยน
            // ข้อยกเว้น "ไม่พบนิยามของตาราง" ทุกตาราง ทั้งที่ไฟล์มีอยู่ครบ
            if (preg_match('/^\s*CREATE TABLE (?:IF NOT EXISTS )?`'.preg_quote($table_name, '/').'`/i', $command)) {
                $db->query($command);
                $created = true;
            } elseif ($created && preg_match('/^\s*ALTER TABLE `'.preg_quote($table_name, '/').'`/i', $command)) {
                // ไฟล์ SQL ที่ export มาจาก phpMyAdmin แยก index กับ AUTO_INCREMENT
                // ออกไปเป็น ALTER TABLE ท้ายไฟล์ ถ้าไม่รันด้วย ตารางที่ตัวปรับรุ่น
                // สร้างจะไม่มี index เลย ต่างจากตารางที่ได้จากการติดตั้งใหม่
                $db->query($command);
            }
        }
    }
    if ($created) {
        return true;
    }
    throw new \Exception('ไม่พบนิยามของตาราง <code>'.$table_name.'</code> ในไฟล์ SQL ของชุดติดตั้ง กรุณาอัปโหลดโฟลเดอร์ install/ ให้ครบก่อนปรับรุ่น');
}

/**
 * สร้างผู้ดูแลระบบสูงสุด (id = 1) ของระบบที่ติดตั้งใหม่
 *
 * ใช้ร่วมกันระหว่าง install/step4.php (ติดตั้งผ่านหน้าเว็บ) กับ
 * install/cli-fresh.php (ติดตั้งจากบรรทัดคำสั่งเพื่อสร้างฐานทดสอบ) เพื่อให้
 * ฐานทดสอบเป็นผลของ "ตัวติดตั้งของจริง" ไม่ใช่สำเนา SQL ที่วันหนึ่งจะเพี้ยนจากกัน
 *
 * ของเดิมประกอบ SQL ด้วยการต่อสตริง ชื่อผู้ใช้ที่มีเครื่องหมาย ' จึงทำให้คำสั่งพัง
 * ตรงนี้ใช้ insert() ที่เป็น prepared statement แทน
 *
 * @param Db     $db
 * @param string $table_user   ชื่อตารางสมาชิก (มี prefix แล้ว)
 * @param string $username
 * @param string $password     รหัสผ่านแบบข้อความ
 * @param string $password_key คีย์ที่ผสมอยู่ในรหัสผ่านของทุกคน
 *
 * @return string salt ที่ใช้
 */
function createAdmin($db, $table_user, $username, $password, $password_key)
{
    include_once ROOT_PATH.'Kotchasan/Password.php';
    $salt = uniqid();
    $db->query("DELETE FROM `$table_user` WHERE `id` = 1");
    $db->insert($table_user, [
        'id' => 1,
        'username' => $username,
        'salt' => $salt,
        'password' => \Kotchasan\Password::hash($password, $password_key),
        'status' => 1,
        'active' => 1,
        'permission' => '',
        'name' => 'แอดมิน',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return $salt;
}

/**
 * ตรวจสอบว่าผู้ใช้ที่กรอกมาเป็นผู้ดูแลระบบสูงสุดจริง และปรับรหัสผ่านรุ่นเก่าให้ทันสมัย
 *
 * @param Db     $db
 * @param string $table_name
 * @param string $username
 * @param string $password
 * @param string $password_key
 *
 * @throws \Exception เมื่อไม่ใช่ผู้ดูแลสูงสุด หรือรหัสผ่านไม่ถูกต้อง
 */
function updateAdmin($db, $table_name, $username, $password, $password_key)
{
    include_once ROOT_PATH.'Kotchasan/Text.php';
    include_once ROOT_PATH.'Kotchasan/Password.php';
    $username = \Kotchasan\Text::username($username);
    $password = \Kotchasan\Text::password($password);
    $result = $db->first($table_name, [
        'username' => $username,
        'status' => 1
    ]);
    if (!$result || $result->id > 1) {
        throw new \Exception('ชื่อผู้ใช้ไม่ถูกต้อง หรือไม่ใช่ผู้ดูแลระบบสูงสุด');
    } elseif (\Kotchasan\Password::verify($password, $result->password, $result->salt, $password_key)) {
        // ถูกต้อง — ถ้ายังเป็น sha1 รุ่นเก่า (มีหรือไม่มี password_key) ให้เก็บใหม่เป็น bcrypt
        if (\Kotchasan\Password::needsRehash($result->password)) {
            // ⚠️ bcrypt ยาว 60 ตัวอักษร แต่ฐานรุ่น 6.x เก็บ password เป็น varchar(50) และฟังก์ชันนี้
            // ทำงาน "ก่อน" upgrade_core.php ขยายคอลัมน์ ตัวติดตั้งตั้ง sql_mode = '' ค่าจึงถูกตัด
            // เหลือ 50 ตัวเงียบ ๆ ปรับรุ่นขึ้นว่าสำเร็จ แต่ผู้ดูแลเข้าระบบไม่ได้อีกเลย (401)
            // และปรับรุ่นซ้ำก็ไม่ได้เพราะรหัสผ่านไม่ตรงแล้ว — ต้องขยายคอลัมน์ก่อนเขียน
            // (ชนิดเดียวกับที่ upgrade_core.php กำหนด)
            $info = columnInfo($db, $table_name, 'password');
            if ($info && preg_match('/char\((\d+)\)/i', $info->Type, $match) && (int) $match[1] < 255) {
                $db->query("ALTER TABLE `$table_name` MODIFY `password` VARCHAR(255) NOT NULL");
            }
            $hash = \Kotchasan\Password::hash($password, $password_key);
            $db->update($table_name, ['id' => $result->id], ['password' => $hash]);
            // ยืนยันว่าเก็บได้ครบ — รหัสผ่านที่ถูกตัดคือการล็อกผู้ดูแลออกจากระบบถาวร
            $saved = $db->first($table_name, ['id' => $result->id]);
            if (!$saved || $saved->password !== $hash) {
                throw new \Exception('บันทึกรหัสผ่านของผู้ดูแลระบบไม่สำเร็จ (คอลัมน์ password เก็บรหัสผ่านรูปแบบใหม่ได้ไม่ครบ) กรุณาขยายคอลัมน์ password ของตาราง '.$table_name.' เป็น VARCHAR(255) แล้วปรับรุ่นใหม่');
            }
        }
    } else {
        // แยกให้ออกระหว่าง "พิมพ์รหัสผ่านผิด" กับ "password_key ไม่ตรงกับที่ฐานข้อมูลนี้ใช้"
        //
        // อาการเหมือนกันเป๊ะ แต่สาเหตุคนละเรื่อง และกรณีหลังเจอบ่อยมากตอนปรับรุ่น
        // เพราะถ้าเอา settings/config.php ของชุดติดตั้งใหม่มาใช้แทนของเดิม
        // password_key จะเป็นคนละตัว แล้ว sha1(key.รหัสผ่าน.salt) จะไม่มีวันตรง
        // ไม่ว่าจะพิมพ์รหัสผ่านถูกแค่ไหน — และรหัสผ่านของสมาชิกทุกคนก็ใช้ไม่ได้ด้วย
        throw new \Exception(
            'รหัสผ่านไม่ถูกต้อง<br>'
            .'ถ้าแน่ใจว่ารหัสผ่านถูก ให้ตรวจ <code>password_key</code> ใน settings/config.php '
            .'ว่าเป็นค่าเดียวกับที่ระบบเดิมใช้หรือไม่ (ปัจจุบันคือ <code>'
            .htmlspecialchars($password_key, ENT_QUOTES).'</code>)<br>'
            .'ค่านี้ถูกผสมอยู่ในรหัสผ่านของสมาชิกทุกคน ถ้าใช้ไฟล์ config ของชุดติดตั้งใหม่ '
            .'แทนของเดิม จะเข้าระบบไม่ได้ทั้งระบบ — ให้นำ settings/config.php เดิมกลับมาก่อน'
        );
    }
}

/**
 * ไซต์ที่ติดตั้งอยู่แล้วในฐานข้อมูลนี้ มองจาก "ตาราง <prefix>_user"
 *
 * ⚠️ มีไว้กันอุบัติเหตุที่มองไม่เห็น : ติดตั้งใหม่ลงฐานที่มีไซต์อยู่แล้วโดยใช้
 * คำนำหน้าตารางคนละอัน จะได้ไซต์เปล่า ๆ ขึ้นมาอีกชุดวางซ้อนกับข้อมูลจริง
 * ตัวติดตั้งไม่ฟ้องอะไรเลยเพราะตารางที่มันสร้างไม่ได้ชนกับของเดิมสักตัว
 * ผู้ใช้เปิดเว็บมาเห็นข้อมูลหายหมด ทั้งที่ข้อมูลยังอยู่ครบใต้คำนำหน้าเดิม
 *
 * (เกิดขึ้นจริงกับ booking : ข้อมูลปี 2022 อยู่ใต้ `booking_` แต่ติดตั้งใหม่
 *  ด้วยคำนำหน้า `app_` แล้วเข้าใจว่าข้อมูลหายไปกับการปรับรุ่น)
 *
 * @param Db     $db     เชื่อมต่อและ USE ฐานที่จะตรวจแล้ว
 * @param string $dbname ชื่อฐานข้อมูล
 *
 * @return array คำนำหน้าตารางที่พบ เรียงตามตัวอักษร
 */
function existingInstallations($db, $dbname)
{
    $rows = $db->customQuery(
        "SELECT TABLE_NAME FROM `information_schema`.`TABLES`
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%\\_user'",
        true,
        [$dbname]
    );
    $prefixes = [];
    foreach ((array) $rows as $row) {
        $name = is_object($row) ? $row->TABLE_NAME : $row['TABLE_NAME'];
        $prefix = substr($name, 0, -5);
        if ($prefix !== '') {
            $prefixes[$prefix] = true;
        }
    }
    ksort($prefixes);

    return array_keys($prefixes);
}
