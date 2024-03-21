<?php
/**
 * @filesource modules/inventory/models/setup.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Setup;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-setup
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query ข้อมูลสำหรับส่งให้กับ DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['category_id'] > 0) {
            $where[] = array('V.category_id', $params['category_id']);
        }
        return static::createQuery()
            ->select('V.id', 'V.topic', 'I.product_no', 'V.category_id', 'V.cost', 'V.stock', 'V.count_stock')
            ->from('inventory V')
            ->join('inventory_items I', 'LEFT', array(array('I.inventory_id', 'V.id'), array('I.cut_stock', 1)))
            ->where($where)
            ->groupBy('V.id');
    }

    /**
     * รับค่าจาก action (setup.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = [];
        // session, referer, can_manage_inventory, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && Login::checkPermission($login, 'can_manage_inventory')) {
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                // Database
                $db = $this->db();
                // id ที่ส่งมา
                if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->filter('0-9,'), $match)) {
                    if ($action === 'delete') {
                        // ลบ
                        $db->delete($this->getTableName('inventory'), array('id', $match[1]), 0);
                        $db->delete($this->getTableName('inventory_meta'), array('inventory_id', $match[1]), 0);
                        $db->delete($this->getTableName('inventory_items'), array('inventory_id', $match[1]), 0);
                        // ลบรูปภาพ
                        $dir = ROOT_PATH.DATA_FOLDER.'inventory/';
                        foreach ($match[1] as $id) {
                            if (is_file($dir.$id.'.jpg')) {
                                unlink($dir.$id.'.jpg');
                            }
                        }
                        // log
                        \Index\Log\Model::add(0, 'inventory', 'Delete', '{LNG_Delete} {LNG_Inventory} ID : '.implode(', ', $match[1]), $login['id']);
                        // reload
                        $ret['location'] = 'reload';
                    }
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่า JSON
        echo json_encode($ret);
    }
}
