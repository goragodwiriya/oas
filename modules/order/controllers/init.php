<?php
/**
 * @filesource modules/order/controllers/init.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Init;

/**
 * Init Controller for Order Module
 *
 * Handles initialization of menus and permissions
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
        // เมนูฝั่งซื้อ
        $children = [];
        foreach (\Order\Helper\Model::$purchaseDocumentTypes as $type => $label) {
            $children[] = [
                'title' => $label,
                'url' => '/orders?document_type='.$type,
                'icon' => 'icon-documents'
            ];
        }
        $menus = parent::insertMenuAfter($menus, [
            [
                'title' => '{LNG_Purchase Documents}',
                'icon' => 'icon-product',
                'children' => $children
            ]
        ], 0);
        // เมนูฝั่งขาย
        $children = [];
        foreach (\Order\Helper\Model::$salesDocumentTypes as $type => $label) {
            $children[] = [
                'title' => $label,
                'url' => '/orders?document_type='.$type,
                'icon' => 'icon-documents'
            ];
        }
        $menus = parent::insertMenuAfter($menus, [
            [
                'title' => '{LNG_Sales Documents}',
                'icon' => 'icon-cart',
                'children' => $children
            ]
        ], 0);

        $settingsChildren = [];

        $settingsChildren[] = [
            'title' => '{LNG_Settings}',
            'url' => '/order-settings',
            'icon' => 'icon-cog'
        ];
        if (empty($settingsChildren)) {
            return $menus;
        }

        $menus = parent::insertMenuChildren($menus, [
            [
                'title' => '{LNG_Orders}',
                'icon' => 'icon-cart',
                'children' => $settingsChildren
            ]
        ], 'settings', null, 2);

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
    public static function initPermission($permissions = [], $params = [])
    {
        $permissions[] = ['value' => 'can_view_order', 'text' => '{LNG_Can view} {LNG_Order}'];
        $permissions[] = ['value' => 'can_edit_order', 'text' => '{LNG_Can edit} {LNG_Order}'];
        $permissions[] = ['value' => 'can_manage_shipping', 'text' => '{LNG_Can manage} {LNG_Shipping}'];

        return $permissions;
    }
}
