<?php
/**
 * install/upgrade_core.php
 *
 * การปรับรุ่นของตารางแกน (ตารางที่นิยามอยู่ใน install/core.sql) ไฟล์นี้ต้อง
 * "เหมือนกันทุกไบต์" ในทุกโปรเจ็คของ now.js — ตารางของแต่ละโปรเจ็คปรับรุ่นใน
 * install/upgrade2.php ของโปรเจ็คนั้นเอง
 *
 * ตัวแปรที่ต้องมีมาก่อนถูก include:
 *   $db         Db object ที่เชื่อมต่อแล้ว
 *   $db_config  ค่ากำหนดฐานข้อมูล (ต้องมี prefix)
 *   $content    แอเรย์สำหรับสะสมรายการผลลัพธ์
 *   $config     ค่ากำหนดปัจจุบันของระบบ
 */
if (!defined('ROOT_PATH')) {
    exit;
}

$prefix = $db_config['prefix'];

// =============================================================================
// user
// =============================================================================
$table_user = $prefix.'_user';
if (ensureTable($db, $prefix, $table_user)) {
    $content[] = '<li class="correct">user: สร้างตารางใหม่</li>';
}

// ระบบเดิมเป็น MyISAM + utf8mb3 ส่วนตารางที่สร้างใหม่เป็น InnoDB + utf8mb4
// ต้องแปลงก่อนปรับคอลัมน์เสมอ เพราะ CONVERT TO CHARACTER SET เลื่อนชนิด TEXT
// เป็น MEDIUMTEXT ถ้าแปลงทีหลังจะได้ชนิดคอลัมน์ไม่ตรงกับที่ติดตั้งใหม่
if (convertToInnoDB($db, $table_user)) {
    $content[] = '<li class="correct">user: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $table_user)) {
    $content[] = '<li class="correct">user: แปลงเป็น utf8mb4</li>';
}

// token: ย้ายไปเก็บที่ตาราง user_session แล้ว
if ($db->fieldExists($table_user, 'token')) {
    $db->query("ALTER TABLE `$table_user` DROP COLUMN `token`");
    $content[] = '<li class="correct">user: ลบ token</li>';
}

// เดิม drop ทุก index ทิ้งแล้วไปสร้างใหม่ท้ายบล็อก ซึ่งไม่มีเงื่อนไขอะไรเลย
// ผลคือทุกครั้งที่ปรับรุ่น ตาราง user จะโดน drop/create index 6 ตัวใหม่หมด
// ทั้งที่ไม่มีอะไรเปลี่ยน (บนตารางสมาชิกขนาดใหญ่คือการ rebuild ที่แพงมาก)
// ตอนนี้ drop เฉพาะตัวที่ "ชนิดยังไม่ตรงกับที่ต้องการ" เท่านั้น
// ส่วน status ยังคง drop ทิ้งเสมอ เพราะถูกแทนด้วย idx_status(active, status)
$_want_unique = ['username', 'id_card', 'phone'];
$_want_index = ['activatecode', 'line_uid', 'telegram_id'];
foreach (array_merge($_want_unique, $_want_index, ['status']) as $_idx) {
    if (!$db->indexExists($table_user, $_idx)) {
        continue;
    }
    if (in_array($_idx, $_want_unique, true) && indexIsUnique($db, $table_user, $_idx)) {
        continue;
    }
    if (in_array($_idx, $_want_index, true) && !indexIsUnique($db, $table_user, $_idx)) {
        continue;
    }
    $db->query("ALTER TABLE `$table_user` DROP INDEX `$_idx`");
}

