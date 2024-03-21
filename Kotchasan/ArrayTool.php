<?php
/**
 * @filesource Kotchasan/ArrayTool.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Array function class
 *
 * @see https://www.kotchasan.com/
 */
class ArrayTool
{
    /**
     * Get values from an array or object based on the specified column key.
     *
     * @assert (array((object)array('id' => 1, 'name' => 'one'), (object)array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name') [==] array(0 => 'one', 1 => 'two', 2 => 'three')
     * @assert (array((object)array('id' => 1, 'name' => 'one'), (object)array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name', 'id') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name') [==] array(0 => 'one', 1 => 'two', 2 => 'three')
     * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name', 'id') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert ((object)array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name', 'id') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     *
     * @param mixed $source Array or object or array of objects
     * @param string $column_key Name of the column to retrieve
     * @param mixed $index_key Null to return the index of $source, string to return the index based on the specified column
     *
     * @return array
     */
    public static function columns($source, $column_key, $index_key = null)
    {
        $result = [];
        foreach ($source as $key => $item) {
            if (is_object($item) && isset($item->$column_key)) {
                $result[$index_key !== null ? $item->$index_key : $key] = $item->$column_key;
            } elseif (is_array($item) && isset($item[$column_key])) {
                $result[$index_key !== null ? $item[$index_key] : $key] = $item[$column_key];
            }
        }
        return $result;
    }

    /**
     * Delete array keys specified by $ids. Preserves the keys of the original array.
     *
     * @assert (array(0, 1, 2, 3, 4, 5), '0,2') [==] array(1 => 1, 3 => 3, 4 => 4, 5 => 5)
     * @assert (array(0, 1, 2, 3, 4, 5), array(0, 2)) [==] array(1 => 1, 3 => 3, 4 => 4, 5 => 5)
     * @assert (array(0, 1, 2, 3, 4, 5), 2) [==] array(0 => 0, 1 => 1, 3 => 3, 4 => 4, 5 => 5)
     * @assert (array('one' => 1, 'two' => 2, 'three' => 3), 'two') [==] array('one' => 1, 'three' => 3)
     *
     * @param array $array
     * @param mixed $ids Items to delete. Can be a single value, comma-separated values, or an array of values. eg. 1 or '1,2,3' or [1,2,3]
     *
     * @return array
     */
    public static function delete($array, $ids)
    {
        $ids = is_array($ids) ? $ids : explode(',', $ids);
        return array_diff_key($array, array_flip($ids));
    }

