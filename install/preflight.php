<?php
/**
 * install/preflight.php — ตรวจก่อนแตะฐานข้อมูล
 *
 * ไฟล์นี้ต้อง "เหมือนกันทุกไบต์" ในทุกโปรเจ็คของ now.js เช่นเดียวกับ
 * install/upgrade_core.php — ข้อตรวจเฉพาะของแต่ละโปรเจ็คให้เขียนไว้ใน
 * install/preflight-module.php ของโปรเจ็คนั้น (ดูท้ายไฟล์)
 *
 * ทำไมต้องมี
 * ----------
 * ไซต์ที่ติดตั้งไปแล้วเราเข้าไม่ถึงเลย ผู้ใช้เป็นคนกดปรับรุ่นเอง ดู log ไม่เป็น
 * และถามเราไม่ได้ ตัวปรับรุ่นจึงต้อง "หยุดก่อนแตะ" เมื่อเงื่อนไขไม่ครบ พร้อม
 * บอกวิธีแก้เป็นภาษาคน ดีกว่าเดินไปครึ่งทางแล้วพังคาไว้โดยไม่มีใครช่วยได้
 *
 * ทุกฟังก์ชันในไฟล์นี้ต้อง "อ่านอย่างเดียว" ยกเว้น probePrivileges() ที่สร้าง
 * ตารางทดสอบของตัวเองแล้วลบทิ้ง และ stampMigration()/writeUpgradeLog() ที่ถูก
 * เรียกหลังการปรับรุ่นเสร็จแล้วเท่านั้น
 */
if (!defined('ROOT_PATH')) {
    exit;
}

/**
 * รายชื่อตารางทั้งหมดของ prefix นี้ในฐานข้อมูลปัจจุบัน
 *
 * ⚠️ ฐานเดียวอาจมีหลายผลิตภัณฑ์หรือหลายรุ่นอยู่ด้วยกัน (พบจริง: acc_oas มีทั้ง
 * app_ และ oas_ · inventory_db มี app_/inventory2_/inventory3_/inventory4_/print_)
 * ทุกอย่างในตัวปรับรุ่นจึงต้องอ้างชื่อตารางจาก prefix ของตัวเองเสมอ
 * ห้ามไล่ตามชื่อตารางที่เจอในฐาน
 *
 * @param Db     $db
 * @param string $prefix
 *
 * @return array
 */
function prefixTables($db, $prefix)
{
    $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'\\_%';
    $result = $db->customQuery(
        "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`TABLES`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE'
           AND `TABLE_NAME` LIKE '$like' ORDER BY `TABLE_NAME`"
    );
    $tables = [];
    foreach ((array) $result as $row) {
        $tables[] = $row->t;
    }

    return $tables;
}

/**
 * นับจำนวนแถวจริงของแต่ละตาราง
 *
 * ⚠️ ต้อง COUNT(*) เท่านั้น ห้ามใช้ information_schema.TABLE_ROWS เพราะของ InnoDB
 * เป็นค่าประมาณจากสถิติ (คลาดเคลื่อนได้หลายสิบเปอร์เซ็นต์) ใช้เป็นหลักฐานว่า
 * "ข้อมูลไม่หาย" ไม่ได้
 *
 * @param Db    $db
 * @param array $tables
 *
 * @return array [ชื่อตาราง => จำนวนแถว]
 */
