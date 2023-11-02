<?php
/**
 * @filesource modules/index/controllers/modal.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Modal;

use Kotchasan\Http\Request;

/**
 * Controller หลัก สำหรับแสดง backend ของ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * Controller สำหรับเรียก Modal มาแสดง
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && preg_match('/^modal_(([a-z]+)\/)?([a-z]+)_(.*)$/', $request->post('data')->toString(), $match)) {
            $match[2] = empty($match[2]) ? 'Index' : ucfirst($match[2]);
            $className = $match[2].'\\'.ucfirst($match[3]).'\View';
            if (class_exists($className) && method_exists($className, 'render')) {
                $content = createClass($className)->render($request, $match[4]);
                if (!empty($content)) {
                    echo \Gcms\View::create()->renderHTML($content);
                }
            }
        }
    }
}
