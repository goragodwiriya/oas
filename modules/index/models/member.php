<?php
/**
 * @filesource modules/index/models/member.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Member;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * ตารางสมาชิก
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
   * @return array
   */
  public static function toDataTable()
  {
    $model = new static;
    return $model->db()->createQuery()
        ->select('id', 'name', 'active', 'fb', 'phone', 'status', 'create_date', 'lastvisited', 'visited', 'website')
        ->from('user');
  }

  /**
   * ฟังก์ชั่นอ่านจำนวนสมาชิกทั้งหมด
   *
   * @return int
   */
  public static function getCount()
  {
    $model = new \Kotchasan\Model;
    $query = $model->db()->createQuery()
      ->selectCount()
      ->from('user')
      ->toArray()
      ->execute();
    return $query[0]['count'];
  }

  /**
   * ตารางสมาชิก (member.php)
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
        // id ที่ส่งมา
        if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->toString(), $match)) {
          // Model
          $model = new \Kotchasan\Model;
          // ตาราง user
          $user_table = $model->getTableName('user');
          if ($action === 'delete') {
            // ลบสมาชิก
            $model->db()->delete($user_table, array(
              array('id', $match[1]),
              array('id', '!=', 1)
              ), 0);
            // reload
            $ret['location'] = 'reload';
          } elseif ($action === 'sendpassword') {
            // ขอรหัสผ่านใหม่
            $query = $model->db()->createQuery()
              ->select('id', 'username')
              ->from('user')
              ->where(array(
                array('id', $match[1]),
                array('id', '!=', 1),
                array('fb', '0'),
                array('username', '!=', '')
              ))
              ->toArray();
            $msgs = array();
            foreach ($query->execute() as $item) {
              // รหัสผ่านใหม่
              $password = \Kotchasan\Text::rndname(6);
              // ส่งอีเมล์ขอรหัสผ่านใหม่
              $err = \Index\Forgot\Model::execute($item['id'], $password, $item['username']);
              if ($err != '') {
                $msgs[] = $err;
              }
            }
            if (isset($password)) {
              if (empty($msgs)) {
                // ส่งอีเมล์ สำเร็จ
                $ret['alert'] = Language::get('Your message was sent successfully');
              } else {
                // มีข้อผิดพลาด
                $ret['alert'] = implode("\n", $msgs);
              }
            }
          } elseif (preg_match('/active_([01])/', $action, $match2)) {
            // สถานะการเข้าระบบ
            $model->db()->update($user_table, array(
              array('id', $match[1]),
              array('id', '!=', '1')
              ), array(
              'active' => (int)$match2[1]
            ));
            // reload
            $ret['location'] = 'reload';
          }
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่า JSON
    echo json_encode($ret);
  }
}
