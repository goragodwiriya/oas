<?php
/**
 * @filesource modules/index/models/member.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Member;

use Gcms\Login;
use Kotchasan\Database\Sql;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=member
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
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['status'] > -1) {
            $where[] = array('U.status', $params['status']);
        }
        $query = static::createQuery()
            ->from('user U');
        $select = array('U.id', 'U.username', 'U.name', 'U.active', 'U.activatecode', 'U.social', 'U.phone');
        $category = \Index\Category\Model::init(false);
        $n = 0;
        foreach ($category->items() as $k => $label) {
            if (!$category->isEmpty($k)) {
                $query->join('user_meta D'.$n, 'LEFT', array(array('D'.$n.'.member_id', 'U.id'), array('D'.$n.'.name', $k)));
                $select[] = Sql::GROUP_CONCAT("D$n.value", $label, ',', true);
                if (!empty($params[$k])) {
                    $where[] = array("D$n.value", $params[$k]);
                }
                $n++;
            }
        }
        $select[] = 'U.create_date';
        $select[] = 'U.status';
        return $query->select($select)
            ->where($where)
            ->groupBy('U.id');
    }

    /**
     * คืนค่าจำนวนสมาชิกทั้งหมดที่รอยืนยัน
     *
     * @return int
     */
    public static function watingForActivate()
    {
        $query = static::createQuery()
            ->selectCount()
            ->from('user')
            ->where(array('active', 0))
            ->execute();
        return $query[0]->count;
    }

    /**
     * ตารางสมาชิก (member.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = [];
        // session, referer, admin, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                // id ที่ส่งมา
                if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->filter('0-9,'), $match)) {
                    if ($action === 'delete') {
                        // ลบสมาชิก
                        $this->db()->delete($this->getTableName('user'), array(
                            array('id', $match[1]),
                            array('id', '!=', 1)
                        ), 0);
                        $this->db()->delete($this->getTableName('user_meta'), array(
                            array('member_id', $match[1]),
                            array('member_id', '!=', 1)
                        ), 0);
                        // ลบไฟล์
                        foreach ($match[1] as $id) {
                            if ($id != 1) {
                                // ชื่อโฟลเดอร์ที่เก็บไฟล์ของสมาชิกที่ต้องการลบ
                                foreach (array('avatar') as $item) {
                                    $img = ROOT_PATH.DATA_FOLDER.$item.'/'.$id.'.jpg';
                                    if (file_exists($img)) {
                                        unlink($img);
                                    }
                                }
                            }
                        }
                        // log
                        \Index\Log\Model::add(0, 'index', 'User', '{LNG_Delete} {LNG_User} ID : '.implode(', ', $match[1]), $login['id']);
                        // reload
                        $ret['location'] = 'reload';
                    } elseif ($action === 'sendpassword') {
                        // ขอรหัสผ่านใหม่
                        $query = $this->db()->createQuery()
                            ->select('id', 'username')
                            ->from('user')
                            ->where(array(
                                array('id', $match[1]),
                                array('id', '!=', 1),
                                array('social', 0),
                                array('username', '!=', ''),
                                array('active', 1)
                            ))
                            ->toArray();
                        $msgs = [];
                        foreach ($query->execute() as $item) {
                            // ส่งอีเมลขอรหัสผ่านใหม่
                            $err = \Index\Forgot\Model::execute($item['id'], $item['username']);
                            if ($err != '') {
                                $msgs[] = $err;
                            }
                        }
                        if (isset($err)) {
                            if (empty($msgs)) {
                                // ส่งอีเมล สำเร็จ
                                $ret['alert'] = Language::get('Your message was sent successfully');
                            } else {
                                // มีข้อผิดพลาด
                                $ret['alert'] = implode("\n", $msgs);
                            }
                        }
                    } elseif (preg_match('/activate_([01])/', $action, $match2)) {
                        // ยืนยันสมาชิก, ส่งอีเมลยืนยันสมาชิก
                        $query = $this->db()->createQuery()
                            ->select('id', 'username', 'name')
                            ->from('user')
                            ->where(array(
                                array('id', $match[1]),
                                array('id', '!=', 1),
                                array('social', 0),
                                array('username', '!=', ''),
                                array('active', 1)
                            ));
                        $emails = [];
                        foreach ($query->execute() as $item) {
                            $emails[$item->id] = array(
                                'username' => $item->username,
                                'name' => $item->name
                            );
                        }
                        if ($match2[1] == '1') {
                            // ยืนยันสมาชิก
                            $this->db()->update($this->getTableName('user'), array('id', array_keys($emails)), array(
                                'activatecode' => ''
                            ));
                            // log
                            \Index\Log\Model::add(0, 'index', 'User', '{LNG_Email address verification} ID : '.implode(', ', $match[1]), $login['id']);
                        } else {
                            // ส่งอีเมลยืนยันสมาชิก
                            foreach ($emails as $id => $item) {
                                $item['activatecode'] = md5($item['username'].uniqid());
                                $this->db()->update($this->getTableName('user'), array('id', $id), $item);
                                \Index\Email\Model::send($item, '******');
                            }
                            // log
                            \Index\Log\Model::add(0, 'index', 'User', '{LNG_Send member confirmation email} ID : '.implode(', ', $match[1]), $login['id']);
                        }
                        // reload
                        $ret['location'] = 'reload';
                    } elseif (preg_match('/active_([012])/', $action, $match2)) {
                        // สถานะการเข้าระบบ
                        $this->db()->update($this->getTableName('user'), array(
                            array('id', $match[1]),
                            array('id', '!=', '1')
                        ), array(
                            'active' => $match2[1] == '0' ? 0 : 1
                        ));
                        if ($match2[1] == '2') {
                            // ส่งอีเมลอนุมัติการเข้าระบบ
                            \Index\Email\Model::sendActive($match[1]);
                        }
                        // log
                        $texts = array(
                            '2' => '{LNG_Send login authorization email} ID : ',
                            '0' => '{LNG_Send member confirmation email} ID : ',
                            '1' => '{LNG_Email address verification} ID : '
                        );
                        \Index\Log\Model::add(0, 'index', 'User', $texts[$match2[1]].implode(', ', $match[1]), $login['id']);
                        // reload
                        $ret['location'] = 'reload';
                    } elseif ($action === 'login') {
                        // เข้าระบบเป็นสมาชิกอื่น
                        $ret = \Index\Login\Model::loginAs($request->post('id')->toInt(), $login);
                    }
                }
            }
        }
        // คืนค่า JSON
        echo json_encode($ret);
    }
}
