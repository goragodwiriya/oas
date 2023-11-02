<?php
/**
 * @filesource modules/inventory/models/autocomplete.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Autocomplete;

use Gcms\Login;
use Kotchasan\Database\Sql;
use Kotchasan\Http\Request;

/**
 * ค้นหา สำหรับ autocomplete
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ค้นหา สำหรับ autocomplete
     * คืนค่าเป็น JSON
     *
     * @param Request $request
     */
    public function findCustomer(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                $search = $request->post('name')->topic();
                if ($search != '') {
                    $where = array();
                    $select = array('id customer_id', 'company customer', 'name', 'email', 'phone', 'customer_no');
                    $order = array();
                    foreach (explode(',', $request->post('from', 'name,email')->filter('a-z,_')) as $item) {
                        if ($item == 'name') {
                            if ($search != '') {
                                $where[] = array('name', 'LIKE', "%$search%");
                            }
                            $order[] = 'name';
                        }
                        if ($item == 'email') {
                            if ($search != '') {
                                $where[] = array('email', 'LIKE', "%$search%");
                            }
                            $order[] = 'email';
                        }
                        if ($item == 'phone') {
                            if ($search != '') {
                                $where[] = array('phone', 'LIKE', "$search%");
                            }
                            $order[] = 'phone';
                        }
                        if ($item == 'company') {
                            if ($search != '') {
                                $where[] = array('company', 'LIKE', "%$search%");
                            }
                            $order[] = 'company';
                        }
                        if ($item == 'customer_no') {
                            if ($search != '') {
                                $where[] = array('customer_no', 'LIKE', "%$search%");
                            }
                            $order[] = 'customer_no';
                        }
                        if ($item == 'discount') {
                            $select[] = 'discount';
                        }
                    }
                    $query = $this->db()->createQuery()
                        ->select($select)
                        ->from('customer')
                        ->order($order)
                        ->limit($request->post('count')->toInt())
                        ->toArray();
                    if (!empty($where)) {
                        $query->andWhere($where, 'OR');
                    }
                    $result = $query->execute();
                    // คืนค่า JSON
                    if (!empty($result)) {
                        echo json_encode($query->execute());
                    }
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }

    /**
     * ค้นหาสินค้า สำหรับ autocomplete ที่กำลังใช้งานอยู่
     * คืนค่าเป็น JSON
     *
     * @param Request $request
     */
    public function findProduct(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                $search = $request->post('name')->topic();
                if ($search != '') {
                    $status = $request->post('status')->filter('A-Z');
                    $where = array();
                    if (in_array($status, self::$cfg->sell_status)) {
                        // ขาย
                        $where[] = array('V.inuse', 1);
                        if ($status == 'OUT') {
                            $where[] = array('I.instock', 1);
                        }
                    }
                    $andWhere = array();
                    $order = array();
                    foreach (explode(',', $request->post('from', 'product_no,topic')->filter('a-z_,')) as $item) {
                        if ($item == 'product_no') {
                            if ($search != '') {
                                $andWhere[] = array('I.product_no', 'LIKE', "%$search%");
                            }
                            $order[] = 'I.product_no';
                        }
                        if ($item == 'topic') {
                            if ($search != '') {
                                $andWhere[] = array('V.topic', 'LIKE', "%$search%");
                            }
                            $order[] = 'V.topic';
                        }
                    }
                    $return = $this->db()->createQuery()
                        ->select('I.product_no', Sql::CONCAT(array('V.topic', 'I.topic'), 'topic', ' '), 'I.price', 'V.cost', 'V.stock', 'V.count_stock', 'I.unit')
                        ->from('inventory V')
                        ->join('inventory_items I', 'INNER', array('I.inventory_id', 'V.id'))
                        ->where($where)
                        ->andWhere($andWhere, 'OR')
                        ->order('I.product_no')
                        ->limit($request->post('count')->toInt())
                        ->toArray()
                        ->execute();
                    // คืนค่า JSON
                    echo json_encode($return);
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }
}
