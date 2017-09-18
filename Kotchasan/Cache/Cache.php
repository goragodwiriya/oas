<?php
/**
 * @filesource  Kotchasan/Cache/Cache.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Cache;

use \Psr\Cache\CacheItemPoolInterface;
use \Psr\Cache\CacheItemInterface;
use \Kotchasan\Cache\CacheItem as Item;

/**
 * Kotchasan Caching Class (base class)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
abstract class Cache extends \Kotchasan\KBase implements CacheItemPoolInterface
{
  /**
   * รายการแคชรอบันทึก
   *
   * @var array
   */
  protected $deferred = array();

  /**
   * อ่านแคช
   *
   * @param string $key
   * @return CacheItemInterface
   */
  public function getItem($key)
  {
    $items = $this->getItems(array($key));
    return isset($items[$key]) ? $items[$key] : new Item($key);
  }

  /**
   * ลบแคช
   *
   * @param string $key
   * @return boolean true ถ้าสำเร็จ, false ถ้าไม่สำเร็จ
   */
  public function deleteItem($key)
  {
    return $this->deleteItems(array($key));
  }

  /**
   * กำหนดรายการแคชสำหรับบันทึกในภายหลัง
   *
   * @param CacheItemInterface $item
   * @return boolean false ถ้าไม่มีรายการในคิว
   */
  public function saveDeferred(CacheItemInterface $item)
  {
    $this->deferred[$item->getKey()] = $item;
    return true;
  }

  /**
   * บันทึกรายการแคชในคิว
   *
   * @return boolean ถ้ามีบางรายการไม่สำเร็จคืนค่า false
   */
  public function commit()
  {
    $cuccess = true;
    foreach ($this->deferred as $item) {
      if (!$this->save($item)) {
        $cuccess = false;
      }
    }
    return $cuccess;
  }
}
