<?php

namespace Kotchasan;

/**
 * Kotchasan Router Class
 *
 * This class handles routing for the Kotchasan framework,
 * allowing for flexible URL patterns and module handling.
 *
 * @package Kotchasan
 */
class Router extends \Kotchasan\KBase
{
    /**
     * Rules for website page routing.
     *
     * @var array
     */
    protected $rules = [
        // /api/v1/auth/login
        '/(api)(\.php)?\/([a-z0-9]+)\/([a-z\-_]+)(\/([0-9a-z\-_]+))?/i' => ['_dir', '', 'module', 'method', '', 'action'],
        // index.php/module/controller|model/folder/_dir/_method
        '/^[a-z0-9]+\.php\/([a-z]+)\/(controller|model)(\/([\/a-z0-9_]+)\/([a-z0-9_]+))?$/i' => ['module', '_mvc', '', '_dir', '_method'],
        // index/model/_dir
        '/([a-z]+)\/(model|controller|view)\/([a-z0-9_]+)/i' => ['module', '_mvc', '_dir'],
        // module/alias
        '/^([a-z]+)\/(.*)$/' => ['module', 'alias'],
        // module, module.php
        '/^([a-z0-9_]+)(\.php)?$/' => ['module'],
        // alias
        '/^(.*)$/' => ['alias']
    ];

    /**
     * Initialize the Router.
     *
     * @param string $className The class to receive values from the Router.
     * @throws \InvalidArgumentException If the target class is not found.
     * @return static
     */
    public function init($className)
    {
        // Check for modules
        $modules = $this->parseRoutes(self::$request->getUri()->getPath(), self::$request->getQueryParams());

        if (isset($modules['module']) && isset($modules['_mvc']) && isset($modules['_dir'])) {
            // Class from URL
            $className = str_replace(' ', '\\', ucwords($modules['module'].' '.str_replace(['\\', '/'], ' ', $modules['_dir']).' '.$modules['_mvc']));
            $method = empty($modules['_method']) ? 'index' : $modules['_method'];
        } elseif (isset($modules['_class']) && isset($modules['_method'])) {
            // Specify the Class and Method directly
            // Custom Router rules must be written to constrain it
            $className = str_replace('/', '\\', $modules['_class']);
            $method = $modules['_method'];
        } elseif (isset($modules['_dir']) && $modules['_dir'] === 'api') {
            // API call Kotchasan\ApiController::index
            $className = '\\Kotchasan\\ApiController';
            $method = 'index';
            // If action is numeric, treat it as an ID and set method to index
            if (!empty($modules['action']) && preg_match('/^[0-9]+$/', $modules['action'])) {
                $modules['id'] = (int) $modules['action'];
                $modules['action'] = 'index';
            }
        } else {
            // No specified method, call the index method
            $method = empty($modules['_method']) ? 'index' : $modules['_method'];
        }

        if (!class_exists($className)) {
            throw new \InvalidArgumentException('Class '.$className.' not found');
        } elseif (method_exists($className, $method)) {
            // Create the class
            $obj = new $className();
            // Call the method and get response
            $response = $obj->$method(self::$request->withQueryParams($modules));
            // Send the response if it's a Response object
            if ($response instanceof \Kotchasan\Http\Response) {
                $response->send();
            }
        } else {
            throw new \InvalidArgumentException('Method '.$method.' not found in '.$className);
        }

        return $this;
    }

    /**
     * Parse the path and return it as a query string.
     *
     * @param string $path The path, e.g., /a/b/c.html
     * @param array $modules Query string
     *
     * @return array
     */
    public function parseRoutes($path, $modules)
    {
        $base_path = preg_quote(BASE_PATH, '/');

        // Extract only the path excluding the application path and file extension
        if (preg_match('/^'.$base_path.'(.*)(\.html?|\/)$/u', $path, $match)) {
            $my_path = $match[1];
        } elseif (preg_match('/^'.$base_path.'(.*)$/u', $path, $match)) {
            $my_path = $match[1];
        }

        if (!empty($my_path) && !preg_match('/^[a-z0-9]+\.php$/i', $my_path)) {
            $my_path = rawurldecode($my_path);

            foreach ($this->rules as $patt => $items) {
                if (preg_match($patt, $my_path, $match)) {
                    foreach ($items as $i => $key) {
                        if (!empty($key) && isset($match[$i + 1]) && !isset($modules[$key])) {
                            $value = $match[$i + 1];
                            if (in_array($key, ['module', 'method', 'action'])) {
                                $value = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $value))));
                            }
                            $modules[$key] = $value;
                        }
                    }
                    break;
                }
            }
        }

        return $modules;
    }
}
