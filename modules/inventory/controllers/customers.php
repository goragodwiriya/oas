<?php
/**
 * @filesource modules/inventory/controllers/customers.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Customers;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-customers
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * ตารางรายชื่อ ลูกค้า
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::trans('{LNG_Customer list}-{LNG_Supplier}');
        // เลือกเมนู
        $this->menu = 'customer';
        // สมาชิก
        $login = Login::isMember();
        // สามารถขายได้
        if (Login::checkPermission($login, array('can_inventory_order', 'can_inventory_receive', 'can_manage_inventory'))) {
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
            $ul->appendChild('<li><span>{LNG_Customer list}-{LNG_Supplier}</span></li>');
            $section->add('header', array(
                'innerHTML' => '<h2 class="icon-customer">'.$this->title.'</h2>'
            ));
            // แสดงตาราง
            $section->appendChild(\Inventory\Customers\View::create()->render($request));
            // คืนค่า HTML
            return $section->render();
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
