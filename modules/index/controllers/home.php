<?php
/**
 * @filesource modules/index/controllers/home.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Home;

use Gcms\Login;
use Kotchasan\Collection;
use Kotchasan\Html;
use Kotchasan\Http\Request;

/**
 * module=home
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * Dashboard
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ไตเติล
        $this->title = self::$cfg->web_title.' - '.self::$cfg->web_description;
        // เมนู
        $this->menu = 'home';
        // สมาชิก
        $login = Login::isMember();
        // card
        $card = new Collection();
        $menu = new Collection();
        $block = new Collection();
        // โหลดโมดูลที่ติดตั้งแล้ว
        $modules = \Gcms\Modules::create();
        foreach ($modules->getControllers('Home') as $className) {
            if (method_exists($className, 'addCard')) {
                $className::addCard($request, $card, $login);
            }
            if (method_exists($className, 'addMenu')) {
                $className::addMenu($request, $menu, $login);
            }
            if (method_exists($className, 'addBlock')) {
                $className::addBlock($request, $block, $login);
            }
        }
        // สามารถแสดงผลหน้า Home ได้ ถ้ามีรายการ
        if ($card->count() > 0 || $menu->count() > 0 || $block->count() > 0) {
            // แสดงผล
            $section = Html::create('section');
            // breadcrumbs
            $breadcrumbs = $section->add('nav', array(
                'class' => 'breadcrumbs'
            ));
            $ul = $breadcrumbs->add('ul');
            $ul->appendChild('<li><span class="icon-home">{LNG_Home}</span></li>');
            $div = $section->add('div', array(
                'class' => 'content_bg'
            ));
            // แสดงจำนวนสมาชิกทั้งหมด
            if ($card->count() < 4 && Login::notDemoMode(Login::isAdmin())) {
                $watingForActivate = \Index\Member\Model::watingForActivate();
                if ($watingForActivate > 0) {
                    self::renderCard($card, 'icon-verfied', '{LNG_Users}', number_format($watingForActivate), '{LNG_Waiting list}', 'index.php?module=member&amp;sort=active%20asc');
                }
            }
            if ($card->count() > 0) {
                // dashboard
                $dashboard = $div->add('article', array(
                    'class' => 'dashboard clear'
                ));
                $dashboard->add('header', array(
                    'innerHTML' => '<h2 class="icon-dashboard">{LNG_Dashboard}</h2>'
                ));
                // grid
                $grid = $dashboard->add('div', array(
                    'class' => 'ggrid'
                ));
                // render card
                foreach ($card as $item) {
                    $grid->add('div', array(
                        'class' => 'block4 card',
                        'innerHTML' => $item
                    ));
                }
            }
            // render quick menu
            if ($menu->count() > 0) {
                $dashboard = $div->add('article', array(
                    'class' => 'dashboard clear'
                ));
                $dashboard->add('header', array(
                    'innerHTML' => '<h2 class="icon-menus">{LNG_Quick Menu}</h2>'
                ));
                // grid
                $grid = $dashboard->add('div', array(
                    'class' => 'ggrid'
                ));
                foreach ($menu as $k => $item) {
                    $grid->add('section', array(
                        'class' => 'qmenu block4',
                        'innerHTML' => $item
                    ));
                }
            }
            // render block
            if ($block->count() > 0) {
                foreach ($block as $k => $item) {
                    $div->add('article', array(
                        'class' => 'dashboard clear',
                        'innerHTML' => $item
                    ));
                }
            }
            return $section->render();
        }
        // หน้า Home
        $home = self::$menus->getTopLvlMenu('home');
        if ($home !== null && !empty($home['url'])) {
            // โหลดหน้า home
            $className = \Index\Main\Controller::parseFromUri($home['url']);
            if ($className !== null) {
                return createClass($className)->render($request);
            }
        }
        // ไม่พบหน้า ไปหน้า login
        return \Index\Error\Controller::execute($this, $request->getUri());
    }

    /**
     * ฟังก์ชั่นสร้าง card ในหน้า Home
     *
     * @param Collection $card
     * @param string     $icon
     * @param string     $title
     * @param string     $value
     * @param string     $link
     * @param string     $url
     * @param string     $target
     * @param string     $background_color
     */
    public static function renderCard($card, $icon, $title, $value, $link, $url = null, $target = '', $background_color = null)
    {
        $prop = [];
        if ($url === null) {
            $tag = 'span';
        } else {
            $tag = 'a';
            $prop[] = 'href="'.$url.'"';
            if (!empty($target)) {
                $prop[] = 'target="'.$target.'"';
            }
        }
        $class = ['card-item'];
        if ($background_color !== null) {
            $class[] = 'bg';
            $prop[] = 'style="background-color:'.$background_color.';"';
        }
        $prop[] = 'class="'.implode(' ', $class).'"';
        $content = '<'.$tag.' '.implode(' ', $prop).'>';
        $content .= '<span class="card-subitem '.$icon.' icon"></span>';
        $content .= '<span class="cuttext title" title="'.strip_tags($title).'">'.$title.'</span>';
        $content .= '<b class="cuttext">'.$value.'</b>';
        if ($link !== '') {
            $content .= '<span class="cuttext card_footer" title="'.strip_tags($link).'">'.$link.'</span>';
        }
        $content .= '</'.$tag.'>';
        $card->set(\Kotchasan\Password::uniqid(), $content);
    }

    /**
     * ฟังก์ชั่นสร้าง เมนูด่วน ในหน้า Home
     *
     * @param Collection $menu
     * @param string     $icon
     * @param string     $title
     * @param string     $url
     * @param string     $target
     */
    public static function renderQuickMenu($menu, $icon, $title, $url, $target = '')
    {
        $menu->set($title, '<a class="cuttext" href="'.$url.'"'.(empty($target) ? '' : ' target="'.$target.'"').'><span class="'.$icon.'">'.$title.'</span></a>');
    }
}
