<?php
namespace Psr\Cache;

/**
 * CacheItemInterface defines an interface for interacting with objects inside a cache.
 */
interface CacheItemInterface
{
    /**
     * Returns the key for the current cache item.
     *
     * The key is loaded by the Implementing Library, but should be available to
     * the higher level callers when needed.
     *
     *   The key string for this cache item.
     *
     * @return string
     */
    public function getKey();

    /**
     * Retrieves the value of the item from the cache associated with this object's key.
     *
     * The value returned must be identical to the value originally stored by set().
     *
     * If isHit() returns false, this method MUST return null. Note that null
     * is a legitimate cached value, so the isHit() method SHOULD be used to
     * differentiate between "null value was found" and "no value was found."
     *
     *   The value corresponding to this cache item's key, or null if not found.
     *
     * @return mixed
     */
    public function get();

    /**
     * Confirms if the cache item lookup resulted in a cache hit.
     *
     * Note: This method MUST NOT have a race condition between calling isHit()
     * and calling get().
     *
     *   True if the request resulted in a cache hit. False otherwise.
     *
     * @return bool
     */
    public function isHit();

    /**
     * Sets the value represented by this cache item.
     *
     * The $value argument may be any item that can be serialized by PHP,
     * although the method of serialization is left up to the Implementing
     * Library.
     *
     *   The serializable value to be stored.
     *   The invoked object.
     *
     * @param mixed $value
     *
     * @return static
     */
    public function set($value);

    /**
     * Sets the expiration time for this cache item.
     *
     *   The point in time after which the item MUST be considered expired.
     *   If null is passed explicitly, a default value MAY be used. If none is set,
     *   the value should be stored permanently or for as long as the
     *   implementation allows.
     *   The called object.
     *
     * @param \DateTimeInterface $expiration
     *
     * @return static
     */
    public function expiresAt($expiration);

    /**
     * Sets the expiration time for this cache item.
     *
     *   The period of time from the present after which the item MUST be considered
     *   expired. An integer parameter is understood to be the time in seconds until
     *   expiration. If null is passed explicitly, a default value MAY be used.
     *   If none is set, the value should be stored permanently or for as long as the
     *   implementation allows.
     *   The called object.
     *
     * @param int|\DateInterval $time
     *
     * @return static
     */
    public function expiresAfter($time);
}
