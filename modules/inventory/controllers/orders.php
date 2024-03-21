<?php
/**
 * @filesource modules/inventory/controllers/orders.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Orders;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-orders
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * รายการ Orders
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // วันที่ 1 ของเดือน
        $first_date = date('Y-m-01');
        // วันสิ้นเดือน
        $last_date = date('Y-m-d', strtotime($first_date.' +1 month -1 day'));
        // ค่าที่ส่งมา
        $owner = (object) array(
            'order_status' => Language::get('ORDER_STATUS'),
            'from' => $request->request('from', $first_date)->date(),
            'to' => $request->request('to', $last_date)->date(),
            'status' => $request->request('status')->filter('A-Z')
        );
        // สมาชิก
        $login = Login::isMember();
        if (in_array($owner->status, self::$cfg->buy_status) && Login::checkPermission($login, 'can_manage_inventory')) {
            // จัดซื้อ
            $this->menu = 'purchase';
            $sub_title = '{LNG_Purchase}';
            $title = '{LNG_Order report} ';
        } elseif (Login::checkPermission($login, 'can_inventory_order')) {
            // พนักงานขาย
            $owner->status = isset($owner->order_status[$owner->status]) ? $owner->status : 'OUT';
            $this->menu = 'sales';
            $sub_title = '{LNG_Sales}';
            $title = '{LNG_Sales report} ';
        } else {
            // ไม่สามารถทำรายการได้
            $owner->status = null;
        }
        if (isset($owner->order_status[$owner->status])) {
            $title .= $owner->order_status[$owner->status];
            $this->title = Language::trans($title);
            // แสดงผล
            $section = Html::create('section');
            // breadcrumbs
            $breadcrumbs = $section->add('nav', array(
                'class' => 'breadcrumbs'
            ));
            $ul = $breadcrumbs->add('ul');
            $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
            $ul->appendChild('<li><span>'.$sub_title.'</span></li>');
            $ul->appendChild('<li><span>{LNG_Report}</span></li>');
            $section->add('header', array(
                'innerHTML' => '<h2 class="icon-report">'.$this->title.'</h2>'
            ));
            $div = $section->add('div', array(
                'class' => 'content_bg'
            ));
            // แสดงตาราง
            $div->appendChild(\Inventory\Orders\View::create()->render($request, $owner));
            // คืนค่า HTML
            return $section->render();
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
