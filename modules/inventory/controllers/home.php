<?php
/**
 * @filesource modules/inventory/controllers/home.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Home;

use \Kotchasan\Http\Request;
use \Kotchasan\Language;
use \Index\Home\Controller AS Home;

/**
 * Controller สำหรับการแสดงผลหน้า Home
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\KBase
{

  /**
   * ฟังก์ชั่นสร้าง card
   *
   * @param Request $request
   * @param \Kotchasan\Collection $card
   * @param array $login
   */
  public static function addCard(Request $request, $card, $login)
  {
    $datas = \Inventory\Home\Model::getCardData($login);
    Home::renderCard($card, 'icon-billing', Language::find('SELL_TYPIES', null, self::$cfg->outstock_status), number_format($datas->receipt), '{LNG_Sales today}', 'index.php?module=inventory-outward&status='.self::$cfg->outstock_status);
    Home::renderCard($card, 'icon-cart', Language::find('BUY_TYPIES', null, 1), number_format($datas->purcashe_order), '{LNG_Waiting for payment}', 'index.php?module=inventory-inward&status=1');
    Home::renderCard($card, 'icon-customer', '{LNG_Customer}', number_format($datas->customers), '{LNG_Customer list}', 'index.php?module=inventory-customers');
    Home::renderCard($card, 'icon-product', '{LNG_Inventory}', number_format($datas->products), '{LNG_List of} {LNG_Product}', 'index.php?module=inventory-setup&amp;sort=quantity%20asc');
  }

  /**
   * ฟังก์ชั่นสร้าง เมนูด่วน
   *
   * @param Request $request
   * @param \Kotchasan\Collection $card
   * @param array $login
   */
  public static function addMenu(Request $request, $menu, $login)
  {
    foreach (Language::get('SELL_TYPIES') As $k => $label) {
      Home::renderQuickMenu($menu, 'icon-plus', '{LNG_Add New} '.$label, 'index.php?module=inventory-sell&amp;typ='.$k);
    }
    foreach (Language::get('BUY_TYPIES') As $k => $label) {
      Home::renderQuickMenu($menu, 'icon-plus', '{LNG_Add New} '.$label, 'index.php?module=inventory-buy&amp;typ='.$k);
    }
  }
}