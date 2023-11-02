<?php
/**
 * @filesource modules/index/controllers/login.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Login;

use Kotchasan\Http\Request;

/**
 * สำหรับแสดงกรอบ Login
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * จัดการกรอบ login
     *
     * @param Request $request
     * @param array $login
     *
     * @return string
     */
    public static function init(Request $request, $login)
    {
        if ($login) {
            return \Index\Login\View::member($request, $login);
        } else {
            return \Index\Login\View::login($request);
        }
    }
}
