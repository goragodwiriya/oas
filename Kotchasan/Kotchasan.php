<?php
/**
 * @filesource Kotchasan/Kotchasan.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

use Kotchasan\Http\Request;

/**
 * The main class of the Kotchasan framework.
 *
 * @see https://www.kotchasan.com/
 */
class Kotchasan extends Kotchasan\KBase
{
    /**
     * Default charset (recommended utf-8).
     *
     * @var string
     */
    public $char_set = 'utf-8';

    /**
     * The main controller.
     *
     * @var string
     */
    public $defaultController = 'Index\Index\Controller';

    /**
     * The main router.
     *
     * @var string
     */
    public $defaultRouter = 'Kotchasan\Router';

    /**
     * Stores DEBUG information.
     *
     * @var array
     */
    public static $debugger = null;

    /**
     * Singleton instance of the class.
     *
     * @var Singleton
     */
    private static $instance = null;

    /**
     * Creates a web application instance. Can only be called once.
     *
     * @param mixed $cfg Name of the class or an object of the Config class. If not specified, \Kotchasan\Config will be used.
     *
     * @return static
     */
    public static function createWebApplication($cfg = null)
    {
        if (null === self::$instance) {
            self::$instance = new static($cfg);
        }
        return self::$instance;
    }

    /**
     * Runs the web page.
     */
    public function run()
    {
        $router = new $this->defaultRouter();
        $router->init($this->defaultController);
    }

    /**
     * Class constructor (private, Singleton).
     *
     * @param mixed $cfg Name of the class or an object of the Config class. If not specified, \Kotchasan\Config will be used.
     */
    private function __construct($cfg)
    {
        /* Request Class with Apache HTTP headers */
        self::$request = new Request(true);

        /* Config */
        if (empty($cfg)) {
            self::$cfg = \Kotchasan\Config::create();
        } elseif (is_string($cfg)) {
            self::$cfg = $cfg::create();
        } else {
            self::$cfg = $cfg;
        }

        /* Charset */
        ini_set('default_charset', $this->char_set);
        if (extension_loaded('mbstring')) {
            mb_internal_encoding($this->char_set);
        }

        /* Time Zone */
        @date_default_timezone_set(self::$cfg->timezone);

        /* Custom site initialization */
        if (is_string($cfg) && method_exists($cfg, 'init')) {
            $cfg::init(self::$cfg);
        }
    }
}
