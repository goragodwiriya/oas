<?php
/**
 * @filesource Kotchasan/Database/DbCache.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

use Kotchasan\Cache\CacheItem as Item;
use Kotchasan\Cache\FileCache as Cache;
use Kotchasan\Text;

/**
 * Provides caching functionality for database query results.
 *
 * @see https://www.kotchasan.com/
 */
class DbCache
{
    /**
     * Defines the cache loading behavior.
     *
     * - 0: Disable caching.
     * - 1: Load and automatically save cache.
     * - 2: Load cache but do not automatically save cache.
     *
     * @var int
     */
    private $action = 0;

    /**
     * Cache driver instance.
     *
     * @var Cache
     */
    private $db_cache;

    /**
     * Singleton instance of the class.
     *
     * @var DbCache
     */
    private static $instance = null;

    /**
     * Enable caching.
     *
     * Cache will be checked before querying data.
     *
     * @param bool $auto_save (optional) Whether to automatically save cache results. Default is true.
     *
     * @return void
     */
    public function cacheOn($auto_save = true)
    {
        $this->action = $auto_save ? 1 : 2;
    }

    /**
     * Clear the cache.
     *
     * @return bool|array True if the cache is cleared successfully, or an array of failed items.
     */
    public function clear()
    {
        return $this->db_cache->clear();
    }

    /**
     * Create an instance of the class (Singleton).
     *
     * @return DbCache
     */
    public static function create()
    {
        if (null === static::$instance) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    /**
     * Get data from the cache.
     *
     * Returns the cached data or false if the cache is not available.
     *
     * @param Item $item The cache item.
     *
     * @return mixed The cached data or false.
     */
    public function get(Item $item)
    {
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * Get the current cache action.
     *
     * - 0: Disable caching.
     * - 1: Load and automatically save cache.
     * - 2: Load cache but do not automatically save cache.
     *
     * @return int The cache action.
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Initialize a cache item based on the SQL query and its values.
     *
     * @param string $sql The SQL query.
     * @param array $values The query values.
     *
     * @return Item The cache item.
     */
    public function init($sql, $values)
    {
        return $this->db_cache->getItem(Text::replace($sql, $values));
    }

    /**
     * Save the cache item with the provided data.
     *
     * Once the cache is saved, the automatic cache action will be disabled.
     * Use this method when calling `cacheOn(false)` to enable caching manually.
     * Subsequent queries that require caching must enable cache before each query.
     *
     * @param Item $item The cache item.
     * @param mixed $data The data to be cached.
     *
     * @return bool True if the cache is saved successfully, false otherwise.
     */
    public function save(Item $item, $data)
    {
        $this->action = 0;
        $item->set($data);
        return $this->db_cache->save($item);
    }

    /**
     * Set the cache action.
     *
     * - 0: Disable caching.
     * - 1: Load and automatically save cache.
     * - 2: Load cache but do not automatically save cache.
     *
     * @param int $value The cache action value.
     *
     * @return DbCache
     */
    public function setAction($value)
    {
        $this->action = $value;
        return $this;
    }

    /**
     * Check if the data was retrieved from the cache item.
     *
     * @param Item $item The cache item.
     *
     * @return bool True if the cache item was used, false otherwise.
     */
    public function usedCache(Item $item)
    {
        return $item->isHit();
    }

    /**
     * Class constructor.
     */
    private function __construct()
    {
        $this->db_cache = new Cache();
    }
}
