<?php

namespace Kotchasan;

/**
 * Kotchasan ArrayTool Class
 *
 * Utility methods for arrays and objects that are NOT available in native PHP
 * or provide significant convenience over native functions.
 *
 * @package Kotchasan
 */
class ArrayTool
{
    /**
     * Get values from an array or object based on the specified column key.
     * Similar to array_column() but works with both arrays and objects.
     *
     * @param iterable $source Array or object or array of objects
     * @param string $column_key Name of the column to retrieve
     * @param string|null $index_key Column to use as index (null = numeric index)
     *
     * @return array
     */
    public static function columns(iterable $source, string $column_key, ?string $index_key = null): array
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
     * Extract keys and values from a nested array recursively.
     *
     * @param array $array Source array (can be nested)
     * @param array $keys Reference array to store extracted keys
     * @param array $values Reference array to store extracted values
     */
    public static function extract(array $array, array &$keys, array &$values): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                self::extract($value, $keys, $values);
            } else {
                $keys[] = $key;
                $values[] = $value;
            }
        }
    }

    /**
     * Filter array items that contain the specified search string (case-insensitive).
     *
     * @param array $array Array to filter
     * @param string $search Search string
     *
     * @return array Filtered array with matching items
     */
    public static function filter(array $array, string $search): array
    {
        if ($search === '') {
            return $array;
        }

        $result = [];
        foreach ($array as $key => $value) {
            if (stripos(self::toString(' ', $value), $search) !== false) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * (No generic get() helper — use null coalescing: $array[$key] ?? $default)

    /**
     * Get the first key of an array or object. Returns null if no keys are found.
     *
     * Note: For arrays in PHP 7.3+, use array_key_first() instead.
     * This method is kept for object support.
     *
     * @param array|object $source Array or object
     *
     * @return string|int|null
     */
    public static function getFirstKey($source)
    {
        if (is_array($source)) {
            return array_key_first($source);
        }
        if (is_object($source)) {
            $keys = array_keys(get_object_vars($source));
            return $keys[0] ?? null;
        }
        return null;
    }

    /**
     * Insert data into an array after the specified key.
     * If key not found, inserts at the end.
     *
     * @param array $source Array to insert into
     * @param string|int $find Key to search for
     * @param string|int $key Key for the new data
     * @param mixed $value Data to insert
     *
     * @return array Modified array
     */
    public static function insertAfter(array $source, $find, $key, $value): array
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
     * Insert data into an array before the specified key.
     * If key not found, inserts at the end.
     *
     * @param array $source Array to insert into
     * @param string|int $find Key to search for
     * @param string|int $key Key for the new data
     * @param mixed $value Data to insert
     *
     * @return array Modified array
     */
    public static function insertBefore(array $source, $find, $key, $value): array
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
     * Merge/replace values from $replace into $source (works with arrays and objects).
     *
     * @param array|object $source Base array or object
     * @param array|object $replace Values to merge/replace
     *
     * @return array|object Modified source
     */
    public static function replace($source, $replace)
    {
        $isArray = is_array($source);
        foreach ($replace as $key => $value) {
            if ($isArray) {
                $source[$key] = $value;
            } else {
                $source->$key = $value;
            }
        }
        return $source;
    }

    /**
     * Search array of arrays/objects for items with matching key-value pair.
     *
     * @param array $array Array of arrays or objects to search
     * @param string $key Key/property to match
     * @param mixed $search Value to match (strict comparison)
     *
     * @return array Matching items with original keys preserved
     */
    public static function search(array $array, string $key, $search): array
    {
        $result = [];

        foreach ($array as $i => $values) {
            if (
                (is_array($values) && ($values[$key] ?? null) === $search) ||
                (is_object($values) && ($values->$key ?? null) === $search)
            ) {
                $result[$i] = $values;
            }
        }

        return $result;
    }

    /**
     * Removes elements from an array starting from a specified key.
     * All elements before that key are removed.
     *
     * @param array $source The source array
     * @param string|int $key The key to start keeping elements from
     *
     * @return array Elements from the specified key onwards
     */
    public static function shift(array $source, $key): array
    {
        $found = false;
        $result = [];

        foreach ($source as $k => $v) {
            if ($k == $key) {
                $found = true;
            }
            if ($found) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Sort array of associative arrays/objects by a specified key.
     *
     * @param array $array Array to sort
     * @param string $sort_key Key to sort by
     * @param bool $descending Sort descending (default: ascending)
     *
     * @return array Sorted array (re-indexed)
     */
    public static function sort(array $array, string $sort_key = 'id', bool $descending = false): array
    {
        if (empty($array)) {
            return $array;
        }

        usort($array, function ($a, $b) use ($sort_key) {
            $v1 = strtolower(self::toString('', $a[$sort_key] ?? ''));
            $v2 = strtolower(self::toString('', $b[$sort_key] ?? ''));
            return $v1 <=> $v2;
        });

        return $descending ? array_reverse($array) : $array;
    }

    /**
     * Convert nested array/object to string by joining values recursively.
     *
     * @param string $glue Separator between values
     * @param mixed $source Array, object, or scalar value
     *
     * @return string Concatenated string
     */
    public static function toString(string $glue, $source): string
    {
        if (!is_array($source) && !is_object($source)) {
            return (string) $source;
        }

        $result = [];
        foreach ($source as $value) {
            $result[] = (is_array($value) || is_object($value))
            ? self::toString($glue, $value)
            : $value;
        }

        return implode($glue, $result);
    }

    /**
     * Unserialize a string and update the source array with the unserialized data.
     *
     * @param string $str The serialized string
     * @param array $source (Optional) Array to update with unserialized data
     * @param bool $replace (Optional) Whether to replace existing values in the source array
     *
     * @return array The updated source array
     */
    public static function unserialize($str, $source = [], $replace = true)
    {
        $datas = json_decode($str, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $datas = @unserialize($str);
        }

        if (is_array($datas)) {
            foreach ($datas as $key => $value) {
                if ($replace || !isset($source[$key])) {
                    $source[$key] = $value;
                }
            }
        }

        return $source;
    }

    /**
     * Check if any value in the needle array exists in the haystack array.
     *
     * Unlike native in_array(), this accepts an array of needles and returns true
     * if ANY of them is found in the haystack.
     *
     * @param array $needles Array of values to search for
     * @param array $haystack Array to search in
     * @param bool $strict Perform strict type comparison
     *
     * @return bool True if any value is found
     */
    public static function inArrayAny(array $needles, array $haystack, bool $strict = false): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $haystack, $strict)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Alias note: Use `inArrayAny()` instead of providing an alias to native `in_array`.
     *
     * (No deprecated compatibility aliases are provided in this class.)
     */

    /**
     * Retrieve the key of the element following a specified key in the array.
     *
     * @param array $array The input array
     * @param string|int $key The key to find the next key after
     *
     * @return string|int|null The next key, or null if not found or at end
     */
    public static function getNextKey(array $array, $key)
    {
        if (!array_key_exists($key, $array)) {
            return null;
        }

        $keys = array_keys($array);
        $index = array_search($key, $keys, true);

        return $keys[$index + 1] ?? null;
    }
}
