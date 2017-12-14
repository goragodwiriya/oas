<?php
/**
 * @filesource modules/index/models/menu.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Menu;

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
   * @return array
   */
  public static function getMenus($login)
  {
    $menus = array(
      'home' => array(
        'text' => '{LNG_Home}',
        'url' => 'index.php?module=home'
      ),
      'settings' => array(
        'text' => '{LNG_Settings}',
        'submenus' => array(
          array(
            'text' => '{LNG_Site settings}',
            'url' => 'index.php?module=system'
          ),
          array(
            'text' => '{LNG_Email settings}',
            'url' => 'index.php?module=mailserver'
          ),
          array(
            'text' => '{LNG_Member status}',
            'url' => 'index.php?module=memberstatus'
          ),
          'company' => array(
            'text' => '{LNG_Company Profile}',
            'url' => 'index.php?module=company'
          ),
          'image' => array(
            'text' => '{LNG_Image}',
            'url' => 'index.php?module=image'
          ),
          'accsettings' => array(
            'text' => '{LNG_Accounting settings}',
            'url' => 'index.php?module=accsettings'
          ),
          'member' => array(
            'text' => '{LNG_Member list}',
            'url' => 'index.php?module=member'
          ),
        ),
      ),
      'signout' => array(
        'text' => '{LNG_Sign out}',
        'url' => 'index.php?action=logout'
      ),
    );
    return $menus;
  }
}