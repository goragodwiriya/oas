<?php
/**
 * @filesource modules/index/models/permission.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Permission;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * module=permission
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
     * @param array $user_permission
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = array(
            array('id', '!=', 1)
        );
        if ($params['status'] > -1) {
            $where[] = array('U.status', $params['status']);
        }
        $select = array('id', 'username', 'name', 'permission', 'status');
        foreach ($params['permission'] as $k => $v) {
            $select[] = '0 '.$k;
        }
        return static::createQuery()
            ->select($select)
            ->from('user')
            ->where($where);
    }

    /**
     * ตารางสมาชิก (permission.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = array();
        // session, referer, admin, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                if ($action === 'login') {
                    // เข้าระบบเป็นสมาชิกอื่น
                    $ret = \Index\Login\Model::loginAs($request->post('id')->toInt(), $login);
                } else {
                    // สิทธิสมาชิก
                    $permission = \Gcms\Controller::getPermissions();
                    if (isset($permission[$action])) {
                        // ตรวจสอบ permission ปัจจุบัน
                        $user = $this->db()->createQuery()
                            ->from('user')
                            ->where(array(
                                array('id', $request->post('id')->toInt()),
                                array('id', '!=', 1)
                            ))
                            ->first('id', 'permission');
                        if ($user) {
                            $user_permission = empty($user->permission) ? array() : explode(',', trim($user->permission, " \t\n\r\0\x0B,"));
                            if (($key = array_search($action, $user_permission)) !== false) {
                                // ลบ permission
                                unset($user_permission[$key]);
                            } else {
                                // เพิ่ม permission
                                $user_permission[] = $action;
                            }
                            // save
                            $this->db()->update($this->getTableName('user'), $user->id, array(
                                'permission' => empty($user_permission) ? '' : ','.implode(',', $user_permission).','
                            ));
                            // log
                            \Index\Log\Model::add(0, 'index', 'User', '{LNG_Update permission} ID : '.$user->id, $login['id']);
                            // คืนค่า
                            $ret['elem'] = $action.'_'.$user->id;
                            $ret['class'] = 'icon-valid '.(in_array($action, $user_permission) ? 'access' : 'disabled');
                        }
                    }
                }
            }
        }
        // คืนค่า JSON
        echo json_encode($ret);
    }
}
