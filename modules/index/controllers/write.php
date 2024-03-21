<?php
/**
 * @filesource modules/index/controllers/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Write;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * แก้ไขหน้าเพจ
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        $params = array(
            'src' => $request->request('src')->filter('a-z'),
            'pages' => Language::get('PAGES')
        );
        if (!isset($params['pages'][$params['src']])) {
            $params['src'] = \Kotchasan\ArrayTool::getFirstKey($params['pages']);
        }
        // ข้อความ title bar
        $this->title = Language::get('Details of').' '.$params['pages'][$params['src']];
        // เลือกเมนู
        $this->menu = 'settings';
        // แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
        if (Login::notDemoMode(Login::isAdmin())) {
            // ckeditor
            self::$view->addJavascript(WEB_URL.'ckeditor/ckeditor.js');
            // แสดงผล
            $section = Html::create('section');
            // breadcrumbs
            $breadcrumbs = $section->add('nav', array(
                'class' => 'breadcrumbs'
            ));
            $ul = $breadcrumbs->add('ul');
            $ul->appendChild('<li><span class="icon-settings">{LNG_Settings}</span></li>');
            $ul->appendChild('<li><span>'.$params['pages'][$params['src']].'</span></li>');
            $section->add('header', array(
                'innerHTML' => '<h2 class="icon-write">'.$this->title.'</h2>'
            ));
            // menu
            $section->appendChild(\Index\Tabmenus\View::render($request, 'settings', 'write'.$params['src']));
            $div = $section->add('div', array(
                'class' => 'content_bg'
            ));
            // แสดงฟอร์ม
            $div->appendChild(\Index\Write\View::create()->render($request, $params));
            // คืนค่า HTML
            return $section->render();
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
