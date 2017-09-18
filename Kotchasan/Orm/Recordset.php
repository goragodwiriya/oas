<?php
/**
 * @filesource Kotchasan/Orm/Recordset.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Orm;

use \Kotchasan\Database\Query;
use \Kotchasan\Database\Schema;
use \Kotchasan\Orm\Field;
use \Kotchasan\ArrayTool;

/**
 * Recordset base class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Recordset extends Query implements \Iterator
{
  /**
   * ข้อมูล
   *
   * @var array
   */
  private $datas;
  /**
   * รายการเริ่มต้นสำหรับการ query เพื่อแบ่งหน้า
   *
   * @var int
   */
  private $firstRecord;
  /**
   * คลาส Field
   *
   * @var Field
   */
  private $field;
  /**
   * จำนวนรายการต่อหน้า สำหรับใช้ในการแบ่งหน้า
   *
   * @var int
   */
  private $perPage;
  /**
   * กำหนดผลลัพท์ของ Recordset
   * true ผลลัพท์เป็น Array
   * false ผลลัพท์เป็น Model
   *
   * @var bool
   */
  private $toArray = false;
  /**
   * ถ้ามีข้อมูลในตัวแปรนี้ จะใช้การ prepare แทน exexute
   *
   * @var array
   */
  private $values;
  /**
   * รายชื่อฟิลด์
   *
   * @var array
   */
  private $fields = array();

  /**
   * create new Recordset
   *
   * @param string $field ชื่อของ Field
   */
  public function __construct($field)
  {
    $this->field = new $field;
    parent::__construct($this->field->getConn());
    $this->sqls = array();
    $this->values = array();
    $this->field->initTableName($this->db);
    if (method_exists($this->field, 'getConfig')) {
      foreach ($this->field->getConfig() as $key => $value) {
        $this->buildQuery($key, $value);
      }
    }
  }

  /**
   * create new Recordset
   *
   * @param string $filed ชื่อ Field
   * @return \static
   */
  public static function create($filed)
  {
    return new static($filed);
  }

  /**
   * query ข้อมูลทุกรายการ
   * SELECT ....
   *
   * @param array|string $fields (options) null หมายถึง SELECT ตามที่กำหนดโดย field
   * @return array|\static
   */
  public function all($fields = null)
  {
    if (!empty($fields)) {
      $qs = array();
      foreach (func_get_args() AS $item) {
        if (!empty($item)) {
          $qs[] = $this->fieldName($item);
        }
      }
      $this->sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
    } elseif (empty($this->sqls['select'])) {
      $this->sqls['select'] = '*';
    }
    return $this->doExecute(0, 0);
  }

  /**
   * build query string
   *
   * @return string
   */
  public function createQuery($start, $count)
  {
    $this->sqls['from'] = $this->field->getTableWithAlias();
    if (!empty($start) || !empty($count)) {
      $this->sqls['limit'] = $count;
      $this->sqls['start'] = $start;
    }
    return $this->db()->makeQuery($this->sqls);
  }

  /**
   * ฟังก์ชั่นประมวลผลคำสั่ง SQL สำหรับสอบถามข้อมูล คืนค่าผลลัพท์เป็นแอเรย์ของข้อมูลที่ตรงตามเงื่อนไข.
   *
   * @param string $sql query string
   * @param boolean $toArray (option) default true คืนค่าเป็น Array, false คืนค่าผลลัทเป็น Object
   * @param array $values ถ้าระบุตัวแปรนี้จะเป็นการบังคับใช้คำสั่ง prepare แทน query
   * @return array|object คืนค่าผลการทำงานเป็น record ของข้อมูลทั้งหมดที่ตรงตามเงื่อนไข
   */
  public function customQuery($sql, $toArray = true, $values = array())
  {
    return $this->db()->customQuery($sql, $toArray, $values);
  }

  /**
   * เปิดการใช้งานแคช
   * จะมีการตรวจสอบจากแคชก่อนการสอบถามข้อมูล
   *
   * @param boolean $auto_save (options) true (default) บันทึกผลลัพท์อัตโนมัติ, false ต้องบันทึกแคชเอง
   * @return \static
   */
  public function cacheOn($auto_save = true)
  {
    $this->cache->cacheOn($auto_save);
    return $this;
  }

  /**
   * สอบถามจำนวน record ใช้สำหรับการแบ่งหน้า
   *
   * @return int
   */
  public function count()
  {
    $old_sqls = $this->sqls;
    $old_values = $this->values;
    $this->sqls = array();
    $this->sqls['select'] = 'COUNT(*) AS `count`';
    foreach ($old_sqls as $key => $value) {
      if ($key !== 'order' && $key !== 'limit' && $key !== 'select') {
        $this->sqls[$key] = $value;
      }
    }
    $sql = $this->createQuery(0, 0);
    $result = $this->db()->customQuery($sql, true, $this->values);
    $count = empty($result) ? 0 : (int)$result[0]['count'];
    $this->sqls = $old_sqls;
    $this->values = $old_values;
    return $count;
  }

  /**
   * ลบ record กำหนดโดย $condition
   *
   * @param mixed $condition int (primaryKey), string (SQL QUERY), array
   * @param boolean $all false (default) ลบรายการเดียว, true ลบทุกรายการที่ตรงตามเงื่อนไข
   * @param string $oprator สำหรับเชื่อมแต่ละ $condition เข้าด้วยกัน AND (default), OR
   * @return boolean true ถ้าสำเร็จ
   */
  public function delete($condition = array(), $all = false, $oprator = 'AND')
  {
    $ret = $this->buildWhereValues($condition, $oprator, $this->field->getPrimarykey());
    $sqls = array(
      'delete' => $this->field->table_name,
      'where' => $ret[0]
    );
    if (!$all) {
      $sqls['limit'] = 1;
    }
    $sql = $this->db()->makeQuery($sqls);
    return $this->db()->query($sql, $ret[1]);
  }

  /**
   * query ข้อมูลที่มีการแบ่งหน้า
   * SELECT ....
   *
   * @param int $start
   * @param int $end
   * @return array|\static
   */
  private function doExecute($start, $end)
  {
    $sql = $this->createQuery($start, $end);
    $result = $this->db()->customQuery($sql, true, $this->values);
    if ($this->toArray) {
      return $result;
    } else {
      $class = get_class($this->field);
      $this->datas = array();
      foreach ($result as $item) {
        $this->datas[] = new $class($item);
      }
      return $this;
    }
  }

  /**
   * INNER JOIN table ON ....
   *
   * @param string $field field class ของตารางที่ join
   * @param string $type เช่น LEFT, RIGHT, INNER...
   * @param mixed $on where condition สำหรับการ join
   * @return \static
   */
  private function doJoin($field, $type, $on)
  {
    if (preg_match('/^([a-zA-Z0-9\\\\]+)(\s+(as|AS))?[\s]+([A-Z0-9]{1,2})?$/', $field, $match)) {
      $field = $match[1];
    }
    $rs = new Recordset($field);
    $table = $rs->field->getTableWithAlias(isset($match[4]) ? $match[4] : null);
    $ret = $rs->buildJoin($table, $type, $on);
    if (is_array($ret)) {
      $this->sqls['join'][] = $ret[0];
      $this->values = ArrayTool::replace($this->values, $ret[1]);
    } else {
      $this->sqls['join'][] = $ret;
    }
    return $this;
  }

  /**
   * query ข้อมูลที่มีการแบ่งหน้า
   * SELECT ....
   *
   * @param array|string $fields (options) null หมายถึง SELECT ตามที่กำหนดโดย field
   * @return array|\static
   */
  public function execute($fields = null)
  {
    if (!empty($fields)) {
      $qs = array();
      foreach (func_get_args() AS $item) {
        if (!empty($item)) {
          $qs[] = $this->fieldName($item);
        }
      }
      $this->sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
    } elseif (empty($this->sqls['select'])) {
      $this->sqls['select'] = '*';
    }
    return $this->doExecute($this->firstRecord, $this->perPage);
  }

  /**
   * สอบถามข้อมูลที่ $primaryKey คืนค่าข้อมูลรายการเดียว
   *
   * @param int $id รายการที่ค้นหา
   * @return Field
   */
  public function find($id)
  {
    return $this->where((int)$id)->first();
  }

  /**
   * Query ข้อมูลรายการเดียว
   * SELECT .... LIMIT 1
   *
   * @param array|string $fields (options) null หมายถึง SELECT ตามที่กำหนดโดย field
   * @return bool|array|Field ไม่พบคืนค่า false พบคืนค่า record ของข้อมูลรายการเดียว
   */
  public function first($fields = null)
  {
    $sqls = array(
      'from' => $this->field->getTableWithAlias(),
      'limit' => 1
    );
    if (!empty($fields)) {
      $qs = array();
      foreach (func_get_args() AS $item) {
        if (!empty($item)) {
          $qs[] = $this->fieldName($item);
        }
      }
      $sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
    } elseif (empty($this->sqls['select'])) {
      $sqls['select'] = '*';
    }
    $sqls = ArrayTool::replace($this->sqls, $sqls);
    $sql = $this->db()->makeQuery($sqls);
    $this->datas = $this->db()->customQuery($sql, true, $this->values);
    if (empty($this->datas)) {
      return false;
    } elseif ($this->toArray) {
      return $this->datas[0];
    } else {
      $class = get_class($this->field);
      return new $class($this->datas[0]);
    }
  }

  /**
   * คืนค่าอ๊อปเจ็ค Field ของ Recordset
   *
   * @return Field
   */
  public function getField()
  {
    return $this->field;
  }

  /**
   * รายชื่อฟิลด์ทั้งหมดของ Model
   *
   * @return array
   */
  public function getFields()
  {
    if (empty($this->datas)) {
      $this->first();
    }
    return $this->db()->getFields();
  }

  /**
   * ฟังก์ชั่นตรวจสอบว่ามีฟิลด์ หรือไม่.
   *
   * @param string $field ชื่อฟิลด์
   * @return boolean คืนค่า true หากมีฟิลด์นี้อยู่ ไม่พบคืนค่า false
   */
  public function fieldExists($field)
  {
    if (empty($this->fields)) {
      $this->fields = Schema::create($this->db())->fields($this->field->table_name);
    }
    return in_array($field, $this->fields);
  }

  /**
   * ฟังก์ชั่นสำหรับจัดกลุ่มคำสั่ง และ เชื่อมแต่ละกลุ่มด้วย $oprator
   *
   * @param array $params คำสั่ง รูปแบบ array('field1', 'condition', 'field2')
   * @param string $oprator AND หรือ OR
   * @return string query ภายใต้ ()
   */
  public function group($params, $oprator = 'AND')
  {
    switch (strtoupper($oprator)) {
      case 'AND':
        return $this->groupAnd($params);
        break;
      case 'OR':
        return $this->groupOr($params);
        break;
    };
  }

  /**
   * insert ข้อมูล
   *
   * @param Field $field
   * @return int|bool สำเร็จ คืนค่า id ที่เพิ่ม ผิดพลาด คืนค่า false
   */
  public function insert(Field $field)
  {
    $save = array();
    foreach (Schema::create($this->db())->fields($this->field->table_name) as $item) {
      if (isset($field->$item)) {
        $save[$item] = $field->$item;
      }
    }
    if (empty($save)) {
      $result = false;
    } else {
      $result = $this->db()->insert($this->field->table_name, $save);
    }
    return $result;
  }

  /**
   * INNER JOIN table ON ....
   *
   * @param string $field field class ของตารางที่ join
   * @param string $type เช่น LEFT, RIGHT, INNER...
   * @param mixed $on where condition สำหรับการ join
   * @return \static
   */
  public function join($field, $type, $on)
  {
    return $this->doJoin($field, $type, $on);
  }

  /**
   * สร้าง query เรียงลำดับ
   *
   * @param mixed $sort array('field ASC','field DESC') หรือ 'field ASC', 'field DESC', ....
   * @return \static
   */
  public function order($sorts)
  {
    $sorts = is_array($sorts) ? $sorts : func_get_args();
    $ret = $this->buildOrder($sorts);
    if (!empty($ret)) {
      $this->sqls['order'] = $ret;
    }
    return $this;
  }

  /**
   * ฟังก์ชั่นประมวลผลคำสั่ง SQL ที่ไม่ต้องการผลลัพท์ เช่น CREATE INSERT UPDATE.
   *
   * @param string $sql
   * @param array $values ถ้าระบุตัวแปรนี้จะเป็นการบังคับใช้คำสั่ง prepare แทน query
   * @return boolean สำเร็จคืนค่า true ไม่สำเร็จคืนค่า false
   */
  public function query($sql, $values = array())
  {
    $this->db()->query($sql, $values);
  }

  /**
   * สร้าง query จาก config
   *
   * @param string $method
   * @param mixed $param
   */
  private function buildQuery($method, $param)
  {
    if ($method == 'join') {
      foreach ($param as $item) {
        $this->doJoin($item[1], $item[0], $item[2]);
      }
    } else {
      $func = 'build'.ucfirst($method);
      if (method_exists($this, $func)) {
        $ret = $this->$func($param);
        if (is_array($ret)) {
          $this->sqls[$method] = $ret[0];
          $this->values = ArrayTool::replace($this->values, $ret[1]);
        } else {
          $this->sqls[$method] = $ret;
        }
      }
    }
  }

  /**
   * จำกัดจำนวนผลลัพท์
   * LIMIT $start, $count
   *
   * @param int $start ข้อมูลเริ่มต้น
   * @param int $count จำนวนผลลัพธ์ที่ต้องการ
   * @return \static
   */
  public function take()
  {
    $count = func_num_args();
    if ($count == 1) {
      $this->perPage = (int)func_get_arg(0);
      $this->firstRecord = 0;
    } elseif ($count == 2) {
      $this->perPage = (int)func_get_arg(1);
      $this->firstRecord = (int)func_get_arg(0);
    }
    return $this;
  }

  /**
   * คืนค่าข้อมูลเป็น Array
   * ฟังก์ชั่นนี้ใช้เรียกก่อนการสอบถามข้อมูล
   *
   * @return \static
   */
  public function toArray()
  {
    $this->toArray = true;
    return $this;
  }

  /**
   * ฟังก์ชั่นลบข้อมูลทั้งหมดในตาราง
   *
   * @return boolean คืนค่า true ถ้าสำเร็จ
   */
  public function emptyTable()
  {
    return $this->db()->emptyTable($this->field->table_name);
  }

  /**
   * อัปเดทข้อมูล
   *
   * @param array $condition
   * @param array|Field $save
   * @return boolean สำเร็จ คืนค่า true, ผิดพลาด คืนค่า false
   */
  public function update($condition, $save)
  {
    $db = $this->db();
    $schema = Schema::create($db);
    $datas = array();
    if ($save instanceof Field) {
      foreach ($schema->fields($this->field->table_name) as $field) {
        if (isset($save->$field)) {
          $datas[$field] = $save->$field;
        }
      }
    } else {
      foreach ($schema->fields($this->field->table_name) as $field) {
        if (isset($save[$field])) {
          $datas[$field] = $save[$field];
        }
      }
    }
    if (empty($datas)) {
      $result = false;
    } else {
      $result = $db->update($this->field->table_name, $condition, $datas);
      if ($db->cacheGetAction() == 1) {
        $db->cacheSave($datas);
      }
    }
    return $result;
  }

  /**
   * อัปเดทข้อมูลทุก record
   *
   * @param array $save ข้อมูลที่ต้องการบันทึก
   * array('key1'=>'value1', 'key2'=>'value2', ...)
   * @return boolean สำเร็จ คืนค่า true, ผิดพลาด คืนค่า false
   */
  public function updateAll($save)
  {
    return $this->db()->updateAll($this->field->table_name, $save);
  }

  /**
   * WHERE ....
   * int ค้นหาจาก primaryKey เช่น id=1 หมายถึง WHERE `id`=1
   * string เช่น QUERY ต่างๆ `email`='xxx.com' หมายถึง WHERE `email`='xxx.com'
   * array เช่น ('id', 1) หมายถึง WHERE `id`=1
   * array เช่น ('email', '!=', 'xxx.com') หมายถึง WHERE `email`!='xxx.com'
   * ถ้าเป็น array สามารถรุบได้หลายค่าโดยแต่ละค่าจะเชื่อมด้วย $oprator
   *
   * @param mixed $where
   * @param string $oprator (options) AND (default), OR
   * @return \static
   */
  public function where($where = array(), $oprator = 'AND')
  {
    if (is_int($where) || (is_string($where) && $where != '') || (is_array($where) && !empty($where))) {
      $where = $this->buildWhere($where, $oprator, $this->field->table_alias.'.'.$this->field->getPrimarykey());
      if (is_array($where)) {
        $this->values = ArrayTool::replace($this->values, $where[1]);
        $where = $where[0];
      }
      $this->sqls['where'] = $where;
    }
    return $this;
  }

  /**
   * จำกัดผลลัพท์ และกำหนดรายการเริ่มต้น
   *
   * @param int $count จำนวนผลลัท์ที่ต้องการ
   * @param int $start รายการเริ่มต้น
   * @return \static
   */
  public function limit($count, $start = 0)
  {
    if (!empty($start)) {
      $this->sqls['start'] = (int)$start;
    }
    $this->sqls['limit'] = (int)$count;
    return $this;
  }

  /**
   * คืนค่า value สำหรับการ execute
   *
   * @return array
   */
  public function getValues()
  {
    return $this->values;
  }

  /**
   * ส่งออกฐานข้อมูลเป็น QueryBuilder
   *
   * @return \Kotchasan\Database\QueryBuilder
   */
  public function toQueryBuilder()
  {
    return $this->db()->createQuery()->assignment($this);
  }

  /**
   * สอบถามจำนวน record ทั้งหมดที่ query แล้ว
   *
   * @return int
   */
  public function recordCount()
  {
    return sizeof($this->datas);
  }

  /**
   * inherited from Iterator
   */
  public function rewind()
  {
    reset($this->datas);
  }

  public function current()
  {
    $var = current($this->datas);
    return $var;
  }

  public function key()
  {
    $var = key($this->datas);
    return $var;
  }

  public function next()
  {
    $var = next($this->datas);
    return $var;
  }

  public function valid()
  {
    $key = key($this->datas);
    $var = ($key !== NULL && $key !== FALSE);
    return $var;
  }
}
