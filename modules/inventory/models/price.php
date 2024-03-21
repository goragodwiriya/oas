<?php
/**
 * @filesource modules/inventory/models/price.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Price;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-write&tab=price
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
     * @param int $inventory_id
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($inventory_id)
    {
        $result = static::createQuery()
            ->select('topic', 'price', 'cut_stock', 'unit')
            ->from('inventory_price')
            ->where(array('inventory_id', $inventory_id))
            ->order('topic')
            ->toArray()
            ->execute();
        if (empty($result)) {
            $result = array(
                array(
                    'topic' => '',
                    'price' => '',
                    'cut_stock' => 1,
                    'unit' => ''
                )
            );
        }
        return $result;
    }

    /**
     * บันทึกข้อมูล (price.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, can_manage_inventory, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_manage_inventory') && Login::notDemoMode($login)) {
                try {
                    // อ่านข้อมูลที่เลือก
                    $index = \Inventory\Write\Model::get($request->post('inventory_id')->toInt());
                    if ($index) {
                        // รับค่าจากการ POST
                        $topic = $request->post('topic', [])->topic();
                        $cut_stock = $request->post('cut_stock', [])->toFloat();
                        $unit = $request->post('unit', [])->topic();
                        $items = [];
                        foreach ($request->post('price', [])->toFloat() as $k => $price) {
                            if ($price > 0) {
                                if ($topic[$k] == '') {
                                    $ret['ret_topic_'.$k] = 'Please fill in';
                                }
                                if ($cut_stock[$k] <= 0) {
                                    $ret['ret_cut_stock_'.$k] = 'Please fill in';
                                }
                                $items[] = array(
                                    'inventory_id' => $index->id,
                                    'topic' => $topic[$k],
                                    'price' => $price,
                                    'cut_stock' => $cut_stock[$k],
                                    'unit' => $unit[$k]
                                );
                            }
                        }
                        // ตาราง
                        $table = $this->getTableName('inventory_price');
                        // Database
                        $db = $this->db();
                        // ลบข้อมูลเก่า
                        $db->delete($table, array('inventory_id', $index->id), 0);
                        foreach ($items as $price) {
                            $db->insert($table, $price);
                        }
                        // คืนค่า
                        $ret['alert'] = Language::get('Saved successfully');
                        $ret['location'] = 'reload';
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
