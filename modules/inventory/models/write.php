<?php
/**
 * @filesource modules/inventory/models/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Write;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลรายการที่เลือก
     * ถ้า $id = 0 หมายถึงรายการใหม่
     * คืนค่าข้อมูล object ไม่พบคืนค่า null
     *
     * @param int $id ID
     *
     * @return object|null
     */
    public static function get($id)
    {
        if (empty($id)) {
            // ใหม่
            return (object) array(
                'id' => 0,
                'product_no' => '',
                'topic' => '',
                'count_stock' => 1,
                'cost' => 0,
                'price' => '',
                'unit' => '',
                'vat' => 0,
                'category_id' => 0
            );
        } else {
            // แก้ไข อ่านรายการที่เลือก
            $query = static::createQuery()
                ->from('inventory V')
                ->where(array('V.id', $id));
            $select = array('V.*');
            $n = 1;
            foreach (Language::get('INVENTORY_METAS', array()) as $key => $label) {
                $query->join('inventory_meta M'.$n, 'LEFT', array(array('M'.$n.'.inventory_id', 'V.id'), array('M'.$n.'.name', $key)));
                $select[] = 'M'.$n.'.value '.$key;
                ++$n;
            }
            return $query->first($select);
        }
    }

    /**
     * บันทึกข้อมูลที่ส่งมาจากฟอร์ม (write.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, can_manage_inventory, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_manage_inventory') && Login::notDemoMode($login)) {
                try {
                    // รับค่าจากการ POST
                    $save = array(
                        'topic' => $request->post('write_topic')->topic(),
                        'count_stock' => $request->post('write_count_stock')->toInt(),
                        // ใหม่
                        'product_no' => $request->post('write_product_no')->topic(),
                        'create_date' => $request->post('write_create_date')->date(),
                        'cost' => $request->post('write_cost')->toDouble(),
                        'stock' => $request->post('write_stock')->toDouble(),
                        'buy_vat' => $request->post('write_buy_vat')->toInt(),
                        'price' => $request->post('write_price')->toDouble(),
                        'unit' => $request->post('write_unit')->topic(),
                        'vat' => $request->post('write_vat')->toInt(),
                        'member_id' => $login['id']
                    );
                    // database connection
                    $db = $this->db();
                    // อ่านข้อมูลที่เลือก
                    $index = self::get($request->post('write_id')->toInt());
                    if ($index) {
                        // หมวดหมู่
                        $category = \Inventory\Category\Model::init(false);
                        foreach ($category->items() as $key => $label) {
                            if ($key == 'category_id') {
                                $save['category'] = $request->post('category_id_text')->topic();
                            } else {
                                $save[$key] = $category->save($key, $request->post($key.'_text')->topic());
                            }
                        }
                        // ตาราง inventory
                        $table_inventory = $this->getTableName('inventory');
                        $table_items = $this->getTableName('inventory_items');
                        if ($index->id == 0) {
                            // ใหม่ ตรวจสอบ product_no
                            if (empty($save['product_no'])) {
                                // ถ้าไม่ได้กรอก product_no มา สร้างเลข running number
                                $save['product_no'] = \Index\Number\Model::get($index->id, 'product_no', $table_items, 'product_no', 'P');
                            } else {
                                // ตรวจสอบ product_no ซ้ำ
                                $search = $db->first($table_items, array('product_no', $save['product_no']));
                                if ($search && $search->inventory_id != $index->id) {
                                    $ret['ret_write_product_no'] = Language::replace('This :name already exist', array(':name' => Language::get('Product code')));
                                }
                            }
                        }
                        // ตรวจสอบ topic
                        if (empty($save['topic'])) {
                            $ret['ret_write_topic'] = 'Please fill in';
                        } else {
                            $search = $db->first($table_inventory, array(
                                array('topic', $save['topic'])
                            ));
                            if ($search !== false && $index->id != $search->id) {
                                $ret['ret_write_topic'] = Language::replace('This :name already exist', array(':name' => Language::get('Product name')));
                            }
                        }
                        if (empty($ret)) {
                            // save
                            if ($index->id == 0) {
                                // ใหม่ (เพิ่ม Stock ด้วย)
                                if ($save['count_stock'] == 2) {
                                    // นับสต๊อกแยก มีจำนวนสินค้าเท่ากับ 1 เสมอ
                                    $save['stock'] = 1;
                                }
                                $id = \Inventory\Product\Model::create($save);
                            } else {
                                // แก้ไขรายละเอียดของสินค้าเท่านั้น
                                $id = \Inventory\Product\Model::update($index, $save);
                            }
                            // log
                            \Index\Log\Model::add($id, 'inventory', 'Save', '{LNG_Product} ID : '.$id, $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            if ($request->post('modal')->toString() == 'xhr') {
                                // ปิด modal
                                $ret['modal'] = 'close';
                                // คืนค่าข้อมูล
                                $ret['product_no'] = $save['product_no'];
                            } elseif ($index->id == 0) {
                                // ใหม่
                                $save_and_create = $request->post('save_and_create')->toInt();
                                if ($save_and_create == 1) {
                                    // เพิ่มรายการใหม่
                                    $ret['location'] = 'reload';
                                } else {
                                    // ไปหน้าแรก แสดงรายการใหม่
                                    $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-setup', 'id' => null, 'page' => null));
                                }
                                // save cookie
                                setcookie('save_and_create', $save_and_create, time() + 2592000, '/', HOST, HTTPS, true);
                            } else {
                                // แก้ไข กลับไปหน้ารายการสินค้า
                                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-setup', 'id' => null));
                            }
                            // เคลียร์
                            $request->removeToken();
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
