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
                $where = [];
                $select = array('id', 'name');
                $order = [];
                foreach (explode(',', $request->post('from', 'name')->filter('a-z,')) as $item) {
                    if ($item == 'name') {
                        if ($search != '') {
                            $where[] = array('name', 'LIKE', "%$search%");
                        }
                        $order[] = 'name';
                    }
                    if ($item == 'username') {
                        if ($search != '') {
                            $where[] = array('username', 'LIKE', "%$search%");
                        }
                        $select[] = 'username';
                        $order[] = 'username';
                    }
                    if ($item == 'phone') {
                        if ($search != '') {
                            $where[] = array('phone', 'LIKE', "$search%");
                        }
                        $select[] = 'phone';
                        $order[] = 'phone';
                    }
                    if ($item == 'id_card') {
                        if ($search != '') {
                            $where[] = array('id_card', 'LIKE', "$search%");
                        }
                        $select[] = 'id_card';
                        $order[] = 'id_card';
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
                    echo json_encode($result);
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }
}
