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

        $key = $this->generateKey($query);
        $success = $this->cache->set($key, $result, $ttl ?? $this->defaultTtl);

        if ($success) {
            $this->stats['writes']++;
        }

        return $success;
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

        return $this->prefix.md5($data);
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
        return $this->prefix.md5($data);
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
     * Invalidate cache by table name.
     * This clears all cached queries for a specific table.
     *
     * @param string $table The table name.
     * @return bool True on success.
     */
    public function invalidateTable(string $table): bool
    {
        // This is a simple implementation - for production use,
        // you might want to track table -> keys mapping
        // For now, just clear all cache (safe but not optimal)
        return $this->clear();
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
