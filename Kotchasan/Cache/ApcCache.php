<?php
/**
 * @filesource  Kotchasan/Cache/ApcCache.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Cache;

use Kotchasan\Cache\CacheItem as Item;
use Psr\Cache\CacheItemInterface;

/**
 * APC cache driver
 *
 * This class provides a cache driver using the APC (Alternative PHP Cache) extension.
 *
 * @see https://www.kotchasan.com/
 */
class ApcCache extends Cache
{
    /**
     * Class constructor
     *
     * @throws Exception if APC is not supported
     */
    public function __construct()
    {
        if (!extension_loaded('apc') || !is_callable('apc_fetch')) {
            throw new \Exception('APC not supported.');
        }
    }

    /**
     * Clear the cache
     *
     * @return bool true if successfully cleared, false otherwise
     */
    public function clear()
    {
        return \apc_clear_cache('user');
    }

    /**
     * Delete multiple cache items
     *
     * @param array $keys
     *
     * @return bool true if successfully deleted, false otherwise
     */
    public function deleteItems(array $keys)
    {
        if ($this->cache_dir) {
            foreach ($keys as $key) {
                \apc_delete($key);
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
        $success = false;
        $values = \apc_fetch($keys, $success);
        if ($success && is_array($values)) {
            foreach ($values as $key => $value) {
                $item = new Item($key);
                $results[$key] = $item->set($value);
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
        return \apc_exists($key);
    }

    /**
     * Save a cache item
     *
     * @param CacheItemInterface $item
     *
     * @throws CacheException
     *
     * @return bool true if successfully saved, false otherwise
     */
    public function save(CacheItemInterface $item)
    {
        return \apc_store($item->getKey(), $item->get(), self::$cfg->get('cache_expire', 5));
    }
}
