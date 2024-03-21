<?php
/**
 * @filesource modules/index/controllers/main.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Main;

use Kotchasan\Http\Request;
use Kotchasan\Template;

/**
 * Controller หลัก สำหรับแสดงหน้าเว็บไซต์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * ฟังก์ชั่นแปลง $url เป็น Controller Class และโหลดคลาสไว้
     * ถ้า module=home หรือไม่มีการระบุ module มา จะคืนค่า null
     *
     * @param string $url
     *
     * @return string|null คืนค่าชื่อคลาส ถ้าไม่พบจะคืนค่า null
     */
    public static function parseFromUri($url)
    {
        if (preg_match('/module=(([a-z0-9]+)([\/\-]([a-z0-9]+))?)/', $url, $match)) {
            if ($match[1] != 'home') {
                return self::parseModule($match[1]);
            }
        }
        return null;
    }

    /**
     * ฟังก์ชั่นแปลงชื่อโมดูลที่ส่งมาจาก requestเป็น Controller Class และโหลดคลาสไว้ เช่น
     *
     * @param Request $request
     * @param string  $default ถ้าไม่ระบุจะคืนค่า Error Controller
     *
     * @return string|null คืนค่าชื่อคลาส ถ้าไม่พบจะคืนค่า null
     */
    public static function parseRequest($request, $default = null)
    {
        $module = strtolower($request->request('module', '')->toString());
        return $module == 'main' ? null : self::parseModule($module, $default);
    }

    /**
     * ฟังก์ชั่นแปลง $module เป็น Controller Class และโหลดคลาสไว้ เช่น
     * home = Index\Home\Controller
     * person-index = Person\Index\Controller
     *
     * @param Request $request
     * @param string  $default ถ้าไม่ระบุจะคืนค่า Error Controller
     *
     * @return string|null คืนค่าชื่อคลาส ถ้าไม่พบจะคืนค่า null
     */
    public static function parseModule($module, $default = null)
    {
        if (!empty($module) && $module != 'index' && preg_match('/^([a-z0-9]+)([\/\-]([a-z0-9]+))?$/', $module, $match)) {
            if (empty($match[3])) {
                if (is_file(APP_PATH.'modules/'.$match[1].'/controllers/index.php')) {
                    $owner = $match[1];
                    $module = 'index';
                } else {
                    $owner = 'index';
                    $module = $match[1];
                }
            } else {
                $owner = $match[1];
                $module = $match[3];
            }
        } elseif (!empty($default) && preg_match('/^([a-z0-9]+)([\/\-]([a-z0-9]+))?$/i', $default, $match)) {
            // ถ้าไม่ระบุ module มาแสดงหน้า $default
            if (empty($match[3])) {
                if (is_file(APP_PATH.'modules/'.$match[1].'/controllers/index.php')) {
                    $owner = $match[1];
                    $module = 'index';
                } else {
                    $owner = 'index';
                    $module = $match[1];
                }
            } else {
                $owner = $match[1];
                $module = $match[3];
            }
        } else {
            // ไม่มีเมนู
            return null;
        }
        // ตรวจสอบหน้าที่เรียก
        if (is_file(APP_PATH.'modules/'.$owner.'/controllers/'.$module.'.php')) {
            // โหลดคลาส ถ้าพบโมดูลที่เรียก
            require_once APP_PATH.'modules/'.$owner.'/controllers/'.$module.'.php';
            // คืนค่า ชื่อคลาส
            return ucfirst($owner).'\\'.ucfirst($module).'\Controller';
        }
        return null;
    }

    /**
     * หน้าหลักเว็บไซต์
     *
     * @param Request $request
     *
     * @return object
     */
    public function execute(Request $request)
    {
        // โมดูลจาก URL ถ้าไม่มีใช้เมนูรายการแรก
        $className = self::parseRequest($request, self::$menus->home());
        if (!$className || !method_exists($className, 'render')) {
            // 404
            $className = 'Index\Error\Controller';
        }
        // create Class
        $controller = new $className();
        // render web
        $detail = $controller->render($request);
        // ตรวจสอบว่าต้องโหลด main.html หรือไม่ (หน้าเว็บปกติ)
        if ($controller->bodyClass == 'mainpage') {
            // main.html
            $template = Template::create('', '', 'main');
            $template->add(array(
                '/{CONTENT}/' => $detail
            ));
            // คืนค่า controller
            $controller->detail = $template->render();
        } else {
            // ไม่ต้องโหลด main.html
            $controller->detail = $detail;
        }
        // คืนค่า HTML
        return $controller;
    }
}
