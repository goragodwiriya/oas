<?php
/**
 * @filesource Kotchasan/Router.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Router class for website page routing.
 *
 * @see https://www.kotchasan.com/
 */
class Router extends \Kotchasan\KBase
{
    /**
     * Rules for website page routing.
     *
     * @var array
     */
    protected $rules = [
        // index.php/module/model/folder/_dir/_method
        '/^[a-z0-9]+\.php\/([a-z]+)\/(model)(\/([\/a-z0-9_]+)\/([a-z0-9_]+))?$/i' => ['module', '_mvc', '', '_dir', '_method'],
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
        } else {
            // No specified method, call the index method
            $method = empty($modules['_method']) ? 'index' : $modules['_method'];
        }

        if (!class_exists($className)) {
            throw new \InvalidArgumentException('Class '.$className.' not found');
        } elseif (method_exists($className, $method)) {
            // Create the class
            $obj = new $className();
            // Call the method
            $obj->$method(self::$request->withQueryParams($modules));
        } else {
            throw new \InvalidArgumentException('Method '.$method.' not found in '.$className);
        }

        return $this;
    }

    /**
     * Parse the path and return it as a query string.
     *
     * @assert ('/print.php/css/view/index', []) [==] array( '_mvc' => 'view', '_dir' => 'index', 'module' => 'css')
     * @assert ('/index/model/updateprofile.php', []) [==] array( '_mvc' => 'model', '_dir' => 'updateprofile', 'module' => 'index')
     * @assert ('/index.php/alias/model/admin/settings/save', []) [==] array('module' => 'alias', '_mvc' => 'model', '_dir' => 'admin/settings', '_method' => 'save')
     * @assert ('/css/view/index.php', []) [==] array('module' => 'css', '_mvc' => 'view', '_dir' => 'index')
     * @assert ('/module/ทดสอบ.html', []) [==] array('alias' => 'ทดสอบ', 'module' => 'module')
     * @assert ('/module.html', []) [==] array('module' => 'module')
     * @assert ('/ทดสอบ.html', []) [==] array('alias' => 'ทดสอบ')
     * @assert ('/ทดสอบ.html', array('module' => 'test')) [==] array('alias' => 'ทดสอบ', 'module' => 'test')
     * @assert ('/index.php', array('_action' => 'one')) [==] array('_action' => 'one')
     * @assert ('/admin_index.php', array('_action' => 'one')) [==] array('_action' => 'one', 'module' => 'admin_index')
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
                            $modules[$key] = $match[$i + 1];
                        }
                    }
                    break;
                }
            }
        }

        return $modules;
    }
}
