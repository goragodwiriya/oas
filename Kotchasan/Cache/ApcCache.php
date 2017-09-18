<?php
/**
 * @filesource  Kotchasan/Cache/ApcCache.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Cache;

use \Psr\Cache\CacheItemInterface;
use \Kotchasan\Cache\CacheItem as Item;
use \Kotchasan\Cache\Cache;
use \Kotchasan\Cache\Exception;

/**
 * APC cache driver
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class ApcCache extends Cache
{

  /**
   * Class constructor
   */
  public function __construct()
  {
    if (!extension_loaded('apc') || !is_callable('apc_fetch')) {
      throw new Exception('APC not supported.');
    }
  }

  /**
   * อ่านแคชหลายรายการ
   *
   * @param array $keys
   * @return array
   */
  public function getItems(array $keys = array())
  {
    $resuts = array();
    $success = false;
    $values = apc_fetch($keys, $success);
    if ($success && is_array($values)) {
      foreach ($values as $key => $value) {
        $item = new Item($key);
        $resuts[$key] = $item->set($value);
      }
    }
    return $resuts;
  }

  /**
   * ตรวจสอบแคช
   *
   * @param string $key
   * @return boolean true ถ้ามี
   */
  public function hasItem($key)
  {
    return apc_exists($key);
  }

  /**
   * เคลียร์แคช
   *
   * @return boolean true ถ้าลบเรียบร้อย, หรือ false ถ้าไม่สำเร็จ
   */
  public function clear()
  {
    return apc_clear_cache('user');
  }

  /**
   * ลบแคชหลายๆรายการ
   *
   * @param array $keys
   * @return boolean true ถ้าสำเร็จ, false ถ้าไม่สำเร็จ
   */
  public function deleteItems(array $keys)
  {
    if ($this->cache_dir) {
      foreach ($keys as $key) {
        apc_delete($key);
      }
    }
    return true;
  }

  /**
   * บันทึกแคช
   *
   * @param CacheItemInterface $item
   * @return boolean สำเร็จคืนค่า true ไม่สำเร็จคืนค่า false
   * @throws CacheException
   */
  public function save(CacheItemInterface $item)
  {
    return apc_store($item->getKey(), $item->get(), self::$cfg->get('cache_expire', 5));
  }
}
