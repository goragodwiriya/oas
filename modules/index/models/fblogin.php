<?php
/**
 * @filesource modules/index/models/fblogin.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Fblogin;

use \Kotchasan\Http\Request;
use \Kotchasan\Language;

/**
 * Facebook Login
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  public function chklogin(Request $request)
  {
    // session, token
    if ($request->initSession() && $request->isSafe()) {
      // สุ่มรหัสผ่านใหม่
      $password = uniqid();
      // db
      $db = $this->db();
      // table
      $user_table = $this->getTableName('user');
      // ตรวจสอบสมาชิกกับ db
      $username = $request->post('id')->number();
      $search = $db->createQuery()
        ->from('user')
        ->where(array('username', $username))
        ->toArray()
        ->first();
      if ($search === false) {
        // ยังไม่เคยลงทะเบียน, ลงทะเบียนใหม่
        if (self::$cfg->demo_mode) {
          $permissions = array_keys(\Gcms\Controller::getPermissions());
          unset($permissions['can_config']);
        } else {
          $permissions = array();
        }
        $name = trim($request->post('first_name')->topic().' '.$request->post('last_name')->topic());
        $website = str_replace(array('http://', 'https://', 'www.'), '', $request->post('link')->url());
        $save = \Index\Register\Model::execute($this, array(
            'username' => $username,
            'password' => $password,
            'name' => $name,
            'email' => $request->post('email')->url(),
            'fb' => 1,
            'visited' => 1,
            'lastvisited' => time(),
            // โหมดตัวอย่างเป็นแอดมิน, ไม่ใช่เป็นสมาชิกทั่วไป
            'status' => self::$cfg->demo_mode ? 1 : 0,
            'website' => $website
            ), $permissions);
        if ($save === null) {
          // ไม่สามารถบันทึก owner ได้
          $ret['alert'] = Language::get('Unable to complete the transaction');
          $ret['isMember'] = 0;
        }
      } elseif ($search['fb'] == 1) {
        // facebook เคยเยี่ยมชมแล้ว อัปเดทการเยี่ยมชม
        $save = $search;
        $save['visited'] ++;
        $save['lastvisited'] = time();
        $save['ip'] = $request->getClientIp();
        $save['password'] = sha1($password.$search['username']);
        // อัปเดท
        $db->update($user_table, $search['id'], $save);
        $save['permission'] = explode(',', $save['permission']);
      } else {
        // ไม่สามารถ login ได้ เนื่องจากมี email อยู่ก่อนแล้ว
        $save = false;
        $ret['alert'] = Language::replace('This :name already exist', array(':name' => Language::get('Username')));
        $ret['isMember'] = 0;
      }
      if (is_array($save)) {
        // clear
        $request->removeToken();
        // login
        $save['password'] = $password;
        $_SESSION['login'] = $save;
        // คืนค่า
        $ret['isMember'] = 1;
        $ret['alert'] = Language::replace('Welcome %s, login complete', array('%s' => $save['name']));
      }
      // คืนค่าเป็น json
      echo json_encode($ret);
    }
  }
}
