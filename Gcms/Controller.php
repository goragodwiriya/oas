<?php
/**
 * @filesource Gcms/Controller.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * Controller base class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\KBase
{
    /**
     * Get province options
     *
     * @return array
     */
    public static function getProvinceOptions()
    {
        $provinces = \Kotchasan\Province::all();

        return self::arrayToOptions($provinces);
    }

    /**
     * Get gender options
     *
     * @return array
     */
    public static function getGenderOptions()
    {
        return [
            ['value' => '', 'text' => 'Not specified'],
            ['value' => 'm', 'text' => 'Male'],
            ['value' => 'f', 'text' => 'Female']
        ];
    }

    /**
     * Get user status options
     *
     * @return array
     */
    public static function getUserStatusOptions()
    {
        return self::arrayToOptions(self::$cfg->member_status);
    }

    /**
     * Get user active options
     *
     * @return array
     */
    public static function getUserActiveOptions()
    {
        return [
            ['value' => '1', 'text' => 'Active'],
            ['value' => '0', 'text' => 'Inactive']
        ];
    }

    /**
     * Get permission options
     *
     * @param object|null $login
     *
     * @return array
     */
    public static function getPermissionOptions($login = null)
    {
        $permissions = [
            ['value' => 'can_config', 'text' => '{LNG_Can configure} {LNG_the system}'],
            ['value' => 'can_view_usage_history', 'text' => '{LNG_Can view} {LNG_system usage history}']
        ];

        // Load module permission
        return \Gcms\Controller::initModule($permissions, 'initPermission', $login);
    }

    /**
     * convert array to options
     *
     * @param  array $array
     *
     * @return array
     */
    public static function arrayToOptions(array $array): array
    {
        $options = [];
        foreach ($array as $key => $value) {
            $options[] = [
                'value' => (string) $key,
                'text' => $value
            ];
        }
        return $options;
    }

    /**
     * Load permissions of various modules
     *
     * @param array $datas
     * @param string $method
     * @param object|null $login
     * @param mixed $params
     *
     * @return array
     */
    public static function initModule($datas, $method, $login = null, &$params = null)
    {
        // Load module settings
        $dir = ROOT_PATH.'modules/';
        $f = @opendir($dir);
        if ($f) {
            while (false !== ($text = readdir($f))) {
                if ($text != '.' && $text != '..' && $text != 'index' && is_dir($dir.$text)) {
                    if (is_file($dir.$text.'/controllers/init.php')) {
                        require_once $dir.$text.'/controllers/init.php';
                        $className = '\\'.ucfirst($text).'\Init\Controller';
                        if (method_exists($className, $method)) {
                            $datas = $className::$method($datas, $params, $login);
                        }
                    }
                }
            }
            closedir($f);
        }
        return $datas;
    }

    /**
     * Safely insert menu items after a specific position or key
     *
     * @param array $menus The menu array to modify
     * @param array $items The menu items to insert (indexed array)
     * @param int|string $position Position (0-indexed) or key name to insert after
     *
     * @return array Modified menu array
     */
    public static function insertMenuAfter($menus, $items, $position = 0)
    {
        if (empty($menus)) {
            return $items;
        }

        // If position is a string (key name), find its numeric position
        if (is_string($position)) {
            $keys = array_keys($menus);
            $keyPosition = array_search($position, $keys);
            if ($keyPosition === false) {
                // Key not found, append to end
                return array_merge($menus, $items);
            }
            $position = $keyPosition;
        }

        // Ensure position is valid
        $position = max(0, min($position, count($menus) - 1));

        // Insert after the position
        array_splice($menus, $position + 1, 0, $items);

        return $menus;
    }

    /**
     * Safely insert menu items before a specific position or key
     *
     * @param array $menus The menu array to modify
     * @param array $items The menu items to insert (indexed array)
     * @param int|string $position Position (0-indexed) or key name to insert before
     *
     * @return array Modified menu array
     */
    public static function insertMenuBefore($menus, $items, $position = 0)
    {
        if (empty($menus)) {
            return $items;
        }

        // If position is a string (key name), find its numeric position
        if (is_string($position)) {
            $keys = array_keys($menus);
            $keyPosition = array_search($position, $keys);
            if ($keyPosition === false) {
                // Key not found, prepend to start
                return array_merge($items, $menus);
            }
            $position = $keyPosition;
        }

        // Ensure position is valid
        $position = max(0, min($position, count($menus)));

        // Insert before the position
        array_splice($menus, $position, 0, $items);

        return $menus;
    }

    /**
     * Safely insert menu items into a menu's children (or a named sub-group's children)
     *
     * @param array       $menus     The menu array to modify
     * @param array       $items     The menu items to insert
     * @param string      $parentKey The parent menu key (default: 'settings')
     * @param string|null $subKey    Optional named key inside children to target (e.g. 'modules', 'widgets')
     * @param int|null    $position  Position to insert at (0-indexed, null = append)
     *
     * @return array Modified menu array
     */
    public static function insertMenuChildren($menus, $items, $parentKey = 'settings', $subKey = null, $position = null)
    {
        // Check if parent menu exists
        if (!isset($menus[$parentKey])) {
            return $menus;
        }

        // Ensure top-level children array exists
        if (!isset($menus[$parentKey]['children'])) {
            $menus[$parentKey]['children'] = [];
        }

        if ($subKey !== null) {
            // Target a named sub-group inside children
            if (!isset($menus[$parentKey]['children'][$subKey])) {
                return $menus;
            }
            if (!isset($menus[$parentKey]['children'][$subKey]['children'])) {
                $menus[$parentKey]['children'][$subKey]['children'] = [];
            }
            $target = &$menus[$parentKey]['children'][$subKey]['children'];
        } else {
            // Target top-level children directly
            $target = &$menus[$parentKey]['children'];
        }

        // Insert items
        if ($position === null) {
            $target = array_merge($target, $items);
        } else {
            $position = max(0, min($position, count($target)));
            array_splice($target, $position, 0, $items);
        }

        return $menus;
    }

    /**
     * Ensure menu structure is properly initialized
     *
     * @param array $menus The menu array to check
     * @param string $key The menu key to ensure exists
     * @param array $default Default structure if key doesn't exist
     *
     * @return array Modified menu array
     */
    public static function ensureMenuStructure($menus, $key, $default = [])
    {
        if (!isset($menus[$key])) {
            $menus[$key] = array_merge([
                'title' => ucfirst($key),
                'children' => []
            ], $default);
        }

        if (!isset($menus[$key]['children'])) {
            $menus[$key]['children'] = [];
        }

        return $menus;
    }

    /**
     * Insert menu item with a specific key (for associative arrays)
     *
     * @param array $menus The menu array to modify
     * @param string $key The key for the new menu item
     * @param array $item The menu item data
     * @param string|null $afterKey Insert after this key (null = append to end)
     *
     * @return array Modified menu array
     */
    public static function insertMenuByKey($menus, $key, $item, $afterKey = null)
    {
        if ($afterKey === null) {
            // Append to end
            $menus[$key] = $item;
            return $menus;
        }

        // Find position of afterKey
        $keys = array_keys($menus);
        $position = array_search($afterKey, $keys);

        if ($position === false) {
            // Key not found, append to end
            $menus[$key] = $item;
            return $menus;
        }

        // Split array and insert
        $before = array_slice($menus, 0, $position + 1, true);
        $after = array_slice($menus, $position + 1, null, true);

        return $before + [$key => $item] + $after;
    }

    /**
     * Find menu position by key
     *
     * @param array $menus The menu array
     * @param string $key The key to find
     *
     * @return int|false Position (0-indexed) or false if not found
     */
    public static function findMenuPosition($menus, $key)
    {
        $keys = array_keys($menus);
        return array_search($key, $keys);
    }

    /**
     * Check if menu key exists
     *
     * @param array $menus The menu array
     * @param string $key The key to check
     *
     * @return bool
     */
    public static function hasMenu($menus, $key)
    {
        return isset($menus[$key]);
    }
}
