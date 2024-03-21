<?php
/**
 * @filesource  Kotchasan/Cache/Cache.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Cache;

use Kotchasan\Cache\CacheItem as Item;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Kotchasan Caching Class (base class)
 *
 * This is an abstract base class that implements the PSR-16 CacheItemPoolInterface.
 *
 * @see https://www.kotchasan.com/
 */
abstract class Cache extends \Kotchasan\KBase implements CacheItemPoolInterface
{
    /**
     * Deferred cache items
     *
     * @var array
     */
    protected $deferred = [];

    /**
     * Commit the cached items in the deferred queue
     *
     * @return bool
     */
    public function commit()
    {
        $success = true;
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Delete a cache item
     *
     * @param string $key
     *
     * @return bool
     */
    public function deleteItem($key)
    {
        return $this->deleteItems(array($key));
    }

    /**
     * Get a cache item
     *
     * @param string $key
     *
     * @return CacheItemInterface
     */
    public function getItem($key)
    {
        $items = $this->getItems(array($key));
        return isset($items[$key]) ? $items[$key] : new Item($key);
    }

    /**
     * Save a cache item for deferred saving
     *
     * @param CacheItemInterface $item
     *
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        $this->deferred[$item->getKey()] = $item;
        return true;
    }
}
