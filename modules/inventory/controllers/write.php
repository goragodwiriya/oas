<?php
/**
 * @filesource modules/inventory/controllers/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Write;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * เพิ่ม-แก้ไข Inventory
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::get('Product');
        // เลือกเมนู
        $this->menu = 'settings';
        // สมาชิก
        $login = Login::isMember();
        // สามารถบริหารจัดการได้
        if (Login::checkPermission($login, 'can_manage_inventory')) {
            // อ่านข้อมูลที่เลือก
            $product = \Inventory\Write\Model::get($request->request('id')->toInt());
            if ($product) {
                // ข้อความ title bar
                if ($product->id == 0) {
                    $title = '{LNG_Add}';
                    $this->title = Language::get('Add').' '.$this->title;
                } else {
                    $title = '{LNG_Details of}';
                    $this->title = Language::get('Details of').' '.$product->topic;
                }
                // แสดงผล
                $section = Html::create('section', array(
                    'class' => 'content_bg'
                ));
                // breadcrumbs
                $breadcrumbs = $section->add('div', array(
                    'class' => 'breadcrumbs'
                ));
                $ul = $breadcrumbs->add('ul');
                $ul->appendChild('<li><span class="icon-product">{LNG_Settings}</span></li>');
                $ul->appendChild('<li><a href="{BACKURL?module=inventory-setup&id=0}">{LNG_Inventory}</a></li>');
                if ($product->id > 0) {
                    $ul->appendChild('<li><a href="{BACKURL?module=inventory-write&tab=product&id='.$product->id.'}">'.$product->topic.'</a></li>');
                }
                $ul->appendChild('<li><span>'.$title.'</span></li>');
                $header = $section->add('header', array(
                    'innerHTML' => '<h2 class="icon-list">'.$title.' {LNG_Product}</h2>'
                ));
                $inline = $header->add('div', array(
                    'class' => 'inline'
                ));
                $writetab = $inline->add('div', array(
                    'class' => 'writetab'
                ));
                $ul = $writetab->add('ul', array(
                    'id' => 'accordient_menu'
                ));
                // tab ที่เลือก
                $tab = $request->request('tab')->filter('a-z');
                if ($tab == '') {
                    $tab = $product->id == 0 ? 'product' : 'overview';
                }
                if ($product->id > 0) {
                    $ul->add('li', array(
                        'class' => $tab == 'overview' ? 'select' : '',
                        'innerHTML' => '<a href="index.php?module=inventory-write&amp;id='.$product->id.'&amp;tab=overview">{LNG_Overview}</a>'
                    ));
                }
                $ul->add('li', array(
                    'class' => $tab == 'product' ? 'select' : '',
                    'innerHTML' => '<a href="index.php?module=inventory-write&amp;id='.$product->id.'&amp;tab=product">{LNG_Product}</a>'
                ));
                if ($product->id > 0) {
                    $ul->add('li', array(
                        'class' => $tab == 'items' ? 'select' : '',
                        'innerHTML' => '<a href="index.php?module=inventory-write&amp;id='.$product->id.'&amp;tab=items">{LNG_Barcode}</a>'
                    ));
                    $ul->add('li', array(
                        'class' => $tab == 'detail' ? 'select' : '',
                        'innerHTML' => '<a href="index.php?module=inventory-write&amp;id='.$product->id.'&amp;tab=detail">{LNG_Other details}</a>'
                    ));
                    $ul->add('li', array(
                        'class' => $tab == 'inventory' ? 'select' : '',
                        'innerHTML' => '<a href="index.php?module=inventory-write&amp;id='.$product->id.'&amp;tab=inventory">{LNG_Inventory}</a>'
                    ));
                }
                if ($tab == 'overview' && $product->id > 0) {
                    // แสดงภาพรวมของสินค้า รูปแบบกราฟ
                    $section->appendChild(\Inventory\Overview\View::create()->render($request, $product));
                } elseif ($tab == 'items' && $product->id > 0) {
                    // รายการ product_no
                    $section->appendChild(\Inventory\Items\View::create()->render($request, $product));
                } elseif ($tab == 'detail' && $product->id > 0) {
                    // รายละเอียดสินค้า
                    $section->appendChild(\Inventory\Detail\View::create()->render($request, $product));
                } elseif ($tab == 'inventory' && $product->id > 0) {
                    // ตารางสต๊อกสินค้า
                    $section->appendChild(\Inventory\Inventory\View::create()->render($request, $product));
                } else {
                    // แสดงฟอร์ม write
                    $section->appendChild(\Inventory\Write\View::create()->render($request, $product));
                }
                // คืนค่า HTML
                return $section->render();
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }

    /**
     * แสดงฟอร์มสำหรับเพิ่มพัสดุ (modal)
     *
     * @param Request $request
     */
    public function showModal(Request $request)
    {
        // สมาชิก
        if (Login::isMember()) {
            // View
            $view = new \Gcms\View();
            // เพิ่ม
            $product = \Inventory\Write\Model::get(0);
            // แสดงผลฟอร์ม
            echo $view->renderHTML(\Inventory\Write\View::create()->render($request, $product, true));
        }
    }
}
