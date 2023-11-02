<?php
/**
 * @filesource Kotchasan/Database/Sql.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

/**
 * SQL Function
 *
 * @see https://www.kotchasan.com/
 */
class Sql
{
    /**
     * คำสั่ง SQL ที่เก็บไว้
     *
     * @var string
     */
    protected $sql;
    /**
     * ตัวแปรเก็บพารามิเตอร์สำหรับการ bind
     *
     * @var array
     */
    protected $values;

    /**
     * หาค่าเฉลี่ยของคอลัมน์ที่เลือก
     *
     * @assert ('id')->text() [==] 'AVG(`id`)'
     *
     * @param string      $column_name ชื่อคอลัมน์
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     * @param bool        $distinct    false (default) นับทุกคอลัมน์, true นับเฉพาะคอลัมน์ที่ไม่ซ้ำ
     *
     * @return static
     */
    public static function AVG($column_name, $alias = null, $distinct = false)
    {
        return self::create('AVG('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * สร้างคำสั่ง BETWEEN ... AND ...
     *
     * @assert ('create_date', 'create_date', 'U.create_date')->text() [==] "`create_date` BETWEEN `create_date` AND U.`create_date`"
     * @assert ('create_date', 'table_name.field_name', 'U.`create_date`')->text() [==] "`create_date` BETWEEN `table_name`.`field_name` AND U.`create_date`"
     * @assert ('create_date', '`database`.`table`', '12-1-1')->text() [==] "`create_date` BETWEEN `database`.`table` AND '12-1-1'"
     * @assert ('create_date', 0, 1)->text() [==] "`create_date` BETWEEN 0 AND 1"
     *
     * @param string $column_name
     * @param string $min
     * @param string $max
     *
     * @return \self
     */
    public static function BETWEEN($column_name, $min, $max)
    {
        return self::create(self::fieldName($column_name).' BETWEEN '.self::fieldName($min).' AND '.self::fieldName($max));
    }

    /**
     * สร้างคำสั่ง CONCAT หรือ CONCAT_WS
     *
     * @assert (array('fname', 'lname'))->text() [==] "CONCAT(`fname`, `lname`)"
     * @assert (array('U.fname', 'U.`lname`'), 'displayname')->text() [==] "CONCAT(U.`fname`, U.`lname`) AS `displayname`"
     * @assert (array('fname', 'lname'), 'displayname', ' ')->text() [==] "CONCAT_WS(' ', `fname`, `lname`) AS `displayname`"
     *
     * @param array       $fields    รายชื่อฟิลด์
     * @param string|null $alias     ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     * @param string|null $separator null (defailt) คืนค่าคำสั่ง CONCAT, ถ้าระบุเป็นค่าอื่นคืนค่าคำสั่ง CONCAT_WS
     *
     * @throws \InvalidArgumentException ถ้ารูปแบบของ $fields ไม่ใช่แอเรย์
     *
     * @return static
     */
    public static function CONCAT($fields, $alias = null, $separator = null)
    {
        $fs = array();
        if (is_array($fields)) {
            foreach ($fields as $item) {
                $fs[] = self::fieldName($item);
            }
            return self::create(($separator === null ? 'CONCAT(' : "CONCAT_WS('$separator', ").implode(', ', $fs).($alias ? ") AS `$alias`" : ')'));
        } else {
            throw new \InvalidArgumentException('$fields is array only');
        }
    }

    /**
     * นับจำนวนเร็คคอร์ดของคอลัมน์ที่เลือก
     *
     * @assert ('id')->text() [==] 'COUNT(`id`)'
     *
     * @param string      $column_name
     * @param string|null $alias
     * @param bool        $distinct    false (default) นับทุกคอลัมน์, true นับเฉพาะคอลัมน์ที่ไม่ซ้ำ
     *
     * @return static
     */
    public static function COUNT($column_name = '*', $alias = null, $distinct = false)
    {
        $column_name = $column_name == '*' ? '*' : self::fieldName($column_name);
        return self::create('COUNT('.($distinct ? 'DISTINCT ' : '').$column_name.')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกวันที่ออกจากคอลัมน์ชนิด DATETIME
     *
     * @assert ('create_date')->text() [==] 'DATE(`create_date`)'
     * @assert ('create_date', 'date')->text() [==] 'DATE(`create_date`) AS `date`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function DATE($column_name, $alias = null)
    {
        return self::create('DATE('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * หาความแตกต่างระหว่างวัน (คืนค่าเป็นจำนวนวันที่แตกต่างกัน)
     *
     * @assert ('create_date', Sql::NOW())->text() [==] "DATEDIFF(`create_date`, NOW())"
     * @assert ('2017-04-04', 'create_date')->text() [==] "DATEDIFF('2017-04-04', `create_date`)"
     *
     * @param string $column_name1
     * @param string $column_name2
     * @param string $alias
     *
     * @return static
     */
    public static function DATEDIFF($column_name1, $column_name2, $alias = null)
    {
        return self::create('DATEDIFF('.self::fieldName($column_name1).', '.self::fieldName($column_name2).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * จัดรูปแบบของวันที่ตอนแสดงผล
     *
     * @assert (Sql::NOW(), '%h:%i')->text() [==] "DATE_FORMAT(NOW(), '%h:%i')"
     * @assert ('create_date', '%Y-%m-%d', 'today')->text() [==] "DATE_FORMAT(`create_date`, '%Y-%m-%d') AS `today`"
     *
     * @param string      $column_name
     * @param string      $format
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function DATE_FORMAT($column_name, $format, $alias = null)
    {
        return self::create('DATE_FORMAT('.self::fieldName($column_name).", '$format')".($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกวันที่ออกจากคอลัมน์ชนิด DATE DATETIME
     *
     * @assert ('date')->text() [==] 'DAY(`date`)'
     * @assert ('date', 'd')->text() [==] 'DAY(`date`) AS `d`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function DAY($column_name, $alias = null)
    {
        return self::create('DAY('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * คืนค่าข้ออมูลที่ไม่ซ้ำกัน
     *
     * @assert ('id')->text() [==] 'DISTINCT `id`'
     *
     * @param string $column_name
     * @param string|null  $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function DISTINCT($column_name, $alias = null)
    {
        return self::create('DISTINCT '.self::fieldName($column_name).($alias ? " AS `$alias`" : ''));
    }

    /**
     * จัดรูปแบบของคอลัมน์ตอนแสดงผล
     *
     * @assert (Sql::NOW(), 'Y-m-d')->text() [==] "FORMAT(NOW(), 'Y-m-d')"
     * @assert ('create_date', 'Y-m-d', 'today')->text() [==] "FORMAT(`create_date`, 'Y-m-d') AS `today`"
     *
     * @param string      $column_name
     * @param string      $format
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function FORMAT($column_name, $format, $alias = null)
    {
        return self::create('FORMAT('.self::fieldName($column_name).", '$format')".($alias ? " AS `$alias`" : ''));
    }

    /**
     * สร้างคำสั่ง GROUP_CONCAT
     *
     * @assert ('C.topic', 'topic', ', ')->text() [==] "GROUP_CONCAT(C.`topic` SEPARATOR ', ') AS `topic`"
     *
     * @param string       $column_name
     * @param string|null  $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     * @param string       $separator   ข้อความเชื่อมฟิลด์เข้าด้วยกัน ค่าเริมต้นคือ ,
     * @param bool         $distinct    false (default) คืนค่ารายการที่ไม่ซ้ำ
     * @param string|array $order       เรียงลำดับ
     *
     * @return \self
     */
    public static function GROUP_CONCAT($column_name, $alias = null, $separator = ',', $distinct = false, $order = null)
    {
        if (!empty($order)) {
            $orders = array();
            if (is_array($order)) {
                foreach ($order as $item) {
                    $orders[] = self::fieldName($item);
                }
            } else {
                $orders[] = self::fieldName($order);
            }
            $order = empty($orders) ? '' : ' ORDER BY '.implode(',', $orders);
        }
        return self::create('GROUP_CONCAT('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name).$order." SEPARATOR '$separator')".($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกชั่วโมงออกจากคอลัมน์ชนิด DATETIME
     *
     * @assert ('create_date')->text() [==] 'HOUR(`create_date`)'
     * @assert ('create_date', 'date')->text() [==] 'HOUR(`create_date`) AS `date`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function HOUR($column_name, $alias = null)
    {
        return self::create('HOUR('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง IFNULL
     *
     * @assert ('create_date', 'U.create_date')->text() [==] "IFNULL(`create_date`, U.`create_date`)"
     * @assert ('create_date', 'U.create_date', 'test')->text() [==] "IFNULL(`create_date`, U.`create_date`) AS `test`"
     *
     * @param string      $column_name1
     * @param string      $column_name2
     * @param string|null $alias        ถ้าระบุจะมีการเติม alias ให้กับคำสั่ง
     *
     * @return \self
     */
    public static function IFNULL($column_name1, $column_name2, $alias = null)
    {
        return self::create('IFNULL('.self::fieldName($column_name1).', '.self::fieldName($column_name2).')'.($alias ? ' AS `'.$alias.'`' : ''));
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง IS NOT NULL
     *
     * @assert ('U.id')->text() [==] "U.`id` IS NOT NULL"
     *
     * @param string $column_name
     *
     * @return \self
     */
    public static function ISNOTNULL($column_name)
    {
        return self::create(self::fieldName($column_name).' IS NOT NULL');
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง IS NULL
     *
     * @assert ('U.id')->text() [==] "U.`id` IS NULL"
     *
     * @param string $column_name
     *
     * @return \self
     */
    public static function ISNULL($column_name)
    {
        return self::create(self::fieldName($column_name).' IS NULL');
    }

    /**
     * หาค่าสูงสุด
     *
     * @assert ('id')->text() [==] 'MAX(`id`)'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function MAX($column_name, $alias = null)
    {
        return self::create('MAX('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * หาค่าต่ำสุด
     *
     * @assert ('id')->text() [==] 'MIN(`id`)'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function MIN($column_name, $alias = null)
    {
        return self::create('MIN('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกนาทีออกจากคอลัมน์ชนิด DATETIME
     *
     * @assert ('create_date')->text() [==] 'MINUTE(`create_date`)'
     * @assert ('create_date', 'date')->text() [==] 'MINUTE(`create_date`) AS `date`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function MINUTE($column_name, $alias = null)
    {
        return self::create('MINUTE('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกเดือนออกจากคอลัมน์ชนิด DATE DATETIME
     *
     * @assert ('date')->text() [==] 'MONTH(`date`)'
     * @assert ('date', 'm')->text() [==] 'MONTH(`date`) AS `m`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function MONTH($column_name, $alias = null)
    {
        return self::create('MONTH('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * ฟังก์ชั่นสร้าง SQL สำหรับหาค่าสูงสุด + 1
     * ใช้ในการหาค่า id ถัดไป
     *
     *
     * @assert ('id', '`world`')->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X), 0))'
     * @assert ('id', '`world`', array(array('module_id', 'D.`id`')), 'next_id')->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X WHERE `module_id` = D.`id`), 0)) AS `next_id`'
     * @assert ('id', '`world`', array(array('module_id', 'D.`id`')), null)->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X WHERE `module_id` = D.`id`), 0))'
     *
     * @param string $field      ชื่อฟิลด์ที่ต้องการหาค่าสูงสุด
     * @param string $table_name ชื่อตาราง
     * @param mixed  $condition  (optional) query WHERE
     * @param array  $values     (optional) แอเรย์สำหรับรับค่า value สำหรับการ bind
     * @param string $alias      (optional) ชื่อฟิลด์ที่ใช้คืนค่า ไม่ระบุ (null) หมายถึงไม่ต้องการชื่อฟิลด์
     * @param string $operator   (optional) เช่น AND หรือ OR
     * @param string $id         (optional )ชื่อฟิลด์ที่เป็น key
     *
     * @return static
     */
    public static function NEXT($field, $table_name, $condition = null, $alias = null, $operator = 'AND', $id = 'id')
    {
        $obj = new static;
        if (empty($condition)) {
            $condition = '';
        } else {
            $condition = ' WHERE '.$obj->buildWhere($condition, $obj->values, $operator, $id);
        }
        $obj->sql = '(1 + IFNULL((SELECT MAX(`'.$field.'`) FROM '.$table_name.' AS X'.$condition.'), 0))';
        if (isset($alias)) {
            $obj->sql .= " AS `$alias`";
        }
        return $obj;
    }

    /**
     * คืนค่าวันที่และเวลาปัจจุบัน
     *
     * @assert ()->text() [==] 'NOW()'
     * @assert ('id')->text() [==] 'NOW() AS `id`'
     *
     * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function NOW($alias = null)
    {
        return self::create('NOW()'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * ค้นหาข้อความ ไม่พบคืนค่า 0, ตัวแรกคือ 1
     *
     * @assert ('find', 'C.`topic`')->text() [==] "LOCATE('find', C.`topic`)"
     *
     * @param string      $substr ข้อความที่ค้นหา ถ้าเป็นชื่อฟิลด์ต้องครอบด้วย ``
     * @param string      $str    ข้อความต้นฉบับ ถ้าเป็นชื่อฟิลด์ต้องครอบด้วย ``
     * @param string|null $alias  ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     * @param int         $pos    ตำแหน่งเริ่มต้นค้นหา (default) หรือไม่ระบุ ค้นหาตั้งแต่ตัวแรก
     *
     * @return \self
     */
    public static function POSITION($substr, $str, $alias = null, $pos = 0)
    {
        $substr = strpos($substr, '`') === false ? "'$substr'" : $substr;
        $str = strpos($str, '`') === false ? "'$str'" : $str;
        return self::create("LOCATE($substr, $str".(empty($pos) ? ')' : ", $pos)").($alias ? " AS `$alias`" : ''));
    }

    /**
     * สุ่มตัวเลข
     *
     * @assert ()->text() [==] 'RAND()'
     * @assert ('id')->text() [==] 'RAND() AS `id`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function RAND($alias = null)
    {
        return self::create('RAND()'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * แยกวินาทีออกจากคอลัมน์ชนิด DATETIME
     *
     * @assert ('create_date')->text() [==] 'SECOND(`create_date`)'
     * @assert ('create_date', 'date')->text() [==] 'SECOND(`create_date`) AS `date`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function SECOND($column_name, $alias = null)
    {
        return self::create('SECOND('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * ผลรวมของคอลัมน์ที่เลือก
     *
     * @assert ('id')->text() [==] 'SUM(`id`)'
     * @assert ('table_name.`id`', 'id')->text() [==] 'SUM(`table_name`.`id`) AS `id`'
     * @assert ('U.id', 'id', true)->text() [==] 'SUM(DISTINCT U.`id`) AS `id`'
     * @assert ('U1.id', 'id', true)->text() [==] 'SUM(DISTINCT U1.`id`) AS `id`'
     *
     * @param string      $column_name
     * @param string|null $alias
     * @param bool        $distinct    false (default) รวมทุกคอลัมน์, true รวมเฉพาะคอลัมน์ที่ไม่ซ้ำ
     *
     * @return static
     */
    public static function SUM($column_name, $alias = '', $distinct = false)
    {
        return self::create('SUM('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * หาความแตกต่างระหว่างเวลา (คืนค่าเป็น H:m:i ที่แตกต่างกัน)
     *
     * @assert ('create_date', Sql::NOW())->text() [==] "TIMEDIFF(`create_date`, NOW())"
     * @assert ('2017-04-04', 'create_date')->text() [==] "TIMEDIFF('2017-04-04', `create_date`)"
     *
     * @param string $column_name1
     * @param string $column_name2
     * @param string $alias
     *
     * @return static
     */
    public static function TIMEDIFF($column_name1, $column_name2, $alias = null)
    {
        return self::create('TIMEDIFF('.self::fieldName($column_name1).', '.self::fieldName($column_name2).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * หาความแตกต่างระหว่างเวลา (คืนค่าตามรูปแบบ $unit)
     *
     * @assert ('HOUR', 'create_date', Sql::NOW())->text() [==] "TIMESTAMPDIFF(HOUR, `create_date`, NOW())"
     * @assert ('MONTH', '2017-04-04', 'create_date')->text() [==] "TIMESTAMPDIFF(MONTH, '2017-04-04', `create_date`)"
     *
     * @param string $unit FRAC_SECOND (microseconds), SECOND, MINUTE, HOUR, DAY, WEEK, MONTH, QUARTER, or YEAR
     * @param string $column_name1
     * @param string $column_name2
     * @param string $alias
     *
     * @return static
     */
    public static function TIMESTAMPDIFF($unit, $column_name1, $column_name2, $alias = null)
    {
        return self::create('TIMESTAMPDIFF('.$unit.', '.self::fieldName($column_name1).', '.self::fieldName($column_name2).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * สร้างคำสั่ง WHERE
     *
     * @assert (1)->text() [==] "`id` = 1"
     * @assert ('1')->text() [==] "`id` = '1'"
     * @assert (0.1)->text() [==] "`id` = 0.1"
     * @assert ('ทดสอบ')->text() [==] "`id` = 'ทดสอบ'"
     * @assert (null)->text() [==] "`id` = NULL"
     * @assert (0x64656)->text() [==] "`id` = 411222"
     * @assert ('SELECT * FROM')->text() [==] "`id` = :id0"
     * @assert (Sql::create('EXISTS SELECT FROM WHERE'))->text() [==] "EXISTS SELECT FROM WHERE"
     * @assert (array('id', '=', 1))->text() [==] "`id` = 1"
     * @assert (array('U.id', '2017-01-01 00:00:00'))->text() [==] "U.`id` = '2017-01-01 00:00:00'"
     * @assert (array('id', 'IN', array(1, '2', null)))->text() [==] "`id` IN (1, '2', NULL)"
     * @assert (array('id', 'SELECT * FROM'))->text() [==] "`id` = :id0"
     * @assert (array('U.`id`', 'NOT IN', Sql::create('SELECT * FROM')))->text() [==] "U.`id` NOT IN SELECT * FROM"
     * @assert (array(array('id', 'IN', array(1, '2', null))))->text() [==] "`id` IN (1, '2', NULL)"
     * @assert (array(array('U.id', 1), array('U.id', '!=', '1')))->text() [==] "U.`id` = 1 AND U.`id` != '1'"
     * @assert (array(array(Sql::MONTH('create_date'), 1), array(Sql::YEAR('create_date'), 1)))->text() [==] "MONTH(`create_date`) = 1 AND YEAR(`create_date`) = 1"
     * @assert (array(array('id', array(1, 'a')), array('id', array('G.id', 'G.`id2`'))))->text() [==] "`id` IN (1, 'a') AND `id` IN (G.`id`, G.`id2`)"
     * @assert (array(array('id', array('', 'th'))))->text() [==] "`id` IN ('', 'th')"
     * @assert (array(Sql::YEAR('create_date'), Sql::YEAR('`create_date`')))->text() [==] "YEAR(`create_date`) = YEAR(`create_date`)"
     * @assert (array('ip', 'NOT IN', array('', '192.168.1.2')))->text() [==] "`ip` NOT IN ('', '192.168.1.2')"
     * @assert (array(1, 1))->text() [==] "1 = 1"
     * @assert (array(array('username', NULL), array('username', '=', NULL), array('username', '!=', NULL)))->text() [==] "`username` IS NULL AND `username` IS NULL AND `username` IS NOT NULL"
     *
     * @param mixed  $condition
     * @param string $operator  (optional) เช่น AND หรือ OR
     * @param string $id        (optional )ชื่อฟิลด์ที่เป็น key
     *
     * @return static
     */
    public static function WHERE($condition, $operator = 'AND', $id = 'id')
    {
        $obj = new static;
        $obj->sql = $obj->buildWhere($condition, $obj->values, $operator, $id);
        return $obj;
    }

    /**
     * แยกปีออกจากคอลัมน์ชนิด DATE DATETIME
     *
     * @assert ('date')->text() [==] 'YEAR(`date`)'
     * @assert ('date', 'y')->text() [==] 'YEAR(`date`) AS `y`'
     *
     * @param string      $column_name
     * @param string|null $alias       ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
     *
     * @return static
     */
    public static function YEAR($column_name, $alias = null)
    {
        return self::create('YEAR('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
    }

    /**
     * class constructer
     *
     * @param string $sql
     */
    public function __construct($sql = null)
    {
        $this->sql = $sql;
        $this->values = array();
    }

    /**
     * สร้าง Object Sql
     *
     * @param string $sql
     */
    public static function create($sql)
    {
        return new static($sql);
    }

    /**
     * ใส่ `` ครอบชื่อคอลัมน์
     * ชื่อคอลัมน์ต้องเป็น ภาษาอังกฤษ ตัวเลข และ _ เท่านั้น
     * ถ้ามีอักขระอื่นนอกจากนี้ คืนค่า ข้อความที่ส่งมา ครอบด้วย ''
     *
     * @assert ('C') [==] "'C'"
     * @assert ('c') [==] "'c'"
     * @assert ('UU') [==] "'UU'"
     * @assert ('U9') [==] "'U9'"
     * @assert ('id') [==] '`id`'
     * @assert ('field_name') [==] '`field_name`'
     * @assert ('U.id') [==] 'U.`id`'
     * @assert ('U1.id') [==] 'U1.`id`'
     * @assert ('U99.member_id') [==] 'U99.`member_id`'
     * @assert ('U99.provinceId1') [==] 'U99.`provinceId1`'
     * @assert ('U999.provinceId1') [==] "`U999`.`provinceId1`"
     * @assert ('U999.`provinceId1`') [==] "`U999`.`provinceId1`"
     * @assert ('U1.id DESC') [==] 'U1.`id` DESC'
     * @assert ('table_name.field_name') [==] '`table_name`.`field_name`'
     * @assert ('`table_name`.`field_name`') [==] '`table_name`.`field_name`'
     * @assert ('table_name.`field_name`') [==] '`table_name`.`field_name`'
     * @assert ('`table_name`.field_name') [==] '`table_name`.`field_name`'
     * @assert ('`table_name`.field_name ASC') [==] '`table_name`.`field_name` ASC'
     * @assert ('0x64656') [==] "`0x64656`"
     * @assert (0x64656) [==] 411222
     * @assert ('DATE(day)') [==] "'DATE(day)'"
     * @assert ('DROP table') [==] "'DROP table'"
     * @assert (array()) [throws] InvalidArgumentException
     *
     * @param string $column_name
     *
     * @throws \InvalidArgumentException ถ้ารูปแบบของ $column_name ไม่ถูกต้อง
     *
     * @return string
     */
    public static function fieldName($column_name)
    {
        if ($column_name instanceof self) {
            // Sql
            return $column_name->text();
        } elseif ($column_name instanceof QueryBuilder) {
            // QueryBuilder
            return '('.$column_name->text().')';
        } elseif (is_string($column_name)) {
            if (preg_match('/^`?([a-z0-9_]{2,})`?(\s(ASC|DESC|asc|desc))?$/', $column_name, $match)) {
                return '`'.$match[1].'`'.(empty($match[3]) ? '' : $match[2]);
            } elseif (preg_match('/^([A-Z][0-9]{0,2}\.)`?([a-zA-Z0-9_]+)`?(\s(ASC|DESC|asc|desc))?$/', $column_name, $match)) {
                return $match[1].'`'.$match[2].'`'.(empty($match[4]) ? '' : $match[3]);
            } elseif (preg_match('/^`?([a-zA-Z0-9_]+)`?\.`?([a-zA-Z0-9_]+)`?(\s(ASC|DESC|asc|desc))?$/', $column_name, $match)) {
                // table_name.field_name, table_name.`field_name`, `table_name`.field_name, `table_name`.`field_name`
                return ("`$match[1]`.`$match[2]`").(empty($match[4]) ? '' : $match[3]);
            } else {
                // อื่นๆ คืนค่าเป็นข้อความภายใต้เครื่องหมาย ' (อัญประกาศเดี่ยว)
                return "'$column_name'";
            }
        } elseif (is_numeric($column_name)) {
            // ตัวเลขเท่านั้น
            return $column_name;
        }
        throw new \InvalidArgumentException('Invalid arguments in fieldName');
    }

    /**
     * คืนค่าแอเร์ยเก็บพารามิเตอร์สำหรับการ bind รวมกับ $values
     *
     * @param array $values
     *
     * @return array
     */
    public function getValues($values = array())
    {
        if (empty($values)) {
            return $this->values;
        }
        foreach ($this->values as $key => $value) {
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * แปลงค่า Value สำหรับใช้ใน query
     *
     * @assert ('id', 'ทดสอบ', $array) [==] "'ทดสอบ'"
     * @assert ('id', 'test', $array) [==] "'test'"
     * @assert ('id', 'abcde012345', $array) [==] "'abcde012345'"
     * @assert ('id', 123456, $array) [==] 123456
     * @assert ('id', 0.1, $array) [==] 0.1
     * @assert ('id', null, $array) [==] 'NULL'
     * @assert ('id', 'U.id', $array) [==] "U.`id`"
     * @assert ('id', 'U.`id`', $array) [==] 'U.`id`'
     * @assert ('id', 'domain.tld', $array) [==] "'domain.tld'"
     * @assert ('id', 'table_name.`id`', $array) [==] '`table_name`.`id`'
     * @assert ('id', '`table_name`.id', $array) [==] '`table_name`.`id`'
     * @assert ('id', '`table_name`.`id`', $array) [==] '`table_name`.`id`'
     * @assert ('id', 'INSERT INTO', $array) [==] ':id0'
     * @assert ('id', array(1, '2', null), $array) [==] "(1, '2', NULL)"
     * @assert ('id', '0x64656', $array) [==] ':id0'
     * @assert ('id', 0x64656, $array) [==] 411222
     * @assert ('`table_name`.`id`', '0x64656', $array) [==] ':tablenameid0'
     * @assert ('U1.`id`', '0x64656', $array) [==] ':u1id0'
     * @assert ('U.id', '0x64656', $array) [==] ':uid0'
     *
     * @param mixed $value
     * @param array $values แอเรย์สำหรับรับค่า value สำหรับการ bind
     *
     * @throws \InvalidArgumentException ถ้ารูปแบบของ $value ไม่ถูกต้อง
     *
     * @return string
     */
    public static function quoteValue($column_name, $value, &$values)
    {
        if (is_array($value)) {
            $qs = array();
            foreach ($value as $v) {
                $qs[] = self::quoteValue($column_name, $v, $values);
            }
            $sql = '('.implode(', ', $qs).')';
        } elseif ($value === null) {
            $sql = 'NULL';
        } elseif ($value === '') {
            $sql = "''";
        } elseif (is_string($value)) {
            if (preg_match('/^([0-9\s\r\n\t\.\_\-:]+)$/', $value)) {
                // ตัวเลข จำนวนเงิน เบอร์โทร วันที่
                $sql = "'$value'";
            } elseif (preg_match('/0x[0-9]+/is', $value)) {
                // 0x
                $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name));
                if (empty($values) || !is_array($values)) {
                    $sql .= 0;
                } else {
                    $sql .= count($values);
                }
                $values[$sql] = $value;
            } else {
                if (preg_match('/^(([A-Z][0-9]{0,2})|`([a-zA-Z0-9_]+)`)\.`?([a-zA-Z0-9_]+)`?$/', $value, $match)) {
                    // U.id U.`id` U1.id U99.id U1.`id`  `table_name`.`module_id`
                    $sql = $match[3] == '' ? "$match[2].`$match[4]`" : "`$match[3]`.`$match[4]`";
                } elseif (preg_match('/^([a-zA-Z0-9_]+)\.`([a-zA-Z0-9_]+)`$/', $value, $match)) {
                    // table_name.`module_id`
                    $sql = "`$match[1]`.`$match[2]`";
                } elseif (!preg_match('/[\s\r\n\t`;\(\)\*\=<>\/\'"]+/s', $value) && !preg_match('/(UNION|INSERT|DELETE|TRUNCATE|DROP|0x[0-9]+)/is', $value)) {
                    // ข้อความที่ไม่มีช่องว่างหรือรหัสที่อาจเป็น SQL
                    $sql = "'$value'";
                } else {
                    $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name));
                    if (empty($values) || !is_array($values)) {
                        $sql .= 0;
                    } else {
                        $sql .= count($values);
                    }
                    $values[$sql] = $value;
                }
            }
        } elseif (is_numeric($value)) {
            // ตัวเลขเท่านั้น
            $sql = $value;
        } elseif ($value instanceof self) {
            // Sql
            $sql = $value->text($column_name);
            $values = $value->getValues($values);
        } elseif ($value instanceof QueryBuilder) {
            // QueryBuilder
            $sql = '('.$value->text().')';
            $values = $value->getValues($values);
        } else {
            throw new \InvalidArgumentException('Invalid arguments in quoteValue');
        }
        return $sql;
    }

    /**
     * ฟังก์ชั่นสำหรับรับค่าเป็นสตริงค์เท่านั้น
     * ผลลัพท์จะถูกครอบด้วย '' (ฟันหนู)
     *
     * @param string $value
     *
     * @return \self
     */
    public static function strValue($value)
    {
        return self::create("'$value'");
    }

    /**
     * คืนค่าคำสั่ง SQL เป็น string
     * ถ้า $sql เป็น null จะคืนค่า :$key ใช้สำหรับการ bind
     *
     * @param string $key
     *
     * @return string
     */
    public function text($key = null)
    {
        if ($this->sql === null) {
            if (is_string($key) && $key != '') {
                return ':'.preg_replace('/[\.`]/', '', strtolower($key));
            } else {
                throw new \InvalidArgumentException('$key must be a non-empty string');
            }
        } else {
            return $this->sql;
        }
    }

    /**
     * create SQL WHERE command
     *
     * @param mixed  $condition
     * @param array  $values    แอเรย์สำหรับรับค่า value สำหรับการ bind
     * @param string $operator  เช่น AND หรือ OR
     * @param string $id        ชื่อฟิลด์ที่เป็น key
     *
     * @return string
     */
    private function buildWhere($condition, &$values, $operator, $id)
    {
        if (is_array($condition)) {
            $qs = array();
            if (is_array($condition[0])) {
                foreach ($condition as $item) {
                    if ($item instanceof QueryBuilder) {
                        $qs[] = '('.$item->text().')';
                        $values = $item->getValues($values);
                    } elseif ($item instanceof self) {
                        $qs[] = $item->text();
                        $values = $item->getValues($values);
                    } else {
                        $qs[] = $this->buildWhere($item, $values, $operator, $id);
                    }
                }
                $sql = implode(' '.$operator.' ', $qs);
            } else {
                if ($condition[0] instanceof QueryBuilder) {
                    $key = '('.$condition[0]->text().')';
                    $values = $condition[0]->getValues($values);
                } elseif ($condition[0] instanceof self) {
                    $key = $condition[0]->text();
                    $values = $condition[0]->getValues($values);
                } elseif (preg_match('/^SQL(\(.*\))$/', $condition[0], $match)) {
                    $key = '('.$match[1].')';
                } else {
                    $key = self::fieldName($condition[0]);
                }
                $c = count($condition);
                if ($c == 2) {
                    if ($condition[1] instanceof QueryBuilder) {
                        $operator = 'IN';
                        $value = '('.$condition[1]->text().')';
                        $values = $condition[1]->getValues($values);
                    } elseif ($condition[1] instanceof self) {
                        $operator = '=';
                        $value = $condition[1]->text();
                        $values = $condition[1]->getValues($values);
                    } elseif ($condition[1] === null) {
                        $operator = 'IS';
                        $value = 'NULL';
                    } else {
                        $operator = '=';
                        if (is_array($condition[1]) && $operator == '=') {
                            $operator = 'IN';
                        }
                        $value = self::quoteValue($key, $condition[1], $values);
                    }
                } elseif ($c == 3) {
                    if ($condition[2] instanceof QueryBuilder) {
                        $operator = trim($condition[1]);
                        $value = '('.$condition[2]->text().')';
                        $values = $condition[2]->getValues($values);
                    } elseif ($condition[2] instanceof self) {
                        $operator = trim($condition[1]);
                        $value = $condition[2]->text();
                        $values = $condition[2]->getValues($values);
                    } elseif ($condition[2] === null) {
                        $operator = trim($condition[1]);
                        if ($operator == '=') {
                            $operator = 'IS';
                        } elseif ($operator == '!=') {
                            $operator = 'IS NOT';
                        }
                        $value = 'NULL';
                    } else {
                        $operator = trim($condition[1]);
                        if (is_array($condition[2]) && $operator == '=') {
                            $operator = 'IN';
                        }
                        $value = self::quoteValue($key, $condition[2], $values);
                    }
                }
                if (isset($value)) {
                    $sql = $key.' '.$operator.' '.$value;
                } else {
                    $sql = $key;
                }
            }
        } elseif ($condition instanceof QueryBuilder) {
            $sql = '('.$condition->text().')';
            $values = $condition->getValues($values);
        } elseif ($condition instanceof self) {
            $sql = $condition->text();
            $values = $condition->getValues($values);
        } elseif (preg_match('/^SQL\((.+)\)$/', $condition, $match)) {
            $sql = '('.$match[1].')';
        } else {
            // ใช้ $id เป็น column_name
            $sql = self::fieldName($id).' = '.self::quoteValue($id, $condition, $values);
        }
        return $sql;
    }
}
