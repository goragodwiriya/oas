<?php

namespace Kotchasan\Cache;

/**
 * File Cache Implementation
 *
 * Stores cache data in files on the filesystem.
 * This is the default cache driver for Kotchasan.
 *
 * @package Kotchasan\Cache
 */
class FileCache implements CacheInterface
{
    /**
     * The cache directory path.
     *
     * @var string
     */
    protected string $path;

    /**
     * Default TTL in seconds (1 hour).
     *
     * @var int
     */
    protected int $defaultTtl = 3600;

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
     * File extension for cache files.
     *
     * @var string
     */
    protected string $extension = '.cache';

    /**
     * Auto garbage collection interval in seconds.
     * Default is 86400 (24 hours / once per day).
     *
     * @var int
     */
    protected int $gcInterval = 86400;

    /**
     * Probability of running GC (1 in gcProbability requests).
     * Set to 0 to disable probability-based GC.
     *
     * @var int
     */
    protected int $gcProbability = 100;

    /**
     * Constructor.
     *
     * @param string|null $path The cache directory path. Defaults to DATA_FOLDER/cache/
     * @param int $defaultTtl Default TTL in seconds.
     * @param bool $autoGc Whether to run automatic garbage collection.
     */
    public function __construct(?string $path = null, int $defaultTtl = 3600, bool $autoGc = true)
    {
        if ($path === null) {
            // Use default cache directory
            if (defined('ROOT_PATH') && defined('DATA_FOLDER')) {
                $path = ROOT_PATH.DATA_FOLDER.'cache/';
            } else {
                $path = sys_get_temp_dir().'/kotchasan_cache/';
            }
        }

        $this->path = rtrim($path, '/').'/';
        $this->defaultTtl = $defaultTtl;

        // Create cache directory if it doesn't exist
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }

        // Run automatic garbage collection
        if ($autoGc) {
            $this->autoGc();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, $default = null)
    {
        $filename = $this->getFilename($key);

        if (!is_file($filename)) {
            $this->stats['misses']++;
            return $default;
        }

        $data = $this->readFile($filename);

        if ($data === false) {
            $this->stats['misses']++;
            return $default;
        }

        // Check if expired
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            $this->delete($key);
            $this->stats['misses']++;
            return $default;
        }

        $this->stats['hits']++;
        return $data['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $expires = $ttl > 0 ? time() + $ttl : 0;

        $data = [
            'key' => $key,
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];

        $filename = $this->getFilename($key);
        $result = $this->writeFile($filename, $data);

        if ($result) {
            $this->stats['writes']++;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $filename = $this->getFilename($key);

        if (is_file($filename)) {
            $result = @unlink($filename);
            if ($result) {
                $this->stats['deletes']++;
            }
            return $result;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $success = true;
        $count = 0;

        // Use RecursiveIterator to clear all cache files including subdirectories
        if (is_dir($this->path)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $file) {
                    $filename = $file->getFilename();

                    // Skip GC lock file
                    if ($filename === '.gc_lock') {
                        continue;
                    }

                    if ($file->isFile() && substr($filename, -strlen($this->extension)) === $this->extension) {
                        if (!@unlink($file->getPathname())) {
                            $success = false;
                        } else {
                            $count++;
                        }
                    }
                }

                // Clean empty directories
                $this->cleanEmptyDirs();

            } catch (\Exception $e) {
                $success = false;
            }
        }

        // Reset stats
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => $count
        ];

        return $success;
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
        $filename = $this->getFilename($key);

        if (!is_file($filename)) {
            return false;
        }

        $data = $this->readFile($filename);

        if ($data === false) {
            return false;
        }

        // Check if expired
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
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
        return is_dir($this->path) && is_writable($this->path);
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        // Calculate total size including subdirectories
        $size = 0;
        $count = 0;
        $expired = 0;
        $now = time();

