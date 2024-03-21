<?php
namespace Psr\Cache;

/**
 * CacheItemPoolInterface generates CacheItemInterface objects.
 */
interface CacheItemPoolInterface
{
    /**
     * Returns a Cache Item representing the specified key.
     *
     * This method must always return a CacheItemInterface object, even in case of
     * a cache miss. It MUST NOT return null.
     *
     *   The key for which to return the corresponding Cache Item.
     *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
     *   MUST be thrown.
     *   The corresponding Cache Item.
     *
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return CacheItemInterface
     */
    public function getItem($key);

    /**
     * Returns a traversable set of cache items.
     *
     * An indexed array of keys of items to retrieve.
     *   If any of the keys in $keys are not a legal value a \Psr\Cache\InvalidArgumentException
     *   MUST be thrown.
     *   A traversable collection of Cache Items keyed by the cache keys of
     *   each item. A Cache item will be returned for each key, even if that
     *   key is not found. However, if no keys are specified then an empty
     *   traversable MUST be returned instead.
     *
     * @param array $keys
     *
     * @throws InvalidArgumentException
     *
     * @return array|\Traversable
     */
    public function getItems(array $keys = []);

    /**
     * Confirms if the cache contains specified cache item.
     *
     * Note: This method MAY avoid retrieving the cached value for performance reasons.
     * This could result in a race condition with CacheItemInterface::get(). To avoid
     * such situation use CacheItemInterface::isHit() instead.
     *
     *    The key for which to check existence.
     *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
     *   MUST be thrown.
     *  True if item exists in the cache, false otherwise.
     *
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function hasItem($key);

    /**
     * Deletes all items in the pool.
     *
     *   True if the pool was successfully cleared. False if there was an error.
     *
     * @return bool
     */
    public function clear();

    /**
     * Removes the item from the pool.
     *
     *   The key for which to delete
     *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
     *   MUST be thrown.
     *   True if the item was successfully removed. False if there was an error.
     *
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function deleteItem($key);

    /**
     * Removes multiple items from the pool.
     *
     *   An array of keys that should be removed from the pool.
     *   If any of the keys in $keys are not a legal value a \Psr\Cache\InvalidArgumentException
     *   MUST be thrown.
     *   True if the items were successfully removed. False if there was an error.
     *
     * @param array $keys
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function deleteItems(array $keys);

    /**
     * Persists a cache item immediately.
     *
     *   The cache item to save.
     *   True if the item was successfully persisted. False if there was an error.
     *
     * @param CacheItemInterface $item
     *
     * @return bool
     */
    public function save(CacheItemInterface $item);

    /**
     * Sets a cache item to be persisted later.
     *
     *   The cache item to save.
     *   False if the item could not be queued or if a commit was attempted and failed. True otherwise.
     *
     * @param CacheItemInterface $item
     *
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item);

    /**
     * Persists any deferred cache items.
     *
     *   True if all not-yet-saved items were successfully saved or there were none. False otherwise.
     *
     * @return bool
     */
    public function commit();
}
