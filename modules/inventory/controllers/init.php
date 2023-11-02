<?php
/**
 * @filesource modules/inventory/controllers/init.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Init;

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
     * รายการ permission ของโมดูล
     *
     * @param array $permissions
     *
     * @return array
     */
    public static function updatePermissions($permissions)
    {
        $permissions['can_inventory_order'] = '{LNG_Can sell items}';
        $permissions['can_inventory_receive'] = '{LNG_Can make an order}';
        $permissions['can_manage_inventory'] = '{LNG_Can manage the inventory}';
        return $permissions;
    }
}