// rename create_date → created_at
if ($db->fieldExists($table_user, 'create_date')) {
    $db->query("ALTER TABLE `$table_user` CHANGE `create_date` `created_at` DATETIME NULL DEFAULT NULL");
    $content[] = '<li class="correct">user: เปลี่ยนชื่อ create_date → created_at</li>';
}
// คอลัมน์ที่ต้องมีและต้องตรงกับ core.sql — ลำดับในแอเรย์คือลำดับที่ใช้เป็น AFTER
// เมื่อต้องเพิ่มคอลัมน์ใหม่ เพื่อให้เรียงเหมือนระบบที่ติดตั้งใหม่
// [ชื่อคอลัมน์ => [ชนิด, ยอมรับ NULL, ค่า DEFAULT, คอลัมน์ที่อยู่ก่อนหน้า]]
$_columns = [
    'username' => ['varchar(50)', true, null, 'id'],
    'salt' => ['varchar(32)', false, '', 'username'],
    'password' => ['varchar(255)', false, null, 'salt'],
    'token_expires' => ['datetime', true, null, 'password'],
    'status' => ['tinyint(1)', true, 0, 'token_expires'],
    'permission' => ['text', true, null, 'status'],
    'name' => ['varchar(150)', false, null, 'permission'],
    'sex' => ['varchar(1)', true, null, 'name'],
    'tax_id' => ['varchar(13)', true, null, 'id_card'],
    'birthday' => ['date', true, null, 'tax_id'],
    'address' => ['varchar(64)', true, null, 'birthday'],
    'address2' => ['varchar(64)', true, null, 'address'],
    'phone' => ['varchar(20)', true, null, 'address2'],
    'phone1' => ['varchar(20)', true, null, 'phone'],
    'provinceID' => ['smallint(3)', true, null, 'phone1'],
    'province' => ['varchar(64)', true, null, 'provinceID'],
    'zipcode' => ['varchar(5)', true, null, 'province'],
    'country' => ['varchar(2)', true, 'TH', 'zipcode'],
    'active' => ['tinyint(1)', true, 1, 'created_at'],
    'line_uid' => ['varchar(33)', true, null, 'social'],
    'telegram_id' => ['varchar(20)', true, null, 'line_uid'],
    'activatecode' => ['varchar(64)', true, null, 'telegram_id'],
    'visited' => ['int(11)', false, 0, 'activatecode'],
    'website' => ['varchar(255)', true, null, 'visited'],
    'company' => ['varchar(64)', true, null, 'website']
];
foreach ($_columns as $_col => $_def) {
    if (ensureColumn($db, $table_user, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">user: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
// phone และ id_card กำลังจะเป็น UNIQUE ค่าว่างต้องเก็บเป็น NULL ไม่ใช่ ''
foreach (['phone', 'id_card'] as $_col) {
    $db->query("UPDATE `$table_user` SET `$_col` = NULL WHERE `$_col` = ''");
}
// id_card: ระบบเก่าบางชุดเป็น varchar(20) NOT NULL
// เงื่อนไขเดิมคือ "มีคอลัมน์ id_card ไหม" ซึ่งเป็นจริงตลอดหลังรอบแรก
// ทำให้ ALTER ซ้ำทุกครั้งที่ปรับรุ่น = rebuild ตาราง user ทั้งตารางฟรี ๆ
if (ensureColumn($db, $table_user, 'id_card', 'varchar(13)', true, null, '', 'sex')) {
    $content[] = '<li class="correct">user: ปรับคอลัมน์ id_card</li>';
    $db->query("UPDATE `$table_user` SET `id_card` = NULL WHERE `id_card` = ''");
}
// social: tinyint → enum (ย้ายค่า 0-4 เป็นชื่อก่อนเปลี่ยนชนิด)
if ($db->isColumnType($table_user, 'social', 'tinyint')) {
    $db->query("ALTER TABLE `$table_user` CHANGE `social` `social` VARCHAR(32) NULL DEFAULT NULL");
    $db->query("UPDATE `$table_user` SET `social` = 'user' WHERE `social` = 0 OR `social` IS NULL");
    $db->query("UPDATE `$table_user` SET `social` = 'facebook' WHERE `social` = 1");
    $db->query("UPDATE `$table_user` SET `social` = 'google' WHERE `social` = 2");
    $db->query("UPDATE `$table_user` SET `social` = 'line' WHERE `social` = 3");
    $db->query("UPDATE `$table_user` SET `social` = 'telegram' WHERE `social` = 4");
    $db->query("ALTER TABLE `$table_user` CHANGE `social` `social` ENUM('user','facebook','google','line','telegram') NULL DEFAULT 'user'");
    $content[] = '<li class="correct">user: แก้ไข social เป็น ENUM</li>';
}

foreach ($_want_index as $_idx) {
    if (!$db->indexExists($table_user, $_idx)) {
        $db->query("ALTER TABLE `$table_user` ADD INDEX `$_idx` (`$_idx`)");
        $content[] = '<li class="correct">user: เพิ่ม index '.$_idx.'</li>';
    }
}
// คอลัมน์กลุ่มนี้กำลังจะเป็น UNIQUE ซึ่ง NULL ซ้ำกันได้ แต่ '' ซ้ำไม่ได้
// การล้าง '' เป็น NULL ของเดิมอยู่ในเงื่อนไข "เปลี่ยนชนิดคอลัมน์" เท่านั้น
// ระบบที่ชนิดคอลัมน์ถูกต้องอยู่แล้ว (หรือปรับรุ่นค้างไว้จากรอบก่อน) จึงข้าม
// การล้างค่า แล้วไปล้มที่ ADD UNIQUE ด้วย Duplicate entry '' for key 'id_card'
foreach ($_want_unique as $_idx) {
    if ($db->indexExists($table_user, $_idx)) {
        continue;
    }
    $_ci = columnInfo($db, $table_user, $_idx);
    if ($_ci && $_ci->Null === 'YES') {
        $db->query("UPDATE `$table_user` SET `$_idx` = NULL WHERE `$_idx` = ''");
    }
    // ค่าที่ซ้ำกันจริง ๆ สร้าง UNIQUE ไม่ได้ ต้องบอกว่าซ้ำที่ค่าไหน
    // ไม่ใช่ปล่อยให้หยุดด้วย SQLSTATE ที่ไม่ได้บอกว่าต้องไปแก้อะไร
    $_dup = $db->customQuery(
        "SELECT `$_idx` AS `v`, COUNT(*) AS `c` FROM `$table_user`
         WHERE `$_idx` IS NOT NULL GROUP BY `$_idx` HAVING `c` > 1 LIMIT 10"
    );
    if (!empty($_dup)) {
        $_list = [];
        foreach ($_dup as $_row) {
            $_list[] = htmlspecialchars((string) $_row->v, ENT_QUOTES).' ('.(int) $_row->c.' รายการ)';
        }
        throw new \Exception(
            'ไม่สามารถสร้าง UNIQUE <code>'.$_idx.'</code> ของตาราง '.$table_user.' ได้ '
            .'เพราะมีข้อมูลซ้ำกันอยู่<br>'.implode(', ', $_list).'<br>'
            .'กรุณาแก้ไขข้อมูลสมาชิกให้ '.$_idx.' ไม่ซ้ำกัน (หรือลบค่าที่ซ้ำออกให้ว่าง) แล้วปรับรุ่นใหม่อีกครั้ง'
        );
    }
    $db->query("ALTER TABLE `$table_user` ADD UNIQUE `$_idx` (`$_idx`)");
    $content[] = '<li class="correct">user: เพิ่ม UNIQUE '.$_idx.'</li>';
}
if (ensureIndexes($db, $table_user, ['idx_status' => '`active`, `status`'])) {
    $content[] = '<li class="correct">user: เพิ่ม index idx_status(active, status)</li>';
}
$content[] = '<li class="correct">user อัปเกรดสำเร็จ</li>';

// =============================================================================
// category
// =============================================================================
$table_category = $prefix.'_category';
if (ensureTable($db, $prefix, $table_category)) {
    $content[] = '<li class="correct">category: สร้างตารางใหม่</li>';
}
if (convertToInnoDB($db, $table_category)) {
    $content[] = '<li class="correct">category: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $table_category)) {
    $content[] = '<li class="correct">category: แปลงเป็น utf8mb4</li>';
}
// migrate published → is_active (ทำก่อนบังคับชนิดคอลัมน์)
if (!$db->fieldExists($table_category, 'is_active')) {
    $db->query("ALTER TABLE `$table_category` ADD `is_active` TINYINT(1) NULL");
    if ($db->fieldExists($table_category, 'published')) {
        $db->query("UPDATE `$table_category` SET `is_active` = `published`");
    } else {
        $db->query("UPDATE `$table_category` SET `is_active` = 1");
    }
    $content[] = '<li class="correct">category: เพิ่ม is_active</li>';
}
if ($db->fieldExists($table_category, 'published')) {
    $db->query("ALTER TABLE `$table_category` DROP COLUMN `published`");
    $content[] = '<li class="correct">category: ลบ published</li>';
}
// ⚠️ ต้องครบทุกคอลัมน์ที่ core.sql ประกาศ ไม่ใช่เฉพาะคอลัมน์ที่ "เคยมีปัญหา"
// ระบบรุ่นเก่าบางชุดใช้ขนาดเล็กกว่า (omsin: type varchar(10) topic varchar(30))
// ถ้าไม่บังคับ ไซต์ที่ปรับรุ่นจะได้คอลัมน์คนละขนาดกับไซต์ที่ติดตั้งใหม่ตลอดไป
foreach ([
    'type' => ['varchar(20)', false, null, ''],
    'category_id' => ['varchar(10)', false, '0', 'type'],
    'language' => ['varchar(2)', false, '', 'category_id'],
    'topic' => ['varchar(150)', false, null, 'language'],
    'color' => ['varchar(16)', true, null, 'topic'],
    'is_active' => ['tinyint(1)', false, 1, 'color']
] as $_col => $_def) {
    if (ensureColumn($db, $table_category, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">category: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $table_category, [
    'type' => '`type`',
    'category_id' => '`category_id`',
    'language' => '`language`'
])) {
    $content[] = '<li class="correct">category: ปรับ index</li>';
}
$content[] = '<li class="correct">category อัปเกรดสำเร็จ</li>';

// =============================================================================
// logs
// =============================================================================
$table_logs = $prefix.'_logs';
if (ensureTable($db, $prefix, $table_logs)) {
    $content[] = '<li class="correct">logs: สร้างตารางใหม่</li>';
}
if (convertToInnoDB($db, $table_logs)) {
    $content[] = '<li class="correct">logs: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $table_logs)) {
    $content[] = '<li class="correct">logs: แปลงเป็น utf8mb4</li>';
}
if ($db->fieldExists($table_logs, 'create_date')) {
    $db->query("ALTER TABLE `$table_logs` CHANGE `create_date` `created_at` DATETIME NOT NULL");
    $content[] = '<li class="correct">logs: เปลี่ยนชื่อ create_date → created_at</li>';
}
// logs มักเป็นตารางที่ใหญ่ที่สุดในระบบ จึงเป็นจุดที่การ ALTER ซ้ำแพงที่สุด
foreach ([
    'created_at' => ['datetime', false, null, 'action'],
    'reason' => ['text', true, null, 'created_at'],
    'member_id' => ['int(11)', false, null, 'reason'],
    'topic' => ['text', false, null, 'member_id'],
    'datas' => ['text', true, null, 'topic']
] as $_col => $_def) {
    if (ensureColumn($db, $table_logs, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">logs: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $table_logs, [
    'src_id' => '`src_id`',
    'module' => '`module`',
    'action' => '`action`',
    'created_at' => '`created_at`'
])) {
    $content[] = '<li class="correct">logs: ปรับ index</li>';
}
$content[] = '<li class="correct">logs อัปเกรดสำเร็จ</li>';

// =============================================================================
// login_attempt
// =============================================================================
$table_login_attempt = $prefix.'_login_attempt';
if (ensureTable($db, $prefix, $table_login_attempt)) {
    $content[] = '<li class="correct">login_attempt: สร้างตารางใหม่</li>';
}
if (ensureIndexes($db, $table_login_attempt, [
    'idx_ip_time' => '`ip_address`, `attempted_at`',
    'idx_user_time' => '`username`, `attempted_at`'
])) {
    $content[] = '<li class="correct">login_attempt: ปรับ index</li>';
}
$content[] = '<li class="correct">login_attempt อัปเกรดสำเร็จ</li>';

// =============================================================================
// number — running number กลาง (Index\Number\Model)
// =============================================================================
$table_number = $prefix.'_number';
if (ensureTable($db, $prefix, $table_number)) {
    $content[] = '<li class="correct">number: สร้างตารางใหม่</li>';
} else {
    if ($db->fieldExists($table_number, 'last_update') && !$db->fieldExists($table_number, 'updated_at')) {
        $db->query("ALTER TABLE `$table_number` CHANGE `last_update` `updated_at` DATE NULL DEFAULT NULL");
        $content[] = '<li class="correct">number: เปลี่ยนชื่อ last_update → updated_at</li>';
    }
    if (!$db->indexExists($table_number, 'PRIMARY')) {
        $db->query("ALTER TABLE `$table_number` ADD PRIMARY KEY (`type`,`prefix`)");
        $content[] = '<li class="correct">number: เพิ่ม PRIMARY KEY</li>';
    }
    foreach ([
        'prefix' => ['varchar(20)', false, '', 'type'],
        'auto_increment' => ['int(11)', false, 0, 'prefix'],
        'updated_at' => ['date', true, null, 'auto_increment']
    ] as $_col => $_def) {
        if (ensureColumn($db, $table_number, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
            $content[] = '<li class="correct">number: ปรับคอลัมน์ '.$_col.'</li>';
        }
    }
    if (convertToUtf8mb4($db, $table_number)) {
        $content[] = '<li class="correct">number: แปลงเป็น utf8mb4</li>';
    }
}
$content[] = '<li class="correct">number อัปเกรดสำเร็จ</li>';

// =============================================================================
// user_meta
// =============================================================================
$table_user_meta = $prefix.'_user_meta';
if (ensureTable($db, $prefix, $table_user_meta)) {
    $content[] = '<li class="correct">user_meta: สร้างตารางใหม่</li>';
}
if (convertToInnoDB($db, $table_user_meta)) {
    $content[] = '<li class="correct">user_meta: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $table_user_meta)) {
    $content[] = '<li class="correct">user_meta: แปลงเป็น utf8mb4</li>';
}
// ชนิดคอลัมน์ต้องตรง core.sql — ระบบรุ่นเก่าบางชุดประกาศ value เป็น int(11) (oms 5.0.0)
// แต่ที่นี่เก็บได้หลายอย่าง (รหัสแผนกจาก category · ค่าที่โมดูลฝากไว้) จึงต้องเป็น varchar
foreach ([
    'value' => ['varchar(10)', false, null, ''],
    'name' => ['varchar(20)', false, null, 'value'],
    'member_id' => ['int(11)', false, null, 'name']
] as $_col => $_def) {
    if (ensureColumn($db, $table_user_meta, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">user_meta: ปรับ '.$_col.' เป็น '.$_def[0].'</li>';
    }
}
// ดัชนี name เดี่ยว ๆ ของรุ่นเก่าซ้ำกับ member_id(member_id, name) — core.sql ไม่มี
if ($db->indexExists($table_user_meta, 'name')) {
    $db->query("ALTER TABLE `$table_user_meta` DROP INDEX `name`");
    $content[] = '<li class="correct">user_meta: ลบดัชนีเกิน name</li>';
}
if (ensureIndexes($db, $table_user_meta, ['member_id' => '`member_id`, `name`'])) {
    $content[] = '<li class="correct">user_meta: ปรับ index member_id(member_id, name)</li>';
}
$content[] = '<li class="correct">user_meta อัปเกรดสำเร็จ</li>';

// =============================================================================
// user_session
// =============================================================================
$table_user_session = $prefix.'_user_session';
if (ensureTable($db, $prefix, $table_user_session)) {
    $content[] = '<li class="correct">user_session: สร้างตารางใหม่</li>';
}
if (ensureIndexes($db, $table_user_session, [
    'member_id' => '`member_id`',
    'expires_at' => '`expires_at`'
])) {
    $content[] = '<li class="correct">user_session: ปรับ index</li>';
}
$content[] = '<li class="correct">user_session อัปเกรดสำเร็จ</li>';

// =============================================================================
// language
// =============================================================================
$table_language = $prefix.'_language';
if (ensureTable($db, $prefix, $table_language)) {
    $content[] = '<li class="correct">language: สร้างตารางใหม่</li>';
}
if (convertToInnoDB($db, $table_language)) {
    $content[] = '<li class="correct">language: แปลงเป็น InnoDB</li>';
}
if (convertToUtf8mb4($db, $table_language)) {
    $content[] = '<li class="correct">language: แปลงเป็น utf8mb4</li>';
}
// CONVERT TO CHARACTER SET เลื่อนชนิด TEXT เป็น MEDIUMTEXT
// ต้องดึงกลับมาเป็น TEXT ให้เหมือนระบบที่ติดตั้งใหม่
// id เป็น AUTO_INCREMENT ต้องใช้ ensureAutoIncrement ไม่ใช่ ensureColumn
// (ระบบรุ่นเก่าประกาศเป็น int(10) unsigned — omsin เจอจริง)
if (ensureAutoIncrement($db, $table_language, 'id', 'int(11)')) {
    $content[] = '<li class="correct">language: ปรับคอลัมน์ id</li>';
}
foreach ([
    'key' => ['text', false, null, 'id'],
    'type' => ['varchar(5)', false, null, 'key'],
    'th' => ['text', true, null, 'type'],
    'en' => ['text', true, null, 'th']
] as $_col => $_def) {
    if (ensureColumn($db, $table_language, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3])) {
        $content[] = '<li class="correct">language: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
foreach (['js', 'la', 'owner'] as $_col) {
    if ($db->fieldExists($table_language, $_col)) {
        $db->query("ALTER TABLE `$table_language` DROP COLUMN `$_col`");
        $content[] = '<li class="correct">language: ลบ '.$_col.'</li>';
    }
}
$content[] = '<li class="correct">language อัปเกรดสำเร็จ</li>';

// =============================================================================
// PRIMARY KEY และ AUTO_INCREMENT ของตารางที่ใช้ `id` เป็นคีย์
//
// ไฟล์ SQL ที่ export มาจาก phpMyAdmin แยก PRIMARY KEY กับ AUTO_INCREMENT ออกไป
// เป็น ALTER TABLE ท้ายไฟล์ ระบบเก่าที่ import ไม่ครบ (หรือ restore มาจาก dump
// ที่ตัดท้ายไฟล์ทิ้ง) จะได้ตารางที่ id ไม่ใช่ AUTO_INCREMENT แล้วการเพิ่มข้อมูล
// ใหม่จะล้มด้วย Duplicate entry '0' for key 'PRIMARY' ตั้งแต่แถวที่สอง
// =============================================================================
foreach ([$table_user, $table_logs, $table_language, $table_login_attempt] as $_t) {
    if (!$db->indexExists($_t, 'PRIMARY')) {
        $db->query("ALTER TABLE `$_t` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">'.$_t.': เพิ่ม PRIMARY KEY (id)</li>';
    }
    if (!isAutoIncrement($db, $_t, 'id')) {
        $db->query("ALTER TABLE `$_t` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT");
        $content[] = '<li class="correct">'.$_t.': กำหนด id เป็น AUTO_INCREMENT</li>';
    }
}

// =============================================================================
// migration — ประวัติการปรับรุ่นของไซต์นี้เอง
//
// ต้องสร้างก่อนจบการปรับรุ่น เพราะ upgrade2.php บันทึกรุ่นลงตารางนี้เป็นขั้นตอน
// สุดท้าย ไซต์ที่เราเข้าไม่ถึงจะได้ตอบตัวเองได้ว่าอยู่รุ่นไหนมาตั้งแต่เมื่อไร
// =============================================================================
$table_migration = $prefix.'_migration';
if (ensureTable($db, $prefix, $table_migration)) {
    $content[] = '<li class="correct">migration: สร้างตารางใหม่</li>';
}

// =============================================================================
// timeline_idempotency — Timeline Provider
//
// เก็บผลของ action ที่ Hub สั่งมาแล้ว เพื่อให้คำขอที่มี idempotency_key ซ้ำได้
// คำตอบเดิมโดยไม่ทำงานซ้ำ · กด "บันทึกว่าติดต่อแล้ว" สองครั้งเพราะเน็ตช้าต้อง
// ไม่ได้สองแถว
// =============================================================================
$table_timeline = $prefix.'_timeline_idempotency';
if (ensureTable($db, $prefix, $table_timeline)) {
    $content[] = '<li class="correct">timeline_idempotency: สร้างตารางใหม่</li>';
}
$content[] = '<li class="correct">timeline_idempotency อัปเกรดสำเร็จ</li>';

// =============================================================================
// ตารางของโมดูลที่ติดตั้งอยู่ — โมดูลดูแลการปรับรุ่นของตัวเอง
//
// โมดูลที่มีตารางของตัวเองวางไฟล์ไว้สองไฟล์: install/database.sql (นิยามตาราง
// สำหรับการติดตั้งใหม่) และ install/upgrade.php (พาฐานเดิมมาถึงนิยามนั้น)
// ตัวแปรที่ใช้ได้ในไฟล์นั้นคือชุดเดียวกับที่ไฟล์นี้ใช้ ($db, $db_config, $prefix,
// $content, $config) — เขียนเงื่อนไขแบบ "ต้องแก้ไหม" เหมือนกันทุกประการ
//
// ที่ต้องอยู่ในไฟล์แกน ไม่ใช่ใน upgrade2.php ของแต่ละโปรเจ็ค เพราะทุกโปรเจ็คที่
// รับโมดูลไปต้องได้พฤติกรรมเดียวกันโดยไม่ต้องไปแก้ตัวปรับรุ่นของตัวเองอีก
// =============================================================================
foreach (glob(ROOT_PATH.'modules/*/install/upgrade.php') ?: [] as $_module_upgrade) {
    include $_module_upgrade;
}

// =============================================================================
// คอลัมน์ที่ระบบนี้ไม่รู้จัก ต้องไม่ขวางการเขียนของระบบนี้
//
// ต้องอยู่ "หลัง" โมดูลทุกตัว — คอลัมน์ที่โมดูลเพิ่มให้ตารางอื่นเป็นของที่ประกาศไว้
// ถ้าเช็กก่อนโมดูลปรับรุ่น จะแยกไม่ออกว่าคอลัมน์ไหนเป็นของเก่าที่ตกค้างจริง
// (ดูเหตุผลเต็มที่ ensureForeignColumnsDefault ใน common.php)
// =============================================================================
ensureForeignColumnsDefault($db, $prefix, $content);
