<?php
/**
 * @filesource modules/inventory/controllers/customer.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Customer;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=inventory-customer
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * เพิ่ม แก้ไข ลูกค้า
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // typ = 1 คือ Supplier ถ้าไม่ใช่ คือ Customer
    $type = Language::get($request->request('typ')->toInt() == 1 ? 'Supplier' : 'Customer');
    // ข้อความ title bar
    $this->title = $type;
    // เลือกเมนู
    $this->menu = 'customer';
    // สามารถดูรายชื่อลูกค้าได้
    if ($login = Login::checkPermission(Login::isMember(), array('can_buy', 'can_sell', 'can_manage_inventory'))) {
      // อ่านข้อมูลลูกค้า
      $customer = \Inventory\Customer\Model::get($request->request('id')->toInt());
      if ($customer) {
        // ข้อความ title bar
        $title = Language::get($customer['id'] == 0 ? 'Add New' : 'Details of');
        $this->title = $title.' '.$this->title;
        // แสดงผล
        $section = Html::create('section');
        // breadcrumbs
        $breadcrumbs = $section->add('div', array(
          'class' => 'breadcrumbs'
        ));
        $ul = $breadcrumbs->add('ul');
        $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
        $ul->appendChild('<li><a href="index.php?module=customers">{LNG_Customer}</a></li>');
        $ul->appendChild('<li><span>'.$title.'</span></li>');
        $section->add('header', array(
          'innerHTML' => '<h2 class="icon-customer">'.$this->title.'</h2>'
        ));
        // แสดงฟอร์ม
        $section->appendChild(createClass('Inventory\Customer\View')->render($customer, $login, $type));
        return $section->render();
      }
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }

  /**
   * แสดงฟอร์มสำหรับลงทะเบียน
   *
   * @param Request $request
   */
  public function showModal(Request $request)
  {
    // สมาชิก
    if ($login = Login::isMember()) {
      // typ = 1 คือ Supplier ถ้าไม่ใช่ คือ Customer
      $type = Language::get($request->post('typ')->toInt() == 1 ? 'Supplier' : 'Customer');
      // View
      $view = new \Gcms\View;
      // เพิ่ม
      $customer = \Inventory\Customer\Model::get(0);
      // แสดงผลฟอร์ม
      echo $view->renderHTML(createClass('Inventory\Customer\View')->render($customer, $login, $type));
    }
  }
}