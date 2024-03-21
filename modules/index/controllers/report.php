<?php
/**
 * @filesource modules/index/controllers/report.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Report;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Http\Uri;
use Kotchasan\Language;

/**
 * module=report
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * รายงาน
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::get('Report');
        // เลือกเมนู
        $this->menu = 'report';
        // สมาชิก
        if ($login = Login::isMember()) {
            // เมนู report
            $top_menu = self::$menus->getTopLvlMenu('report');
            if (isset($top_menu['submenus'])) {
                if (count($top_menu['submenus']) == 1) {
                    $menu = reset($top_menu['submenus']);
                    if (isset($menu['url'])) {
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
                        // แสดง report menu
                        return $this->tabMenus($request, $login);
                    }
                } else {
                    // แสดง report menu
                    return $this->tabMenus($request, $login);
                }
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }

    /**
     * แสดงเมนู report
     *
     * @param Request $request
     * @param array $login
     *
     * @return string
     */
    public function tabMenus(Request $request, $login)
    {
        // แสดงผล
        $section = Html::create('section');
        // breadcrumbs
        $breadcrumbs = $section->add('nav', array(
            'class' => 'breadcrumbs'
        ));
        $ul = $breadcrumbs->add('ul');
        $ul->appendChild('<li><span class="icon-menus">{LNG_Report}</span></li>');
        $section->add('header', array(
            'innerHTML' => '<h2 class="icon-report">'.$this->title.'</h2>'
        ));
        $div = $section->add('div', array(
            'class' => 'content_bg'
        ));
        // menu
        $div->appendChild(\Index\Tabmenus\View::render($request, 'report', 'report'));
        // คืนค่า HTML
        return $section->render();
    }
}
