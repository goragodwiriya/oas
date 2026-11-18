<?php

namespace Kotchasan\Cache;

/**
 * Cache Interface
 *
 * Defines the standard interface for all cache implementations.
 * This interface follows PSR-16 Simple Cache conventions.
 *
 * @package Kotchasan\Cache
 */
interface CacheInterface
{
    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, $default = null);

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store.
     * @param int|null $ttl Optional. The TTL value of this item in seconds.
     * @return bool True on success and false on failure.
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool;

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys A list of keys that can be obtained in a single operation.
     * @param mixed $default Default value to return for keys that do not exist.
     * @return iterable A list of key => value pairs.
     */
    public function getMultiple(iterable $keys, $default = null): iterable;

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable $values A list of key => value pairs for a multiple-set operation.
     * @param int|null $ttl Optional. The TTL value of this item in seconds.
     * @return bool True on success and false on failure.
     */
    public function setMultiple(iterable $values, ?int $ttl = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys A list of string-based keys to be deleted.
     * @return bool True if the items were successfully removed. False if there was an error.
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Check if the cache driver is available and ready.
     *
     * @return bool True if available, false otherwise.
     */
    public function isAvailable(): bool;

    /**
     * Get cache statistics (optional).
     *
     * @return array Statistics array with keys like 'hits', 'misses', 'size', etc.
     */
    public function getStats(): array;
}
