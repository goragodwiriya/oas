<?php
/**
 * @filesource modules/inventory/controllers/init.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Init;

use Gcms\Api as ApiController;

/**
 * Init Controller for Inventory Module
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * Register inventory permissions.
     *
     * @param array $permissions
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initPermission($permissions, $params = null, $login = null)
    {
        $permissions[] = [
            'value' => 'can_manage_inventory',
            'text' => '{LNG_Can manage} {LNG_Inventory}'
        ];

        return $permissions;
    }

    /**
     * Register inventory menus.
     *
     * @param array $menus
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initMenus($menus, $params = null, $login = null)
    {
        if (!$login) {
            return $menus;
        }

        $children = [];

        $children[] = [
            'title' => '{LNG_Product}',
            'url' => '/inventory-products',
            'icon' => 'icon-list'
        ];
        $children[] = [
            'title' => '{LNG_Stock Movement}',
            'url' => '/inventory-stock-movements',
            'icon' => 'icon-report'
        ];
        $children[] = [
            'title' => '{LNG_Cost Layers}',
            'url' => '/inventory-cost-layers',
            'icon' => 'icon-report'
        ];

        $menus = parent::insertMenuAfter($menus, [
            [
                'title' => '{LNG_Inventory}',
                'icon' => 'icon-product',
                'children' => $children
            ]
        ], 0);

        // Inventory settings
        $settingsChildren = [];

        $settingsChildren[] = [
            'title' => '{LNG_Settings}',
            'url' => '/inventory-settings',
            'icon' => 'icon-cog'
        ];

        if (ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
            foreach (\Inventory\Category\Controller::items() as $key => $title) {
                $settingsChildren[] = [
                    'title' => $title,
                    'url' => '/inventory-categories?type='.$key,
                    'icon' => 'icon-tags'
                ];
            }
        }

        if (empty($settingsChildren)) {
            return $menus;
        }

        return parent::insertMenuChildren($menus, [
            [
                'title' => '{LNG_Product}',
                'icon' => 'icon-product',
                'children' => $settingsChildren
            ]
        ], 'settings', null, 2);
    }
}
