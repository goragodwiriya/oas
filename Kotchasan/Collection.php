<?php
/**
 * @filesource Kotchasan/Collection.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Collection Class
 *
 * @see https://www.kotchasan.com/
 */
class Collection implements \Countable, \IteratorAggregate, \ArrayAccess
{
    /**
     * Holds the class members.
     *
     * @var array
     */
    private $datas = [];

    /**
     * Create a new collection.
     *
     * @param array $items The initial members of the Collection.
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Clear all data.
     *
     * @return void
     */
    public function clear()
    {
        $this->datas = [];
    }

    /**
     * Get the count of all data.
     *
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return \count($this->datas);
    }

    /**
     * Get the value at $key, or $default if not found.
     *
     * @param mixed $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->has($key) ? $this->datas[$key] : $default;
    }

    /*   * **********************
     * IteratorAggregate interface
     * ************************* */

    /**
     * Retrieve an external iterator.
     *
     * @return \ArrayIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->datas);
    }

    /**
     * Check if $key exists.
     *
     * @param mixed $key
     *
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->datas);
    }

    /**
     * Get a list of keys.
     *
     * @return array
     */
    public function keys()
    {
        return array_keys($this->datas);
    }

    /*   * *****************
     * ArrayAccess interface
     * ********************* */

    /**
     * Check if $key exists.
     *
     * @param mixed $key
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($key)
    {
        return $this->has($key);
    }

    /**
     * Get the value at $key.
     *
     * @param mixed $key
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->get($key);
    }

    /**
     * Set the $value of $key.
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        $this->set($key, $value);
    }

    /**
     * Remove the item at $key.
     *
     * @param mixed $key
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        $this->remove($key);
    }

    /**
     * Remove the item at $key.
     *
     * @param mixed $key
     *
     * @return void
     */
    public function remove($key)
    {
        unset($this->datas[$key]);
    }

    /**
     * Add new items, replacing existing items.
     *
     * @param array $items array(array($key => $value), array($key => $value), ...)
     *
     * @return void
     */
    public function replace(array $items)
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value);
        }
    }

    /*   * ****************
     * Collection interface
     * ******************* */

    /**
     * Set the $value of $key.
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    public function set($key, $value)
    {
        $this->datas[$key] = $value;
    }

    /**
     * Get all data as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->datas;
    }
}
