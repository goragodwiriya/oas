<?php
/**
 * @filesource Kotchasan/Database.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Database class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
final class Database extends KBase
{
  /**
   * database connection instances
   *
   * @var array
   */
  private static $instances = array();

  /**
   * Create Database Connection
   *
   * @param string|array $name ชื่อของการเชื่อมต่อกำหนดค่าใน config หรือ array ของค่ากำหนดของฐานข้อมูล
   * @return \static
   */
  public static function create($name = 'mysql')
  {
    $param = (object)array(
        'settings' => (object)array(
          'driver' => 'PdoMysql',
          'char_set' => 'utf8',
          'dbdriver' => 'mysql',
          'hostname' => 'localhost'
        ),
        'tables' => (object)array(
        )
    );
    // database config จาก config.php
    if (isset(self::$cfg->database[$name]) && is_array(self::$cfg->database[$name])) {
      foreach (self::$cfg->database[$name] as $key => $value) {
        $param->settings->$key = $value;
      }
    }
    if (is_string($name) && empty(self::$instances[$name])) {
      if (is_file(APP_PATH.'settings/database.php')) {
        $config = include APP_PATH.'settings/database.php';
      } elseif (is_file(ROOT_PATH.'settings/database.php')) {
        $config = include ROOT_PATH.'settings/database.php';
      }
      foreach ($config as $key => $values) {
        if ($key == $name) {
          foreach ($values as $k => $v) {
            $param->settings->$k = $v;
          }
        } elseif ($key == 'tables') {
          foreach ($values as $k => $v) {
            $param->tables->$k = $v;
          }
        }
      }
    } elseif (is_array($name)) {
      foreach ($name as $k => $v) {
        $param->settings->$k = $v;
      }
      $name = rand();
    }
    if (empty(self::$instances[$name])) {
      // โหลด driver (base)
      require_once VENDOR_DIR.'Database/Driver.php';
      // โหลด driver ตาม config ถ้าไม่พบ ใช้ PdoMysqlDriver
      if (is_file(VENDOR_DIR.'Database/'.$param->settings->driver.'Driver.php')) {
        $class = $param->settings->driver.'Driver';
      } else {
        // default driver
        $class = 'PdoMysqlDriver';
      }
      require_once VENDOR_DIR.'Database/'.$class.'.php';
      $class = 'Kotchasan\\Database\\'.$class;
      self::$instances[$name] = new $class;
      self::$instances[$name]->connect($param);
    }
    return self::$instances[$name];
  }
}
