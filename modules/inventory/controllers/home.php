<?php
/**
 * @filesource modules/inventory/controllers/home.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Home;

use Gcms\Login;
use Index\Home\Controller as Home;
use Kotchasan\Http\Request;
use Kotchasan\Language;

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
     * @param Request               $request
     * @param \Kotchasan\Collection $card
     * @param array                 $login
     */
    public static function addCard(Request $request, $card, $login)
    {
        if ($login) {
            $order_status = Language::get('ORDER_STATUS');
            $datas = \Inventory\Home\Model::getCardData($login);
            $url = 'index.php?module=inventory-orders&day='.date('d').'&month='.date('m').'&year='.date('Y');
            if (isset($order_status['OUT'])) {
                Home::renderCard($card, 'icon-billing', $order_status['OUT'], number_format($datas->sell), '{LNG_Sales today}', $url.'&status=OUT');
            }
            if (isset($order_status['PO'])) {
                Home::renderCard($card, 'icon-cart', $order_status['PO'], number_format($datas->purcashe_order), '{LNG_Waiting for payment}', $url.'&status=PO');
            }
            Home::renderCard($card, 'icon-customer', '{LNG_Customer}', number_format($datas->customers), '{LNG_Customer list}', 'index.php?module=inventory-customers');
            Home::renderCard($card, 'icon-product', '{LNG_Inventory}', number_format($datas->products), '{LNG_List of} {LNG_Product}', 'index.php?module=inventory-products&amp;sort=quantity%20asc');
        }
    }

    /**
     * ฟังก์ชั่นสร้าง เมนูด่วน
     *
     * @param Request               $request
     * @param \Kotchasan\Collection $card
     * @param array                 $login
     */
    public static function addMenu(Request $request, $menu, $login)
    {
        if ($login) {
            // พนักงานขาย
            $salesperson = Login::checkPermission($login, 'can_inventory_order');
            // จัดซื้อ
            $purchasing = Login::checkPermission($login, 'can_inventory_receive');
            foreach (Language::get('ORDER_STATUS') as $k => $v) {
                if ($purchasing && in_array($k, self::$cfg->buy_status)) {
                    Home::renderQuickMenu($menu, 'icon-plus', '{LNG_Add} '.$v, 'index.php?module=inventory-order&amp;status='.$k);
                } elseif ($salesperson) {
                    Home::renderQuickMenu($menu, 'icon-plus', '{LNG_Add} '.$v, 'index.php?module=inventory-order&amp;status='.$k);
                }
            }
        }
    }
}
