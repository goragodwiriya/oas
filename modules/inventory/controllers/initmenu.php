<?php
/**
 * @filesource modules/inventory/controllers/initmenu.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Initmenu;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * Init Menu
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
     * @param Request                $request
     * @param \Index\Menu\Controller $menu
     * @param array                  $login
     */
    public static function execute(Request $request, $menu, $login)
    {
        if ($login) {
            // บริหารจัดการคลังสินค้าได้
            $warehouse = Login::checkPermission($login, 'can_manage_inventory');
            // พนักงานขาย
            $salesperson = Login::checkPermission($login, 'can_inventory_order');
            // จัดซื้อ
            $purchasing = Login::checkPermission($login, 'can_inventory_receive');
            // สามารถตั้งค่าระบบได้
            if (Login::checkPermission($login, 'can_config')) {
                $menu->add('settings', '{LNG_Accounting settings}', 'index.php?module=inventory-settings', null, 'inventory');
                $menu->add('settings', '{LNG_Pictures for a receipt}', 'index.php?module=inventory-image', null, 'image');
            }
            // สามารถดูรายชื่อลูกค้าได้
            if ($warehouse || $salesperson || $purchasing) {
                $menu->addTopLvlMenu('customer', '{LNG_Customer list}-{LNG_Supplier}', 'index.php?module=inventory-customers', null, 'member');
            }
            // สามารถบริหารคลังสินค้าได้
            if ($warehouse) {
                foreach (Language::get('INVENTORY_CATEGORIES', []) as $type => $text) {
                    $menu->add('settings', $text, 'index.php?module=inventory-categories&amp;type='.$type, null, 'category'.$type);
                }
                $menu->add('settings', '{LNG_Inventory}', 'index.php?module=inventory-setup', null, 'setup');
            }
            $submenus = array();
            foreach (Language::get('ORDER_STATUS') as $k => $v) {
                if ($purchasing && in_array($k, self::$cfg->buy_status)) {
                    $submenus['buy'][$k] = array(
                        'text' => $v,
                        'url' => 'index.php?module=inventory-orders&amp;status='.$k
                    );
                } elseif ($salesperson) {
                    $submenus['sell'][$k] = array(
                        'text' => $v,
                        'url' => 'index.php?module=inventory-orders&amp;status='.$k
                    );
                }
            }
            if (!empty($submenus)) {
                // เมนูรายการสินค้า
                $menu->addTopLvlMenu('products', '{LNG_Inventory}', 'index.php?module=inventory-products', null, 'customer');
                // สามารถขายได้
                if ($salesperson) {
                    if (!empty($submenus['sell'])) {
                        $menu->addTopLvlMenu('sales', Language::get('INVENTORY_TYPIES', '', 'sell'), null, $submenus['sell'], 'products');
                    }
                }
                // สามารถซื้อได้
                if ($purchasing) {
                    if (!empty($submenus['buy'])) {
                        $menu->addTopLvlMenu('purchase', Language::get('INVENTORY_TYPIES', '', 'buy'), null, $submenus['buy'], 'products');
                    }
                }
            }
        }
    }
}
