<?php

namespace Kotchasan\Cache;

/**
 * Redis Cache Implementation
 *
 * Stores cache data in Redis server.
 * Requires the phpredis extension.
 *
 * @package Kotchasan\Cache
 */
class RedisCache implements CacheInterface
{
    /**
     * Redis connection instance (phpredis extension).
     * Kept as a docblock type so IDEs/static analyzers understand the type
     * even when the phpredis extension is not installed.
     *
     * @var \Redis|null
     */
    protected $redis = null;

    /**
     * Redis host.
     *
     * @var string
     */
    protected string $host;

    /**
     * Redis port.
     *
     * @var int
     */
    protected int $port;

    /**
     * Redis password.
     *
     * @var string|null
     */
    protected ?string $password;

    /**
     * Redis database number.
     *
     * @var int
     */
    protected int $database;

    /**
     * Key prefix.
     *
     * @var string
     */
    protected string $prefix;

    /**
     * Default TTL in seconds.
     *
     * @var int
     */
    protected int $defaultTtl;

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
     * @param string $host Redis host.
     * @param int $port Redis port.
     * @param string|null $password Redis password.
     * @param int $database Redis database number.
     * @param string $prefix Key prefix.
     * @param int $defaultTtl Default TTL in seconds.
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'kotchasan_',
        int $defaultTtl = 3600
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Connect to Redis server.
     *
     * @return bool True if connected successfully.
     */
    protected function connect(): bool
    {
        if ($this->redis !== null) {
            return true;
        }

        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $this->redis = new \Redis();

            // suppress warnings from phpredis (connect/auth/select) which emit warnings, not exceptions
            if (!@$this->redis->connect($this->host, $this->port)) {
                $this->redis = null;
                return false;
            }

            if ($this->password !== null) {
                if (!@$this->redis->auth($this->password)) {
                    $this->redis = null;
                    return false;
                }
            }

            if ($this->database !== 0) {
                @$this->redis->select($this->database);
            }

            return true;
        } catch (\Exception $e) {
            $this->redis = null;
            return false;
        }
    }

    /**
     * Get the full key with prefix.
     *
     * @param string $key The cache key.
     * @return string The prefixed key.
     */
    protected function getKey(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, $default = null)
    {
        if (!$this->connect()) {
            return $default;
        }

        /** @var \Redis $redisInstance */
        $redisInstance = $this->redis;

        if ($redisInstance === null) {
            return $default;
        }

        $value = $redisInstance->get($this->getKey($key));

        if ($value === false) {
            $this->stats['misses']++;
            return $default;
        }

        $this->stats['hits']++;
        return unserialize($value);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $serialized = serialize($value);

        if ($ttl > 0) {
            $result = $this->redis->setex($this->getKey($key), $ttl, $serialized);
        } else {
            $result = $this->redis->set($this->getKey($key), $serialized);
        }

        if ($result) {
            $this->stats['writes']++;
        }

        return (bool) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $result = $this->redis->del($this->getKey($key));

        if ($result > 0) {
            $this->stats['deletes']++;
        }

        return $result > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        if (!$this->connect()) {
            return false;
        }

        // Clear only keys with our prefix
        $keys = $this->redis->keys($this->prefix.'*');

        if (!empty($keys)) {
            $this->redis->del($keys);
        }

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
        if (!$this->connect()) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $default;
            }
            return $result;
        }

        $prefixedKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $prefixedKey = $this->getKey($key);
            $prefixedKeys[] = $prefixedKey;
            $keyMap[$prefixedKey] = $key;
        }

        $values = $this->redis->mget($prefixedKeys);
        $result = [];

        foreach ($prefixedKeys as $i => $prefixedKey) {
            $originalKey = $keyMap[$prefixedKey];

            if ($values[$i] === false) {
                $result[$originalKey] = $default;
                $this->stats['misses']++;
            } else {
                $result[$originalKey] = unserialize($values[$i]);
                $this->stats['hits']++;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $success = true;

        // Use pipeline for better performance
        $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $serialized = serialize($value);

            if ($ttl > 0) {
                $this->redis->setex($this->getKey($key), $ttl, $serialized);
            } else {
                $this->redis->set($this->getKey($key), $serialized);
            }
        }

        $results = $this->redis->exec();

        // exec() may return null on error. Ensure we have an array before iterating
        // to satisfy static analyzers and avoid foreach on null.
        if (!is_array($results)) {
            // Treat as failure
            return false;
        }

        foreach ($results as $result) {
            if ($result) {
                $this->stats['writes']++;
            } else {
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
        if (!$this->connect()) {
            return false;
        }

        $prefixedKeys = [];
        foreach ($keys as $key) {
            $prefixedKeys[] = $this->getKey($key);
        }

        if (empty($prefixedKeys)) {
            return true;
        }

        $deleted = $this->redis->del($prefixedKeys);
        $this->stats['deletes'] += $deleted;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        return (bool) $this->redis->exists($this->getKey($key));
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->connect();
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        $info = [];

        if ($this->connect()) {
            try {
                $redisInfo = $this->redis->info();
                $info = [
                    'redis_version' => $redisInfo['redis_version'] ?? 'unknown',
                    'used_memory' => $redisInfo['used_memory'] ?? 0,
                    'connected_clients' => $redisInfo['connected_clients'] ?? 0,
                    'db_size' => $this->redis->dbSize()
                ];
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        return array_merge($this->stats, [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'prefix' => $this->prefix,
            'connected' => $this->redis !== null
        ], $info);
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
     * Get the Redis connection.
     *
     * @return \Redis|null
     */
    public function getRedis()
    {
        $this->connect();
        return $this->redis;
    }

    /**
     * Close the Redis connection.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // Ignore errors
            }
            $this->redis = null;
        }
    }

    /**
     * Destructor - close connection.
     */
    public function __destruct()
    {
        $this->close();
    }
}
