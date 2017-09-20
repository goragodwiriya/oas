<?php
/**
 * @filesource Kotchasan/Database/Schema.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Database;

use \Kotchasan\Database\Driver;
use \Kotchasan\Database\Exception;

/**
 * Database schema
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Schema
{
  /**
   * รายการ Schema ที่โหลดแล้ว
   *
   * @var array
   */
  private $tables = array();
  /**
   * Database object
   *
   * @var Driver
   */
  private $db;

  /**
   * Create Schema Class
   *
   * @param Driver $db

   * @return \static
   */
  public static function create(Driver $db)
  {
    $obj = new static;
    $obj->db = $db;
    return $obj;
  }

  /**
   * อ่านข้อมูล Schema จากตาราง
   *
   * @param string $table
   */
  private function init($table)
  {
    if (empty($this->tables[$table])) {
      $sql = "SHOW FULL COLUMNS FROM $table";
      $columns = $this->db->cacheOn()->customQuery($sql, true);
      if (empty($columns)) {
        throw new \Exception($this->db->getError());
      } else {
        $datas = array();
        foreach ($columns as $column) {
          $datas[$column['Field']] = $column;
        }
        $this->tables[$table] = $datas;
      }
    }
  }

  /**
   * อ่านรายชื่อฟิลด์ของตาราง
   *
   * @return array รายชื่อฟิลด์ทั้งหมดในตาราง
   */
  public function fields($table)
  {
    if (empty($table)) {
      throw new \InvalidArgumentException('table name empty in fields');
    } else {
      $this->init($table);
      return array_keys($this->tables[$table]);
    }
  }
}
