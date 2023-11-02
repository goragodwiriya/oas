<?php
/**
 * @filesource modules/inventory/controllers/customer.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Customer;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

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
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::trans('{LNG_Customer}-{LNG_Supplier}');
        // เลือกเมนู
        $this->menu = 'customer';
        // สมาชิก
        $login = Login::isMember();
        // สามารถดูรายชื่อลูกค้าได้
        if (Login::checkPermission($login, array('can_inventory_order', 'can_inventory_receive', 'can_manage_inventory'))) {
            // อ่านข้อมูลลูกค้า
            $customer = \Inventory\Customer\Model::get($request->request('id')->toInt());
            if ($customer) {
                // ข้อความ title bar
                $title = Language::get($customer['id'] == 0 ? 'Add' : 'Details of');
                $this->title = $title.' '.$this->title;
                // แสดงผล
                $section = Html::create('section', array(
                    'class' => 'content_bg'
                ));
                // breadcrumbs
                $breadcrumbs = $section->add('div', array(
                    'class' => 'breadcrumbs'
                ));
                $ul = $breadcrumbs->add('ul');
                $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
                $ul->appendChild('<li><a href="index.php?module=customers">{LNG_Customer list}-{LNG_Supplier}</a></li>');
                $ul->appendChild('<li><span>'.$title.'</span></li>');
                $section->add('header', array(
                    'innerHTML' => '<h2 class="icon-customer">'.$this->title.'</h2>'
                ));
                // แสดงฟอร์ม
                $section->appendChild(\Inventory\Customer\View::create()->render($customer, $this->title));
                // คืนค่า HTML
                return $section->render();
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }

    /**
     * แสดงฟอร์ม Modal
     *
     * @param Request $request
     */
    public function showModal(Request $request)
    {
        // สมาชิก
        if (Login::isMember()) {
            // typ = 1 คือ Supplier ถ้าไม่ใช่ คือ Customer
            $type = Language::get($request->post('typ')->toInt() == 1 ? 'Supplier' : 'Customer');
            // View
            $view = new \Gcms\View();
            // เพิ่ม
            $customer = \Inventory\Customer\Model::get(0);
            // ชื่อมาจากฟอร์ม order
            $customer['company'] = $request->post('name')->topic();
            // แสดงผลฟอร์ม
            echo $view->renderHTML(\Inventory\Customer\View::create()->render($customer, $type, true));
        }
    }
}
