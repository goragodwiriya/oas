<?php
// นำเข้าภาษา
$dir = ROOT_PATH.'language/';
if (is_dir($dir)) {
    $table = $db_config['prefix'].'_language';
    $files = getLanguageFiles($dir);

    foreach ($files as $lang => $file) {
        ensureLanguageColumn($db, $table, $lang);
        importJSON($db, $table, $lang, $file);
    }

    $content[] = '<li class="correct">นำเข้า `'.$table.'` สำเร็จ</li>';
}

/**
 * อ่านรายการไฟล์ภาษา JSON ที่ถูกต้อง
 *
 * @param string $dir
 *
 * @return array
 */
function getLanguageFiles($dir)
{
    $files = [];
    foreach (glob($dir.'*.json') ?: [] as $file) {
        $lang = basename($file, '.json');
        if (preg_match('/^[a-z]{2}$/', $lang)) {
            $files[$lang] = $file;
        }
    }
    ksort($files);

    return $files;
}

/**
 * เพิ่มคอลัมน์ภาษา ถ้ายังไม่มีภาษาที่ต้องการ
 *
 * @param Database $db
 * @param string   $table
 * @param string   $lang
 */
function ensureLanguageColumn($db, $table, $lang)
{
    if (!$db->fieldExists($table, $lang)) {
        if ($db->driver() == 'mysql') {
            // ไม่ระบุ CHARACTER SET — ใช้ utf8mb4 ของตาราง (เดิมระบุ utf8 ซึ่งคือ utf8mb3 เก็บอีโมจิไม่ได้)
            // แบบเดียวกับที่หน้าจัดการภาษา (Index\Languages\Model) เพิ่มคอลัมน์
            $db->query("ALTER TABLE `$table` ADD `$lang` TEXT NULL AFTER `en`");
        } else {
            // ฐานอื่นไม่มี AFTER (คอลัมน์ใหม่ต่อท้ายเสมอ) และ TEXT ของ SQL Server ไม่ใช่ Unicode
            $db->query('ALTER TABLE '.$db->quote($table).' ADD '.$db->quote($lang).' '.($db->driver() == 'sqlsrv' ? 'NVARCHAR(MAX)' : 'TEXT').' NULL');
        }
    }
}

/**
 * นำเข้าข้อมูลไฟล์ภาษา JSON
 *
 * @param Database $db         Database Object
 * @param string   $table      ชื่อตาราง language
 * @param string   $lang       ชื่อภาษา
 * @param string   $file_name  ไฟล์ภาษา JSON
 */
function importJSON($db, $table, $lang, $file_name)
{
    $content = file_get_contents($file_name);
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return;
    }

    // คีย์ที่ต่างกันแค่ตัวพิมพ์คือรายการเดียว ("Save" "save" "SAVE") — การแปลหาแบบตรงตัว
    // ตัวสะกดที่สองจึงเป็นอีกคีย์ที่ซ้ำ จับคู่แถวเดิมด้วยตัวพิมพ์เล็กใน PHP (ไม่พึ่ง collation)
    $ids = [];
    $names = [];
    foreach ($db->customQuery('SELECT '.$db->quote('id').', '.$db->quote('key').' FROM '.$db->quote($table).' ORDER BY '.$db->quote('id')) as $row) {
        $lower = mb_strtolower($row->key);
        if (!isset($ids[$lower])) {
            $ids[$lower] = (int) $row->id;
            $names[$lower] = $row->key;
        }
    }

    // ตัวสะกดในแฟ้มที่ต่างกันแค่ตัวพิมพ์ยุบเป็นตัวเดียว: ตัวที่ตารางมีอยู่แล้ว (นำเข้าจากแฟ้มภาษาก่อนหน้า)
    // ไม่งั้นตัวแรกในแฟ้ม — คำแปลมาจากตัวสะกดเดียวกับคีย์เสมอ (en.json มีแค่ "Line" แต่ th.json มี "LINE" ก่อน
    // "Line" ถ้าเลือกตัวแรกของ th.json แถว "Line" จะได้คำแปลของ "LINE")
    $pick = [];
    foreach (array_keys($data) as $key) {
        $key = (string) $key;
        $lower = mb_strtolower($key);
        if (!isset($pick[$lower]) || (isset($names[$lower]) && $names[$lower] === $key)) {
            $pick[$lower] = $key;
        }
    }

    foreach ($pick as $lower => $key) {
        $value = $data[$key];
        $type = detectLanguageType($value);
        $value = prepareLanguageValue($key, $lang, $value);

        if (isset($ids[$lower])) {
            $db->update($table, [
                'id' => $ids[$lower]
            ], [
                'type' => $type,
                $lang => $value
            ]);
        } else {
            $ids[$lower] = (int) $db->insert($table, [
                'key' => (string) $key,
                'type' => $type,
                $lang => $value
            ]);
        }
    }
}

/**
 * ตรวจสอบชนิดข้อมูลภาษา
 *
 * @param mixed $value
 *
 * @return string
 */
function detectLanguageType($value)
{
    if (is_array($value)) {
        return 'array';
    }
    if (is_int($value)) {
        return 'int';
    }
    // array ที่ถูก serialize() ไว้จากระบบเดิม ต้องบันทึกเป็นชนิด array
    // ไม่งั้น restoreValue() จะไม่ถอดกลับตอน export
    if (unserializeLegacyArray($value) !== null) {
        return 'array';
    }

    return 'text';
}

/**
 * แปลงค่าก่อนบันทึกลงฐานข้อมูล
 *
 * @param string $key
 * @param string $lang
 * @param mixed  $value
 *
 * @return string|int
 */
function prepareLanguageValue($key, $lang, $value)
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    if (is_int($value)) {
        return $value;
    }

    $value = (string) $value;

    // ไฟล์ภาษาที่ export มาจากฐานข้อมูลของ Gcms รุ่นเก่า อาจมี array ที่ถูก
    // serialize() ไว้เป็นสตริง แปลงกลับเป็น JSON เพื่อไม่ให้ค่าเสียซ้ำอีกรอบ
    $legacy = unserializeLegacyArray($value);
    if ($legacy !== null) {
        return json_encode($legacy, JSON_UNESCAPED_UNICODE);
    }

    if ($lang === 'en' && $value === $key) {
        return '';
    }

    return $value;
}

/**
 * แปลงค่า array ที่เก็บด้วย serialize() ของระบบเดิมกลับเป็น array
 *
 * @param string $value
 *
 * @return array|null
 */
function unserializeLegacyArray($value)
{
    if (!is_string($value) || !preg_match('/^a:\d+:\{/', $value)) {
        return null;
    }
    $restored = @unserialize($value, ['allowed_classes' => false]);

    return is_array($restored) ? $restored : null;
}
