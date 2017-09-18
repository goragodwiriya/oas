<?php
/**
 * @filesource modules/inventory/views/customers.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Customers;

use \Kotchasan\Http\Request;
use \Kotchasan\DataTable;

/**
 * module=inventory-customers
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * ตารางรายชื่อ ลูกค้า
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // URL สำหรับส่งให้ตาราง
    $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
    // ตาราง
    $table = new DataTable(array(
      /* Uri */
      'uri' => $uri,
      /* Model */
      'model' => \Inventory\Customers\Model::toDataTable(),
      /* เรียงลำดับ */
      'sort' => $request->cookie('customer_sort', 'id desc')->toString(),
      /* คอลัมน์ที่สามารถค้นหาได้ */
      'searchColumns' => array('name', 'email', 'phone', 'branch', 'contactor'),
      /* รายการต่อหน้า */
      'perPage' => $request->cookie('customer_perPage', 30)->toInt(),
      /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
      'onRow' => array($this, 'onRow'),
      /* คอลัมน์ที่ไม่ต้องแสดงผล */
      'hideColumns' => array('id'),
      /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
      'action' => 'index.php/inventory/model/customers/action',
      'actions' => array(
        array(
          'id' => 'action',
          'class' => 'ok',
          'text' => '{LNG_With selected}',
          'options' => array(
            'delete' => '{LNG_Delete}'
          )
        ),
        array(
          'class' => 'button green icon-plus',
          'href' => $uri->createBackUri(array('module' => 'inventory-customer', 'id' => '0')),
          'text' => '{LNG_Add New} {LNG_Customer}/{LNG_Supplier}'
        )
      ),
      /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
      'headers' => array(
        'company' => array(
          'text' => '{LNG_Name}/{LNG_Company name}',
          'sort' => 'company'
        ),
        'branch' => array(
          'text' => '{LNG_Branch name}',
          'sort' => 'branch'
        ),
        'phone' => array(
          'text' => '{LNG_Phone}'
        ),
        'email' => array(
          'text' => '{LNG_Email}',
        ),
        'create_date' => array(
          'text' => '{LNG_Created}',
          'class' => 'center'
        ),
      ),
      /* ปุ่มแสดงในแต่ละแถว */
      'buttons' => array(
        array(
          'class' => 'icon-edit button green',
          'href' => $uri->createBackUri(array('module' => 'inventory-customer', 'id' => ':id')),
          'text' => '{LNG_Edit}'
        )
      )
    ));
    // save cookie
    setcookie('customer_perPage', $table->perPage, time() + 3600 * 24 * 365, '/');
    setcookie('customer_sort', $table->sort, time() + 3600 * 24 * 365, '/');
    return $table->render();
  }

  /**
   * จัดรูปแบบการแสดงผลในแต่ละแถว
   *
   * @param array $item ข้อมูลแถว
   * @param int $o ID ของข้อมูล
   * @param object $prop กำหนด properties ของ TR
   * @return array คืนค่า $item กลับไป
   */
  public function onRow($item, $o, $prop)
  {
    $item['email'] = empty($item['email']) ? '' : '<a href="mailto:'.$item['email'].'">'.$item['email'].'</a>';
    $item['phone'] = self::showPhone($item['phone']);
    return $item;
  }
}