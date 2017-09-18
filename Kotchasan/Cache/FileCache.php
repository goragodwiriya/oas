<?php
/**
 * @filesource Kotchasan/Cache/FileCache.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Cache;

use \Psr\Cache\CacheItemInterface;
use \Kotchasan\Cache\CacheItem as Item;
use \Kotchasan\Cache\Cache;
use \Kotchasan\Cache\Exception;
use \Kotchasan\File;

/**
 * Filesystem cache driver
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class FileCache extends Cache
{
  /**
   * ไดเร็คทอรี่แคช
   *
   * @var string /root/to/dir/cache/
   */
  private $cache_dir = null;
  /**
   * อายุของแคช (วินาที) 0 หมายถึงไม่มีการแคช
   *
   * @var int
   */
  private $cache_expire = 0;

  /**
   * class constructor
   *
   * @throws Exception
   */
  public function __construct()
  {
    $this->cache_expire = self::$cfg->get('cache_expire', 0);
    if (!empty($this->cache_expire)) {
      //  folder cache
      $dir = ROOT_PATH.DATA_FOLDER.'cache/';
      if (!File::makeDirectory($dir)) {
        throw new Exception('Folder '.DATA_FOLDER.'cache/ cannot be created.');
      }
      $this->cache_dir = $dir;
      // clear old cache every day
      $d = is_file($dir.'index.php') ? file_get_contents($dir.'index.php') : 0;
      if ($d != date('d')) {
        $this->clear();
        $f = @fopen($dir.'index.php', 'wb');
        if ($f === false) {
          throw new Exception('File '.DATA_FOLDER.'cache/index.php cannot be written.');
        } else {
          fwrite($f, date('d'));
          fclose($f);
        }
      }
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
    foreach ($keys as $key) {
      $file = $this->fetchStreamUri($key);
      if ($this->isExpired($file)) {
        $item = new Item($key);
        $resuts[$key] = $item->set(unserialize(preg_replace('/^<\?php\sexit\?>/', '', file_get_contents($file), 1)));
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
    return $this->isExpired($this->fetchStreamUri($key));
  }

  /**
   * เคลียร์แคช
   *
   * @return boolean true ถ้าลบเรียบร้อย, หรือ false ถ้าไม่สำเร็จ
   */
  public function clear()
  {
    $error = array();
    if ($this->cache_dir && !empty($this->cache_expire)) {
      $this->clearCache($this->cache_dir, $error);
    }
    return empty($error) ? true : false;
  }

  /**
   * ลบไฟล์ทั้งหมดในไดเร็คทอรี่ (cache)
   *
   * @param string $dir
   * @param array $error เก็บรายชื่อไฟล์ที่ไม่สามารถลบได้
   */
  private function clearCache($dir, &$error)
  {
    $f = @opendir($dir);
    if ($f) {
      while (false !== ($text = readdir($f))) {
        if ($text != "." && $text != ".." && $text != 'index.php') {
          if (is_dir($dir.$text)) {
            $this->clearCache($dir.$text.'/', $error);
          } elseif (is_file($dir.$text)) {
            if (@unlink($dir.$text) === false) {
              $error[] = $dir.$text;
            }
          }
        }
      }
      closedir($f);
    }
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
        @unlink($this->fetchStreamUri($key));
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
    if ($this->cache_dir && !empty($this->cache_expire)) {
      $f = @fopen($this->fetchStreamUri($item->getKey()), 'wb');
      if (!$f) {
        throw new Exception('resource cache file cannot be created.');
      } else {
        fwrite($f, '<?php exit?>'.serialize($item->get()));
        fclose($f);
        return true;
      }
    }
    return false;
  }

  /**
   * อ่านค่า full path ของไฟล์แคช
   *
   * @param string $key
   * @return string
   */
  private function fetchStreamUri($key)
  {
    return $this->cache_dir.md5($key).'.php';
  }

  /**
   * ตรวจสอบวันหมดอายุของไฟล์แคช
   *
   * @param string $file
   * @return boolean คืนค่า true ถ้าแคชสามารถใช้งานได้
   */
  private function isExpired($file)
  {
    if ($this->cache_dir && !empty($this->cache_expire)) {
      return file_exists($file) && time() - filemtime($file) < $this->cache_expire;
    }
    return false;
  }
}
