<?php
/**
 * @filesource modules/index/controllers/settings.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Settings;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Http\Uri;
use Kotchasan\Language;

/**
 * module=settings
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * ตั้งค่าเว็บไซต์
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::get('General site settings');
        // เลือกเมนู
        $this->menu = 'settings';
        // สมาชิก
        if (Login::isMember()) {
            // เมนู settings
            $top_menu = self::$menus->getTopLvlMenu('settings');
            if (isset($top_menu['submenus'])) {
                if (count($top_menu['submenus']) == 1) {
                    $menu = reset($top_menu['submenus']);
                    if (empty($menu['submenus']) && isset($menu['url'])) {
                        $query = Uri::createFromUri($menu['url'])->parseQueryParams();
                        if (isset($query['module'])) {
                            $className = \Index\Main\Controller::parseModule($query['module']);
                            $query_params = $request->getQueryParams();
                            foreach ($query as $key => $value) {
                                $query_params[$key] = $value;
                            }
                            return createClass($className)->render($request->withQueryParams($query_params));
                        }
                    } else {
                        return $this->tabMenus($request);
                    }
                } else {
                    // แสดง settings menu
                    return $this->tabMenus($request);
                }
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }

    /**
     * แสดงเมนู settings
     *
     * @param Request $request
     *
     * @return string
     */
    public function tabMenus(Request $request)
    {
        // แสดงผล
        $section = Html::create('section');
        // breadcrumbs
        $breadcrumbs = $section->add('nav', array(
            'class' => 'breadcrumbs'
        ));
        $ul = $breadcrumbs->add('ul');
        $ul->appendChild('<li><span class="icon-menus">{LNG_Settings}</span></li>');
        $section->add('header', array(
            'innerHTML' => '<h2 class="icon-settings">'.$this->title.'</h2>'
        ));
        $div = $section->add('div', array(
            'class' => 'content_bg'
        ));
        // menu
        $div->appendChild(\Index\Tabmenus\View::render($request, 'settings', 'settings'));
        // คืนค่า HTML
        return $section->render();
    }
}
