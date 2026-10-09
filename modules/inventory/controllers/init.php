<?php
/**
 * @filesource modules/inventory/controllers/init.php
 *
 * สิทธิ์ เมนู และการ์ดหน้าแรกของโมดูล inventory กลาง
 *
 * ชื่อสิทธิ์ทั้งหมดตรงกับที่เก็บอยู่ใน user.permission ของผลิตภัณฑ์เดิม
 * **ห้ามเปลี่ยนชื่อ** ไม่งั้นสิทธิ์ของสมาชิกทุกคนในไซต์ที่อัปเกรดมาหายทันที
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Init;

use Gcms\Api as ApiController;

/**
 * Init Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * สิทธิ์ของโมดูล
     *
     * @param array       $permissions
     * @param mixed       $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initPermission($permissions, $params = null, $login = null)
    {
        foreach ([
            'can_manage_inventory' => '{LNG_Can manage the inventory}',
            'can_inventory_order' => '{LNG_Can make an order}',
            'can_stock' => '{LNG_Can manage the product}',
            'can_sell' => '{LNG_Can sell items}',
            'can_buy' => '{LNG_Can make an order}'
        ] as $value => $text) {
            $permissions[] = ['value' => $value, 'text' => $text];
        }

        return $permissions;
    }

    /**
     * เมนูของโมดูล
     *
     * ⚠️ ชนิดเอกสารในเมนูสร้างจากแม่แบบในฐานข้อมูล ไม่ได้เขียนไว้ในโค้ด
     * แอดมินเพิ่มหรือปิดชนิดเอกสารเองแล้วเมนูตามทันที — และผลิตภัณฑ์ที่ใช้
     * โมดูลนี้เพื่อการยืม-คืนหรือรับซ่อม ก็ได้เมนูของตัวเองโดยไม่ต้องแก้โค้ด
     *
     * @param array       $menus
     * @param mixed       $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initMenus($menus, $params = null, $login = null)
    {
        if (!$login) {
            return $menus;
        }

        $items = [];
        if (ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            $items[] = [
                'title' => '{LNG_Customer}-{LNG_Supplier}',
                'icon' => 'icon-users',
                'children' => [
                    [
                        'title' => '{LNG_Customer}',
                        'url' => '/inventory-customers?type=customer',
                        'icon' => 'icon-user'
                    ],
                    [
                        'title' => '{LNG_Supplier}',
                        'url' => '/inventory-customers?type=supplier',
                        'icon' => 'icon-customer'
                    ]
                ]
            ];
        }

        if (ApiController::hasPermission($login, 'can_inventory_order')) {
            $items[] = [
                'title' => '{LNG_Inventory}',
                'url' => '/inventory-products',
                'icon' => 'icon-product'
            ];
        }

        if (ApiController::hasPermission($login, 'can_inventory_order')) {
            $labels = [
                'buy' => '{LNG_Purchase}',
                'sell' => '{LNG_Sales}'
            ];
            $icons = [
                'buy' => 'icon-import',
                'sell' => 'icon-export'
            ];
            foreach (\Inventory\Base\Model::statusesByMode() as $mode => $statuses) {
                if (empty($statuses)) {
                    continue;
                }
                $children = [];
                foreach ($statuses as $status => $topic) {
                    $children[] = [
                        'title' => $topic,
                        'url' => '/inventory-orders?status='.$status,
                        'icon' => 'icon-file'
                    ];
                }
                $items[] = [
                    'title' => isset($labels[$mode]) ? $labels[$mode] : $mode,
                    'icon' => isset($icons[$mode]) ? $icons[$mode] : 'icon-file',
                    'children' => $children
                ];
            }
        }

        if (!empty($items)) {
            $menus = parent::insertMenuAfter($menus, $items, 0);
        }

        $settings = [];
        if (ApiController::hasPermission($login, 'can_manage_inventory')) {
            $settings[] = [
                'title' => '{LNG_List of} {LNG_Inventory}',
                'url' => '/inventory-setup',
                'icon' => 'icon-list'
            ];
            // เมนูหนึ่งรายการต่อชนิดหมวดหมู่ ชื่อชนิดมาจากคอนโทรลเลอร์หมวดหมู่
            // ที่เดียว จึงไม่มีทางหลุดจากของจริง
            foreach (\Inventory\Categories\Controller::types() as $type => $text) {
                $settings[] = [
                    'title' => $text,
                    'url' => '/inventory-categories?type='.$type,
                    'icon' => 'icon-tags'
                ];
            }
        }
        if (ApiController::hasPermission($login, 'can_config')) {
            $settings[] = [
                'title' => '{LNG_Accounting settings}',
                'url' => '/inventory-settings',
                'icon' => 'icon-cog'
            ];
        }

        if (!empty($settings)) {
            $menus = parent::insertMenuChildren($menus, [
                [
                    'title' => '{LNG_Inventory}',
                    'icon' => 'icon-product',
                    'children' => $settings
                ]
            ], 'settings', null, 2);
        }

        return $menus;
    }

    /**
     * การ์ดสรุปของโมดูลนี้ ไปแสดงบนหน้าแรก
     *
     * หน้าแรกเรียก hook นี้ผ่าน \Gcms\Controller::initModule() แบบเดียวกับเมนู
     * ถอดโมดูลออกแล้วการ์ดหายไปเอง ไม่ต้องแก้หน้าแรก
     *
     * @param array       $cards
     * @param mixed       $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initDashboardCards($cards, $params = null, $login = null)
    {
        if (!$login || !ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $cards;
        }
        foreach (\Inventory\Dashboard\Model::cards() as $card) {
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * บล็อกตารางของโมดูลนี้ ไปแสดงบนหน้าแรกใต้การ์ด
     *
     * หน้าแรกเรียก hook นี้แบบเดียวกับการ์ดและเมนู ถอดโมดูลออกแล้วบล็อกหายไปเอง
     *
     * @param array       $blocks
     * @param mixed       $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initDashboardBlocks($blocks, $params = null, $login = null)
    {
        if (!$login || !ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $blocks;
        }
        foreach (\Inventory\Dashboard\Model::blocks() as $block) {
            $blocks[] = $block;
        }

        return $blocks;
    }
}
