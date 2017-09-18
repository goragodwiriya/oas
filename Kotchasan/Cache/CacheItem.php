<?php
/**
 * @filesource  Kotchasan/Cache/CacheItem.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Cache;

use \Psr\Cache\CacheItemInterface;

/**
 * Cache Item
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class CacheItem implements CacheItemInterface
{
  /**
   * Cache Key
   *
   * @var string
   */
  private $key;
  /**
   * Cache value
   *
   * @var mixed
   */
  private $value;
  /**
   *
   *
   * @var bool
   */
  private $hit;

  /**
   * Class constructor
   *
   * @param string $key Cache Key
   */
  public function __construct($key)
  {
    $this->key = $key;
    $this->value = null;
    $this->hit = false;
  }

  /**
   * อ่านค่าคีย์ของแคช
   *
   * @return string
   */
  public function getKey()
  {
    return $this->key;
  }

  /**
   * อ่านค่าของแคช
   *
   * @return mixed
   */
  public function get()
  {
    return $this->value;
  }

  /**
   * ฟังก์ชั่นตรวจสอบว่ามีการกำหนดข้อมูลลงในแคชหรือไม่
   *
   * @return boolean true ถ้ามีการใส่ value ในแคชแล้ว
   */
  public function isHit()
  {
    return $this->hit;
  }

  /**
   * กำหนดค่า
   *
   * @param mixed $value
   * @return \static
   */
  public function set($value)
  {
    $this->value = $value;
    $this->hit = true;
    return $this;
  }

  /**
   * กำหนดวันที่และเวลาหมดอายุของแคช
   *
   * @param \DateTimeInterface $expiration
   * @return \static
   */
  public function expiresAt($expiration)
  {

  }

  /**
   * กำหนดอายุของแคช (วินาที)
   *
   * @param int|\DateInterval $time
   * @return \static
   */
  public function expiresAfter($time)
  {

  }
}
