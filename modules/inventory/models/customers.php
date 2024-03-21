<?php
/**
 * @filesource modules/inventory/models/customers.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Customers;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * module=inventory-customers
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลสำหรับใส่ลงในตาราง
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable()
    {
        return static::createQuery()
            ->select('customer_no', 'company', 'branch', 'phone', 'email', 'id')
            ->from('customer');
    }

    /**
     * รับค่าจาก action
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        // session, referer, สามารถขายได้, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (Login::checkPermission($login, array('can_inventory_order', 'can_manage_inventory')) && Login::notDemoMode($login)) {
                // id ที่ส่งมา
                if ($request->post('action')->toString() === 'delete' && preg_match_all('/,?([0-9]+),?/', $request->post('id')->toString(), $match)) {
                    // ลบลูกค้ายังไม่เคยทำรายการสั่งซื้อสินค้า
                    self::createQuery()
                        ->delete('customer', array(
                            array('id', $match[1])
                        ))
                        ->notExists('orders', array(
                            array('customer_id', $match[1])
                        ))
                        ->execute();
                    // log
                    \Index\Log\Model::add(0, 'inventory', 'Delete', '{LNG_Delete} {LNG_Customer list}-{LNG_Supplier} ID : '.implode(', ', $match[1]), $login['id']);
                }
            }
        }
    }
}
