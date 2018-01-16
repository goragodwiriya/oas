<?php
/**
 * @filesource Kotchasan/Database/PdoMysqlDriver.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Database;

use \Kotchasan\Database\Driver;
use \PDO;
use \Kotchasan\Database\QueryBuilder;
use \Kotchasan\Database\Sql;

/**
 * PDO MySQL Database Adapter Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class PdoMysqlDriver extends Driver
{

  /**
   * เชื่อมต่อ database
   *
   * @param array $param
   * @return \static
   */
  public function connect($param)
  {
    $this->options = array(
      \PDO::ATTR_STRINGIFY_FETCHES => 0,
      \PDO::ATTR_EMULATE_PREPARES => 0,
      \PDO::ATTR_PERSISTENT => 1,
      \PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    );
    foreach ($param as $key => $value) {
      $this->$key = $value;
    }
    if ($this->settings->dbdriver == 'mysql') {
      $this->options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES '.$this->settings->char_set;
      $this->options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = 1;
    }
    $sql = $this->settings->dbdriver.':host='.$this->settings->hostname;
    $sql .= empty($this->settings->port) ? '' : ';port='.$this->settings->port;
    $sql .= empty($this->settings->dbname) ? '' : ';dbname='.$this->settings->dbname;
    if (isset($this->settings->username) && isset($this->settings->password)) {
      try {
        $this->connection = new \PDO($sql, $this->settings->username, $this->settings->password, $this->options);
      } catch (\PDOException $e) {
        throw new \Exception($e->getMessage(), 500, $e);
      }
    } else {
      throw new \InvalidArgumentException('Database configuration is invalid');
    }
    return $this;
  }

  /**
   * ประมวลผลคำสั่ง SQL สำหรับสอบถามข้อมูล คืนค่าผลลัพท์เป็นแอเรย์ของข้อมูลที่ตรงตามเงื่อนไข.
   *
   * @param string $sql query string
   * @param array $values ถ้าระบุตัวแปรนี้จะเป็นการบังคับใช้คำสั่ง prepare แทน query
   * @return array|bool คืนค่าผลการทำงานเป็น record ของข้อมูลทั้งหมดที่ตรงตามเงื่อนไข หรือคืนค่า false หามีข้อผิดพลาด
   */
  protected function doCustomQuery($sql, $values = array())
  {
    $action = $this->cache->getAction();
    if ($action) {
      $cache = $this->cache->init($sql, $values);
      $result = $this->cache->get($cache);
    } else {
      $result = false;
    }
    if (!$result) {
      try {
        if (empty($values)) {
          $this->result_id = $this->connection->query($sql);
        } else {
          $this->result_id = $this->connection->prepare($sql);
          $this->result_id->execute($values);
        }
        self::$query_count++;
        $result = $this->result_id->fetchAll(\PDO::FETCH_ASSOC);
        if ($action == 1) {
          $this->cache->save($cache, $result);
        } elseif ($action == 2) {
          $this->cache_item = $cache;
        }
      } catch (\PDOException $e) {
        throw new \Exception($e->getMessage(), 500, $e);
      }
      $this->log('Database', $sql, $values);
    } else {
      $this->cache->setAction(0);
      $this->cache_item = null;
      $this->log('Cached', $sql, $values);
    }
    return $result;
  }

  /**
   * ประมวลผลคำสั่ง SQL ที่ไม่ต้องการผลลัพท์ เช่น CREATE INSERT UPDATE.
   *
   * @param string $sql
   * @param array $values ถ้าระบุตัวแปรนี้จะเป็นการบังคับใช้คำสั่ง prepare แทน query
   * @return int|bool สำเร็จคืนค่าจำนวนแถวที่มีผล ไม่สำเร็จคืนค่า false
   */
  protected function doQuery($sql, $values = array())
  {
    try {
      if (empty($values)) {
        $query = $this->connection->query($sql);
      } else {
        $query = $this->connection->prepare($sql);
        $query->execute($values);
      }
      self::$query_count++;
      $this->log(__FUNCTION__, $sql, $values);
      return $query->rowCount();
    } catch (\PDOException $e) {
      throw new \Exception($e->getMessage(), 500, $e);
    }
  }

  /**
   * จำนวนฟิลด์ทั้งหมดในผลลัพท์จากการ query
   *
   * @param resource $res ผลลัพท์จากการ query
   */
  public function fieldCount()
  {
    if (isset($this->result_id)) {
      return $this->result_id->columnCount();
    } else {
      return 0;
    }
  }

  /**
   * รายชื่อฟิลด์ทั้งหมดจากผลัพท์จองการ query
   *
   * @return array
   */
  public function getFields()
  {
    $filed_list = array();
    for ($i = 0, $c = $this->fieldCount(); $i < $c; $i++) {
      $result = @$this->result_id->getColumnMeta($i);
      if ($result) {
        $filed_list[$result['name']] = $result;
      }
    }
    return $filed_list;
  }

  /**
   * ฟังก์ชั่นสร้างคำสั่ง SQL สำหรับการ INSERT ข้อมูล
   *
   * @param string $table_name ชื่อตาราง
   * @param array|object $save ข้อมูลที่ต้องการบันทึก รูปแบบ array('key1'=>'value1', 'key2'=>'value2', ...)
   * @param array $params ตัวแปร Array สำหรับรับค่า params ส่งให้ execute
   * @return string
   */
  private function makeInsert($table_name, $save, &$params)
  {
    $keys = array();
    $values = array();
    foreach ($save as $key => $value) {
      if ($value instanceof QueryBuilder) {
        $keys[] = $key;
        $values[] = '('.$value->text().')';
      } elseif ($value instanceof Sql) {
        $keys[] = $key;
        $values[] = $value->text();
        $params = $value->getValues($params);
      } else {
        $keys[] = $key;
        $values[] = ':'.$key;
        $params[':'.$key] = $value;
      }
    }
    return 'INSERT INTO '.$table_name.' (`'.implode('`,`', $keys).'`) VALUES ('.implode(',', $values).')';
  }

  /**
   * ฟังก์ชั่นเพิ่มข้อมูลใหม่ลงในตาราง
   *
   * @param string $table_name ชื่อตาราง
   * @param array|object $save ข้อมูลที่ต้องการบันทึก รูปแบบ array('key1'=>'value1', 'key2'=>'value2', ...)
   * @return int|null สำเร็จ คืนค่า id ที่เพิ่ม ผิดพลาด คืนค่า null
   */
  public function insert($table_name, $save)
  {
    $params = array();
    $sql = $this->makeInsert($table_name, $save, $params);
    try {
      $query = $this->connection->prepare($sql);
      $query->execute($params);
      $this->log('insert', $sql, $params);
      self::$query_count++;
      return (int)$this->connection->lastInsertId();
    } catch (\PDOException $e) {
      throw new \Exception($e->getMessage(), 500, $e);
    }
  }

  /**
   * ฟังก์ชั่นเพิ่มข้อมูลใหม่ลงในตาราง
   * ถ้ามีข้อมูลเดิมอยู่แล้วจะเป็นการอัปเดท
   * (ข้อมูลเดิมตาม KEY ที่เป็น UNIQUE)
   *
   * @param string $table_name ชื่อตาราง
   * @param array|object $save ข้อมูลที่ต้องการบันทึก รูปแบบ array('key1'=>'value1', 'key2'=>'value2', ...)
   * @return int|null insert คืนค่า id ที่เพิ่ม, update คืนค่า 0, ผิดพลาด คืนค่า null
   */
  public function insertOrUpdate($table_name, $save)
  {
    $updates = array();
    $params = array();
    foreach ($save as $key => $value) {
      $updates[] = '`'.$key.'`=:U'.$key;
      $params[':U'.$key] = $value;
    }
    $sql = $this->makeInsert($table_name, $save, $params);
    $sql .= ' ON DUPLICATE KEY UPDATE '.implode(', ', $updates);
    try {
      $query = $this->connection->prepare($sql);
      $query->execute($params);
      $this->log(__FUNCTION__, $sql);
      self::$query_count++;
      return (int)$this->connection->lastInsertId();
    } catch (\PDOException $e) {
      throw new \Exception($e->getMessage(), 500, $e);
    }
  }

  /**
   * ฟังก์ชั่นสร้างคำสั่ง sql query
   *
   * @param array $sqls คำสั่ง sql จาก query builder
   * @return string sql command
   *
   * @assert (array('update' => '`user`', 'where' => '`id` = 1', 'set' => array('`id` = 1', "`email` = 'admin@localhost'"))) [==] "UPDATE `user` SET `id` = 1, `email` = 'admin@localhost' WHERE `id` = 1"
   * @assert (array('insert' => '`user`', 'keys' => array('id' => ':id', 'email' => ':email'))) [==] "INSERT INTO `user` (`id`, `email`) VALUES (:id, :email)"
   * @assert (array('insert' => '`user`', 'keys' => array('id' => ':id'), 'orupdate' => array('`id`=VALUES(`id`)'))) [==] "INSERT INTO `user` (`id`) VALUES (:id) ON DUPLICATE KEY UPDATE `id`=VALUES(`id`)"
   * @assert (array('select'=>'*', 'from'=>'`user`','where'=>'`id` = 1', 'order' => '`id`', 'start' => 1, 'limit' => 10, 'join' => array(" INNER JOIN ..."))) [==] "SELECT * FROM `user` INNER JOIN ... WHERE `id` = 1 ORDER BY `id` LIMIT 1,10"
   * @assert (array('select'=>'*', 'from'=>'`user`','where'=>'`id` = 1', 'order' => '`id`', 'start' => 1, 'limit' => 10, 'group' => '`id`')) [==] "SELECT * FROM `user` WHERE `id` = 1 GROUP BY `id` ORDER BY `id` LIMIT 1,10"
   * @assert (array('delete' => '`user`', 'where' => '`id` = 1')) [==] "DELETE FROM `user` WHERE `id` = 1"
   */
  public function makeQuery($sqls)
  {
    $sql = '';
    if (isset($sqls['insert'])) {
      $keys = array_keys($sqls['keys']);
      $sql = 'INSERT INTO '.$sqls['insert'].' (`'.implode('`, `', $keys);
      $sql .= "`) VALUES (".implode(", ", $sqls['keys']).")";
      if (isset($sqls['orupdate'])) {
        $sql .= ' ON DUPLICATE KEY UPDATE '.implode(', ', $sqls['orupdate']);
      }
    } elseif (isset($sqls['union'])) {
      $sql = '('.implode(') UNION (', $sqls['union']).')';
    } elseif (isset($sqls['unionAll'])) {
      $sql = '('.implode(') UNION ALL (', $sqls['unionAll']).')';
    } else {
      if (isset($sqls['select'])) {
        $sql = 'SELECT '.$sqls['select'];
        if (isset($sqls['from'])) {
          $sql .= ' FROM '.$sqls['from'];
        }
      }
      if (isset($sqls['update'])) {
        $sql = 'UPDATE '.$sqls['update'];
      } elseif (isset($sqls['delete'])) {
        $sql = 'DELETE FROM '.$sqls['delete'];
      }
      if (isset($sqls['set'])) {
        $sql .= ' SET '.implode(', ', $sqls['set']);
      }
      if (isset($sqls['join'])) {
        foreach ($sqls['join'] AS $join) {
          $sql .= $join;
        }
      }
      if (isset($sqls['where'])) {
        $sql .= ' WHERE '.$sqls['where'];
      }
      if (isset($sqls['group'])) {
        $sql .= ' GROUP BY '.$sqls['group'];
      }
      if (isset($sqls['having'])) {
        $sql .= ' HAVING '.$sqls['having'];
      }
      if (isset($sqls['order'])) {
        $sql .= ' ORDER BY '.$sqls['order'];
      }
      if (isset($sqls['limit'])) {
        $sql .= ' LIMIT '.(empty($sqls['start']) ? '' : $sqls['start'].',').$sqls['limit'];
      }
    }
    return $sql;
  }

  /**
   * เรียกดูข้อมูล
   *
   * @param string $table_name ชื่อตาราง
   * @param mixed $condition query WHERE
   * @param array $sort เรียงลำดับ
   * @param int $limit จำนวนข้อมูลที่ต้องการ
   * @return array ผลลัพท์ในรูป array ถ้าไม่สำเร็จ คืนค่าแอเรย์ว่าง
   */
  public function select($table_name, $condition, $sort = array(), $limit = 0)
  {
    $values = array();
    $condition = $this->buildWhere($condition);
    if (is_array($condition)) {
      $values = $condition[1];
      $condition = $condition[0];
    }
    $sql = 'SELECT * FROM '.$table_name.' WHERE '.$condition;
    if (!empty($sort)) {
      $qs = array();
      foreach ($sort as $item) {
        if (preg_match('/^([a-z0-9_]+)\s(asc|desc)$/i', trim($item), $match)) {
          $qs[] = '`'.$match[1].'`'.(empty($match[2]) ? '' : ' '.$match[2]);
        }
      }
      if (sizeof($qs) > 0) {
        $sql .= ' ORDER BY '.implode(', ', $qs);
      }
    }
    if (is_int($limit) && $limit > 0) {
      $sql .= ' LIMIT '.$limit;
    }
    return $this->doCustomQuery($sql, $values);
  }

  /**
   * เลือกฐานข้อมูล.
   *
   * @param string $database
   * @return boolean false หากไม่สำเร็จ
   */
  public function selectDB($database)
  {
    $this->settings->dbname = $database;
    $result = $this->connection->query("USE $database");
    self::$query_count++;
    return $result === false ? false : true;
  }

  /**
   * ฟังก์ชั่นแก้ไขข้อมูล
   *
   * @param string $table_name ชื่อตาราง
   * @param mixed $condition query WHERE
   * @param array|object $save ข้อมูลที่ต้องการบันทึก รูปแบบ array('key1'=>'value1', 'key2'=>'value2', ...)
   * @return boolean สำเร็จ คืนค่า true, ผิดพลาด คืนค่า false
   */
  public function update($table_name, $condition, $save)
  {
    $sets = array();
    $values = array();
    foreach ($save as $key => $value) {
      if ($value instanceof QueryBuilder) {
        $sets[] = '`'.$key.'` = ('.$value->text().')';
      } elseif ($value instanceof Sql) {
        $sets[] = '`'.$key.'` = '.$value->text();
        $values = $value->getValues($values);
      } else {
        $k = ':'.$key.sizeof($values);
        $sets[] = '`'.$key.'` = '.$k;
        $values[$k] = $value;
      }
    }
    $q = Sql::WHERE($condition);
    $sql = 'UPDATE '.$table_name.' SET '.implode(', ', $sets).' WHERE '.$q->text();
    $values = $q->getValues($values);
    try {
      $query = $this->connection->prepare($sql);
      $query->execute($values);
      $this->log(__FUNCTION__, $sql, $values);
      self::$query_count++;
      return true;
    } catch (\PDOException $e) {
      throw new \Exception($e->getMessage(), 500, $e);
    }
  }

  /**
   * close database
   */
  public function close()
  {
    $this->connection = null;
  }
}