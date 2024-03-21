<?php
/**
 * @filesource Kotchasan/Cache/FileCache.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Cache;

use Kotchasan\Cache\CacheItem as Item;
use Kotchasan\File;
use Psr\Cache\CacheItemInterface;

/**
 * This class provides functionality for caching data to the filesystem.
 *
 * @see https://www.kotchasan.com/
 */
class FileCache extends Cache
{
    /**
     * Cache directory path
     *
     * @var string /root/to/dir/cache/
     */
    protected $cache_dir = null;
    /**
     * Cache expiration time in seconds (0 means no cache)
     *
     * @var int
     */
    protected $cache_expire = 0;

    /**
     * Class constructor
     *
     * @throws Exception if the cache directory cannot be created
     */
    public function __construct()
    {
        $this->cache_expire = self::$cfg->get('cache_expire', 0);
        if (!empty($this->cache_expire)) {
            // Cache directory
            $this->cache_dir = ROOT_PATH.'datas/cache/';
            if (!File::makeDirectory($this->cache_dir)) {
                throw new \Exception('Folder '.str_replace(ROOT_PATH, '', $this->cache_dir).' cannot be created.');
            }
            // Clear old cache every day
            $d = is_file($this->cache_dir.'index.php') ? (int) file_get_contents($this->cache_dir.'index.php') : 0;
            if ($d != (int) date('d')) {
                $this->clear();
                $f = @fopen($this->cache_dir.'index.php', 'wb');
                if ($f === false) {
                    throw new \Exception('File '.str_replace(ROOT_PATH, '', $this->cache_dir).'index.php cannot be written.');
                } else {
                    fwrite($f, date('d-m-Y H:i:s'));
                    fclose($f);
                }
            }
        }
    }

    /**
     * Clear the cache
     *
     * @return bool true if the cache is cleared successfully, false otherwise
     */
    public function clear()
    {
        $error = [];
        if ($this->cache_dir && !empty($this->cache_expire)) {
            $this->clearCache($this->cache_dir, $error);
        }
        return empty($error) ? true : false;
    }

    /**
     * Delete multiple cache items
     *
     * @param array $keys
     *
     * @return bool true if the deletion is successful, false otherwise
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
     * Get multiple cache items
     *
     * @param array $keys
     *
     * @return array
     */
    public function getItems(array $keys = [])
    {
        $results = [];
        foreach ($keys as $key) {
            $file = $this->fetchStreamUri($key);
            if ($this->isExpired($file)) {
                $item = new Item($key);
                $results[$key] = $item->set(json_decode(preg_replace('/^<\?php\sexit\?>/', '', file_get_contents($file), 1), true));
            }
        }
        return $results;
    }

    /**
     * Check if a cache item exists
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasItem($key)
    {
        return $this->isExpired($this->fetchStreamUri($key));
    }

    /**
     * Save a cache item
     *
     * @param CacheItemInterface $item
     *
     * @throws Exception if the cache file cannot be created
     *
     * @return bool true if the cache item is saved successfully, false otherwise
     */
    public function save(CacheItemInterface $item)
    {
        if ($this->cache_dir && !empty($this->cache_expire)) {
            $f = @fopen($this->fetchStreamUri($item->getKey()), 'wb');
            if (!$f) {
                throw new \Exception('Resource cache file cannot be created.');
            } else {
                fwrite($f, '<?php exit?>'.json_encode($item->get()));
                fclose($f);
                return true;
            }
        }
        return false;
    }

    /**
     * Clear all files in the cache directory
     *
     * @param string $dir
     * @param array  $error
     */
    private function clearCache($dir, &$error)
    {
        $f = @opendir($dir);
        if ($f) {
            while (false !== ($text = readdir($f))) {
                if ($text != '.' && $text != '..' && $text != 'index.php') {
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
     * Get the full path of the cache file
     *
     * @param string $key
     *
     * @return string
     */
    private function fetchStreamUri($key)
    {
        return $this->cache_dir.md5($key).'.php';
    }

    /**
     * Check if a cache file has expired
     *
     * @param string $file
     *
     * @return bool
     */
    private function isExpired($file)
    {
        if ($this->cache_dir && !empty($this->cache_expire)) {
            return file_exists($file) && time() - filemtime($file) < $this->cache_expire;
        }
        return false;
    }
}
