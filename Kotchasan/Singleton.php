<?php
/**
 * @filesource Kotchasan/Singleton.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides the base functionality for creating singleton classes.
 *
 * @see https://www.kotchasan.com/
 */
abstract class Singleton
{
    /**
     * @var Singleton|null The instance of the class.
     *                     This variable holds the single instance of the class.
     */
    private static $instance = null;

    /**
     * Get the instance of the class.
     *
     * This method returns the instance of the class.
     * If the instance doesn't exist, it creates a new one.
     *
     * @return static The instance of the class.
     */
    public static function &getInstance()
    {
        if (null === static::$instance) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    /**
     * Initialize the class.
     *
     * This method is called when the class is loaded.
     *
     * @return void
     */
    abstract protected function init();

    /**
     * Clone method.
     *
     * This method is private to prevent cloning of the instance.
     *
     * @return void
     */
    private function __clone()
    {
        // Do nothing
    }

    /**
     * Constructor.
     *
     * This method is private to prevent direct instantiation of the class.
     * It initializes the class by calling the `init` method.
     *
     * @return void
     */
    private function __construct()
    {
        // Initial class
        static::init();
    }

    /**
     * Wakeup method.
     *
     * This method is private to prevent deserialization of the instance.
     *
     * @return void
     */
    private function __wakeup()
    {
        // Do nothing
    }
}
