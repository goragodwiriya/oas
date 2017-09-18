<?php
/**
 * @filesource modules/inventory/controllers/init.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Init;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * Init Module
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\KBase
{

  /**
   * ฟังก์ชั่นเริ่มต้นการทำงานของโมดูลที่ติดตั้ง
   * และจัดการเมนูของโมดูล
   *
   * @param Request $request
   * @param \Index\Menu\Controller $menu
   * @param array $login
   */
  public static function execute(Request $request, $menu, $login)
  {
    $menu->addTopLvlMenu('customer', '{LNG_Customer}/{LNG_Supplier}', 'index.php?module=inventory-customers', null, 'settings');
    if (Login::checkPermission($login, 'can_manage_inventory')) {
      $submenus = array(
        array(
          'text' => '{LNG_List of} {LNG_Product}',
          'url' => 'index.php?module=inventory-setup'
        ),
        array(
          'text' => '{LNG_Add New} {LNG_Product}',
          'url' => 'index.php?module=inventory-write'
        )
      );
      $menu->addTopLvlMenu('inventory', '{LNG_Inventory}', 'index.php?module=inventory-setup', null, 'settings');
      $submenus = array();
      foreach (Language::get('BUY_TYPIES') as $k => $v) {
        $submenus[$k] = array(
          'text' => $v,
          'url' => 'index.php?module=inventory-inward&amp;status='.$k
        );
      }
      $menu->addTopLvlMenu('buy', '{LNG_Buy}', null, $submenus, 'inventory');
      $submenus = array();
      foreach (Language::get('SELL_TYPIES') as $k => $v) {
        $submenus[$k] = array(
          'text' => $v,
          'url' => 'index.php?module=inventory-outward&amp;status='.$k
        );
      }
      $menu->addTopLvlMenu('sell', '{LNG_Sell}', null, $submenus, 'buy');
    }
  }

  /**
   * รายการ permission ของโมดูล
   *
   * @param array $permissions
   * @return array
   */
  public static function updatePermissions($permissions)
  {
    $permissions['can_stock'] = '{LNG_Can manage the product}';
    $permissions['can_sell'] = '{LNG_Can sell items}';
    $permissions['can_buy'] = '{LNG_Can make an order}';
    $permissions['can_manage_inventory'] = '{LNG_Can manage the inventory}';
    return $permissions;
  }
}