    /**
     * Extract keys and values from an array (supports nested arrays).
     *
     * @param array $array Array to extract eg. array('key1' => 'value1', 'key2' => 'value2', array('key3' => 'value3', 'key4' => 'value4'))
     * @param array $keys Reference to an array to store the keys eg. Array ( [0] => key1 [1] => key2 [2] => key3 [3] => key4 )
     * @param array $values Reference to an array to store the values eg. Array ( [0] => value1 [1] => value2 [2] => value3 [3] => value4 )
     */
    public static function extract($array, &$keys, &$values)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                self::extract($array[$key], $keys, $values);
            } else {
                $keys[] = $key;
                $values[] = $value;
            }
        }
    }

    /**
     * Filter array items that contain the specified search string.
     *
     * @assert (array('one', 'One', 'two'), 'one') [==] array('one', 'One')
     *
     * @param array $array Array to filter
     * @param string $search Search string
     *
     * @return array
     */
    public static function filter($array, $search)
    {
        if ($search == '') {
            return $array;
        } else {
            $result = [];
            foreach ($array as $key => $value) {
                if (stripos(self::toString(' ', $value), $search) !== false) {
                    $result[$key] = $value;
                }
            }
            return $result;
        }
    }

    /**
     * Get the value from an array based on the specified key. Return the default value if the key is not found.
     *
     * @assert (array('one', 'two', 'three'), 0, '') [==] 'one'
     * @assert (array('one', 'two', 'three'), 4, '') [==] ''
     *
     * @param array $array Array to retrieve the value from
     * @param mixed $key Key to search for
     * @param mixed $default Default value to return if the key is not found
     *
     * @return mixed
     */
    public static function get($array, $key, $default = '')
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    /**
     * Get the first key of an array or object. Returns null if no keys are found.
     *
     * @assert (array('one', 'two', 'three')) [==] 0
     * @assert (array('one' => 1, 'two' => 2, 'three' => 3)) [==] 'one'
     * @assert ((object)array('one' => 1, 'two' => 2, 'three' => 3)) [==] 'one'
     * @assert ([]) [==] null
     * @assert (0) [==] null
     *
     * @param mixed $source Array or object
     *
     * @return mixed|null
     */
    public static function getFirstKey($source)
    {
        if (is_array($source)) {
            reset($source);
            return key($source);
        } elseif (is_object($source)) {
            $keys = array_keys(get_object_vars($source));
            return isset($keys[0]) ? $keys[0] : null;
        }
        return null;
    }

    /**
     * Insert data into an array after the specified key. If the key is not found, insert at the end of the array.
     *
     * @assert (array('one' => 1, 'two' => 2), 'two', 'three', 3) [==] array('one' => 1, 'two' => 2, 'three' => 3)
     * @assert (array(1 => 'one', 2 => 'two'), 1, 3, 'three') [==] array(1 => 'one', 3 => 'three', 2 => 'two')
     * @assert (array(1 => 'one', 2 => 'two'), 2, 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert (array(1 => 'one', 2 => 'two'), 3, 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert (array(1 => 'one', 2 => 'two'), '1', 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     *
     * @param array $source Array to insert into
     * @param mixed $find Key to search for
     * @param mixed $key Key of the data to insert
     * @param mixed $value Data to insert
     *
     * @return array
     */
    public static function insertAfter($source, $find, $key, $value)
    {
        $result = [];
        $inserted = false;
        foreach ($source as $k => $v) {
            $result[$k] = $v;
            if ($k === $find) {
                $result[$key] = $value;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Insert data into an array before the specified key. If the key is not found, insert at the end of the array.
     *
     * @assert (array('one' => 1, 'three' => 3), 'three', 'two', 2) [==] array('one' => 1, 'two' => 2, 'three' => 3)
     * @assert (array(1 => 'one', 3 => 'three'), 3, 2, 'two') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert (array(1 => 'one', 3 => 'three'), 2, 2, 'two') [==] array(1 => 'one', 3 => 'three', 2 => 'two')
     * @assert (array(1 => 'one', 2 => 'two'), 1, 3, 'three') [==] array(3 => 'three', 1 => 'one', 2 => 'two')
     * @assert (array(1 => 'one', 2 => 'two'), 2, 3, 'three') [==] array(1 => 'one', 3 => 'three', 2 => 'two')
     * @assert (array(1 => 'one', 2 => 'two'), 3, 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert (array(1 => 'one', 2 => 'two'), '1', 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     *
     * @param array $source Array to insert into
     * @param mixed $find Key to search for
     * @param mixed $key Key of the data to insert
     * @param mixed $value Data to insert
     *
     * @return array
     */
    public static function insertBefore($source, $find, $key, $value)
    {
        $result = [];
        $inserted = false;
        foreach ($source as $k => $v) {
            if ($k === $find) {
                $result[$key] = $value;
                $inserted = true;
            }
            $result[$k] = $v;
        }
        if (!$inserted) {
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Replace values in an array or object with the specified replacement values.
     *
     * @assert (array(1 => 1, 2 => 2, 3 => 'three'), array(1 => 'one', 2 => 'two')) [==] array(1 => 'one', 2 => 'two', 3 => 'three')
     * @assert ((object)array('one' => 1), array('two' => 2)) [==] (object)array('one' => 1, 'two' => 2)
     * @assert ((object)array('one' => 1), (object)array('two' => 2)) [==] (object)array('one' => 1, 'two' => 2)
     *
     * @param mixed $source Array or object to replace values in
     * @param array $replace Associative array of replacement values (key => value)
     *
     * @return mixed The modified source array or object
     */
    public static function replace($source, $replace)
    {
        $isArray = is_array($source);

        foreach ($replace as $key => $value) {
            if ($isArray) {
                // If the source is an array, replace the value at the specified key
                $source[$key] = $value;
            } else {
                // If the source is an object, replace the value of the specified property
                $source->$key = $value;
            }
        }
        return $source;
    }

    /**
     * Searches an array for elements with a specific key-value pair and returns the matching elements.
     *
     * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'one')), 'name', 'one') [==] array(0 => array('id' => 1, 'name' => 'one'), 2 => array('id' => 3, 'name' => 'one'))
     * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'one')), 'id', 'one') [==] []
     * @assert (array((object)array('id' => 1, 'name' => 'one'), (object)array('id' => 2, 'name' => 'two'), (object)array('id' => 3, 'name' => 'one')), 'name', 'one') [==] array(0 => (object)array('id' => 1, 'name' => 'one'), 2 => (object)array('id' => 3, 'name' => 'one'))
     * @assert (array((object)array('id' => 1, 'name' => 'one'), (object)array('id' => 2, 'name' => 'two'), (object)array('id' => 3, 'name' => 'one')), 'id', 'one') [==] []
     *
     * @param array $array The array to search
     * @param string $key The key to search for
     * @param mixed $search The value to search for
     *
     * @return array The matching elements
     */
    public static function search($array, $key, $search)
    {
        $result = [];

        foreach ($array as $i => $values) {
            if (
                (is_array($values) && isset($values[$key]) && $values[$key] === $search) ||
                (is_object($values) && isset($values->$key) && $values->$key === $search)
            ) {
                $result[$i] = $values;
            }
        }

        return $result;
    }

    /**
     * Removes an element from an array by key and returns the resulting array.
     *
     * @assert (array('one' => 1, 'two' => 2, 'three' => 3), 'two') [==] array('three' => 3)
     * @assert (array('one' => 1, 'two' => 2, 'three' => 3), 1) [==] array('one' => 1, 'two' => 2, 'three' => 3)
     *
     * @param array $source The source array
     * @param mixed $key The key of the element to remove
     *
     * @return array The resulting array after removing the element
     */
    public static function shift($source, $key)
    {
        $result = [];

        foreach ($source as $k => $v) {
            if ($k == $key) {
                $result = [];
            } else {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Sorts an array of associative arrays by a specified key in ascending or descending order.
     *
     * @assert (array(array('id' => 2, 'value' => 'two'), array('id' => 3, 'value' => 'three'), array('id' => 1, 'value' => 'one'))) [==] array(array('id' => 1, 'value' => 'one'), array('id' => 2, 'value' => 'two'), array('id' => 3, 'value' => 'three'))
     *
     * @param array $array The array to sort
     * @param string $sort_key The key to sort the array by
     * @param bool $sort_desc Whether to sort the array in descending order
     *
     * @return array The sorted array
     */
    public static function sort($array, $sort_key = 'id', $sort_desc = false)
    {
        if (!empty($array) && is_array($array)) {
            usort($array, function ($a, $b) use ($sort_key) {
                $v1 = isset($a[$sort_key]) ? strtolower(self::toString('', $a[$sort_key])) : '';
                $v2 = isset($b[$sort_key]) ? strtolower(self::toString('', $b[$sort_key])) : '';
                return $v1 == $v2 ? 0 : ($v1 < $v2 ? -1 : 1);
            });

            if ($sort_desc) {
                $array = array_reverse($array); // Reverse the array if sort_desc is true
            }

            return $array;
        }

        return $array; // Return the input array if it's empty or not an array
    }

    /**
     * Convert a nested array or object into a string by concatenating its values with a glue.
     *
     * @assert ('|', array('a' => 'A', 'b' => array('b', 'B'), 'c' => array('c' => array('c', 'C')))) [==] "A|b|B|c|C"
     * @assert ('|', (object)array('a' => 'A', 'b' => array('b', 'B'), 'c' => array('c' => array('c', 'C')))) [==] "A|b|B|c|C"
     * @assert ('|', 'one') [==] 'one'
     * @assert ('|', 1) [==] 1
     *
     * @param string $glue The glue to join the values with
     * @param array|object $source The source array or object
     *
     * @return string The concatenated string
     */
    public static function toString($glue, $source)
    {
        if (is_array($source) || is_object($source)) {
            $result = [];

            foreach ($source as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $result[] = self::toString($glue, $value); // Recursively process nested arrays or objects
                } else {
                    $result[] = $value;
                }
            }

            return implode($glue, $result);
        } else {
            return (string) $source; // Convert non-array, non-object values to string
        }
    }

    /**
     * Unserialize a string and update the source array with the unserialized data.
     *
     * @assert ('') [==] []
     * @assert (serialize(array(1, 2, 3))) [==] array(1, 2, 3)
     * @assert (serialize(array(1 => 'One', 2 => 'Two', 3 => 'Three')), array(3 => 3, 4 => 'Four'), true) [==] array(3 => 'Three', 4 => 'Four', 1 => 'One', 2 => 'Two')
     * @assert (serialize(array(1 => 'One', 2 => 'Two', 3 => 'Three')), array(3 => 3, 4 => 'Four'), false) [==] array(3 => 3, 4 => 'Four', 1 => 'One', 2 => 'Two')
     *
     * @param string $str The serialized string
     * @param array $source (Optional) Array to update with unserialized data
     * @param bool $replace (Optional) Whether to replace existing values in the source array
     *
     * @return array The updated source array
     */
    public static function unserialize($str, $source = [], $replace = true)
    {
        try {
            $datas = unserialize($str);

            if (is_array($datas)) {
                foreach ($datas as $key => $value) {
                    if ($replace || !isset($source[$key])) {
                        $source[$key] = $value;
                    }
                }
            }
        } catch (\Throwable $th) {
            // Handle any exceptions thrown during unserialization
        }

        return $source;
    }

    /**
     * Check if any value in the needle array exists in the haystack array.
     *
     * @assert (array('12.4'), array('1.10', 12.4, 1.13)) [==] true
     * @assert (array('12.4'), array('1.10', 12.4, 1.13), true) [==] false
     * @assert (array(1, 2), array('1', 2)) [==] true
     * @assert (array('1', 2), array(1, 2)) [==] true
     * @assert (array(1), array('1', 2), true) [==] false
     * @assert (array('1'), array(1, 2), true) [==] false
     * @assert (array(1, 2), array(1, 2)) [==] true
     * @assert (array(2), array(1, 2, 3)) [==] true
     * @assert (array(1, 2), array(3, 4)) [==] false
     * @assert ([], array(3, 4)) [==] false
     * @assert ([], []) [==] false
     * @assert (array('q', array('p', 'h')), array(array('p', 'h'), array('p', 'r'), 'o')) [==] true
     * @assert (array('r', 'h'), array(array('p', 'h'), array('p', 'r'), 'o')) [==] false
     * @assert (array('f', 'i'), array(array('p', 'h'), array('p', 'r'), 'o')) [==] false
     * @assert (array('o'), array(array('p', 'h'), array('p', 'r'), 'o')) [==] true
     *
     * @param mixed $needle Value or array of values to search for
     * @param array $haystack Array to search in
     * @param bool $strict (Optional) Perform strict comparison when checking values
     *
     * @return bool True if any value is found, false otherwise
     */
    public static function in_array($needle, $haystack, $strict = false)
    {
        // Ensure the needle is an array
        if (!is_array($needle)) {
            $needle = [$needle];
        }

        foreach ($needle as $value) {
            // Check if the value exists in the haystack array
            if (in_array($value, $haystack, $strict)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the key of the element following a specified key in the array.
     *
     * @param array $array The input array.
     * @param mixed $key The key to find the next key after.
     * @return mixed|false The key of the element following the specified key, or false if not found.
     */
    public static function getNextKey($array, $key)
    {
        // Check if the specified key exists in the array
        if (isset($array[$key])) {
            // Get the current key of the array cursor
            $currentKey = key($array);

            // Iterate through the array until the current key matches the specified key
            while ($currentKey !== null && $currentKey != $key) {
                next($array); // Move the array cursor to the next element
                $currentKey = key($array); // Update the current key
            }

            next($array); // Move the array cursor to the next element
            return key($array); // Return the key of the next element
        }

        // If the specified key is not found in the array, return false
        return false;
    }
}
