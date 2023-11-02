<?php
/**
 * @filesource modules/index/models/autocomplete.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Autocomplete;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * สำหรับ autocomplete
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ค้นหาสมาชิก สำหรับ autocomplete
     * คืนค่าเป็น JSON
     *
     * @param Request $request
     */
    public function findUser(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                // ข้อความค้นหา
                $search = $request->post('name')->topic();
                // 1 (default) คืนค่าสมาชิกที่สามารถเข้าระบบได้ (active=1), 0 ทั้งหมด
                $active = $request->post('active', 1)->toBoolean();
                // query
                $where = array();
                $select = array('id', 'name', 'email');
                $order = array();
                foreach (explode(',', $request->post('from', 'name,email')->filter('a-z,')) as $item) {
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
                        $select[] = 'phone';
                        $order[] = 'phone';
                    }
                    if ($item == 'company') {
                        if ($search != '') {
                            $where[] = array('company', 'LIKE', "$search%");
                        }
                        $select[] = 'company';
                        $order[] = 'company';
                    }
                    if ($item == 'discount') {
                        $select[] = 'discount';
                    }
                }
                $query = $this->db()->createQuery()
                    ->select($select)
                    ->from('user')
                    ->order($order)
                    ->limit($request->post('count')->toInt())
                    ->toArray();
                if ($active) {
                    $query->where(array('active', 1));
                }
                if (!empty($where)) {
                    $query->andWhere($where, 'OR');
                }
                $result = $query->execute();
                // คืนค่า JSON
                if (!empty($result)) {
                    echo json_encode($query->execute());
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }

    /**
     * ค้นหาหมวดหมู่ สำหรับ autocomplete
     * คืนค่าเป็น JSON
     *
     * @param Request $request
     */
    public function findCategory(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                $search = $request->post('name')->topic();
                $query = $this->db()->createQuery()
                    ->select('category_id id', 'topic name')
                    ->from('category')
                    ->where(array(
                        array('type', $request->post('typ')->filter('a-z_'))
                    ))
                    ->order('topic')
                    ->limit($request->post('count')->toInt())
                    ->toArray();
                if ($search != '') {
                    $query->andWhere(array('topic', 'LIKE', '%'.$search.'%'));
                }
                $result = $query->execute();
                // คืนค่า JSON
                if (!empty($result)) {
                    echo json_encode($result);
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }
}
