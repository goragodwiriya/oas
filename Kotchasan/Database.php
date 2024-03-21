<?php
/**
 * @filesource Kotchasan/Database.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides methods for creating and managing database connections.
 *
 * @see https://www.kotchasan.com/
 */
final class Database extends KBase
{
    /**
     * Database connection instances.
     *
     * @var array
     */
    private static $instances = [];

    /**
     * Create Database Connection.
     *
     * @param mixed $name The name of the connection specified in the config or an array of database settings.
     *
     * @return \Kotchasan\Database\Driver The database driver instance.
     */
    public static function create($name = 'mysql')
    {
        $param = (object) array(
            'settings' => (object) array(
                'driver' => 'PdoMysql',
                'char_set' => 'utf8',
                'dbdriver' => 'mysql',
                'hostname' => 'localhost'
            ),
            'tables' => (object) []
        );

        // Check if $name is a string
        if (is_string($name)) {
            // Load database config from config.php
            if (isset(self::$cfg->database[$name]) && is_array(self::$cfg->database[$name])) {
                foreach (self::$cfg->database[$name] as $key => $value) {
                    $param->settings->$key = $value;
                }
            }

            if (empty(self::$instances[$name])) {
                // Check if database config file exists in APP_PATH or ROOT_PATH
                if (is_file(APP_PATH.'settings/database.php')) {
                    $config = include APP_PATH.'settings/database.php';
                } elseif (is_file(ROOT_PATH.'settings/database.php')) {
                    $config = include ROOT_PATH.'settings/database.php';
                }

                // Parse the config file
                foreach ($config as $key => $values) {
                    if ($key == $name) {
                        foreach ($values as $k => $v) {
                            $param->settings->$k = $v;
                        }
                    } elseif ($key == 'tables') {
                        foreach ($values as $k => $v) {
                            $param->tables->$k = $v;
                        }
                    }
                }
            }
        } elseif (is_array($name)) {
            // If $name is an array, use the settings directly
            foreach ($name as $k => $v) {
                $param->settings->$k = $v;
            }
            $name = rand();
        }

        if (empty(self::$instances[$name])) {
            // Load the base driver class
            require_once VENDOR_DIR.'Database/Driver.php';

            // Load the specified driver or use PdoMysqlDriver if not found
            if (is_file(VENDOR_DIR.'Database/'.$param->settings->driver.'Driver.php')) {
                $class = $param->settings->driver.'Driver';
            } else {
                // Default driver
                $class = 'PdoMysqlDriver';
            }
            require_once VENDOR_DIR.'Database/'.$class.'.php';
            $class = 'Kotchasan\\Database\\'.$class;

            // Create and connect the database driver instance
            self::$instances[$name] = new $class();
            self::$instances[$name]->connect($param);
        }

        return self::$instances[$name];
    }
}