        if (is_dir($this->path)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->path, \RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && substr($file->getFilename(), -strlen($this->extension)) === $this->extension) {
                        $count++;
                        $size += $file->getSize();

                        // Check if expired
                        $data = $this->readFile($file->getPathname());
                        if ($data !== false && $data['expires'] !== 0 && $data['expires'] < $now) {
                            $expired++;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        return array_merge($this->stats, [
            'count' => $count,
            'size' => $size,
            'expired' => $expired,
            'path' => $this->path
        ]);
    }

    /**
     * Get the filename for a cache key.
     *
     * @param string $key The cache key.
     * @return string The filename.
     */
    protected function getFilename(string $key): string
    {
        // Create a safe filename from the key
        $hash = md5($key);

        // Use subdirectories to avoid too many files in one directory
        $dir = $this->path.substr($hash, 0, 2).'/';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir.$hash.$this->extension;
    }

    /**
     * Read data from a cache file.
     *
     * @param string $filename The filename.
     * @return array|false The data array or false on failure.
     */
    protected function readFile(string $filename)
    {
        $content = @file_get_contents($filename);

        if ($content === false) {
            return false;
        }

        $data = @unserialize($content);

        if ($data === false || !is_array($data)) {
            return false;
        }

        return $data;
    }

    /**
     * Write data to a cache file.
     *
     * @param string $filename The filename.
     * @param array $data The data to write.
     * @return bool True on success, false on failure.
     */
    protected function writeFile(string $filename, array $data): bool
    {
        $content = serialize($data);

        // Write to temp file first, then rename for atomic operation
        $tempFile = $filename.'.tmp';

        $result = @file_put_contents($tempFile, $content, LOCK_EX);

        if ($result === false) {
            return false;
        }

        return @rename($tempFile, $filename);
    }

    /**
     * Remove expired cache files.
     *
     * @return int Number of files removed.
     */
    public function gc(): int
    {
        $removed = 0;
        $dirs = glob($this->path.'*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return 0;
        }

        foreach ($dirs as $dir) {
            $files = glob($dir.'/*'.$this->extension);

            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $data = $this->readFile($file);

                if ($data === false || ($data['expires'] !== 0 && $data['expires'] < time())) {
                    if (@unlink($file)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
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
     * Get the cache directory path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Automatic garbage collection.
     * Runs GC once per day (or based on gcInterval).
     * Uses a lock file to track last GC time.
     *
     * @return bool True if GC was run, false otherwise.
     */
    protected function autoGc(): bool
    {
        $lockFile = $this->path.'.gc_lock';

        // Check if it's time to run GC
        if (is_file($lockFile)) {
            $lastGc = (int) @file_get_contents($lockFile);

            // Not time yet
            if ($lastGc > 0 && (time() - $lastGc) < $this->gcInterval) {
                // Probability-based GC as fallback (1 in gcProbability requests)
                if ($this->gcProbability > 0 && mt_rand(1, $this->gcProbability) !== 1) {
                    return false;
                }
            }
        }

        // Run GC
        $removed = $this->gc();

        // Update lock file with current timestamp
        @file_put_contents($lockFile, (string) time(), LOCK_EX);

        // Also clean up empty subdirectories
        $this->cleanEmptyDirs();

        return $removed > 0;
    }

    /**
     * Remove empty cache subdirectories.
     *
     * @return int Number of directories removed.
     */
    protected function cleanEmptyDirs(): int
    {
        $removed = 0;
        $dirs = glob($this->path.'*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return 0;
        }

        foreach ($dirs as $dir) {
            $files = glob($dir.'/*');

            if ($files === false || count($files) === 0) {
                if (@rmdir($dir)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Set the garbage collection interval.
     *
     * @param int $seconds Interval in seconds (default: 86400 = 24 hours).
     * @return self
     */
    public function setGcInterval(int $seconds): self
    {
        $this->gcInterval = $seconds;
        return $this;
    }

    /**
     * Set the garbage collection probability.
     *
     * @param int $probability Run GC 1 in $probability requests. Set 0 to disable.
     * @return self
     */
    public function setGcProbability(int $probability): self
    {
        $this->gcProbability = $probability;
        return $this;
    }

    /**
     * Force run garbage collection immediately.
     *
     * @return int Number of expired cache files removed.
     */
    public function forceGc(): int
    {
        $removed = $this->gc();

        // Update lock file
        $lockFile = $this->path.'.gc_lock';
        @file_put_contents($lockFile, (string) time(), LOCK_EX);

        // Clean empty dirs
        $this->cleanEmptyDirs();

        return $removed;
    }

    /**
     * Get the last garbage collection time.
     *
     * @return int|null Unix timestamp of last GC, or null if never run.
     */
    public function getLastGcTime(): ?int
    {
        $lockFile = $this->path.'.gc_lock';

        if (!is_file($lockFile)) {
            return null;
        }

        $time = (int) @file_get_contents($lockFile);
        return $time > 0 ? $time : null;
    }
}
