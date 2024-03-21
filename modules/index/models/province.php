<?php
/**
 * @filesource modules/index/models/province.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Province;

use Kotchasan\Http\Request;

/**
 * module=province
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คืนค่ารายชื่อจังหวัด
     *
     * @param Request $request
     *
     * @return JSON
     */
    public function toJSON(Request $request)
    {
        // referer, ajax
        if ($request->isReferer() && $request->isAjax()) {
            echo json_encode(array(
                'province' => \Kotchasan\Province::all($request->post('country')->filter('A-Z'))
            ));
        }
    }
}
