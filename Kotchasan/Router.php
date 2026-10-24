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
        // The controller segment must accept digits: generated modules name
        // controllers after their source sheet (sheet1) and de-duplicate
        // colliding names with a numeric suffix (products2). Without 0-9 the
        // match stops at the digit and routes to the wrong class.
        '/(api)(\.php)?\/([a-z0-9]+)\/([a-z0-9\-_]+)(\/([0-9a-z\-_]+))?/i' => ['_dir', '', 'module', 'method', '', 'action'],
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
     * กุญแจภายในที่ "กฎเส้นทาง" เท่านั้นเป็นคนกำหนด ห้ามรับค่าจากผู้ใช้
     *
     * ⚠️ parseRoutes() เอา query string มาเป็นฐานแล้วเติมค่าที่จับได้จากกฎลงไป
     * เฉพาะคีย์ที่ยังว่าง ค่าที่มากับ URL จึงชนะกฎเสมอ ถ้าไม่ตัดทิ้งก่อน ใครก็ได้
     * ที่ต่อ ?_class=Any/Class&_method=any ท้าย URL จะสั่งสร้างคลาสอะไรก็ได้ใน
     * ระบบแล้วเรียกเมธอดอะไรก็ได้ โดยข้ามด่านทั้งหมดของ ApiController::index()
     * (ไวต์ลิสต์ api_ips และ isRoutableAction)
     *
     * @var array
     */
    protected $reservedKeys = ['_class', '_method', '_mvc', '_dir'];

    /**
     * Initialize the Router.
     *
     * @param string $className The class to receive values from the Router.
     * @param bool $lockController true = entry point นี้ปักคอนโทรลเลอร์ของตัวเอง
     *                             ไว้แล้ว (export.php · sitemap.php · robots.php ·
     *                             manifest.php) เส้นทางใน URL จึงเลือกคลาสอื่น
     *                             ไม่ได้ ค่าปริยาย false = เส้นทางเลือกได้ตามเดิม
     *                             (index.php ของเว็บแบบเดิม และ api.php)
     * @throws \InvalidArgumentException If the target class is not found.
     * @return static
     */
    public function init($className, $lockController = false)
    {
        // ⚠️ ตัดกุญแจภายในที่มากับ query string ทิ้งก่อนเสมอ ดูคำอธิบายที่ $reservedKeys
        $queryParams = self::$request->getQueryParams();
        foreach ($this->reservedKeys as $_key) {
            unset($queryParams[$_key]);
        }

        // Check for modules
        $modules = $this->parseRoutes(self::$request->getUri()->getPath(), $queryParams);

        if ($lockController) {
            // ไฟล์ทางเข้าที่มีไว้เสิร์ฟคอนโทรลเลอร์ตัวเดียว — เส้นทางแบบ MVC เก่า
            // (export.php/module/controller/dir/method) ต้องไม่พาไปคลาสอื่น
            $method = 'index';
        } elseif (isset($modules['module']) && isset($modules['_mvc']) && isset($modules['_dir'])) {
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
        } elseif ($this->isCallableAction($className, $method)) {
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
     * เมธอดนี้เรียกจาก URL ได้หรือไม่
     *
     * ⚠️ method_exists() เป็นจริงกับ private/protected/static และเมธอดมหัศจรรย์
     * อย่าง __construct ด้วย การเช็คแค่นั้นจึงยิง fatal error ให้ผู้ใช้เห็นแทนที่จะ
     * ตอบว่าไม่มีหน้านี้ และเปิดทางเรียก __construct/__destruct ตรง ๆ
     *
     * @param string $className
     * @param string $method
     *
     * @return bool
     */
    protected function isCallableAction($className, $method)
    {
        if ($method === '' || strpos($method, '__') === 0 || !method_exists($className, $method)) {
            return false;
        }

        try {
            $reflection = new \ReflectionMethod($className, $method);
        } catch (\ReflectionException $e) {
            return false;
        }

        return $reflection->isPublic() && !$reflection->isStatic();
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
