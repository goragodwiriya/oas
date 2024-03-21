<?php
/**
 * @filesource Kotchasan/Inputs.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Input item array wrapper class.
 *
 * This class represents an array of input items.
 *
 * @see https://www.kotchasan.com/
 */
class Inputs implements \Iterator
{
    /**
     * The array to store the input item objects.
     *
     * @var array
     */
    private $datas = [];

    /**
     * Magic method to retrieve data for array type input.
     *
     * @param string $name
     * @param array  $arguments
     *
     * @throws \InvalidArgumentException If the requested method does not exist
     *
     * @return array The array of input item values
     */
    public function __call($name, $arguments)
    {
        if (method_exists('Kotchasan\InputItem', $name)) {
            $result = [];
            foreach ($this->datas as $key => $item) {
                $result[$key] = $this->collectInputs($item, $name, $arguments);
            }
            return $result;
        } else {
            throw new \InvalidArgumentException('Method '.$name.' not found');
        }
    }

    /**
     * Class constructor.
     *
     * @param array       $items The input items array
     * @param string|null $type  The input type (e.g., GET, POST, SESSION, COOKIE) or null if not derived from the above lists
     */
    public function __construct(array $items = [], $type = null)
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $this->datas[$k][$key] = InputItem::create($v, $type);
                }
            } else {
                $this->datas[$key] = InputItem::create($value, $type);
            }
        }
    }

    /**
     * Prepare the result for array type input.
     *
     * @param Object $item      The input item object
     * @param string $name      The method name to call
     * @param array  $arguments The method arguments
     *
     * @return array|object The array of input item values or an object
     */
    private function collectInputs($item, $name, $arguments)
    {
        if (is_array($item)) {
            $array = [];
            foreach ($item as $k => $v) {
                $array[$k] = $this->collectInputs($v, $name, $arguments);
            }
            return $array;
        }
        if (isset($arguments[1])) {
            return $item->$name($arguments[0], $arguments[1]);
        } elseif (isset($arguments[0])) {
            return $item->$name($arguments[0]);
        } else {
            return $item->$name($arguments);
        }
    }

    /**
     * Returns the current InputItem in the list.
     *
     * @return \Kotchasan\InputItem The current InputItem
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        $var = current($this->datas);
        return $var;
    }

    /**
     * Get the specified InputItem.
     *
     * @param string|int $key The key of the input item to retrieve
     *
     * @return \Kotchasan\InputItem The specified InputItem
     */
    #[\ReturnTypeWillChange]
    public function get($key)
    {
        return $this->datas[$key];
    }

    /**
     * Returns the key or index of the current InputItem in the list.
     *
     * @return string The key or index of the current InputItem
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        $var = key($this->datas);
        return $var;
    }

    /**
     * Returns the next InputItem in the list.
     *
     * @return \Kotchasan\InputItem The next InputItem
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $var = next($this->datas);
        return $var;
    }

    /**
     * Rewind the Iterator to the first InputItem in the list.
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        reset($this->datas);
    }

    /**
     * Checks if the current position of the Iterator is valid.
     *
     * @return bool True if the current position is valid, false otherwise
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        $key = key($this->datas);
        return $key !== null && $key !== false;
    }
}
