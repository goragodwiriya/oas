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
        $db->query("ALTER TABLE `$table` ADD `$lang` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci AFTER `en`");
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

    foreach ($data as $key => $value) {
        $type = detectLanguageType($value);
        $value = prepareLanguageValue($key, $lang, $value);
        $search = $db->first($table, ['key' => $key]);

        if ($search) {
            $db->update($table, [
                'id' => $search->id
            ], [
                'type' => $type,
                $lang => $value
            ]);
        } else {
            $db->insert($table, [
                'key' => $key,
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
    if ($lang === 'en' && $value === $key) {
        return '';
    }

    return $value;
}
