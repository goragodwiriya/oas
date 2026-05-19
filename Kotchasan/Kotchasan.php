<?php

use Kotchasan\Http\Request;

/**
 * Kotchasan Class
 *
 * This class serves as the main entry point for Kotchasan applications.
 * It initializes the application with configuration settings and handles
 * the request routing.
 *
 * @package Kotchasan
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

        // If JWT secret is configured, run JwtMiddleware early to populate request attributes
        if (!empty(self::$cfg->jwt_secret)) {
            try {
                $jwtMw = new \Kotchasan\Http\Middleware\JwtMiddleware(self::$cfg->jwt_secret);
                // handle may return Response or modified Request; we only need attributes populated
                $jwtMw->handle(self::$request);
            } catch (\Throwable $e) {
                // ignore middleware failures to keep backward compatibility
            }
        }

        // Initialize database query cache if enabled
        if (defined('DB_CACHE') && DB_CACHE) {
            // Define cache constants based on configuration if not already defined (default 3600 seconds)
            if (!defined('CACHE_TTL')) {
                define('CACHE_TTL', self::$cfg->cache_expire ?? 3600);
            }

            $this->initializeQueryCache();
        }
    }

    /**
     * Initialize query cache for database operations.
     *
     * @return void
     */
    protected function initializeQueryCache(): void
    {
        try {
            $cacheConfig = [
                'driver' => defined('CACHE_DRIVER') ? CACHE_DRIVER : 'file',
                'ttl' => CACHE_TTL
            ];

            // Add driver-specific configuration
            switch ($cacheConfig['driver']) {
                case 'file':
                    $cacheConfig['path'] = defined('ROOT_PATH') && defined('DATA_FOLDER')
                    ? ROOT_PATH.DATA_FOLDER.'cache/'
                    : null;
                    break;

                case 'redis':
                    // Redis configuration from config if available
                    if (isset(self::$cfg->redis_host)) {
                        $cacheConfig['host'] = self::$cfg->redis_host;
                    }
                    if (isset(self::$cfg->redis_port)) {
                        $cacheConfig['port'] = self::$cfg->redis_port;
                    }
                    if (isset(self::$cfg->redis_password)) {
                        $cacheConfig['password'] = self::$cfg->redis_password;
                    }
                    if (isset(self::$cfg->redis_database)) {
                        $cacheConfig['database'] = self::$cfg->redis_database;
                    }
                    break;
            }

            // Configure cache if Database is available
            if (class_exists('\Kotchasan\Database')) {
                \Kotchasan\Database::configureCache($cacheConfig, $cacheConfig['ttl']);
            }
        } catch (\Throwable $e) {
            // Log error but don't fail - cache is optional
            if (defined('DEBUG') && DEBUG > 0) {
                error_log('Failed to initialize query cache: '.$e->getMessage());
            }
        }
    }
}