function countRows($db, array $tables)
{
    $counts = [];
    foreach ($tables as $table) {
        $row = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$table`");
        $counts[$table] = empty($row) ? 0 : (int) $row[0]->c;
    }

    return $counts;
}

/**
 * รุ่นของเซิร์ฟเวอร์ฐานข้อมูล คืนเป็น [ข้อความเต็ม, ตัวเลขรุ่น, เป็น MariaDB ไหม]
 *
 * ⚠️ MariaDB 10+ รายงานรุ่นเป็น "5.5.5-10.11.14-MariaDB" (คำนำหน้า 5.5.5 ปลอม
 * ที่ใส่ไว้ให้ไคลเอนต์เก่าไม่สับสน) ถ้าอ่านตรง ๆ จะสรุปผิดว่าเซิร์ฟเวอร์เก่าเกินไป
 *
 * @param Db $db
 *
 * @return array
 */
function serverVersion($db)
{
    $row = $db->customQuery('SELECT VERSION() AS `v`');
    $full = empty($row) ? '' : (string) $row[0]->v;
    $isMaria = stripos($full, 'mariadb') !== false;
    $number = $full;
    if ($isMaria && strpos($number, '5.5.5-') === 0) {
        $number = substr($number, 6);
    }
    $number = preg_match('/^(\d+\.\d+\.\d+)/', $number, $match) ? $match[1] : '0.0.0';

    return [$full, $number, $isMaria];
}

/**
 * ค่าที่ซ้ำกันในคอลัมน์ที่กำลังจะเป็น UNIQUE
 *
 * ข้าม NULL และค่าว่าง เพราะตัวปรับรุ่นแปลง '' เป็น NULL ให้ก่อนสร้าง UNIQUE อยู่แล้ว
 * (NULL ซ้ำกันได้ในดัชนี UNIQUE ของ MySQL)
 *
 * @param Db     $db
 * @param string $table
 * @param string $column
 * @param int    $limit
 *
 * @return array [['value' => ค่า, 'rows' => จำนวนแถว, 'ids' => 'id ตัวอย่าง']]
 */
function duplicateValues($db, $table, $column, $limit = 10)
{
    $rows = $db->customQuery(
        "SELECT `$column` AS `v`, COUNT(*) AS `c`, GROUP_CONCAT(`id` ORDER BY `id` SEPARATOR ', ') AS `ids`
         FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != ''
         GROUP BY `$column` HAVING `c` > 1 ORDER BY `c` DESC LIMIT $limit"
    );
    $result = [];
    foreach ((array) $rows as $row) {
        $result[] = [
            'value' => (string) $row->v,
            'rows' => (int) $row->c,
            'ids' => (string) $row->ids
        ];
    }

    return $result;
}

/**
 * ทดลองใช้สิทธิที่ตัวปรับรุ่นต้องใช้จริง บนตารางทดสอบของตัวเอง แล้วลบทิ้ง
 *
 * ตรวจจาก SHOW GRANTS ไม่ได้ผลเสมอไป (สิทธิระดับฐานข้อมูล/ระดับตาราง/ผ่าน role
 * เขียนได้หลายแบบ) ลองทำจริงตรง ๆ ตอบได้ตรงกว่า และไม่แตะข้อมูลของผู้ใช้เลย
 *
 * @param Db     $db
 * @param string $prefix
 *
 * @return string ว่าง = ผ่าน, มีข้อความ = ทำอะไรไม่ได้
 */
function probePrivileges($db, $prefix)
{
    $probe = $prefix.'_upgrade_probe';
    try {
        $db->query("DROP TABLE IF EXISTS `$probe`");
        $db->query("CREATE TABLE `$probe` (`id` int(11) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("ALTER TABLE `$probe` ADD `probe` varchar(10) NULL DEFAULT NULL");
        $db->query("ALTER TABLE `$probe` ADD INDEX `probe` (`probe`)");
        $db->query("INSERT INTO `$probe` (`id`, `probe`) VALUES (1, 'x')");
        $db->query("UPDATE `$probe` SET `probe` = 'y' WHERE `id` = 1");
        $db->query("DELETE FROM `$probe` WHERE `id` = 1");
        $db->query("DROP TABLE `$probe`");
    } catch (\Exception $exc) {
        // พยายามเก็บกวาดให้เรียบร้อย ถ้าลบไม่ได้ก็ไม่เป็นไร ตารางนี้ว่างเปล่า
        try {
            $db->query("DROP TABLE IF EXISTS `$probe`");
        } catch (\Exception $ignore) {
        }

        return $exc->getMessage();
    }

    return '';
}

/**
 * ตรวจทุกอย่างที่ต้องรู้ก่อนเริ่มปรับรุ่น
 *
 * @param Db    $db
 * @param array $db_config  ค่ากำหนดฐานข้อมูล (ต้องมี prefix)
 * @param array $config     settings/config.php ปัจจุบันของไซต์
 * @param array  $new_config  install/settings/config.php ของชุดติดตั้งใหม่
 * @param string $config_file ไฟล์ที่ตัวปรับรุ่นจะเขียนค่ากำหนดลงไป
 *                            (ว่าง = settings/config.php ของโปรเจ็ค)
 *
 * @return array ['errors' => [], 'warnings' => [], 'notes' => [], 'tables' => [], 'counts' => []]
 */
function preflight($db, $db_config, $config, $new_config, $config_file = '')
{
    $result = [
        'errors' => [],
        'warnings' => [],
        'notes' => [],
        'tables' => [],
        'counts' => []
    ];
    $prefix = $db_config['prefix'];
    $table_user = $prefix.'_user';

    // -------------------------------------------------------------------------
    // 1. รุ่นของเซิร์ฟเวอร์ฐานข้อมูล
    // -------------------------------------------------------------------------
    list($version_full, $version_number, $is_maria) = serverVersion($db);
    $min = $is_maria ? '5.5.0' : '5.5.3';
    if (version_compare($version_number, $min, '<')) {
        $result['errors'][] = 'ฐานข้อมูลของเซิร์ฟเวอร์เป็นรุ่น <code>'.htmlspecialchars($version_full, ENT_QUOTES).'</code> '
            .'ซึ่งเก่าเกินกว่าจะเก็บภาษาไทยแบบ utf8mb4 ได้ (ต้องการ '.($is_maria ? 'MariaDB' : 'MySQL').' '.$min.' ขึ้นไป)<br>'
            .'กรุณาแจ้งผู้ให้บริการโฮสต์ให้อัปเกรดฐานข้อมูลก่อน แล้วจึงปรับรุ่นระบบนี้';
    } else {
        $result['notes'][] = 'ฐานข้อมูล '.htmlspecialchars($version_full, ENT_QUOTES);
    }

    // -------------------------------------------------------------------------
    // 2. รุ่นของระบบ — ห้ามถอยรุ่น
    // -------------------------------------------------------------------------
    $old_version = isset($config['version']) ? (string) $config['version'] : '';
    $new_version = isset($new_config['version']) ? (string) $new_config['version'] : '';
    if ($new_version === '') {
        $result['errors'][] = 'ไม่พบเลขรุ่นในชุดติดตั้ง (<code>install/settings/config.php</code>) '
            .'กรุณาอัปโหลดโฟลเดอร์ <code>install/</code> ของรุ่นใหม่ให้ครบก่อนปรับรุ่น';
    } elseif ($old_version !== '' && version_compare($old_version, $new_version, '>')) {
        $result['errors'][] = 'ฐานข้อมูลนี้เป็นของรุ่น <code>'.htmlspecialchars($old_version, ENT_QUOTES).'</code> '
            .'ซึ่งใหม่กว่าชุดติดตั้งที่กำลังจะใช้ (<code>'.htmlspecialchars($new_version, ENT_QUOTES).'</code>)<br>'
            .'การถอยรุ่นทำให้ข้อมูลเสียหายและตัวปรับรุ่นทำให้ไม่ได้ '
            .'กรุณาอัปโหลดไฟล์ของรุ่นใหม่ให้ครบทุกโฟลเดอร์ แล้วลองใหม่อีกครั้ง';
    } else {
        $result['notes'][] = 'ปรับรุ่นจาก '.($old_version === '' ? 'รุ่นที่ไม่ได้ระบุ' : $old_version).' → '.$new_version
            .($old_version === $new_version ? ' (รุ่นเดียวกัน — รันซ้ำได้ ไม่มีอะไรเสียหาย)' : '');
    }

    // -------------------------------------------------------------------------
    // 3. ฐานข้อมูลนี้ใช่ระบบนี้ไหม
    // -------------------------------------------------------------------------
    if (!$db->tableExists($table_user)) {
        $result['errors'][] = 'ไม่พบตาราง <code>'.$table_user.'</code> ในฐานข้อมูลนี้<br>'
            .'กรุณาตรวจค่า <code>prefix</code> และ <code>dbname</code> ใน <code>settings/database.php</code> '
            .'ว่าตรงกับฐานข้อมูลของระบบเดิมหรือไม่<br>'
            .'(ถ้านี่คือระบบใหม่ที่ยังไม่เคยติดตั้ง ให้ลบ <code>settings/config.php</code> แล้วเริ่มการติดตั้งใหม่แทน)';

        // ตรวจอย่างอื่นต่อไม่ได้แล้ว เพราะไม่รู้ว่าตารางของใครอยู่ที่ไหน
        return $result;
    }

    // -------------------------------------------------------------------------
    // 4. password_key — ค่าที่ถ้าหายแล้วรหัสผ่านของทุกคนใช้ไม่ได้ตลอดกาล
    // -------------------------------------------------------------------------
    if (empty($config['password_key'])) {
        $row = $db->customQuery(
            "SELECT COUNT(*) AS `c` FROM `$table_user` WHERE `password` != '' AND `password` IS NOT NULL"
        );
        $has_password = empty($row) ? 0 : (int) $row[0]->c;
        if ($has_password > 0) {
            $result['errors'][] = 'ไม่พบ <code>password_key</code> ใน <code>settings/config.php</code> '
                .'แต่ในฐานข้อมูลมีรหัสผ่านอยู่แล้ว '.$has_password.' รายการ<br>'
                .'รหัสผ่านที่เก็บไว้ผูกกับ password_key (bcrypt ของ HMAC(password_key, รหัสผ่าน) หรือ sha1 รุ่นเก่า) '
                .'ถ้าปรับรุ่นต่อโดยสร้าง <code>password_key</code> ใหม่ ทุกคนจะเข้าระบบไม่ได้และกู้คืนไม่ได้<br>'
                .'กรุณานำ <code>settings/config.php</code> เดิมของระบบกลับมา (หรือคัดลอกเฉพาะค่า <code>password_key</code>) แล้วลองใหม่';
        }
    }

    // -------------------------------------------------------------------------
    // 5. สิทธิของบัญชีฐานข้อมูล
    // -------------------------------------------------------------------------
    $denied = probePrivileges($db, $prefix);
    if ($denied !== '') {
        $result['errors'][] = 'บัญชีฐานข้อมูล <code>'.htmlspecialchars($db_config['username'], ENT_QUOTES).'</code> '
            .'ทำงานที่ตัวปรับรุ่นต้องใช้ไม่ได้ (สร้าง/แก้ไขตาราง)<br>'
            .'<code>'.htmlspecialchars($denied, ENT_QUOTES).'</code><br>'
            .'กรุณาให้สิทธิ CREATE, ALTER, INDEX, INSERT, UPDATE, DELETE, DROP '
            .'กับบัญชีนี้บนฐานข้อมูล <code>'.htmlspecialchars($db_config['dbname'], ENT_QUOTES).'</code> แล้วลองใหม่';
    }

    // -------------------------------------------------------------------------
    // 6. ข้อมูลที่จะทำให้ ADD UNIQUE ล้ม
    //
    // ถ้าปล่อยไปล้มเองจะได้แค่ SQLSTATE ที่ไม่ได้บอกว่าต้องไปแก้แถวไหน
    // และตอนนั้นตารางอื่นถูกแก้ไปแล้วครึ่งทาง
    // -------------------------------------------------------------------------
    foreach (['username', 'id_card', 'phone'] as $column) {
        if (!$db->fieldExists($table_user, $column) || $db->indexExists($table_user, $column)) {
            continue;
        }
        $duplicates = duplicateValues($db, $table_user, $column);
        if (empty($duplicates)) {
            continue;
        }
        $list = [];
        foreach ($duplicates as $item) {
            $list[] = '<code>'.htmlspecialchars($item['value'], ENT_QUOTES).'</code> '
                .'ซ้ำ '.$item['rows'].' แถว (id '.htmlspecialchars($item['ids'], ENT_QUOTES).')';
        }
        $result['errors'][] = 'คอลัมน์ <code>'.$column.'</code> ของตาราง <code>'.$table_user.'</code> '
            .'กำลังจะห้ามค่าซ้ำ แต่ตอนนี้ยังมีค่าซ้ำอยู่<br>'.implode('<br>', $list).'<br>'
            .'กรุณาแก้ข้อมูลสมาชิกให้ '.$column.' ไม่ซ้ำกัน หรือลบค่าที่ซ้ำออกให้ว่าง แล้วปรับรุ่นใหม่อีกครั้ง';
    }

    // -------------------------------------------------------------------------
    // 7. ไฟล์และโฟลเดอร์ที่ต้องเขียนได้
    // -------------------------------------------------------------------------
    if ($config_file === '') {
        $config_file = ROOT_PATH.'settings/config.php';
    }
    foreach ([
        $config_file => 'ตัวปรับรุ่นต้องเขียนค่ากำหนดรุ่นใหม่ลงไฟล์นี้',
        ROOT_PATH.'datas/' => 'ใช้เก็บไฟล์บันทึกผลการปรับรุ่น'
    ] as $full => $why) {
        if (!file_exists($full) || !is_writable($full)) {
            $path = str_replace(ROOT_PATH, '', $full);
            $result['errors'][] = 'เขียนไฟล์ <code>'.$path.'</code> ไม่ได้ ('.$why.')<br>'
                .'กรุณาตั้งสิทธิ (chmod) ของ <code>'.$path.'</code> ให้เขียนได้ก่อนปรับรุ่น';
        }
    }

    // -------------------------------------------------------------------------
    // 8. สำรวจตารางของ prefix นี้ — engine / charset / จำนวนแถวจริง
    // -------------------------------------------------------------------------
    $result['tables'] = prefixTables($db, $prefix);
    if (empty($result['tables'])) {
        $result['warnings'][] = 'ไม่พบตารางที่ขึ้นต้นด้วย <code>'.$prefix.'_</code> เลยในฐานข้อมูลนี้';
    } else {
        $result['counts'] = countRows($db, $result['tables']);
        $rows = $db->customQuery(
            "SELECT `TABLE_NAME` AS `t`, `ENGINE` AS `e`, `TABLE_COLLATION` AS `c`
             FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ('".implode("','", $result['tables'])."')"
        );
        $old_engine = [];
        $old_charset = [];
        foreach ((array) $rows as $row) {
            if (strcasecmp((string) $row->e, 'InnoDB') !== 0) {
                $old_engine[] = $row->t.' ('.$row->e.')';
            }
            if (strpos((string) $row->c, 'utf8mb4') !== 0) {
                $old_charset[] = $row->t.' ('.$row->c.')';
            }
        }
        // ไม่ใช่ข้อผิดพลาด ตัวปรับรุ่นแปลงให้เอง แต่ผู้ใช้ควรรู้ว่าจะใช้เวลานาน
        if (!empty($old_engine)) {
            $result['notes'][] = 'จะแปลงเป็น InnoDB '.count($old_engine).' ตาราง: '.implode(', ', $old_engine);
        }
        if (!empty($old_charset)) {
            $result['notes'][] = 'จะแปลงเป็น utf8mb4 '.count($old_charset).' ตาราง: '.implode(', ', $old_charset);
        }
        $total = 0;
        foreach ($result['counts'] as $count) {
            $total += $count;
        }
        if ($total > 100000) {
            $result['warnings'][] = 'ฐานข้อมูลนี้มีข้อมูลรวม '.number_format($total).' แถว '
                .'การปรับรุ่นอาจใช้เวลาหลายนาที กรุณาอย่าปิดหน้าต่างนี้ระหว่างทำงาน';
        }
    }

    // -------------------------------------------------------------------------
    // 9. ข้อตรวจเฉพาะของโปรเจ็คนี้ (ถ้ามี)
    //
    // โปรเจ็คลูกที่มีตารางของตัวเองให้สร้าง install/preflight-module.php แล้ว
    // ประกาศ preflightModule($db, $db_config, &$result) เพิ่มข้อความลง
    // $result['errors'] / ['warnings'] / ['notes'] ได้เลย
    // -------------------------------------------------------------------------
    if (is_file(ROOT_PATH.'install/preflight-module.php')) {
        include_once ROOT_PATH.'install/preflight-module.php';
    }
    if (function_exists('preflightModule')) {
        preflightModule($db, $db_config, $result);
    }

    return $result;
}

/**
 * รายงานผลการตรวจเป็น HTML สำหรับหน้าปรับรุ่น
 *
 * @param array $result ผลจาก preflight()
 *
 * @return string
 */
function preflightHtml(array $result)
{
    $html = '';
    if (!empty($result['errors'])) {
        $html .= '<h2>ยังปรับรุ่นไม่ได้</h2>';
        $html .= '<p>ตัวปรับรุ่น <b>ยังไม่ได้แก้ไขฐานข้อมูลของคุณเลย</b> '
            .'กรุณาแก้เรื่องต่อไปนี้ก่อน แล้วกดปรับรุ่นใหม่อีกครั้ง</p>';
        $html .= '<ul>';
        foreach ($result['errors'] as $message) {
            $html .= '<li class="incorrect">'.$message.'</li>';
        }
        $html .= '</ul>';
    }
    if (!empty($result['warnings'])) {
        $html .= '<ul>';
        foreach ($result['warnings'] as $message) {
            $html .= '<li class="warning">'.$message.'</li>';
        }
        $html .= '</ul>';
    }

    return $html;
}

/**
 * แปลงรายงาน HTML ของตัวปรับรุ่นเป็นข้อความบรรทัดเดียวต่อรายการ
 * ใช้ทั้งกับไฟล์ log และกับ install/cli-upgrade.php
 *
 * @param string $html
 *
 * @return string
 */
function upgradeReportText($html)
{
    $html = preg_replace('#<li class="?correct"?>#i', "\n  [ok]   ", $html);
    $html = preg_replace('#<li class="?incorrect"?>#i', "\n  [FAIL] ", $html);
    $html = preg_replace('#<li class="?warning"?>#i', "\n  [warn] ", $html);
    $html = preg_replace('#<li[^>]*>#i', "\n  [--]   ", $html);
    $html = preg_replace('#<h2>#i', "\n== ", $html);
    $html = preg_replace('#</t[hd]>#i', "\t", $html);
    $html = preg_replace('#</tr>#i', "\n", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n         ", $html);
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

    return preg_replace('/\n{3,}/', "\n\n", $text);
}

/**
 * แจ้งว่าแถวถูก "ย้าย" ไปตารางอื่นโดยตั้งใจ ไม่ใช่หายไป
 *
 * โมดูลที่เปลี่ยนชื่อตารางหรือยกแถวออกจากตารางของแกน ต้องเรียกไฟล์นี้เสมอ
 * ไม่งั้น rowCountHtml() จะรายงานว่า "ข้อมูลหาย" แล้วตัดสินว่าการปรับรุ่น
 * ล้มเหลว ทั้งที่ข้อมูลอยู่ครบ แล้วสั่งให้ผู้ใช้กู้คืนการเปล่าโดยไม่จำเป็น
 *
 * @param string $from ตารางต้นทาง (มี prefix แล้ว)
 * @param string $to   ตารางปลายทาง (มี prefix แล้ว)
 * @param int    $count จำนวนแถวที่ย้าย
 */
function noteRowsMoved($from, $to, $count)
{
    if (!isset($GLOBALS['upgrade_row_moves'][$from][$to])) {
        $GLOBALS['upgrade_row_moves'][$from][$to] = 0;
    }
    $GLOBALS['upgrade_row_moves'][$from][$to] += (int) $count;
}

/**
 * แจ้งว่าตารางถูกลบทิ้งโดยตั้งใจ (ตารางที่เลิกใช้แล้ว)
 *
 * ⚠️ คำแจ้งนี้มีผลเฉพาะตารางที่ "ว่างอยู่แล้วก่อนปรับรุ่น" เท่านั้น
 * ถ้าตารางมีข้อมูลอยู่ rowCountHtml() จะยังรายงานว่าข้อมูลหายเหมือนเดิม
 * ไม่ว่าจะแจ้งไว้หรือไม่ — ตัวปรับรุ่นไม่มีสิทธิ์ทำลายข้อมูลของใครทั้งนั้น
 * ตารางที่เลิกใช้แต่ยังมีข้อมูล ให้เปลี่ยนชื่อเก็บไว้แล้วแจ้งด้วย noteRowsMoved()
 *
 * @param string $table ชื่อตาราง (มี prefix แล้ว)
 */
function noteTableDropped($table)
{
    $GLOBALS['upgrade_tables_dropped'][$table] = true;
}

/**
 * ตารางเทียบจำนวนแถวก่อน/หลังปรับรุ่น
 *
 * @param array $before
 * @param array $after
 * @param array $moves รายการย้ายแถว (null = อ่านจากที่ noteRowsMoved บันทึกไว้)
 *
 * @return array [html, ข้อมูลหายจริงหรือไม่]
 */
function rowCountHtml(array $before, array $after, ?array $moves = null)
{
    if ($moves === null) {
        $moves = isset($GLOBALS['upgrade_row_moves']) ? $GLOBALS['upgrade_row_moves'] : [];
    }
    $dropped = isset($GLOBALS['upgrade_tables_dropped']) ? $GLOBALS['upgrade_tables_dropped'] : [];
    $names = array_keys($before + $after);
    sort($names);
    $lost = false;
    $html = '<h2>จำนวนข้อมูลก่อน/หลังปรับรุ่น</h2>';
    $html .= '<table class="rowcount"><tr><th>ตาราง</th><th>ก่อน</th><th>หลัง</th><th></th></tr>';
    foreach ($names as $name) {
        $b = isset($before[$name]) ? $before[$name] : null;
        $a = isset($after[$name]) ? $after[$name] : null;
        // แถวที่โมดูลแจ้งว่า "ย้ายไปตารางอื่นโดยตั้งใจ" จะนับว่ายังอยู่ ก็ต่อเมื่อ
        // ตารางปลายทางมีจริงและรับไปแล้วไม่น้อยกว่าที่แจ้ง — คำแจ้งอย่างเดียว
        // เชื่อไม่ได้ ต้องเห็นของอยู่ปลายทางด้วย ไม่งั้นก็แค่ปิดปากสัญญาณเตือน
        $declared = isset($moves[$name]) ? $moves[$name] : [];
        $moved = 0;
        $dest = [];
        $moved_ok = !empty($declared);
        foreach ($declared as $_to => $_n) {
            if (isset($after[$_to]) && $after[$_to] >= $_n) {
                $moved += $_n;
                $dest[] = $_to;
            } else {
                $moved_ok = false;
            }
        }
        $to_text = implode(', ', $dest);
        if ($b === null) {
            $note = 'ตารางใหม่';
        } elseif ($a === null) {
            if ($moved_ok && $moved >= $b) {
                $note = 'ย้ายไป '.$to_text.' ทั้งตาราง';
            } elseif ($b === 0 && !empty($dropped[$name])) {
                // ตารางที่เลิกใช้แล้วและว่างอยู่แล้ว — ลบได้ ไม่มีอะไรหาย
                $note = 'ลบตารางที่เลิกใช้แล้ว (ว่าง)';
            } else {
                $note = 'หายไป';
                $lost = true;
            }
        } elseif ($a + $moved < $b) {
            $note = 'ลดลง '.number_format($b - $a - $moved).' แถว';
            if ($moved > 0) {
                $note .= ' (ย้ายไป '.$to_text.' แล้ว '.number_format($moved).' แถว)';
            }
            $lost = true;
        } elseif ($a < $b) {
            $note = 'ย้ายไป '.$to_text.' '.number_format($b - $a).' แถว';
        } elseif ($a > $b) {
            $note = 'เพิ่มขึ้น '.number_format($a - $b).' แถว';
        } else {
            $note = 'เท่าเดิม';
        }
        $html .= '<tr class="'.($note === 'หายไป' || strpos($note, 'ลดลง') === 0 ? 'incorrect' : 'correct').'">'
            .'<td>'.htmlspecialchars($name, ENT_QUOTES).'</td>'
            .'<td>'.($b === null ? '-' : number_format($b)).'</td>'
            .'<td>'.($a === null ? '-' : number_format($a)).'</td>'
            .'<td>'.$note.'</td></tr>';
    }
    $html .= '</table>';

    return [$html, $lost];
}

/**
 * บันทึกรุ่นและวันที่ปรับรุ่นลงฐานข้อมูลของไซต์เอง
 *
 * ไซต์ต้องรู้สถานะของตัวเองได้โดยไม่ต้องถามเรา และการปรับรุ่นครั้งถัดไปจะได้รู้ว่า
 * เริ่มจากรุ่นไหน — ตาราง {prefix}_migration นิยามอยู่ใน install/core.sql
 *
 * @param Db     $db
 * @param string $prefix
 * @param string $module  'core' หรือชื่อโมดูล
 * @param string $version
 * @param string $note
 */
function stampMigration($db, $prefix, $module, $version, $note = '')
{
    $table = $prefix.'_migration';
    if (!$db->tableExists($table)) {
        return;
    }
    $db->insert($table, [
        'module' => $module,
        'version' => $version,
        'applied_at' => date('Y-m-d H:i:s'),
        'note' => mb_substr($note, 0, 255)
    ]);
}

/**
 * รุ่นล่าสุดของโมดูลที่บันทึกไว้ในฐานข้อมูล
 *
 * @param Db     $db
 * @param string $prefix
 * @param string $module
 *
 * @return string ว่าง = ยังไม่เคยบันทึก
 */
function migrationVersion($db, $prefix, $module = 'core')
{
    $table = $prefix.'_migration';
    if (!$db->tableExists($table)) {
        return '';
    }
    $rows = $db->customQuery(
        "SELECT `version` FROM `$table` WHERE `module` = '".addslashes($module)."' ORDER BY `id` DESC LIMIT 1"
    );

    return empty($rows) ? '' : (string) $rows[0]->version;
}

/**
 * เขียนผลการปรับรุ่นลงไฟล์ในโปรเจ็ค
 *
 * ถ้าผู้ใช้ทักมาว่าปรับรุ่นแล้วมีปัญหา ให้เขาส่งไฟล์นี้มา แทนการขอเข้าเครื่อง
 *
 * @param string $text
 *
 * @return string ที่อยู่ของไฟล์ (ว่าง = เขียนไม่ได้)
 */
function writeUpgradeLog($text)
{
    $dir = ROOT_PATH.'datas/logs/';
    if (!is_dir($dir)) {
        makeDirectory($dir);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return '';
    }
    $file = $dir.'upgrade-'.date('Ymd-His').'.log';
    $head = 'ปรับรุ่นเมื่อ '.date('Y-m-d H:i:s')."\n"
        .'PHP '.PHP_VERSION."\n"
        .str_repeat('-', 70)."\n";

    return file_put_contents($file, $head.$text."\n") === false ? '' : $file;
}
