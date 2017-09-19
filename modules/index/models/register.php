<?php
/**
 * @filesource modules/index/models/register.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Register;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * ข้อมูลพนักงาน, การ login
 * ตาราง user ใช้ id เป็น primaryKey
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูล user
   * คืนค่ารายการใหม่ถ้า $id = 0
   *
   * @param int $id
   * @return array|null คืนค่า array ของข้อมูล ไม่พบคืนค่า null
   */
  public static function get($id)
  {
    if (empty($id)) {
      // ใหม่ $id = 0
      $result = array(
        'username' => '',
        'name' => '',
        'phone' => '',
        'status' => 3,
        'id' => $id,
        'permission' => array()
      );
      foreach (Language::get('PERMISSIONS') AS $k => $v) {
        if ($k != 'can_config') {
          $result['permission'][] = $k;
        }
      }
    } else {
      // ตรวจสอบรายการที่เลือก
      $model = new static;
      $result = $model->db()->createQuery()
        ->from('user')
        ->where(array(
          array('id', $id),
        ))
        ->toArray()
        ->first('id', 'username', 'name', 'phone', 'permission', 'status');
      if ($result) {
        $result['permission'] = explode(',', $result['permission']);
      }
    }
    return $result;
  }

  /**
   * บันทึกข้อมูล (register.php)
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, admin, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isSafe() && $login = Login::isAdmin()) {
      if (Login::notDemoMode($login)) {
        // รับค่าจากการ POST
        $save = array(
          'username' => $request->post('register_username')->username(),
          'name' => $request->post('register_name')->topic(),
          'phone' => $request->post('register_phone')->topic(),
          'status' => $request->post('register_status')->toInt(),
          'active' => 1
        );
        $permission = $request->post('register_permission', array())->topic();
        // ตรวจสอบค่าที่ส่งมา
        $index = self::get($request->post('register_id')->toInt());
        // ตัวเอง, แอดมิน
        if ($index && ($login['id'] == $index['id'] || $login['status'] == 1)) {
          if ($save['username'] == '') {
            // ไม่ได้กรอก username
            $ret['ret_register_username'] = 'Please fill in';
          } elseif ($save['name'] == '') {
            // ไม่ได้กรอก ชื่อ
            $ret['ret_register_name'] = 'Please fill in';
          } else {
            // Model
            $model = new \Kotchasan\Model;
            // ชื่อตาราง user
            $table_user = $model->getTableName('user');
            // database connection
            $db = $model->db();
            // ตรวจสอบค่าที่ส่งมา
            $requirePassword = false;
            if ($login['status'] != 1 && $index['id'] > 0) {
              // แก้ไขและไม่ใช่แอดมิน ใช้ username เดิมจากฐานข้อมูล
              $save['username'] = $index['username'];
            } else {
              // ตรวจสอบ username ซ้ำ
              $search = $db->first($table_user, array('username', $save['username']));
              if ($search !== false && $index['id'] != $search->id) {
                // มี username อยู่ก่อนแล้ว
                $ret['ret_register_username'] = Language::replace('This :name already exist', array(':name' => Language::get('Email')));
              } else {
                $requirePassword = $index['username'] !== $save['username'];
              }
            }
            // password
            $password = $request->post('register_password')->topic();
            $repassword = $request->post('register_repassword')->topic();
            if (!empty($password) || !empty($repassword)) {
              if (mb_strlen($password) < 4) {
                // รหัสผ่านต้องไม่น้อยกว่า 4 ตัวอักษร
                $ret['ret_register_password'] = 'this';
              } elseif ($repassword != $password) {
                // ถ้าต้องการเปลี่ยนรหัสผ่าน กรุณากรอกรหัสผ่านสองช่องให้ตรงกัน
                $ret['ret_register_repassword'] = 'this';
              } else {
                $save['password'] = $password;
                $requirePassword = false;
              }
            }
            // มีการเปลี่ยน email ต้องการรหัสผ่าน
            if (empty($ret) && $requirePassword) {
              $ret['ret_register_password'] = 'this';
            }
            if (empty($ret)) {
              // บันทึก
              if ($index['id'] == 0) {
                // register
                \Index\Register\Model::execute($model, $save, $permission);
                // ไปหน้ารายการสมาชิก
                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'member', 'id' => null, 'page' => null));
              } else {
                // แก้ไข
                if (isset($save['password'])) {
                  $save['password'] = sha1($password.$save['username']);
                  if ($login['id'] == $index['id']) {
                    // ตัวเอง อัปเดท password ที่ login
                    $_SESSION['login']['username'] = $save['username'];
                    $_SESSION['login']['password'] = $password;
                  }
                }
                // permission
                if (!empty($permission) && ($login['status'] == 1 || ($login['status'] == 2 && $login['id'] != $index['id']))) {
                  $permission = implode(',', $permission);
                } else {
                  unset($permission);
                }
                $db->update($table_user, $index['id'], $save);
                if ($login['status'] == 3) {
                  // พนักงาน reload หน้าเว็บ
                  $ret['location'] = 'reload';
                } else {
                  // ไปหน้าเดิม แสดงรายการ
                  $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'member', 'id' => null));
                }
              }
              // คืนค่า
              $ret['alert'] = Language::get('Saved successfully');
              // เคลียร์
              $request->removeToken();
            }
          }
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }

  /**
   * ลงทะเบียนสมาชิกใหม่
   *
   * @param Model $model
   * @param array $save ข้อมูลสมาชิก
   * @param array $permission
   * @return array คืนค่าแอเรย์ของข้อมูลสมาชิกใหม่
   */
  public static function execute($model, $save, $permission = null)
  {
    // permission ถ้าเป็น null สามารถทำได้ทั้งหมด
    if ($permission === null) {
      $permission = array_keys(\Gcms\Controller::getPermissions());
    }
    if (!isset($save['username'])) {
      $save['username'] = '';
    }
    if (!isset($save['password'])) {
      $save['password'] = '';
    } else {
      $save['password'] = sha1($save['password'].$save['username']);
    }
    $save['permission'] = empty($permission) ? '' : implode(',', $permission);
    $save['active'] = 1;
    $save['create_date'] = date('Y-m-d H:i:s');
    // บันทึกลงฐานข้อมูล
    $save['id'] = $model->db()->insert($model->getTableName('user'), $save);
    // คืนค่าแอเรย์ของข้อมูลสมาชิกใหม่
    $save['permission'] = array();
    foreach ($permission As $key => $value) {
      $save['permission'][] = $value;
    }
    return $save;
  }
}
