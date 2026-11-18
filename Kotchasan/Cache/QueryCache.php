<?php

namespace Kotchasan\Cache;

use Kotchasan\QueryBuilder\QueryBuilderInterface;

/**
 * Query Cache
 *
 * Specialized cache wrapper for database query caching.
 * Generates cache keys from SQL queries and parameters.
 *
 * @package Kotchasan\Cache
 */
class QueryCache
{
    /**
     * The underlying cache implementation.
     *
     * @var CacheInterface
     */
    protected CacheInterface $cache;

    /**
     * Default TTL in seconds.
     *
     * @var int
     */
    protected int $defaultTtl;

    /**
     * Flag to enable/disable caching globally.
     *
     * @var bool
     */
    protected bool $enabled = true;

    /**
     * Cache key prefix for query cache.
     *
     * @var string
     */
    protected string $prefix = 'query_';

    /**
     * Keys looked up by get(), by query object, for the set() that follows.
     *
     * @var array<int, string>
     */
    protected array $pendingKeys = [];

    /**
     * Cache statistics.
     *
     * @var array
     */
    protected array $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0
    ];

    /**
     * Constructor.
     *
     * @param CacheInterface $cache The cache implementation.
     * @param int $defaultTtl Default TTL in seconds.
     */
    public function __construct(CacheInterface $cache, int $defaultTtl = 3600)
    {
        $this->cache = $cache;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Get a cached query result.
     *
     * @param QueryBuilderInterface $query The query builder.
     * @return mixed|null The cached result or null if not found.
     */
    public function get(QueryBuilderInterface $query)
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateKey($query);
        // set() stores under this same key, so a write that lands while the
        // query runs cannot file its old result under the new generation
        $this->pendingKeys[spl_object_id($query)] = $key;
        $result = $this->cache->get($key);

        if ($result !== null) {
            $this->stats['hits']++;
        } else {
            $this->stats['misses']++;
        }

        return $result;
    }

    /**
     * Cache a query result.
     *
     * @param QueryBuilderInterface $query The query builder.
     * @param mixed $result The query result to cache.
     * @param int|null $ttl Optional TTL in seconds.
     * @return bool True on success.
     */
    public function set(QueryBuilderInterface $query, $result, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $id = spl_object_id($query);
        $key = $this->pendingKeys[$id] ?? $this->generateKey($query);
        unset($this->pendingKeys[$id]);
        $success = $this->cache->set($key, $result, $ttl ?? $this->defaultTtl);

        if ($success) {
            $this->stats['writes']++;
        }

        return $success;
    }

    /**
     * The current cache generation. Every key includes it, so starting a new
     * generation makes every cached result unreachable at once; old entries
     * simply expire.
     *
     * @return string
     */
    protected function generation(): string
    {
        $generation = $this->cache->get($this->prefix.'generation');
        if (is_string($generation) && $generation !== '') {
            return $generation;
        }
        // No generation yet, or the store evicted it: start a new one rather than
        // a fixed value, so results stored before an eviction never come back
        $generation = bin2hex(random_bytes(8));
        $this->cache->set($this->prefix.'generation', $generation, 0);

        return $generation;
    }

    /**
     * Drop every cached query result by starting a new generation. Called after
     * each INSERT, UPDATE, DELETE, emptyTable() or write through raw(), so a read
     * that follows a write never gets the old rows.
     *
     * @return bool True on success.
     */
    public function invalidateAll(): bool
    {
        // A random value rather than a counter: two writers can never produce
        // the same generation by reading the same old number
        return $this->cache->set($this->prefix.'generation', bin2hex(random_bytes(8)), 0);
    }

    /**
     * Generate a cache key for a query.
     *
     * @param QueryBuilderInterface $query The query builder.
     * @return string The cache key.
     */
    protected function generateKey(QueryBuilderInterface $query): string
    {
        // Get the SQL and bindings
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $embeddedBindings = [];
        if (method_exists($query, 'getEmbeddedBindings')) {
            $embeddedBindings = $query->getEmbeddedBindings();
            if (!empty($embeddedBindings) && is_array($embeddedBindings)) {
                ksort($embeddedBindings);
            }
        }

        // Create a unique key from SQL + bindings (include embedded bindings)
        $data = $sql.serialize([
            'bindings' => $bindings,
            'embedded' => $embeddedBindings
        ]);

        return $this->prefix.$this->generation().'_'.md5($data);
    }

    /**
     * Generate a cache key from raw SQL and parameters.
     *
     * @param string $sql The SQL query.
     * @param array $params The query parameters.
     * @return string The cache key.
     */
    public function generateKeyFromSql(string $sql, array $params = []): string
    {
        $data = $sql.serialize($params);
        return $this->prefix.$this->generation().'_'.md5($data);
    }

    /**
     * Get a cached result by raw SQL.
     *
     * @param string $sql The SQL query.
     * @param array $params The query parameters.
     * @return mixed|null The cached result or null if not found.
     */
    public function getByKey(string $sql, array $params = [])
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateKeyFromSql($sql, $params);
        $result = $this->cache->get($key);

        if ($result !== null) {
            $this->stats['hits']++;
        } else {
            $this->stats['misses']++;
        }

        return $result;
    }

    /**
     * Cache a result by raw SQL.
     *
     * @param string $sql The SQL query.
     * @param array $params The query parameters.
     * @param mixed $result The result to cache.
     * @param int|null $ttl Optional TTL in seconds.
     * @return bool True on success.
     */
    public function setByKey(string $sql, array $params, $result, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->generateKeyFromSql($sql, $params);
        $success = $this->cache->set($key, $result, $ttl ?? $this->defaultTtl);

        if ($success) {
            $this->stats['writes']++;
        }

        return $success;
    }

    /**
     * Invalidate a cached query.
     *
     * @param QueryBuilderInterface $query The query builder.
     * @return bool True on success.
     */
    public function invalidate(QueryBuilderInterface $query): bool
    {
        $key = $this->generateKey($query);
        return $this->cache->delete($key);
    }

    /**
     * Invalidate cached queries after a change to a table.
     * A query can read several tables through joins and subqueries, so every
     * cached result is dropped, not only those of this table. Other data in the
     * same cache store is kept.
     *
     * @param string $table The table name.
     * @return bool True on success.
     */
    public function invalidateTable(string $table): bool
    {
        return $this->invalidateAll();
    }

    /**
     * Clear all cached queries.
     *
     * @return bool True on success.
     */
    public function clear(): bool
    {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0
        ];

        return $this->cache->clear();
    }

    /**
     * Enable caching.
     *
     * @return self
     */
    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    /**
     * Disable caching.
     *
     * @return self
     */
    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        $cacheStats = $this->cache->getStats();

        return array_merge($this->stats, [
            'enabled' => $this->enabled,
            'default_ttl' => $this->defaultTtl,
            'backend' => get_class($this->cache),
            'backend_stats' => $cacheStats
        ]);
    }

    /**
     * Get the underlying cache instance.
     *
     * @return CacheInterface
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Get the default TTL.
     *
     * @return int TTL in seconds.
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Set the default TTL.
     *
     * @param int $ttl TTL in seconds.
     * @return self
     */
    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }

    /**
     * Set the cache key prefix.
     *
     * @param string $prefix The prefix.
     * @return self
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }
}
