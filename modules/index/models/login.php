<?php
/**
 * @filesource modules/index/models/login.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Login;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * Controller หลัก สำหรับแสดง frontend ของ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * ฟังก์ชั่นตรวจสอบการ Login
     *
     * @param Request $request
     */
    public function chklogin(Request $request)
    {
        if ($request->initSession() && $request->isSafe()) {
            // ตรวจสอบการ login
            Login::create($request);
            // ตรวจสอบสมาชิก
            $login = Login::isMember();
            if ($login) {
                $ret = array(
                    'alert' => Language::replace('Welcome %s, login complete', $login['name']),
                    'url' => $request->post('login_action')->toString()
                );
                // เคลียร์
                $request->removeToken();
            } else {
                $ret = array(
                    'ret_'.Login::$login_input => Login::$login_message
                );
            }
            // คืนค่า JSON
            echo json_encode($ret);
        }
    }

    /**
     * ฟังก์ชั่นเข้าระบบเป็นสมาชิกอื่น
     * สามารถเข้าระบบได้โดย Super Admin (ID-1) เท่านั้น
     *
     * @param int $id
     * @param array $login
     *
     * @return array
     */
    public static function loginAs($id, $login)
    {
        // เข้าระบบได้โดย Super Admin (ID-1) เท่านั้น
        if ($login['id'] == 1) {
            // ตรวจสอบ $id ที่เลือก
            $user = \Index\Editprofile\Model::get($id);
            if ($user) {
                // อัปเดทการเยี่ยมชมล่าสุด
                $save = array(
                    'token' => empty($user['token']) ? \Kotchasan\Password::uniqid(40) : $user['token']
                );
                $model = \Kotchasan\Model::create();
                $model->db()->update($model->getTableName('user'), $user['id'], $save);
                // log
                \Index\Log\Model::add(0, 'index', 'User', '{LNG_Login as} ID : '.$user['id'], $login['id']);
                // ใช้ token ของ user ที่เลือก
                $_SESSION['login'] = array(
                    'username' => $user['username'],
                    'token' => $save['token']
                );
                // บันทึกการเข้าระบบโดยแอดมิน
                $_SESSION['lastLogin'] = array(
                    'username' => $login['username'],
                    'token' => $login['token']
                );
                // คืนค่ากลับไปเข้าระบบ
                return array(
                    'location' => WEB_URL.'index.php'
                );
            }
        }
    }
}
