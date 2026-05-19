<?php
/**
 * @filesource modules/customer/controllers/init.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Customer\Init;

/**
 * API Authentication Controller
 *
 * Handles user authentication endpoints with production-grade security
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * Register admin menus
     *
     * @param array  $menus
     * @param array  $params
     * @param object $login
     *
     * @return array
     */
    public static function initMenus($menus, $params, $login)
    {
        $children = [];

        if (\Gcms\Api::hasPermission($login, 'can_view_customer') || $params['isAdmin']) {
            $children[] = [
                'title' => '{LNG_Customer}',
                'url' => '/customers?type=customer',
                'icon' => 'icon-user'
            ];
            $children[] = [
                'title' => '{LNG_Supplier}',
                'url' => '/customers?type=supplier',
                'icon' => 'icon-customer'
            ];
        }

        if (empty($children)) {
            return $menus;
        }

        $menus = parent::insertMenuAfter($menus, [
            [
                'title' => '{LNG_Customer}/{LNG_Supplier}',
                'icon' => 'icon-users',
                'children' => $children
            ]
        ], 0);

        // return menus
        return $menus;
    }

    /**
     * Get permission data
     *
     * @param array $permissions
     * @param array $params
     *
     * @return array
     */
    public static function initPermission($permissions, $params)
    {
        // Add Permissions
        $permissions[] = ['value' => 'can_view_customer', 'text' => '{LNG_Can view} {LNG_Customers}'];
        $permissions[] = ['value' => 'can_manage_customer', 'text' => '{LNG_Can manage} {LNG_Customers}'];
        $permissions[] = ['value' => 'can_delete_customer', 'text' => '{LNG_Can delete} {LNG_Customers}'];
        $permissions[] = ['value' => 'can_view_customer_report', 'text' => '{LNG_Can view} {LNG_Customer} {LNG_Reports}'];

        // return permissions
        return $permissions;
    }
}
