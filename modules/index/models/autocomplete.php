<?php
/**
 * @filesource modules/index/models/autocomplete.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Autocomplete;

use \Kotchasan\Http\Request;
use \Gcms\Login;

/**
 * ค้นหาสมาชิก สำหรับ autocomplete
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
      $search = $request->post('name')->topic();
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
      $search = $request->post('name')->topic();
      $query = $this->db()->createQuery()
        ->select('category_id id', 'topic name')
        ->from('category')
        ->where(array(
          array('type', $request->post('typ')->toInt()),
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
    }
  }
}