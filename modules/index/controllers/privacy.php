<?php
/**
 * @filesource modules/index/controllers/privacy.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Privacy;

use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=privacy
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * @param Request $request
     */
    public function render(Request $request)
    {
        // template ตามภาษาที่เลือก ถ้าไม่มีใช้ภาษาไทย
        $template = ROOT_PATH.self::$cfg->skin.'/privacy_'.Language::name().'.html';
        if (!file_exists($template)) {
            $template = ROOT_PATH.self::$cfg->skin.'/privacy_th.html';
        }
        // content
        $content = file_get_contents($template);
        // title, menu
        if (preg_match('/<h1[^>]{0,}>(.*)<\/h1>/', $content, $match)) {
            $this->title = strip_tags($match[1]);
        }
        $this->menu = 'privacy';
        // คืนค่า HTML
        $data_controller = empty(self::$cfg->data_controller) ? '' : self::$cfg->data_controller;
        return str_replace('{DATACONTROLLER}', $data_controller, $content);
    }
}
