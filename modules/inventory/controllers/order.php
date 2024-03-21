<?php
/**
 * @filesource modules/inventory/controllers/order.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Order;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-order
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * เพิ่ม-แก้ไข Order
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // สมาชิก
        $login = Login::isMember();
        // ข้อมูลที่ต้องการ
        $index = \Inventory\Order\Model::get($request->request('id')->toInt(), $request->request('status')->filter('A-Z'));
        if ($index) {
            $order_status = Language::get('ORDER_STATUS');
            $index->order_status = [];
            if (in_array($index->status, self::$cfg->buy_status) && Login::checkPermission($login, 'can_inventory_receive')) {
                // จัดซื้อ
                foreach (self::$cfg->buy_status as $k) {
                    $index->order_status[$k] = $order_status[$k];
                }
                $this->menu = 'purchase';
                $sub_title = '{LNG_Purchase}';
                $title = '{LNG_Order report} ';
            } elseif (in_array($index->status, self::$cfg->sell_status) && Login::checkPermission($login, 'can_inventory_order')) {
                // พนักงานขาย
                foreach (self::$cfg->sell_status as $k) {
                    $index->order_status[$k] = $order_status[$k];
                }
                if (!isset($index->order_status[$index->status])) {
                    $index->status = self::$cfg->out_stock_status[0];
                }
                $this->menu = 'sales';
                $sub_title = '{LNG_Sales}';
                $title = '{LNG_Sales report} ';
            }
            // สามารถ ซื้อ/ขาย ได้
            if (!empty($index->order_status)) {
                $index->menu = $this->menu;
                // ข้อความ title bar
                $title = Language::get($index->id > 0 ? 'Edit' : 'Create');
                $this->title = $title.' '.$index->order_status[$index->status];
                // แสดงผล
                $section = Html::create('section');
                // breadcrumbs
                $breadcrumbs = $section->add('nav', array(
                    'class' => 'breadcrumbs'
                ));
                $ul = $breadcrumbs->add('ul');
                $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
                $ul->appendChild('<li><span>'.$sub_title.'</span></li>');
                $ul->appendChild('<li><span>'.$title.'</span></li>');
                $section->add('header', array(
                    'innerHTML' => '<h2 class="icon-file">'.$this->title.'</h2>'
                ));
                $div = $section->add('div', array(
                    'class' => 'content_bg'
                ));
                // แสดงตาราง
                $div->appendChild(\Inventory\Order\View::create()->render($request, $index));
                // คืนค่า HTML
                return $section->render();
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
