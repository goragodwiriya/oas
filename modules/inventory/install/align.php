<?php
/**
 * modules/inventory/install/align.php — ปรับโครงตารางของโมดูลให้เท่ากับ CREATE TABLE ใน database.sql
 *
 * oas ไม่มีตัวเทียบสคีมาแบบทั่วไป (oms มี install/schema.php) — ตารางทะเบียนสินค้า/สต๊อกที่เกิดจาก
 * ตัวแปลงรุ่นก่อน (legacy.php · ตาราง ledger ของ oas 7.0.x) จึงค้างชนิดเดิม (double · varchar สั้น) ขาดคอลัมน์ใหม่
 * (cost_layer.lot_no) และขาดดัชนี UNIQUE (inventory.sku) ทั้งที่ engine เขียนคอลัมน์เหล่านั้นทุกครั้ง
 * เจอจริง 2026-10-24 : ปรับรุ่น oas.acc.in.th แล้วรับของเข้าไม่ได้ (Unknown column 'lot_no')
 *
 * อ่านคำนิยามจากไฟล์เดียวกับที่ตัวติดตั้งใช้ (ไม่คัดลอกคำนิยามมาเขียนซ้ำที่นี่) แล้วใช้ตัวช่วยของตัวติดตั้ง
 *   คอลัมน์ : ensureColumn (ชนิด · NULL · DEFAULT) · AUTO_INCREMENT ผ่าน ensureAutoIncrement
 *   ดัชนี   : KEY / UNIQUE KEY ตามที่ประกาศ · ดัชนีอื่นที่ไม่ได้ประกาศถูกถอด (ดัชนีไม่ใช่ข้อมูล)
 *             UNIQUE ที่ข้อมูลยังซ้ำอยู่ ไม่บังคับ — รายงานเป็นคำเตือนให้แก้เอง
 * ไม่ลบคอลัมน์ที่ไม่ได้ประกาศ (กฎ "ห้ามลบข้อมูลธุรกิจ" — คอลัมน์เดิมที่ย้ายค่าแล้ว ผู้ย้ายเป็นผู้ตัดเอง)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

/**
 * @param object $db      Db ของตัวติดตั้ง
 * @param string $prefix
 * @param array  $names   ชื่อตารางไม่มี prefix
 * @param array  $content รายการผลลัพธ์ (HTML <li>)
 */
function inventoryAlignTables($db, $prefix, array $names, array &$content)
{
    $creates = [];
    foreach (sqlCommands(ROOT_PATH.'modules/inventory/install/database.sql', $prefix) as $command) {
        if (preg_match('/^\s*CREATE TABLE `([^`]+)` \((.*)\)[^)]*$/is', $command, $match)) {
            $creates[$match[1]] = $match[2];
        }
    }
    foreach ($names as $name) {
        $table = $prefix.'_'.$name;
        if (!isset($creates[$table]) || !$db->tableExists($table)) {
            continue;
        }
        if (convertToInnoDB($db, $table)) {
            $content[] = '<li class="correct">'.$name.': แปลงเป็น InnoDB</li>';
        }
        if (convertToUtf8mb4($db, $table)) {
            $content[] = '<li class="correct">'.$name.': แปลงเป็น utf8mb4</li>';
        }
        $after = '';
        $indexes = [];
        foreach (preg_split('/\R/', $creates[$table]) as $line) {
            // หมายเหตุท้ายบรรทัด (-- ...) ไม่ใช่ส่วนของคำนิยาม
            $line = trim(preg_replace('/\s--\s.*$/u', '', $line));
            $line = rtrim($line, ', ');
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(UNIQUE\s+)?KEY\s+`([^`]+)`\s*\((.+)\)$/i', $line, $key)) {
                $indexes[$key[2]] = ['columns' => str_replace('`,`', '`, `', $key[3]), 'unique' => $key[1] !== ''];
                continue;
            }
            if (!preg_match('/^`([^`]+)`\s+([a-z]+(?:\([^)]*\))?(?:\s+unsigned)?)(.*)$/i', $line, $col)) {
                continue;
            }
            list(, $column, $type, $rest) = $col;
            if (stripos($rest, 'AUTO_INCREMENT') !== false) {
                if (ensureAutoIncrement($db, $table, $column, $type)) {
                    $content[] = '<li class="correct">'.$name.': ปรับคอลัมน์ '.$column.'</li>';
                }
                $after = $column;
                continue;
            }
            $nullable = !preg_match('/NOT\s+NULL/i', $rest);
            $default = null;
            if (preg_match("/DEFAULT\s+('(?:[^']|'')*'|[^\s,]+)/i", $rest, $def) && strcasecmp($def[1], 'NULL') !== 0) {
                $default = $def[1][0] === "'" ? str_replace("''", "'", substr($def[1], 1, -1)) : $def[1];
            }
            if (ensureColumn($db, $table, $column, $type, $nullable, $default, '', $after)) {
                $content[] = '<li class="correct">'.$name.': ปรับคอลัมน์ '.$column.'</li>';
            }
            $after = $column;
        }
        // ดัชนีที่ไม่ได้ประกาศ — ของรุ่นก่อนที่ตั้งชื่อคนละแบบหรือครอบซ้ำกัน
        $existing = [];
        foreach ((array) $db->customQuery('SHOW INDEX FROM `'.$table.'`') as $row) {
            $existing[$row->Key_name] = true;
        }
        foreach (array_keys($existing) as $index) {
            if ($index !== 'PRIMARY' && !isset($indexes[$index])) {
                $db->query('ALTER TABLE `'.$table.'` DROP INDEX `'.$index.'`');
                $content[] = '<li class="correct">'.$name.': ถอดดัชนี '.$index.' ที่ไม่ได้ใช้แล้ว</li>';
            }
        }
        foreach ($indexes as $index => $spec) {
            dropMismatchedIndex($db, $table, $index, $spec['columns'], $spec['unique']);
            if (!$spec['unique']) {
                if (ensureIndexes($db, $table, [$index => $spec['columns']])) {
                    $content[] = '<li class="correct">'.$name.': ปรับดัชนี '.$index.'</li>';
                }
                continue;
            }
            if ($db->indexExists($table, $index)) {
                continue;
            }
            // ค่าว่าง ('' / NULL) ไม่นับเป็นค่าซ้ำ — UNIQUE ยอม NULL หลายแถว
            $cols = $spec['columns'];
            $notBlank = [];
            foreach (explode(',', str_replace(['`', ' '], '', $cols)) as $_c) {
                $notBlank[] = "`$_c` IS NOT NULL";
            }
            $dup = $db->customQuery('SELECT COUNT(*) AS `c` FROM `'.$table.'` WHERE '.implode(' AND ', $notBlank)
                .' GROUP BY '.$cols.' HAVING `c` > 1 LIMIT 1');
            if (!empty($dup)) {
                $content[] = '<li class="warning">'.$name.': ยังบังคับห้ามซ้ำ ('.$index.') ไม่ได้ เพราะข้อมูลยังซ้ำอยู่ — แก้แล้วปรับรุ่นอีกครั้ง</li>';
                continue;
            }
            $db->query('ALTER TABLE `'.$table.'` ADD UNIQUE `'.$index.'` ('.$cols.')');
            $content[] = '<li class="correct">'.$name.': เพิ่ม UNIQUE '.$index.'</li>';
        }
    }
}
