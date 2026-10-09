<?php

namespace Kotchasan\Cache;

/**
 * Cache Factory
 *
 * Creates cache instances based on configuration.
 *
 * @package Kotchasan\Cache
 */
class CacheFactory
{
    /**
     * Available cache drivers.
     *
     * @var array
     */
    protected static array $drivers = [
        'file' => FileCache::class,
        'memory' => MemoryCache::class,
        'redis' => RedisCache::class
    ];

    /**
     * Create a cache instance based on configuration.
     *
     * @param array $config The cache configuration.
     * @return CacheInterface The cache instance.
     * @throws \InvalidArgumentException If the driver is not supported.
     */
    public static function create(array $config): CacheInterface
    {
        $driver = $config['driver'] ?? 'file';

        if (!isset(self::$drivers[$driver])) {
            throw new \InvalidArgumentException("Unsupported cache driver: {$driver}");
        }

        $class = self::$drivers[$driver];

        switch ($driver) {
            case 'file':
                return new $class(
                    $config['path'] ?? null,
                    $config['ttl'] ?? 3600
                );

            case 'memory':
                return new $class(
                    $config['ttl'] ?? 3600,
                    $config['max_items'] ?? 1000
                );

            case 'redis':
                return new $class(
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 6379,
                    $config['password'] ?? null,
                    $config['database'] ?? 0,
                    $config['prefix'] ?? 'kotchasan_',
                    $config['ttl'] ?? 3600
                );

            default:
                return new $class();
        }
    }

    /**
     * Register a custom cache driver.
     *
     * @param string $name The driver name.
     * @param string $class The driver class (must implement CacheInterface).
     * @return void
     * @throws \InvalidArgumentException If the class doesn't implement CacheInterface.
     */
    public static function registerDriver(string $name, string $class): void
    {
        if (!in_array(CacheInterface::class, class_implements($class))) {
            throw new \InvalidArgumentException(
                "Cache driver class must implement CacheInterface: {$class}"
            );
        }

        self::$drivers[$name] = $class;
    }

    /**
     * Get available cache drivers.
     *
     * @return array
     */
    public static function getAvailableDrivers(): array
    {
        return array_keys(self::$drivers);
    }

    /**
     * Create a file cache instance.
     *
     * @param string|null $path The cache directory path.
     * @param int $ttl Default TTL in seconds.
     * @return FileCache
     */
    public static function createFileCache(?string $path = null, int $ttl = 3600): FileCache
    {
        return new FileCache($path, $ttl);
    }

    /**
     * Create a memory cache instance.
     *
     * @param int $ttl Default TTL in seconds.
     * @param int $maxItems Maximum number of items.
     * @return MemoryCache
     */
    public static function createMemoryCache(int $ttl = 3600, int $maxItems = 1000): MemoryCache
    {
        return new MemoryCache($ttl, $maxItems);
    }

    /**
     * Create a Redis cache instance.
     *
     * @param string $host Redis host.
     * @param int $port Redis port.
     * @param string|null $password Redis password.
     * @param int $database Redis database number.
     * @param string $prefix Key prefix.
     * @param int $ttl Default TTL in seconds.
     * @return RedisCache
     */
    public static function createRedisCache(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'kotchasan_',
        int $ttl = 3600
    ): RedisCache {
        return new RedisCache($host, $port, $password, $database, $prefix, $ttl);
    }
}
