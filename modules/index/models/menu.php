<?php
/**
 * @filesource modules/index/models/menu.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Menu;

use Gcms\Login;
use Kotchasan\Language;

/**
 * รายการเมนู
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model
{
    /**
     * รายการเมนู
     *
     * @param array $login
     *
     * @return array
     */
    public static function getMenus($login)
    {
        $notDemoMode = Login::notDemoMode($login);
        // แอดมิน
        $isAdmin = $notDemoMode && Login::isAdmin();
        // สามารถตั้งค่าได้
        $can_config = Login::checkPermission($login, 'can_config');
        // เมนูตั้งค่า
        $settings = [];
        if ($can_config) {
            // สามารถตั้งค่าระบบได้
            $settings['system'] = array(
                'text' => '{LNG_Site settings}',
                'url' => 'index.php?module=system'
            );
            $settings['theme'] = array(
                'text' => '{LNG_Website template}',
                'url' => 'index.php?module=theme'
            );
            $settings['loginpage'] = array(
                'text' => '{LNG_Login page}',
                'url' => 'index.php?module=loginpage'
            );
        }
        if ($isAdmin) {
            $settings['mailserver'] = array(
                'text' => '{LNG_Email settings}',
                'url' => 'index.php?module=mailserver'
            );
            $settings['linesettings'] = array(
                'text' => '{LNG_LINE settings}',
                'url' => 'index.php?module=linesettings'
            );
            $settings['apis'] = array(
                'text' => 'API',
                'url' => 'index.php?module=apis'
            );
            $settings['modules'] = array(
                'text' => '{LNG_Module}',
                'url' => 'index.php?module=modules'
            );
        }
        if ($can_config) {
            $settings['language'] = array(
                'text' => '{LNG_Language}',
                'url' => 'index.php?module=language'
            );
            foreach (Language::get('CATEGORIES', []) as $k => $label) {
                $settings[$k] = array(
                    'text' => $label,
                    'url' => 'index.php?module=categories&amp;type='.$k
                );
            }
        }
        if ($isAdmin) {
            foreach (Language::get('PAGES', []) as $src => $label) {
                $settings['write'.$src] = array(
                    'text' => $label,
                    'url' => 'index.php?module=write&amp;src='.$src,
                    'target' => '_self'
                );
            }
            $settings['consentsettings'] = array(
                'text' => '{LNG_Cookie Policy}',
                'url' => 'index.php?module=consentsettings'
            );
        }
        if ($notDemoMode && Login::checkPermission($login, 'can_view_usage_history')) {
            $settings['usage'] = array(
                'text' => '{LNG_Usage history}',
                'url' => 'index.php?module=usage'
            );
        }
        if ($login) {
            return array(
                'home' => array(
                    'text' => '{LNG_Home}',
                    'url' => 'index.php?module=home'
                ),
                'member' => array(
                    'text' => '{LNG_Users}',
                    'submenus' => array(
                        array(
                            'text' => '{LNG_Member list}',
                            'url' => 'index.php?module=member'
                        ),
                        array(
                            'text' => '{LNG_Permission}',
                            'url' => 'index.php?module=permission'
                        ),
                        array(
                            'text' => '{LNG_Member status}',
                            'url' => 'index.php?module=memberstatus'
                        )
                    )
                ),
                'report' => array(
                    'text' => '{LNG_Report}',
                    'url' => 'index.php?module=report',
                    'submenus' => []
                ),
                'settings' => array(
                    'text' => '{LNG_Settings}',
                    'url' => 'index.php?module=settings',
                    'submenus' => $settings
                )
            );
        }
        // ไม่ได้ login
        return array(
            'home' => array(
                'text' => '{LNG_Home}',
                'url' => 'index.php?module=home'
            )
        );
    }
}
