<?php
/**
 * @filesource modules/inventory/models/items.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Items;

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
     * @param object $product
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($product)
    {
        $select = array('I.product_no barcode', 'I.product_no', 'I.topic', 'I.price', 'I.unit');
        if ($product->count_stock != 2) {
            $select[] = 'I.cut_stock';
        }
        $query = static::createQuery()
            ->select($select)
            ->from('inventory_items I')
            ->where(array(
                array('I.inventory_id', $product->id),
                array('I.instock', 1)
            ))
            ->order('I.product_no')
            ->toArray();
        $result = $query->execute();
        if (empty($result)) {
            $result = array(
                array(
                    'barcode' => '',
                    'product_no' => '',
                    'topic' => '',
                    'price' => '',
                    'unit' => ''
                )
            );
            if ($product->count_stock != 2) {
                $result[0]['cut_stock'] = 1;
            }
        }
        return $result;
    }

    /**
     * บันทึกข้อมูล (items.php)
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
                        // ตาราง
                        $table_items = $this->getTableName('inventory_items');
                        $table_stock = $this->getTableName('stock');
                        // Database
                        $db = $this->db();
                        // รับค่าจากการ POST
                        $product_no = $request->post('product_no', [])->topic();
                        $topic = $request->post('topic', [])->topic();
                        $price = $request->post('price', [])->toFloat();
                        $unit = $request->post('unit', [])->topic();
                        $cut_stock = $request->post('cut_stock', [])->toFloat();
                        $items = [];
                        foreach ($request->post('product_no', [])->topic() as $k => $product_no) {
                            if ($product_no != '') {
                                if (isset($items[$product_no])) {
                                    // product_no ซ้ำ
                                    $ret['ret_product_no_'.$k] = Language::replace('This :name already exist', array(':name' => Language::get('Product code')));
                                } else {
                                    // ตรวจสอบ product_no ซ้ำ (DB)
                                    $search = $db->first($table_items, array('product_no', $product_no));
                                    if ($search && $index->count_stock == 2 && $search->instock == 0) {
                                        // product_no ซ้ำ
                                        $ret['ret_product_no_'.$k] = Language::replace('This :name already exist', array(':name' => Language::get('Product code')));
                                    } elseif ($search && $search->inventory_id != $index->id) {
                                        // product_no ซ้ำ
                                        $ret['ret_product_no_'.$k] = Language::replace('This :name already exist', array(':name' => Language::get('Product code')));
                                    } else {
                                        $items[$product_no] = array(
                                            'inventory_id' => $index->id,
                                            'product_no' => $product_no,
                                            'topic' => $topic[$k],
                                            'price' => $price[$k],
                                            'unit' => $unit[$k],
                                            'cut_stock' => $index->count_stock == 2 ? 1 : $cut_stock[$k],
                                            'instock' => 1
                                        );
                                    }
                                }
                            }
                        }
                        if (empty($ret)) {
                            $orders = [];
                            if ($index->count_stock == 2) {
                                // สต๊อกแยก ตรวจสอบรายการเดิม
                                $search = $db->select($table_stock, array(
                                    array('inventory_id', $index->id),
                                    array('product_no', array_keys($items)),
                                    array('status', 'IN')
                                ), 0);
                                foreach ($search as $item) {
                                    $orders[$item['product_no']] = array(
                                        'id' => $item['id'],
                                        'order_id' => $item['order_id'],
                                        'member_id' => $item['member_id'],
                                        'create_date' => $item['create_date']
                                    );
                                }
                            }
                            // ลบข้อมูลเก่า ที่ยังไม่ได้ขาย
                            $where = array(
                                array('inventory_id', $index->id)
                            );
                            if ($index->count_stock == 2) {
                                $where[] = array('instock', 1);
                                // ลบ Stock
                                $db->delete($table_stock, array(
                                    array('inventory_id', $index->id),
                                    array('used', 0),
                                    array('status', 'IN')
                                ), 0);
                            }
                            $db->delete($table_items, $where, 0);
                            // เพิ่มรายการใหม่
                            foreach ($items as $item) {
                                $db->insert($table_items, $item);
                                if ($index->count_stock == 2) {
                                    // เพิ่ม Stock
                                    $db->insert($table_stock, array(
                                        'id' => isset($orders[$item['product_no']]) ? $orders[$item['product_no']]['id'] : null,
                                        'order_id' => isset($orders[$item['product_no']]) ? $orders[$item['product_no']]['order_id'] : 0,
                                        'member_id' => isset($orders[$item['product_no']]) ? $orders[$item['product_no']]['member_id'] : $login['id'],
                                        'create_date' => isset($orders[$item['product_no']]) ? $orders[$item['product_no']]['create_date'] : date('Y-m-d H:i:s'),
                                        'inventory_id' => $index->id,
                                        'product_no' => $item['product_no'],
                                        'topic' => null,
                                        'price' => $index->cost,
                                        'quantity' => 1,
                                        'unit' => $item['unit'],
                                        'cut_stock' => 1,
                                        'used' => 0,
                                        'vat' => 0,
                                        'discount' => 0,
                                        'total' => $index->cost
                                    ));
                                }
                            }
                            if ($index->count_stock == 2) {
                                // อัปเดท Stock
                                \Inventory\Fifo\Model::update($index->id);
                            }
                            // log
                            \Index\Log\Model::add($index->id, 'inventory', 'Save', '{LNG_Barcode} ID : '.$index->id, $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            $ret['location'] = 'reload';
                        }
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
