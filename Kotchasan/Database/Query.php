<?php
/**
 * @filesource Kotchasan/Database/Query.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Database;

use \Kotchasan\ArrayTool;
use \Kotchasan\Database\Sql;

/**
 * Database Query (base class)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
abstract class Query extends \Kotchasan\Database\Db
{
  /**
   * ตัวแปรเก็บคำสั่ง SQL
   *
   * @var array
   */
  protected $sqls;
  /**
   * true แสดง Query ออกทางหน่าจอก่อนการ execute
   *
   * @var boolean
   */
  protected $debugger = false;

  /**
   * ฟังก์ชั่นสำหรับจัดกลุ่มคำสั่ง และ เชื่อมแต่ละกลุ่มด้วย AND
   *
   * @param array $params คำสั่ง รูปแบบ array('field1', 'condition', 'field2')
   * @return Sql
   */
  protected function groupAnd($params)
  {
    if (func_num_args() > 1) {
      $params = func_get_args();
    }
    $sqls = array();
    foreach ($params as $i => $item) {
      $sqls[] = $this->buildValue($item);
    }
    return Sql::create('('.implode(' AND ', $sqls).')');
  }

  /**
   * ฟังก์ชั่นสำหรับจัดกลุ่มคำสั่ง และ เชื่อมแต่ละกลุ่มด้วย OR
   *
   * @param array $params คำสั่ง รูปแบบ array('field1', 'condition', 'field2')
   * @return Sql
   */
  protected function groupOr($params)
  {
    if (func_num_args() > 1) {
      $params = func_get_args();
    }
    $sqls = array();
    foreach ($params as $i => $item) {
      $sqls[] = $this->buildValue($item);
    }
    return Sql::create('('.implode(' OR ', $sqls).')');
  }

  /**
   * ฟังก์ชั่นสร้างคำสั่ง SQL เป็นข้อความ
   *
   * @return string
   */
  public function text()
  {
    if (!empty($this->sqls)) {
      $sql = $this->db->makeQuery($this->sqls);
      foreach ($this->getValues() as $key => $value) {
        $sql = str_replace($key, (is_string($value) ? "'$value'" : $value), $sql);
      }
      return $sql;
    }
    return '';
  }

  /**
   * คำสั่งสำหรับแสดง Query ออกทางหน้าจอ
   * ใช้ในการ debug Query
   */
  public function debug()
  {
    echo '<pre>'.$this->text().'</pre>';
    return $this;
  }

  /**
   * ฟังก์ชั่นอ่านชื่อตารางและชื่อรอง และใส่ ` ครอบชื่อตารางด้วย
   *
   * @param string $table ชื่อตารางตามที่กำหนดใน settings/datasbase.php
   * @return string
   */
  protected function quoteTableName($table)
  {
    if (is_array($table)) {
      if ($table[0] instanceof QueryBuilder) {
        $table = '('.$table[0]->text().') AS '.$table[1];
      } else {
        $table = '('.$table[0].') AS '.$table[1];
      }
    } elseif (preg_match('/^([a-zA-Z0-9_]+)(\s+(as|AS))?[\s]+([A-Z0-9]{1,2})$/', $table, $match)) {
      $table = $this->getFullTableName($match[1]).' AS '.$match[4];
    } elseif (preg_match('/^([a-zA-Z0-9_]+)(\s+(as|AS))?[\s]+([a-zA-Z0-9]+)$/', $table, $match)) {
      $table = $this->getFullTableName($match[1]).' AS `'.$match[4].'`';
    } else {
      $table = $this->getFullTableName($table);
    }
    return $table;
  }

  /**
   * ฟังก์ชั่นอ่านชื่อตารางจากการตั้งค่าฐานข้อมุล
   *
   * @param string $table ชื่อตารางตามที่กำหนดใน settings/datasbase.php
   * @return string ชื่อตารางรวม prefix ถ้าไม่มีชื่อกำหนดไว้ จะคืนค่า $table ครอบชื่อตารางด้วย ``
   */
  public function getFullTableName($table)
  {
    $dbname = empty($this->db->settings->dbname) ? '' : '`'.$this->db->settings->dbname.'`.';
    return $dbname.'`'.$this->getTableName($table).'`';
  }

  /**
   * ฟังก์ชั่นอ่านชื่อตารางจากการตั้งค่าฐานข้อมุล
   *
   * @param string $table ชื่อตารางตามที่กำหนดใน settings/datasbase.php
   * @return string ชื่อตารางรวม prefix ถ้าไม่มีชื่อกำหนดไว้ จะคืนค่า $table
   */
  public function getTableName($table)
  {
    $prefix = empty($this->db->settings->prefix) ? '' : $this->db->settings->prefix.'_';
    return $prefix.(isset($this->db->tables->$table) ? $this->db->tables->$table : $table);
  }

  /**
   * ฟังก์ชั่นสร้าง query string สำหรับคำสั่ง SELECT
   *
   * @param string|array|QueryBuilder $fields
   * @return string
   */
  protected function buildSelect($fields)
  {
    if (is_array($fields)) {
      if ($fields[0] instanceof QueryBuilder) {
        // QueryBuilder
        $ret = '('.$fields[0]->text().') AS `'.$fields[1].'`';
      } elseif (is_string($fields[0]) && preg_match('/^([a-zA-Z0-9\\\]+)::([a-zA-Z0-9]+)$/', $fields[0], $match)) {
        // Recordset
        $ret = '\''.addslashes($fields[0]).'\' AS `'.$fields[1].'`';
      } else {
        // multiples
        $rets = array();
        foreach ($fields AS $item) {
          $rets[] = $this->buildSelect($item);
        }
        $ret = implode(',', $rets);
      }
    } elseif ($fields instanceof QueryBuilder) {
      // QueryBuilder
      $ret = '('.$fields->text().')';
    } elseif ($fields instanceof Sql) {
      // Sql
      $ret = $fields->text();
    } elseif ($fields == '*') {
      $ret = '*';
    } elseif (preg_match('/^(NULL|[0-9]+)([\s]+as)?[\s]+`?([^`]+)`?$/i', $fields, $match)) {
      // 0 as alias, NULL as alias
      $ret = $match[1].' AS `'.$match[3].'`';
    } elseif (preg_match('/^([\'"])(.*)\\1([\s]+as)?[\s]+`?([^`]+)`?$/i', $fields, $match)) {
      // 'string' as alias
      $ret = "'$match[2]' AS `$match[4]`";
    } elseif (preg_match('/^([A-Z0-9]{1,2})\.`?([\*a-z0-9_]+)`?(([\s]+as)?[\s]+`?([^`]+)`?)?$/i', $fields, $match)) {
      // U.id alias
      $ret = $match[1].'.'.($match[2] == '*' ? '*' : '`'.$match[2].'`').(isset($match[5]) ? ' AS `'.$match[5].'`' : '');
    } elseif (preg_match('/^`?([a-z0-9_]+)`?\.`?([\*a-z0-9_]+)`?(([\s]+as)?[\s]+`?([^`]+)`?)?$/i', $fields, $match)) {
      // table.field alias
      $ret = '`'.$match[1].'`.'.($match[2] == '*' ? '*' : '`'.$match[2].'`').(isset($match[5]) ? ' AS `'.$match[5].'`' : '');
    } elseif (preg_match('/^`?([a-z0-9_]+)`?([\s]+as)?[\s]+`?([^`]+)`?$/i', $fields, $match)) {
      // table.field
      $ret = '`'.$match[1].'` AS `'.$match[3].'`';
    } elseif (preg_match('/([a-z0-9_]+)/i', $fields, $match)) {
      // field name เช่น id
      $ret = '`'.$fields.'`';
    }
    return isset($ret) ? $ret : '';
  }

  /**
   * สร้างคำสั่ง JOIN
   *
   * @param string|array $table ชื่อตารางต้องมี alias ด้วย หรือ (QueryBuilder, alias)
   * @param string $type เข่น INNER OUTER LEFT RIGHT
   * @param mixed $on query string หรือ array
   * @return string ถ้าไม่มี alias คืนค่าว่าง
   */
  protected function buildJoin($table, $type, $on)
  {
    $ret = $this->buildWhere($on);
    $sql = is_array($ret) ? $ret[0] : $ret;
    if (is_array($table)) {
      $sql = ' '.$type.' JOIN ('.$table[0]->text().') AS '.$table[1].' ON '.$sql;
    } elseif (preg_match('/^([a-zA-Z0-9_]+)([\s]+(as|AS))?[\s]+([A-Z0-9]{1,2})$/', $table, $match)) {
      $sql = ' '.$type.' JOIN '.$this->getFullTableName($match[1]).' AS '.$match[4].' ON '.$sql;
    } elseif (preg_match('/^([a-z0-9_]+)([\s]+as)?[\s]+([a-z0-9_]+)$/i', $table, $match)) {
      $sql = ' '.$type.' JOIN '.$this->getFullTableName($match[1]).' AS `'.$match[3].'` ON '.$sql;
    } else {
      $sql = ' '.$type.' JOIN '.$table.' ON '.$sql;
    }
    if (is_array($ret)) {
      return array($sql, $ret[1]);
    } else {
      return $sql;
    }
  }

  /**
   * สร้าง query เรียงลำดับ
   *
   * @param array|string $fields array('field ASC','field DESC') หรือ 'field ASC', 'field DESC', ....
   * @return string
   */
  protected function buildOrder($fields)
  {
    $sqls = array();
    foreach ((array)$fields as $item) {
      if (preg_match('/^([A-Z0-9]{1,2}\.)([a-z0-9_]+)([\s]{1,}(ASC|DESC))?$/i', $item, $match)) {
        // U.id DESC
        $sqls[] = $match[1].'`'.$match[2].'`'.(isset($match[4]) ? " $match[4]" : '');
      } elseif (preg_match('/^([a-z0-9_]+)(\.([a-z0-9_]+))?(([\s]+)?(ASC|DESC))?$/i', $item, $match)) {
        // field.id DESC
        $sqls[] = '`'.$match[1].'`'.(empty($match[3]) ? '' : '.`'.$match[3].'`').(isset($match[6]) ? " $match[6]" : '');
      } elseif (strtoupper($item) === 'RAND()') {
        // RAND()
        $sqls[] = 'RAND()';
      }
    }
    return implode(', ', $sqls);
  }

  /**
   * สร้าง query สำหรับ GROUP BY
   *
   * @param array|string $fields array('U.id', 'U.username') หรือ string U.id
   * @return string
   */
  protected function buildGroup($fields)
  {
    $sqls = array();
    foreach ((array)$fields as $item) {
      $sqls[] = $this->fieldName($item);
    }
    return empty($sqls) ? '' : implode(', ', $sqls);
  }

  /**
   * ฟังก์ชั่นสร้างคีย์ สำหรับการ execute
   *
   * @param string $name ชื่อฟิลด์
   * @param string $prefix คำนำหน้าชื่อฟิลด์ ใช้เพื่อป้องกันการใช้ตัวแปรซ้ำ
   * @return string
   */
  protected function aliasName($name, $prefix = '')
  {
    return ':'.$prefix.trim(preg_replace('/[`\._\-]/', '', $name));
  }

  /**
   * แปลงข้อความสำหรับชื่อฟิลด์หรือชื่อตาราง
   *
   * @param string $name
   * @return string
   */
  protected function fieldName($name)
  {
    if (is_array($name)) {
      if ($name[0] instanceof QueryBuilder) {
        $ret = '('.$name[0]->text().') AS `'.$name[1].'`';
      } else {
        $rets = array();
        foreach ($name AS $item) {
          $rets[] = $this->fieldName($item);
        }
        $ret = implode(', ', $rets);
      }
    } elseif (is_numeric($name)) {
      $ret = $name;
    } else {
      $name = trim($name);
      if (strpos($name, '(') !== false && preg_match('/^(.*?)(\s{0,}(as)?\s{0,}`?([a-z0-9_]+)`?)?$/i', $name, $match)) {
        // (...) as pos
        $ret = $match[1].(isset($match[4]) ? " AS `$match[4]`" : '');
      } elseif (preg_match('/^([A-Z0-9]{1,2})\.([\*a-zA-Z0-9_]+)((\s+(as|AS))?\s+([a-zA-Z0-9_]+))?$/', $name, $match)) {
        // U.id as user_id
        $ret = $match[1].'.'.($match[2] == '*' ? '*' : '`'.$match[2].'`').(isset($match[6]) ? ' AS `'.$match[6].'`' : '');
      } elseif (preg_match('/^`?([a-z0-9_]+)`?\.([\*a-z0-9_]+)(([\s]+as)?[\s]+([a-z0-9_]+))?$/i', $name, $match)) {
        // `user`.id, user.id as user_id
        $ret = '`'.$match[1].'`.'.($match[2] == '*' ? '*' : '`'.$match[2].'`').(isset($match[5]) ? ' AS `'.$match[5].'`' : '');
      } elseif (preg_match('/^([a-z0-9_]+)(([\s]+as)?[\s]+([a-z0-9_]+))?$/i', $name, $match)) {
        // user as user_id
        $ret = '`'.$match[1].'`'.(isset($match[4]) ? ' AS `'.$match[4].'`' : '');
      } else {
        $ret = $name == '*' ? '*' : '`'.$name.'`';
      }
    }
    return $ret;
  }

  /**
   * แปลงข้อความสำหรับ value
   *
   * @param string $value
   * @return string
   */
  protected function fieldValue($value)
  {
    if (is_array($value)) {
      $rets = array();
      foreach ($value AS $item) {
        $rets[] = $this->fieldValue($item);
      }
      $ret = '('.implode(', ', $rets).')';
    } elseif (is_numeric($value)) {
      $ret = $value;
    } elseif (preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)(([\s]+as)?[\s]+([a-z0-9]+))?$/i', $value, $match)) {
      $ret = "`$match[1]`.`$match[2]`".(isset($match[5]) ? ' AS `'.$match[5].'`' : '');
    } else {
      $ret = '\''.$value.'\'';
    }
    return $ret;
  }

  /**
   * แปลงข้อมูลรูปแบบ SQL
   *
   * @param array $params
   * รูปแบบ array('field1', 'condition', 'field2')
   * ไม่ระบุ condition หมายถึง = หรือ IN
   * @return string
   */
  protected function buildValue($params)
  {
    if (is_array($params)) {
      if (sizeof($params) == 2) {
        $params = array($params[0], '=', $params[1]);
      } else {
        $params = array($params[0], trim($params[1]), $params[2]);
      }
      $key = $this->fieldName($params[0]);
      if (is_numeric($params[2]) || is_bool($params[2])) {
        // value เป็นตัวเลข หรือ boolean
        $value = $params[2];
      } elseif (is_array($params[2])) {
        // value เป็น array
        if ($params[1] == '=') {
          $params[1] = 'IN';
        }
        $qs = array();
        foreach ($params[2] as $item) {
          if (is_numeric($item) || is_bool($item)) {
            $qs[] = $item;
          } else {
            $qs[] = "'$item'";
          }
        }
        $value = '('.implode(', ', $qs).')';
      } elseif (preg_match('/^\((.*)\)([\s]+as)?[\s]+([a-z0-9_]+)$/i', $params[2], $match)) {
        // value เป็น query string
        $value = "($match[1]) AS `$match[3]`";
      } elseif (preg_match('/^([A-Z0-9]{1,2})\.([a-zA-Z0-9_]+)$/', $params[2], $match)) {
        // U.id
        $value = $match[1].'.`'.$match[2].'`';
      } elseif (preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/i', $params[2], $match)) {
        // value เป็น table.field
        $value = '`'.$match[1].'`.`'.$match[2].'`';
      } else {
        // value เป็น string
        $value = "'".$params[2]."'";
      }
      $params = $key.' '.$params[1].' '.$value;
    }
    return $params;
  }

  /**
   * ฟังก์ชั่นสร้างคำสั่ง WHERE
   *
   * @param mixed $condition
   * @param string $operator (optional) เช่น AND หรือ OR
   * @param string $id (optional )ชื่อฟิลด์ที่เป็น key
   * @return string|array คืนค่า string สำหรับคำสั่ง WHERE หรือคืนค่า array(where, values) สำหรับใช้กับการ bind
   */
  protected function buildWhere($condition, $operator = 'AND', $id = 'id')
  {
    if (is_array($condition)) {
      if (is_array($condition[0])) {
        $qs = array();
        $ps = array();
        foreach ($condition as $i => $item) {
          $ret = $this->whereValue($item, $i);
          if (is_array($ret)) {
            $qs[] = $ret[0];
            $ps = ArrayTool::replace($ps, $ret[1]);
          } else {
            $qs[] = $ret;
          }
        }
        $ret = implode(' '.$operator.' ', $qs);
        if (!empty($ps)) {
          $ret = array($ret, $ps);
        }
      } else {
        $ret = $this->whereValue($condition);
      }
    } elseif ($condition instanceof Sql) {
      $values = $condition->getValues();
      if (empty($values)) {
        $ret = $condition->text();
      } else {
        $ret = array($condition->text(), $values);
      }
    } elseif (preg_match('/^[0-9]+$/', $condition)) {
      // primaryKey
      $ret = $this->fieldName($id).' = '.$condition;
    } else {
      // พารามิเตอร์ ไม่ถูกต้อง
      throw new \InvalidArgumentException('Invalid arguments in buildWhere');
    }
    return $ret;
  }

  /**
   * ฟังก์ชั่นสร้างคำสั่ง WHERE และ values ไม่ใส่ alias ให้กับชื่อฟิลด์
   *
   * @param mixed $condition
   * @param string $operator (optional) เช่น AND หรือ OR
   * @param string $id (optional )ชื่อฟิลด์ที่เป็น key
   * @return array ($condition, $values)
   */
  protected function buildWhereValues($condition, $operator = 'AND', $id = 'id')
  {
    if (is_array($condition)) {
      $values = array();
      $qs = array();
      if (is_array($condition[0])) {
        foreach ($condition as $item) {
          $ret = $this->buildWhereValues($item, $operator, $id);
          $qs[] = $ret[0];
          $values = ArrayTool::replace($values, $ret[1]);
        }
        $condition = implode(' '.$operator.' ', $qs);
      } elseif (strpos($condition[0], '(') !== false) {
        $condition = $condition[0];
      } else {
        if (sizeof($condition) == 2) {
          $condition = array($condition[0], '=', $condition[1]);
        } else {
          $condition[1] = strtoupper(trim($condition[1]));
        }
        if (is_array($condition[2])) {
          $operator = $condition[1] == '=' ? 'IN' : $condition[1];
          $qs = array();
          foreach ($condition[2] as $k => $v) {
            $qs[] = ":$condition[0]$k";
            $values[":$condition[0]$k"] = $v;
          }
          $condition = $this->fieldName($condition[0]).' '.$operator.' ('.implode(',', $qs).')';
        } else {
          $values[":$condition[0]"] = $condition[2];
          $condition = $this->fieldName($condition[0]).' '.$condition[1].' :'.$condition[0];
        }
      }
    } elseif (is_numeric($condition)) {
      // primaryKey
      $values = array(":$id" => $condition);
      $condition = "`$id` = :$id";
    } else {
      $values = array();
    }
    return array($condition, $values);
  }

  /**
   * สร้างคำสั่ง WHERE
   *
   * @param array $params
   * @param int|null $i
   * @return array|string
   */
  private function whereValue($params, $i = null)
  {
    $result = array();
    if (is_array($params)) {
      if (sizeof($params) == 2) {
        $operator = '=';
        $value = $params[1];
      } else {
        $operator = trim($params[1]);
        $value = $params[2];
      }
      $key = $this->fieldName($params[0]);
      if ($value instanceof QueryBuilder) {
        $values = $value->getValues();
        if (empty($values)) {
          $result = $key.' '.$operator.' ('.$value->text().')';
        } else {
          $result = array($key.' '.$operator.' ('.$value->text().')', $values);
        }
      } elseif ($value instanceof Sql) {
        $values = $value->getValues();
        if (empty($values)) {
          $result = $key.' '.$operator.' '.$value->text();
        } else {
          $result = array($key.' '.$operator.' '.$value->text(), $values);
        }
      } elseif (is_array($value)) {
        if ($operator == '=') {
          $operator = 'IN';
        }
        $q = $this->aliasName($key);
        $qs = array();
        $vs = array();
        foreach ($value as $i => $item) {
          if (empty($item)) {
            $qs[] = is_string($item) ? "'$item'" : $item;
          } elseif (is_string($item)) {
            if (preg_match('/^([a-zA-Z0-9]{1,2})\.`?([a-zA-Z0-9_\-]+)`?$/', $item, $match)) {
              $qs[] = "$match[1].`$match[2]`";
            } elseif (preg_match('/^`([a-zA-Z0-9_\-]+)`$/', $item, $match)) {
              $qs[] = "`$match[1]`";
            } else {
              $qs[] = $q.$i;
              $vs[$q.$i] = $item;
            }
          } else {
            $qs[] = $q.$i;
            $vs[$q.$i] = $item;
          }
        }
        $result = array($key.' '.$operator.' ('.implode(', ', $qs).')', $vs);
      } elseif (empty($value)) {
        // value เป็น string ว่าง, 0, null
        $result = $key.' '.$operator.' '.(is_string($value) ? "'$value'" : $value);
      } elseif (preg_match('/^(\-?[0-9\s\.]+|true|false)$/i', $value)) {
        // value เป็น ตัวเลข จุดทศนิยม เครื่องหมาย - / , และ true, false
        // เช่น ตัวเลข, จำนวนเงิน, boolean
        $result = "$key $operator ".(is_string($value) ? "'$value'" : $value);
      } elseif (preg_match('/^[0-9\s\-:]+$/', $value)) {
        // วันที่
        $result = "$key $operator '$value'";
      } elseif (preg_match('/^([A-Z0-9]{1,2})\.([a-zA-Z0-9_\-]+)$/', $value, $match)) {
        // U.id
        if ($operator == 'IN' || $operator == 'NOT IN') {
          $result = "$key $operator ($match[1].`$match[2]`)";
        } else {
          $result = "$key $operator $match[1].`$match[2]`";
        }
      } elseif (preg_match('/^`([a-zA-Z0-9_\-]+)`$/', $value, $match)) {
        // `id`
        if ($operator == 'IN' || $operator == 'NOT IN') {
          $result = "$key $operator (`$match[1]`)";
        } else {
          $result = "$key $operator `$match[1]`";
        }
      } else {
        // value เป็น string
        $q = ':'.preg_replace('/[\.`]/', '', strtolower($key)).($i === null ? '' : $i);
        $result = array($key.' '.$operator.' '.$q, array($q => $value));
      }
    } else {
      $result = $params;
    }
    return $result;
  }
}