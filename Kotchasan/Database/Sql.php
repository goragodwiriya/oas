<?php
/**
 * @filesource Kotchasan/Database/Sql.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Database;

use \Kotchasan\Database\QueryBuilder;

/**
 * SQL Function
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
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
   * คืนค่าคำสั่ง SQL เป็น string
   * ถ้า $sql เป็น null จะคืนค่า :$key ใช้สำหรับการ bind
   *
   * @param string $key
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
   * คืนค่าแอเร์ยเก็บพารามิเตอร์สำหรับการ bind รวมกับ $values
   *
   * @param array $values
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
   * @param string $column_name
   * @return string
   * @throws \InvalidArgumentException ถ้ารูปแบบของ $column_name ไม่ถูกต้อง
   *
   * @assert ('U.id') [==] 'U.`id`'
   * @assert ('U1.id') [==] 'U1.`id`'
   * @assert ('field_name') [==] '`field_name`'
   * @assert ('table_name.field_name') [==] '`table_name`.`field_name`'
   * @assert ('`table_name`.`field_name`') [==] '`table_name`.`field_name`'
   * @assert ('table_name.`field_name`') [==] '`table_name`.`field_name`'
   * @assert ('`table_name`.field_name') [==] '`table_name`.`field_name`'
   * @assert ('0x64656') [==] "`0x64656`"
   * @assert (0x64656) [==] 411222
   * @assert ('DATE(day)') [==] "'DATE(day)'"
   * @assert ('DROP table') [==] "'DROP table'"
   * @assert (array()) [throws] InvalidArgumentException
   */
  public static function fieldName($column_name)
  {
    if (is_numeric($column_name)) {
      // ตัวเลขเท่านั้น
      return $column_name;
    } elseif ($column_name instanceof self) {
      // Sql
      return $column_name->text();
    } elseif ($column_name instanceof QueryBuilder) {
      // QueryBuilder
      return '('.$column_name->text().')';
    } elseif (is_string($column_name)) {
      if (preg_match('/^(([A-Z0-9]{1,2})\.)?`?([a-zA-Z0-9_]+)`?$/', $column_name, $match)) {
        // U.id, U.`id`, U1.id, U1.`id`, field_name, `field_name`
        return $match[2] == '' ? "`$match[3]`" : "$match[2].`$match[3]`";
      } elseif (preg_match('/^`?([a-zA-Z0-9_]+)`?\.`?([a-zA-Z0-9_]+)`?$/', $column_name, $match)) {
        // table_name.field_name, table_name.`field_name`, `table_name`.field_name, `table_name`.`field_name`
        return "`$match[1]`.`$match[2]`";
      } else {
        // อื่นๆ คืนค่าเป็นข้อความภายใต้เครื่องหมาย ' (อัญประกาศเดี่ยว)
        return "'$column_name'";
      }
    }
    throw new \InvalidArgumentException('Invalid arguments in fieldName');
  }

  /**
   * สร้างคำสั่ง WHERE
   *
   * @param mixed $condition
   * @param string $operator (optional) เช่น AND หรือ OR
   * @param string $id (optional )ชื่อฟิลด์ที่เป็น key
   * @return \static
   *
   * @assert WHERE(1)->text() [==] "`id` = 1"
   * @assert WHERE('1')->text() [==] "`id` = '1'"
   * @assert WHERE(0.1)->text() [==] "`id` = 0.1"
   * @assert WHERE('ทดสอบ')->text() [==] "`id` = 'ทดสอบ'"
   * @assert WHERE(null)->text() [==] "`id` = NULL"
   * @assert WHERE(0x64656)->text() [==] "`id` = 411222"
   * @assert WHERE('SELECT * FROM')->text() [==] "`id` = :id0"
   * @assert WHERE(Sql::create('EXISTS SELECT FROM WHERE'))->text() [==] "EXISTS SELECT FROM WHERE"
   * @assert WHERE(array('id', '=', 1))->text() [==] "`id` = 1"
   * @assert WHERE(array('U.id', '2017-01-01 00:00:00'))->text() [==] "U.`id` = '2017-01-01 00:00:00'"
   * @assert WHERE(array('id', 'IN', array(1, '2', null)))->text() [==] "`id` IN (1, '2', NULL)"
   * @assert WHERE(array('id', 'SELECT * FROM'))->text() [==] "`id` = :id0"
   * @assert WHERE(array('U.`id`', 'NOT IN', Sql::create('SELECT * FROM')))->text() [==] "U.`id` NOT IN SELECT * FROM"
   * @assert WHERE(array(array('id', 'IN', array(1, '2', null))))->text() [==] "`id` IN (1, '2', NULL)"
   * @assert WHERE(array(array('U.id', 1), array('U.id', '!=', '1')))->text() [==] "U.`id` = 1 AND U.`id` != '1'"
   * @assert WHERE(array(array(Sql::MONTH('create_date'), 1), array(Sql::YEAR('create_date'), 1)))->text() [==] "MONTH(`create_date`) = 1 AND YEAR(`create_date`) = 1"
   * @assert WHERE(array(array('id', array(1, 'a')), array('id', array('G.id', 'G.`id2`'))))->text() [==] "`id` IN (1, 'a') AND `id` IN (G.`id`, G.`id2`)"
   * @assert WHERE(array(array('id', array('', 'th'))))->text() [==] "`id` IN ('', 'th')"
   * @assert WHERE(array(Sql::YEAR('create_date'), Sql::YEAR('`create_date`')))->text() [==] "YEAR(`create_date`) = YEAR(`create_date`)"
   * @assert WHERE(array('ip', 'NOT IN', array('', '192.168.1.2')))->text() [==] "`ip` NOT IN ('', '192.168.1.2')"
   * @assert (array(1, 1))->text() [==] "1 = 1"
   */
  public static function WHERE($condition, $operator = 'AND', $id = 'id')
  {
    $obj = new static;
    $obj->sql = $obj->buildWhere($condition, $obj->values, $operator, $id);
    return $obj;
  }

  /**
   * create SQL WHERE command
   *
   * @param mixed $condition
   * @param array $values แอเรย์สำหรับรับค่า value สำหรับการ bind
   * @param string $operator เช่น AND หรือ OR
   * @param string $id ชื่อฟิลด์ที่เป็น key
   * @return string
   */
  private function buildWhere($condition, &$values, $operator, $id)
  {
    if (is_array($condition)) {
      if (is_array($condition[0])) {
        $qs = array();
        foreach ($condition as $items) {
          $qs[] = $this->buildWhere($items, $values, $operator, $id);
        }
        $sql = implode(' '.$operator.' ', $qs);
      } else {
        if (sizeof($condition) == 2) {
          $operator = '=';
          $value = $condition[1];
        } else {
          $operator = trim($condition[1]);
          $value = $condition[2];
        }
        $key = self::fieldName($condition[0]);
        if (is_array($value) && $operator == '=') {
          $operator = 'IN';
        }
        $sql = $key.' '.$operator.' '.self::quoteValue($key, $value, $values);
      }
    } elseif ($condition instanceof QueryBuilder) {
      // QueryBuilder ไม่มี column_name
      $sql = $condition->text();
      $values = $condition->getValues($values);
    } elseif ($condition instanceof self) {
      // Sql ไม่มี column_name
      $sql = $condition->text();
      $values = $condition->getValues($values);
    } else {
      // ใช้ $id เป็น column_name
      $sql = self::fieldName($id).' = '.self::quoteValue($id, $condition, $values);
    }
    return $sql;
  }

  /**
   * แปลงค่า Value สำหรับใช้ใน query
   *
   * @param mixed $value
   * @param array $values แอเรย์สำหรับรับค่า value สำหรับการ bind
   * @return string
   * @throws \InvalidArgumentException ถ้ารูปแบบของ $value ไม่ถูกต้อง
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
   */
  public static function quoteValue($column_name, $value, &$values)
  {
    if (is_array($value)) {
      $qs = array();
      foreach ($value as $v) {
        $qs[] = self::quoteValue($column_name, $v, $values);
      }
      $sql = '('.implode(', ', $qs).')';
    } elseif ($value === NULL) {
      $sql = 'NULL';
    } elseif ($value === '') {
      $sql = "''";
    } elseif (is_string($value)) {
      if (preg_match('/^([0-9\s\r\n\t\.\_\-:]+)$/', $value)) {
        // ตัวเลข จำนวนเงิน เบอร์โทร วันที่
        $sql = "'$value'";
      } elseif (preg_match('/0x[0-9]+/is', $value)) {
        // 0x
        $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name)).sizeof($values);
        $values[$sql] = $value;
      } else {
        if (preg_match('/^(([A-Z0-9]{1,2})|`([a-zA-Z0-9_]+)`)\.`?([a-zA-Z0-9_]+)`?$/', $value, $match)) {
          // U.id U.`id` U1.id U1.`id`  `table_name`.`module_id`
          $sql = $match[3] == '' ? "$match[2].`$match[4]`" : "`$match[3]`.`$match[4]`";
        } elseif (preg_match('/^([a-zA-Z0-9_]+)\.`([a-zA-Z0-9_]+)`$/', $value, $match)) {
          // table_name.`module_id`
          $sql = "`$match[1]`.`$match[2]`";
        } elseif (!preg_match('/[\s\r\n\t`;\(\)\*\=<>\/\'"]+/s', $value) && !preg_match('/(UNION|INSERT|DELETE|TRUNCATE|DROP|0x[0-9]+)/is', $value)) {
          // ข้อความที่ไม่มีช่องว่างหรือรหัสที่อาจเป็น SQL
          $sql = "'$value'";
        } else {
          $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name)).sizeof($values);
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
   * ฟังก์ชั่นสร้าง SQL สำหรับหาค่าสูงสุด + 1
   * ใช้ในการหาค่า id ถัดไป
   *
   *
   * @param string $field ชื่อฟิลด์ที่ต้องการหาค่าสูงสุด
   * @param string $table_name ชื่อตาราง
   * @param mixed $condition (optional) query WHERE
   * @param array $values (optional) แอเรย์สำหรับรับค่า value สำหรับการ bind
   * @param string $alias (optional) ชื่อฟิลด์ที่ใช้คืนค่า ไม่ระบุ (null) หมายถึงไม่ต้องการชื่อฟิลด์
   * @param string $operator (optional) เช่น AND หรือ OR
   * @param string $id (optional )ชื่อฟิลด์ที่เป็น key
   * @return \static
   *
   * @assert ('id', '`world`')->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X), 0))'
   * @assert ('id', '`world`', array(array('module_id', 'D.`id`')), 'next_id')->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X WHERE `module_id` = D.`id`), 0)) AS `next_id`'
   * @assert ('id', '`world`', array(array('module_id', 'D.`id`')), null)->text() [==] '(1 + IFNULL((SELECT MAX(`id`) FROM `world` AS X WHERE `module_id` = D.`id`), 0))'
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
   * ผลรวมของคอลัมน์ที่เลือก
   *
   * @param string $column_name
   * @param string|null $alias
   * @param boolean $distinct false (default) รวมทุกคอลัมน์, true รวมเฉพาะคอลัมน์ที่ไม่ซ้ำ
   * @return \static
   *
   * @assert ('id')->text() [==] 'SUM(`id`)'
   * @assert ('table_name.`id`', 'id')->text() [==] 'SUM(`table_name`.`id`) AS `id`'
   * @assert ('U.id', 'id', true)->text() [==] 'SUM(DISTINCT U.`id`) AS `id`'
   * @assert ('U1.id', 'id', true)->text() [==] 'SUM(DISTINCT U1.`id`) AS `id`'
   */
  public static function SUM($column_name, $alias = '', $distinct = false)
  {
    return self::create('SUM('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * นับจำนวนเร็คคอร์ดของคอลัมน์ที่เลือก
   *
   * @param string $column_name
   * @param string|null $alias
   * @param boolean $distinct false (default) นับทุกคอลัมน์, true นับเฉพาะคอลัมน์ที่ไม่ซ้ำ
   * @return \static
   *
   * @assert ('id')->text() [==] 'COUNT(`id`)'
   */
  public static function COUNT($column_name = '*', $alias = null, $distinct = false)
  {
    $column_name = $column_name == '*' ? '*' : self::fieldName($column_name);
    return self::create('COUNT('.($distinct ? 'DISTINCT ' : '').$column_name.')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * หาค่าเฉลี่ยของคอลัมน์ที่เลือก
   *
   * @param string $column_name ชื่อคอลัมน์
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @param boolean $distinct false (default) นับทุกคอลัมน์, true นับเฉพาะคอลัมน์ที่ไม่ซ้ำ
   * @return \static
   *
   * @assert ('id')->text() [==] 'AVG(`id`)'
   */
  public static function AVG($column_name, $alias = null, $distinct = false)
  {
    return self::create('AVG('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * หาค่าต่ำสุด
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('id')->text() [==] 'MIN(`id`)'
   */
  public static function MIN($column_name, $alias = null)
  {
    return self::create('MIN('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * หาค่าต่ำสุด
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('id')->text() [==] 'MAX(`id`)'
   */
  public static function MAX($column_name, $alias = null)
  {
    return self::create('MAX('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * แยกวันที่ออกจากคอลัมน์ชนิด DATE DATETIME
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('date')->text() [==] 'DAY(`date`)'
   * @assert ('date', 'd')->text() [==] 'DAY(`date`) AS `d`'
   */
  public static function DAY($column_name, $alias = null)
  {
    return self::create('DAY('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * แยกเดือนออกจากคอลัมน์ชนิด DATE DATETIME
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('date')->text() [==] 'MONTH(`date`)'
   * @assert ('date', 'm')->text() [==] 'MONTH(`date`) AS `m`'
   */
  public static function MONTH($column_name, $alias = null)
  {
    return self::create('MONTH('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * แยกปีออกจากคอลัมน์ชนิด DATE DATETIME
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('date')->text() [==] 'YEAR(`date`)'
   * @assert ('date', 'y')->text() [==] 'YEAR(`date`) AS `y`'
   */
  public static function YEAR($column_name, $alias = null)
  {
    return self::create('YEAR('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * แยกวันที่ออกจากคอลัมน์ชนิด DATETIME
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ('create_date')->text() [==] 'DATE(`create_date`)'
   * @assert ('create_date', 'date')->text() [==] 'DATE(`create_date`) AS `date`'
   */
  public static function DATE($column_name, $alias = null)
  {
    return self::create('DATE('.self::fieldName($column_name).')'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * จัดรูปแบบของวันที่ตอนแสดงผล
   *
   * @param string $column_name
   * @param string $format
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert (Sql::NOW(), '%h:%i')->text() [==] "DATE_FORMAT(NOW(), '%h:%i')"
   * @assert ('create_date', '%Y-%m-%d', 'today')->text() [==] "DATE_FORMAT(`create_date`, '%Y-%m-%d') AS `today`"
   */
  public static function DATE_FORMAT($column_name, $format, $alias = null)
  {
    return self::create("DATE_FORMAT(".self::fieldName($column_name).", '$format')".($alias ? " AS `$alias`" : ''));
  }

  /**
   * หาความแตกต่างระหว่างวัน (คืนค่าเป็นจำนวนวันที่แตกต่างกัน)
   *
   * @param string $column_name1
   * @param string $column_name2
   * @param string $alias
   * @return \static
   *
   * @assert ('create_date', Sql::NOW())->text() [==] "DATEDIFF(`create_date`, NOW())"
   * @assert ('2017-04-04', 'create_date')->text() [==] "DATEDIFF('2017-04-04', `create_date`)"
   */
  public static function DATEDIFF($column_name1, $column_name2, $alias = null)
  {
    return self::create("DATEDIFF(".self::fieldName($column_name1).", ".self::fieldName($column_name2).")".($alias ? " AS `$alias`" : ''));
  }

  /**
   * จัดรูปแบบของคอลัมน์ตอนแสดงผล
   *
   * @param string $column_name
   * @param string $format
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert (Sql::NOW(), 'Y-m-d')->text() [==] "FORMAT(NOW(), 'Y-m-d')"
   * @assert ('create_date', 'Y-m-d', 'today')->text() [==] "FORMAT(`create_date`, 'Y-m-d') AS `today`"
   */
  public static function FORMAT($column_name, $format, $alias = null)
  {
    return self::create("FORMAT(".self::fieldName($column_name).", '$format')".($alias ? " AS `$alias`" : ''));
  }

  /**
   * สุ่มตัวเลข
   *
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ()->text() [==] 'RAND()'
   * @assert ('id')->text() [==] 'RAND() AS `id`'
   */
  public static function RAND($alias = null)
  {
    return self::create('RAND()'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * คืนค่าวันที่และเวลาปัจจุบัน
   *
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @return \static
   *
   * @assert ()->text() [==] 'NOW()'
   * @assert ('id')->text() [==] 'NOW() AS `id`'
   */
  public static function NOW($alias = null)
  {
    return self::create('NOW()'.($alias ? " AS `$alias`" : ''));
  }

  /**
   * สร้างคำสั่ง CONCAT หรือ CONCAT_WS
   *
   * @param array $fields รายชื่อฟิลด์
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @param string|null $separator null (defailt) คืนค่าคำสั่ง CONCAT, ถ้าระบุเป็นค่าอื่นคืนค่าคำสั่ง CONCAT_WS
   * @return \static
   *
   * @assert (array('fname', 'lname'))->text() [==] "CONCAT(`fname`, `lname`)"
   * @assert (array('U.fname', 'U.`lname`'), 'displayname')->text() [==] "CONCAT(U.`fname`, U.`lname`) AS `displayname`"
   * @assert (array('fname', 'lname'), 'displayname', ' ')->text() [==] "CONCAT_WS(' ', `fname`, `lname`) AS `displayname`"
   */
  public static function CONCAT($fields, $alias = null, $separator = null)
  {
    $fs = array();
    foreach ($fields as $item) {
      $fs[] = self::fieldName($item);
    }
    return self::create(($separator === null ? 'CONCAT(' : "CONCAT_WS('$separator', ").implode(', ', $fs).($alias ? ") AS `$alias`" : ')'));
  }

  /**
   * สร้างคำสั่ง GROUP_CONCAT
   * @param string $column_name
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @param string $separator ข้อความเชื่อมฟิลด์เข้าด้วยกัน ค่าเริมต้นคือ ,
   * @param boolean $distinct false (default) คืนค่ารายการที่ไม่ซ้ำ
   * @return \self
   *
   * @assert ('C.topic', 'topic', ', ')->text() [==] "GROUP_CONCAT(C.`topic` SEPARATOR ', ') AS `topic`"
   */
  public static function GROUP_CONCAT($column_name, $alias = null, $separator = ',', $distinct = false, $order = null)
  {
    return self::create('GROUP_CONCAT('.($distinct ? 'DISTINCT ' : '').self::fieldName($column_name)." SEPARATOR '$separator')".($alias ? " AS `$alias`" : ''));
  }

  /**
   * ค้นหาข้อความ ไม่พบคืนค่า 0, ตัวแรกคือ 1
   *
   * @param string $substr ข้อความที่ค้นหา ถ้าเป็นชื่อฟิลด์ต้องครอบด้วย ``
   * @param string $str ข้อความต้นฉบับ ถ้าเป็นชื่อฟิลด์ต้องครอบด้วย ``
   * @param string|null $alias ชื่อรองที่ต้องการ ถ้าไม่ระบุไม่มีชื่อรอง
   * @param int $pos ตำแหน่งเริ่มต้นค้นหา 0 (default) หรือไม่ระบุ ค้นหาตั้งแต่ตัวแรก
   * @return \self
   *
   * @assert ('find', 'C.`topic`')->text() [==] "LOCATE('find', C.`topic`)"
   */
  public static function POSITION($substr, $str, $alias = null, $pos = 0)
  {
    $substr = strpos($substr, '`') === false ? "'$substr'" : $substr;
    $str = strpos($str, '`') === false ? "'$str'" : $str;
    return self::create("LOCATE($substr, $str".(empty($pos) ? ')' : ", $pos)").($alias ? " AS `$alias`" : ''));
  }

  /**
   * สร้างคำสั่ง BETWEEN ... AND ...
   *
   * @param string $column_name1
   * @param string $column_name2
   * @return \static
   *
   * @assert ('create_date', 'U.create_date')->text() [==] "BETWEEN `create_date` AND U.`create_date`"
   * @assert ('table_name.field_name', 'U.`create_date`')->text() [==] "BETWEEN `table_name`.`field_name` AND U.`create_date`"
   * @assert ('`database`.`table`', '12-1-1')->text() [==] "BETWEEN `database`.`table` AND '12-1-1'"
   */
  public static function BETWEEN($column_name1, $column_name2)
  {
    return self::create('BETWEEN '.self::fieldName($column_name1).' AND '.self::fieldName($column_name2));
  }

  /**
   * ฟังก์ชั่นสำหรับรับค่าเป็นสตริงค์เท่านั้น
   * ผลลัพท์จะถูกครอบด้วย '' (ฟันหนู)
   *
   * @param string $value
   * @return \self
   */
  public static function strValue($value)
  {
    return self::create("'$value'");
  }
}
