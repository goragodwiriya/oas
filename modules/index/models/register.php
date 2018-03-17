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
 * ลงทะเบียนสมาชิกใหม่
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

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
          'status' => $request->post('register_status')->toInt(),
          'active' => 1
        );
        $permission = $request->post('register_permission', array())->topic();
        if (empty($save['username'])) {
          $ret['ret_register_username'] = 'this';
        } else {
          // ตรวจสอบ username ซ้ำ
          $search = $this->db()->first($this->getTableName('user'), array('username', $save['username']));
          if ($search) {
            $ret['ret_register_username'] = Language::replace('This :name already exist', array(':name' => Language::get('Email')));
          }
        }
        // name
        if (empty($save['name'])) {
          $ret['ret_register_name'] = 'this';
        }
        // password
        $password = $request->post('register_password')->password();
        $repassword = $request->post('register_repassword')->password();
        if (mb_strlen($password) < 4) {
          // รหัสผ่านต้องไม่น้อยกว่า 4 ตัวอักษร
          $ret['ret_register_password'] = 'this';
        } elseif ($repassword != $password) {
          // กรอกรหัสผ่านสองช่องให้ตรงกัน
          $ret['ret_register_repassword'] = 'this';
        } else {
          $save['password'] = $password;
        }
        if (empty($ret)) {
          // ลงทะเบียนสมาชิกใหม่
          self::execute($this, $save, $permission);
          // คืนค่า
          $ret['alert'] = Language::get('Saved successfully');
          $ret['location'] = 'index.php?module=member';
          // clear
          $request->removeToken();
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
      $save['salt'] = uniqid();
      $save['password'] = sha1($save['password'].$save['salt']);
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