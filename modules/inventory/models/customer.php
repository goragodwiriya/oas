<?php
/**
 * @filesource modules/inventory/models/customer.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Customer;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * ข้อมูลลูกค้า, คู่ค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูลลูกค้า
   * คืนค่ารายการใหม่ถ้า $id = 0
   *
   * @param int $id
   * @return array|null คืนค่า array ของข้อมูล ไม่พบคืนค่า null
   */
  public static function get($id)
  {
    if (empty($id)) {
      // ใหม่ $id = 0
      return array(
        'company' => '',
        'branch' => '',
        'tax_id' => '',
        'name' => '',
        'address' => '',
        'provinceID' => self::$request->cookie('provinceID', '102')->number(),
        'province' => '',
        'zipcode' => self::$request->cookie('zipcode', 10000)->number(),
        'country' => self::$request->cookie('country', 'TH')->filter('A-Z'),
        'phone' => '',
        'fax' => '',
        'email' => '',
        'website' => '',
        'id' => 0,
      );
    } else {
      // ตรวจสอบรายการที่เลือก
      $model = new static;
      return $model->db()->createQuery()
          ->from('customer')
          ->where(array('id', $id))
          ->toArray()
          ->first();
    }
  }

  /**
   * บันทึกข้อมูล
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, สามารถขายได้, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
      if (Login::checkPermission($login, array('can_buy', 'can_sell', 'can_manage_inventory')) && Login::notDemoMode($login)) {
        // รับค่าจากการ POST
        $save = array(
          'company' => $request->post('register_company')->topic(),
          'branch' => $request->post('register_branch')->topic(),
          'tax_id' => $request->post('register_tax_id')->number(),
          'name' => $request->post('register_name')->topic(),
          'address' => $request->post('register_address')->topic(),
          'provinceID' => $request->post('register_provinceID')->toInt(),
          'province' => $request->post('register_province')->topic(),
          'zipcode' => $request->post('register_zipcode')->number(),
          'country' => $request->post('register_country')->filter('A-Z'),
          'phone' => $request->post('register_phone')->number(),
          'fax' => $request->post('register_fax')->number(),
          'email' => $request->post('register_email')->url(),
          'website' => $request->post('register_website')->url(),
        );
        // ตรวจสอบค่าที่ส่งมา
        $index = self::get($request->post('register_id')->toInt());
        if (!$index) {
          // ไม่พบข้อมูลที่แก้ไข
          $ret['alert'] = Language::get('not a registered user');
        } elseif ($save['company'] == '') {
          // ไม่ได้กรอกชื่อบริษัท
          $ret['ret_register_company'] = 'Please fill in';
        } else {
          // Model
          $model = new \Kotchasan\Model;
          // ชื่อตาราง user
          $table_customer = $model->getTableName('customer');
          // database connection
          $db = $model->db();
          // ใช้จังหวัดจาก provinceID ถ้าเป็นประเทศไทย
          if ($save['country'] == 'TH') {
            $save['province'] = \Kotchasan\Province::get($save['provinceID']);
          }
          if ($index['id'] == 0) {
            // รายการใหม่
            $save['id'] = $db->insert($table_customer, $save);
          } else {
            // แก้ไข
            $save['id'] = $index['id'];
            $db->update($table_customer, array('id', $index['id']), $save);
          }
          // คืนค่า
          $ret['alert'] = Language::get('Saved successfully');
          if ($request->post('modal')->toString() == 'xhr') {
            // ปิด modal
            $ret['modal'] = 'close';
            // คืนค่าข้อมูล
            $ret['customer'] = $save['company'];
            $ret['customer_id'] = $save['id'];
            $ret['valid'] = 'customer';
          } else {
            if ($index['id'] == 0) {
              // ไปหน้าแรก แสดงรายการใหม่
              $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-customers', 'id' => null, 'page' => null));
            } else {
              // ไปหน้าเดิม แสดงรายการ
              $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-customers', 'id' => null));
            }
          }
          // เคลียร์
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
}