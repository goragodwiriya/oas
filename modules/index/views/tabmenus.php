<?php
/**
 * @filesource modules/index/views/tabmenus.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Tabmenus;

use Kotchasan\Http\Request;

/**
 * Settings Menu (Tab)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * Menus
     *
     * @param Request $request
     * @param string $menu
     * @param string $tab
     *
     * @return string
     */
    public static function render(Request $request, $menu, $tab)
    {
        // เมนูที่ต้องการ
        $menu_tabs = \Index\Index\Controller::menus()->getTopLvlMenu($menu);
        $content = '';
        if (!empty($menu_tabs['submenus'])) {
            // สร้างเมนู tab
            $content = '<div class="tab_settings_bg"><div class="tab_settings"><ul class="tab_menus clear">';
            foreach ($menu_tabs['submenus'] as $name => $item) {
                $hasSubmenu = empty($item['submenus']) ? false : true;
                if ($hasSubmenu) {
                    $sel = $tab == $name ? 'select menu-arrow' : 'menu-arrow';
                } else {
                    $sel = $tab == $name ? 'select center' : 'center';
                }
                $content .= '<li class="'.$sel.'"><a';
                if (isset($item['url'])) {
                    $content .= ' href="'.$item['url'].'" title="'.$item['text'].'"';
                }
                if (isset($item['target'])) {
                    $content .= ' target='.$item['target'];
                }
                $content .= ' class="cuttext">'.$item['text'].'</a>';
                if ($hasSubmenu) {
                    $content .= '<ul>';
                    foreach ($item['submenus'] as $submenu) {
                        $content .= '<li><a href="'.$submenu['url'].'" title="'.$submenu['text'].'" class="cuttext">'.$submenu['text'].'</a></li>';
                    }
                    $content .= '</ul>';
                }
                $content .= '</li>';
            }
            $content .= '</ul></div></div>';
        }
        // คืนค่า HTML
        return $content;
    }
}
