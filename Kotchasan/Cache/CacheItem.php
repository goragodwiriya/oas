<?php
/**
 * @filesource  Kotchasan/Cache/CacheItem.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Cache;

use Psr\Cache\CacheItemInterface;

/**
 * This class represents a cache item that implements the PSR-16 CacheItemInterface.
 *
 * @see https://www.kotchasan.com/
 */
class CacheItem implements CacheItemInterface
{
    /**
     * @var bool
     */
    private $hit;
    /**
     * Cache Key
     *
     * @var string
     */
    private $key;
    /**
     * Cache value
     *
     * @var mixed
     */
    private $value;

    /**
     * Class constructor
     *
     * @param string $key Cache Key
     */
    public function __construct($key)
    {
        $this->key = $key;
        $this->value = null;
        $this->hit = false;
    }

    /**
     * Set the expiration time of the cache item (in seconds)
     *
     * @param int|\DateInterval $time
     *
     * @return static
     */
    public function expiresAfter($time)
    {
        return $this;
    }

    /**
     * Set the expiration date and time of the cache item
     *
     * @param \DateTimeInterface $expiration
     *
     * @return static
     */
    public function expiresAt($expiration)
    {
        return $this;
    }

    /**
     * Get the value of the cache item
     *
     * @return mixed
     */
    public function get()
    {
        return $this->value;
    }

    /**
     * Get the key of the cache item
     *
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Check if the cache item has a value
     *
     * @return bool
     */
    public function isHit()
    {
        return $this->hit;
    }

    /**
     * Set the value of the cache item
     *
     * @param mixed $value
     *
     * @return static
     */
    public function set($value)
    {
        $this->value = $value;
        $this->hit = true;
        return $this;
    }
}
