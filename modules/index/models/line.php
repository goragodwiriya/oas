<?php
/**
 * @filesource modules/index/models/line.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Line;

use Gcms\Line;
use Kotchasan\Http\Request;

/**
 * module=line
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * ทดสอบการส่ง Line
     *
     * @param Request $request
     */
    public function test(Request $request)
    {
        // referer
        if ($request->isReferer() && $request->isAjax()) {
            // ทดสอบส่งข้อความ Line
            Line::notify(strip_tags(self::$cfg->web_title), $request->post('id')->quote());
        }
    }
}
