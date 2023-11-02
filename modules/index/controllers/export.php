<?php
/**
 * @filesource modules/index/controllers/export.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Export;

use Kotchasan\Http\Request;
use Kotchasan\Template;

/**
 * export.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * Controller สำหรับส่งออกข้อมูล
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        // ตัวแปรป้องกันการเรียกหน้าเพจโดยตรง
        define('MAIN_INIT', 'export');
        // session cookie
        $request->initSession();
        // กำหนด skin ให้กับ template
        Template::init(self::$cfg->skin);
        // ตรวจสอบโมดูลที่เรียก
        $className = \Index\Main\Controller::parseRequest($request);
        $ret = false;
        if ($className && method_exists($className, 'export')) {
            // create Class
            $ret = createClass($className)->export($request);
        }
        if ($ret === false) {
            // ไม่พบโมดูล หรือ ไม่สามารถทำรายการได้
            new \Kotchasan\Http\NotFound();
        } elseif (is_string($ret)) {
            // คืนค่าเป็น string มา เช่น พิมพ์
            echo $ret;
        }
    }
}
