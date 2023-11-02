<?php
// นำเข้าภาษา
$dir = ROOT_PATH.'language/';
if (is_dir(ROOT_PATH.'language/')) {
    // ตาราง language
    $table = $db_config['prefix'].'_language';
    // อ่านไฟล์ภาษาที่ติดตั้ง
    $f = opendir($dir);
    if ($f) {
        while (false !== ($text = readdir($f))) {
            if (preg_match('/^([a-z]{2,2})\.(php|js)$/', $text, $match)) {
                if ($db->fieldExists($table, $match[1]) == false) {
                    // เพิ่มคอลัมน์ภาษา ถ้ายังไม่มีภาษาที่ต้องการ
                    $db->query("ALTER TABLE `$table` ADD `$match[1]` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci AFTER `en`");
                }
                if ($match[2] == 'php') {
                    importPHP($db, $table, $match[1], $dir.$text);
                } else {
                    importJS($db, $table, $match[1], $dir.$text);
                }
            }
        }
        closedir($f);
    }
    $content[] = '<li class="correct">นำเข้า `'.$table.'` สำเร็จ</li>';
}

/**
 * นำเข้าข้อมูลไฟล์ภาษา PHP
 *
 * @param Db $db             Database Class
 * @param string   $table ชื่อตาราง language
 * @param string   $lang           ชื่อภาษา
 * @param string   $file_name      ไฟล์ภาษา
 */
function importPHP($db, $table, $lang, $file_name)
{
    foreach (include ($file_name) as $key => $value) {
        if (is_array($value)) {
            $type = 'array';
        } elseif (is_int($value)) {
            $type = 'int';
        } else {
            $type = 'text';
        }
        $search = $db->first($table, array('key' => $key, 'js' => 0));
        if ($type == 'array') {
            $value = serialize($value);
        }
        if ($search) {
            $db->update($table, array(
                'id' => $search->id
            ), array(
                $lang => $value
            ));
        } else {
            $db->insert($table, array(
                'key' => $key,
                'js' => 0,
                'type' => $type,
                'owner' => 'index',
                $lang => $value
            ));
        }
    }
}

/**
 * นำเข้าข้อมูลไฟล์ภาษา Javascript
 *
 * @param Database $db             Database Object
 * @param string   $table ชื่อตาราง language
 * @param string   $lang           ชื่อภาษา
 * @param string   $file_name      ไฟล์ภาษา
 */
function importJS($db, $table, $lang, $file_name)
{
    $patt = '/^var[\s]+([A-Z0-9_]+)[\s]{0,}=[\s]{0,}[\'"](.*)[\'"];$/';
    foreach (file($file_name) as $item) {
        $item = trim($item);
        if ($item != '') {
            if (preg_match($patt, $item, $match)) {
                $search = $db->first($table, array('key' => $match[1], 'js' => 1));
                if ($search) {
                    $db->update($table, array(
                        'id' => $search->id
                    ), array(
                        $lang => $match[2]
                    ));
                } else {
                    $db->insert($table, array(
                        'key' => $match[1],
                        'js' => 1,
                        'type' => 'text',
                        'owner' => 'index',
                        $lang => $match[2]
                    ));
                }
            }
        }
    }
}
