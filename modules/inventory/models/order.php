<?php
/**
 * @filesource modules/inventory/models/order.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Order;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-order
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูล Order
     *
     * @param int $id คืนค่ารายการใหม่, > คืนค่ารายการที่เลือก
     * @param string $status
     *
     * @return object|null ไม่พบคืนค่า null
     */
    public static function get($id, $status)
    {
        if ($id > 0) {
            return self::createQuery()
                ->from('orders O')
                ->join('customer U', 'LEFT', array('U.id', 'O.customer_id'))
                ->where(array('O.id', $id))
                ->first('O.*', 'U.company customer', 'U.customer_no', 'U.name contactor', 'U.branch', 'U.address', 'U.province', 'U.zipcode', 'U.country', 'U.phone', 'U.email', 'U.tax_id');
        } else {
            return (object) array(
                'id' => 0,
                'customer_id' => 0,
                'customer_no' => '',
                'customer' => '',
                'order_no' => '',
                'order_date' => date('Y-m-d H:i:s'),
                'discount' => 0,
                'vat' => 0,
                'tax' => 0,
                'comment' => '',
                'status' => $status,
                'tax_status' => 0,
                'vat_status' => self::$request->cookie('vat_status')->toInt(),
                'discount_percent' => 0,
                'due_date' => date('Y-m-d')
            );
        }
    }

    /**
     * บันทึกข้อมูลการสั่งซื้อ (order.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, สามารถ ซื้อ/ขาย ได้, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_inventory_order') && Login::notDemoMode($login)) {
                try {
                    // ค่าที่ส่งมา
                    $order = array(
                        'order_no' => $request->post('order_no')->topic(),
                        'customer_id' => $request->post('customer_id')->toInt(),
                        'comment' => $request->post('comment')->textarea(),
                        'order_date' => $request->post('order_date')->date(),
                        'due_date' => $request->post('due_date')->date(),
                        'discount_percent' => $request->post('discount_percent')->toDouble(),
                        'discount' => $request->post('total_discount')->toDouble(),
                        'tax' => $request->post('tax_total')->toDouble(),
                        'vat' => $request->post('vat_total')->toDouble(),
                        'total' => $request->post('amount')->toDouble(),
                        'vat_status' => $request->post('vat_status')->toInt(),
                        'tax_status' => $request->post('tax_status')->toInt(),
                        'status' => $request->post('status')->filter('A-Z')
                    );
                    $order_id = $request->post('order_id')->toInt();
                    // ชื่อตาราง
                    $table_orders = $this->getTableName('orders');
                    $table_stock = $this->getTableName('stock');
                    // Database
                    $db = $this->db();
                    // ตรวจสอบรายการ order ที่เลือก
                    $orders = \Inventory\Order\Model::get($order_id, $order['status']);
                    if (!$orders) {
                        // ไม่พบข้อมูลที่แก้ไข
                        $ret['alert'] = Language::get('Sorry, Item not found It&#39;s may be deleted');
                    } elseif (empty($order['customer_id']) && in_array($order['status'], self::$cfg->buy_status)) {
                        // ทำรายการซื้อ ไม่ได้เลือก Supplyer
                        $ret['ret_customer'] = 'Please fill in';
                    } else {
                        // สินค้าที่เลือก
                        $quantity = $request->post('quantity', array())->toDouble();
                        $topic = $request->post('topic', array())->topic();
                        $price = $request->post('price', array())->toDouble();
                        $discount = $request->post('discount', array())->toDouble();
                        $total = $request->post('total', array())->toDouble();
                        $vat = $request->post('vat', array())->toDouble();
                        $productStock = array();
                        foreach ($request->post('product_no', array())->topic() as $k => $product_no) {
                            if (isset($productStock[$product_no])) {
                                // product_no ซ้ำ
                                $ret['ret_topic_'.$k] = Language::replace('This :name already exist', array(':name' => Language::get('Product code')));
                            } else {
                                $productStock[$product_no] = array(
                                    'id' => null,
                                    'row' => $k,
                                    'inventory_id' => 0,
                                    'topic' => $topic[$k],
                                    'quantity' => $quantity[$k],
                                    'price' => $price[$k],
                                    'discount' => $discount[$k],
                                    'total' => $total[$k],
                                    'vat' => empty($vat[$k]) ? 0 : 1,
                                    'member_id' => $login['id'],
                                    'create_date' => $order['order_date'],
                                    'stock' => 0
                                );
                            }
                        }
                        // สำหรับอัปเดท stock
                        $inventory = array();
                        // ตรวจสอบ Stock เดิม
                        if ($order_id > 0) {
                            foreach ($db->select($table_stock, array('order_id', $order_id)) as $item) {
                                if (isset($inventory[$item['inventory_id']])) {
                                    $inventory[$item['inventory_id']] += ($item['quantity'] * $item['cut_stock']);
                                } else {
                                    $inventory[$item['inventory_id']] = $item['quantity'] * $item['cut_stock'];
                                }
                                if (isset($productStock[$item['product_no']])) {
                                    $productStock[$item['product_no']]['stock'] = $item['quantity'] * $item['cut_stock'];
                                    $productStock[$item['product_no']]['id'] = $item['id'];
                                    $productStock[$item['product_no']]['member_id'] = $item['member_id'];
                                    $productStock[$item['product_no']]['create_date'] = $item['create_date'];
                                }
                            }
                        }
                        // ตรวจสอบ Stock ปัจจุบัน
                        $query = static::createQuery()
                            ->select('V.id', 'I.product_no', 'V.stock', 'V.count_stock', 'I.cut_stock', 'I.unit', 'S.id stock_id')
                            ->from('inventory_items I')
                            ->join('inventory V', 'INNER', array('V.id', 'I.inventory_id'))
                            ->join('stock S', 'LEFT', array(array('S.inventory_id', 'I.inventory_id'), array('S.product_no', 'I.product_no'), array('S.status', 'OUT')))
                            ->where(array('I.product_no', array_keys($productStock)))
                            ->groupBy('I.product_no')
                            ->order('I.cut_stock DESC');
                        foreach ($query->execute() as $item) {
                            if ($order['status'] == 'OUT' && $item->count_stock == 1) {
                                // stock รวม
                                if (isset($inventory[$item->id])) {
                                    $inventory[$item->id] += ($item->stock * $item->cut_stock);
                                } else {
                                    $inventory[$item->id] = $item->stock * $item->cut_stock;
                                }
                                $productStock[$item->product_no]['stock'] = $inventory[$item->id];
                                $inventory[$item->id] -= ($productStock[$item->product_no]['quantity'] * $item->cut_stock);
                            } elseif ($order['status'] == 'OUT' && $item->count_stock == 2) {
                                // stock แยก
                                if (empty($item->stock_id)) {
                                    $productStock[$item->product_no]['stock'] = 1;
                                }
                            } else {
                                // ไม่นับสต๊อก
                                $productStock[$item->product_no]['stock'] = 99999999999999;
                            }
                            $productStock[$item->product_no]['cut_stock'] = $item->cut_stock;
                            $productStock[$item->product_no]['unit'] = $item->unit;
                            $productStock[$item->product_no]['inventory_id'] = $item->id;
                        }
                        $stock = array();
                        foreach ($productStock as $product_no => $item) {
                            if ($item['topic'] != '' && $item['quantity'] > 0) {
                                if (empty($item['stock'])) {
                                    $ret['ret_quantity_'.$item['row']] = Language::replace('Not enough products, Remaining :stock', array(':stock' => 0));
                                } elseif ($item['quantity'] * $item['cut_stock'] > $item['stock']) {
                                    $ret['ret_quantity_'.$item['row']] = Language::replace('Not enough products, Remaining :stock', array(':stock' => $item['stock'] / $item['cut_stock']));
                                } else {
                                    $inventory[$item['inventory_id']] = 0;
                                    $stock[] = array(
                                        'id' => $item['id'],
                                        'create_date' => $item['create_date'],
                                        'member_id' => $item['member_id'],
                                        'inventory_id' => $item['inventory_id'],
                                        'product_no' => $product_no,
                                        'status' => $order['status'],
                                        'topic' => $item['topic'],
                                        'quantity' => $item['quantity'],
                                        'cut_stock' => $item['cut_stock'],
                                        'unit' => $item['unit'],
                                        'used' => 0,
                                        'price' => $item['price'],
                                        'vat' => $item['vat'],
                                        'discount' => $item['discount'],
                                        'total' => $item['total']
                                    );
                                }
                            } elseif ($item['topic'] != '' && $item['quantity'] == 0) {
                                $ret['ret_quantity_'.$item['row']] = 'Please fill in';
                            } elseif ($item['quantity'] > 0 && $item['topic'] == '') {
                                $ret['ret_topic_'.$item['row']] = 'Please fill in';
                            }
                        }
                        if (empty($ret)) {
                            if (empty($stock)) {
                                // ไม่ได้เลือกสินค้า
                                $ret['ret_product_no'] = 'this';
                            } else {
                                // save order
                                if ($order['order_no'] == '') {
                                    // สร้างเลข running number
                                    $order['order_no'] = \Index\Number\Model::get($order_id, $order['status'].'_NO', $table_orders, 'order_no', $order['status']);
                                } else {
                                    // ตรวจสอบ order_no ซ้ำ
                                    $search = $db->first($table_orders, array('order_no', $order['order_no']));
                                    if ($search !== false && $order_id != $search->id) {
                                        $ret['ret_order_no'] = Language::replace('This :name already exist', array(':name' => Language::get('Order No.')));
                                    }
                                }
                            }
                        }
                        if (empty($ret)) {
                            if ($order_id > 0) {
                                // แก้ไข
                                $db->update($table_orders, array('id', $order_id), $order);
                            } else {
                                // ใหม่
                                $order['member_id'] = $login['id'];
                                $order_id = $db->insert($table_orders, $order);
                            }
                            // ลบ Stock เก่า
                            $db->delete($table_stock, array('order_id', $order_id), 0);
                            // save Stock
                            foreach ($stock as $save) {
                                $save['order_id'] = $order_id;
                                $db->insert($table_stock, $save);
                            }
                            // อัปเดต Stock
                            \Inventory\Fifo\Model::update(array_keys($inventory));
                            if (in_array($order['status'], self::$cfg->buy_status)) {
                                $log = '{LNG_Purchase} ID : '.$order_id;
                            } else {
                                $log = '{LNG_Sales} ID : '.$order_id;
                            }
                            // log
                            \Index\Log\Model::add($order_id, 'inventory', 'Save', $log, $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            $save_and_create = $request->post('save_and_create')->toInt();
                            if ($save_and_create == 1) {
                                // reload
                                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-order', 'status' => $order['status'], 'id' => null));
                            } else {
                                // กลับไปหน้ารวมรายการ Order
                                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-orders', 'status' => $order['status'], 'id' => null));
                            }
                            // save cookie
                            setcookie('save_and_create', $save_and_create, time() + 2592000, '/', HOST, HTTPS, true);
                            setcookie('vat_status', $order['vat_status'], time() + 2592000, '/', HOST, HTTPS, true);
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
