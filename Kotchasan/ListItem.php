<?php
/**
 * @filesource Kotchasan/ListItem.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class ListItem
 *
 * This class handles array manipulation.
 *
 * @see https://www.kotchasan.com/
 */
class ListItem
{
    /**
     * @var array The data.
     */
    public $datas;

    /**
     * @var string The source file path.
     */
    private $source;

    /**
     * Assigns multiple items to the data array.
     *
     * @param array $array The data to assign.
     */
    public function assign($array)
    {
        if (isset($this->datas)) {
            $this->datas = array_merge($this->datas, $array);
        } else {
            $this->datas = $array;
        }
    }

    /**
     * Clears all data.
     */
    public function clear()
    {
        unset($this->datas);
    }

    /**
     * Retrieves the total number of items.
     *
     * @return int The total number of items.
     */
    public function count()
    {
        return count($this->datas);
    }

    /**
     * Deletes the specified item.
     * Returns true if successful, false if not found.
     *
     * @param string $key The key of the item to delete.
     *
     * @return bool True if the item is deleted successfully, false otherwise.
     */
    public function delete($key)
    {
        if (array_key_exists($key, $this->datas)) {
            unset($this->datas[$key]);
            return true;
        }
        return false;
    }

    /**
     * Retrieves the first item in the array.
     *
     * @return mixed The first item.
     */
    public function firstItem()
    {
        return reset($this->datas);
    }

    /**
     * Retrieves the value at the specified key.
     * Returns the item at the key if found, null otherwise.
     *
     * @param string $key The key to retrieve.
     *
     * @return mixed The item at the key, or null if not found.
     */
    public function get($key)
    {
        return array_key_exists($key, $this->datas) ? $this->datas[$key] : null;
    }

    /**
     * Searches for a value in the array.
     * Returns the key of the found item if found, false otherwise.
     *
     * @param mixed $value The value to search for.
     *
     * @return mixed The key of the found item, or false if not found.
     */
    public function indexOf($value)
    {
        return array_search($value, $this->datas);
    }

    /**
     * Initializes the class with default values.
     *
     * @param array $config The configuration values.
     */
    public function init($config)
    {
        $this->datas = $config;
    }

    /**
     * Inserts a new item after the specified key.
     *
     * @param mixed $key  The key after which the new item will be inserted.
     * @param mixed $item The new item.
     */
    public function insert($key, $item)
    {
        if (is_int($key) && $key == count($this->datas)) {
            $this->datas[] = $item;
        } else {
            $temp = $this->datas;
            $this->datas = [];
            foreach ($temp as $k => $value) {
                $this->datas[$k] = $value;
                if ($k == $key) {
                    $this->datas[$key] = $item;
                }
            }
        }
    }

    /**
     * Inserts a new item before the specified key.
     *
     * @param mixed $key  The key before which the new item will be inserted.
     * @param mixed $item The new item.
     */
    public function insertBefore($key, $item)
    {
        $temp = $this->datas;
        $this->datas = [];
        foreach ($temp as $k => $value) {
            if ($k == $key) {
                $this->datas[$key] = $item;
            }
            $this->datas[$k] = $value;
        }
    }

    /**
     * Retrieves all items.
     *
     * @return array The array of items.
     */
    public function items()
    {
        return $this->datas;
    }

    /**
     * Retrieves the list of keys.
     *
     * @return array The array of keys.
     */
    public function keys()
    {
        return array_keys($this->datas);
    }

    /**
     * Retrieves the last item in the array.
     *
     * @return mixed The last item.
     */
    public function lastItem()
    {
        return end($this->datas);
    }

    /**
     * Loads the array from a file.
     *
     * @param string $file The file path to load, including the path.
     *
     * @return static
     */
    public function loadFromFile($file)
    {
        if (is_file($file)) {
            $config = include $file;
            $this->source = $file;
            $this->assign($config);
        }
        return $this;
    }

    /**
     * Saves the array to a file.
     *
     * @return bool True if the array is successfully saved, false otherwise.
     */
    public function saveToFile()
    {
        if (!isset($this->source) || empty($this->datas)) {
            return false;
        } else {
            $datas = [];
            foreach ($this->datas as $key => $value) {
                if (is_array($value)) {
                    $datas[] = (is_int($key) ? $key : "'".strtolower($key)."'")." => array(\n".$this->arrayToString(1, $value)."\n\t)";
                } else {
                    $datas[] = (is_int($key) ? $key : "'".strtolower($key)."'").' => '.(is_int($value) ? $value : "'".addslashes($value)."'");
                }
            }
            $file = str_replace(ROOT_PATH, '', $this->source);
            $f = @fopen(ROOT_PATH.$file, 'w');
            if ($f === false) {
                return false;
            } else {
                fwrite($f, "<?php\n/* $file */\nreturn array (\n\t".implode(",\n\t", $datas)."\n);");
                fclose($f);
                return true;
            }
        }
    }

    /**
     * Sets a value at the specified key.
     *
     * @param string $key   The key to set.
     * @param mixed  $value The value to set.
     */
    public function set($key, $value)
    {
        $this->datas[$key] = $value;
    }

    /**
     * Retrieves all values.
     *
     * @return array The array of values.
     */
    public function values()
    {
        return array_values($this->datas);
    }

    /**
     * Converts an array to a string.
     *
     * @param int   $indent The indentation level.
     * @param array $array  The array to convert.
     *
     * @return string The string representation of the array.
     */
    private function arrayToString($indent, $array)
    {
        $t = str_repeat("\t", $indent + 1);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $datas[] = (is_int($key) ? $key : "'$key'")." => array(\n".$this->arrayToString($indent + 1, $value)."\n$t)";
            } else {
                $datas[] = (is_int($key) ? $key : "'$key'").' => '.(is_int($value) ? $value : "'".addslashes($value)."'");
            }
        }
        return $t.implode(",\n$t", $datas);
    }
}
