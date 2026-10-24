<?php

namespace Kotchasan\Cache;

/**
 * Memory Cache Implementation
 *
 * Stores cache data in memory (PHP array).
 * Data is lost when the script ends.
 * Useful for caching within a single request.
 *
 * @package Kotchasan\Cache
 */
class MemoryCache implements CacheInterface
{
    /**
     * The cache storage.
     *
     * @var array
     */
    protected array $cache = [];

    /**
     * Default TTL in seconds (1 hour).
     *
     * @var int
     */
    protected int $defaultTtl = 3600;

    /**
     * Maximum number of items to store.
     *
     * @var int
     */
    protected int $maxItems = 1000;

    /**
     * Cache statistics.
     *
     * @var array
     */
    protected array $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0
    ];

    /**
     * Constructor.
     *
     * @param int $defaultTtl Default TTL in seconds.
     * @param int $maxItems Maximum number of items to store.
     */
    public function __construct(int $defaultTtl = 3600, int $maxItems = 1000)
    {
        $this->defaultTtl = $defaultTtl;
        $this->maxItems = $maxItems;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, $default = null)
    {
        if (!isset($this->cache[$key])) {
            $this->stats['misses']++;
            return $default;
        }

        $item = $this->cache[$key];

        // Check if expired
        if ($item['expires'] !== 0 && $item['expires'] < time()) {
            $this->delete($key);
            $this->stats['misses']++;
            return $default;
        }

        $this->stats['hits']++;
        return $item['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        // Evict if at max capacity
        if (count($this->cache) >= $this->maxItems && !isset($this->cache[$key])) {
            $this->evict();
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $expires = $ttl > 0 ? time() + $ttl : 0;

        $this->cache[$key] = [
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];

        $this->stats['writes']++;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            $this->stats['deletes']++;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->cache = [];
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => 0
        ];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        $item = $this->cache[$key];

        // Check if expired
        if ($item['expires'] !== 0 && $item['expires'] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        // Calculate approximate memory usage
        $size = 0;
        foreach ($this->cache as $item) {
            $size += strlen(serialize($item));
        }

        return array_merge($this->stats, [
            'count' => count($this->cache),
            'size' => $size,
            'max_items' => $this->maxItems
        ]);
    }

    /**
     * Evict items to make room for new ones.
     * Uses LRU (Least Recently Used) strategy by removing oldest items.
     *
     * @return void
     */
    protected function evict(): void
    {
        // First, remove expired items
        $now = time();
        foreach ($this->cache as $key => $item) {
            if ($item['expires'] !== 0 && $item['expires'] < $now) {
                unset($this->cache[$key]);
            }
        }

        // If still over capacity, remove oldest items
        if (count($this->cache) >= $this->maxItems) {
            // Sort by creation time and remove oldest 10%
            $toRemove = (int) ceil($this->maxItems * 0.1);

            uasort($this->cache, function ($a, $b) {
                return $a['created'] <=> $b['created'];
            });

            $removed = 0;
            foreach ($this->cache as $key => $item) {
                if ($removed >= $toRemove) {
                    break;
                }
                unset($this->cache[$key]);
                $removed++;
            }
        }
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
     * Set the maximum number of items.
     *
     * @param int $maxItems Maximum items.
     * @return self
     */
    public function setMaxItems(int $maxItems): self
    {
        $this->maxItems = $maxItems;
        return $this;
    }
}
