<?php
/**
 * @filesource modules/inventory/controllers/write.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Write;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

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
   * เพิ่ม-แก้ไข สินค้า
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // ข้อความ title bar
    $this->title = Language::get('Product');
    // เลือกเมนู
    $this->menu = 'inventory';
    // สามารถทำรายการสินค้าได้
    if ($login = Login::checkPermission(Login::isMember(), 'can_manage_inventory')) {
      // อ่านข้อมูลที่เลือก
      $product = \Inventory\Write\Model::get($request->request('id')->toInt());
      if ($product) {
        // ข้อความ title bar
        if ($product['id'] == 0) {
          $title = '{LNG_Add New}';
          $this->title = Language::get('Add New').' '.$this->title;
        } else {
          $title = '{LNG_Detail}';
          $this->title = Language::get('Details of').' '.$product['topic'];
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
        $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
        $ul->appendChild('<li><a href="index.php?module=inventory-setup">{LNG_Product}</a></li>');
        $ul->appendChild('<li><span>'.$title.'</span></li>');
        $header = $section->add('header', array(
          'innerHTML' => '<h2 class="icon-product">'.$this->title.'</h2>'
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
          $tab = $product['id'] == 0 ? 'product' : 'overview';
        }
        if ($product['id'] > 0) {
          $ul->add('li', array(
            'innerHTML' => '<a'.($tab == 'overview' ? ' class=select' : '').' href="index.php?module=inventory-write&amp;id='.$product['id'].'&amp;tab=overview">{LNG_Overview}</a>'
          ));
        }
        $ul->add('li', array(
          'innerHTML' => '<a'.($tab == 'product' ? ' class=select' : '').' href="index.php?module=inventory-write&amp;id='.$product['id'].'&amp;tab=product">{LNG_Details of} {LNG_Product}</a>'
        ));
        if ($product['id'] > 0) {
          $ul->add('li', array(
            'innerHTML' => '<a'.($tab == 'inventory' ? ' class=select' : '').' href="index.php?module=inventory-write&amp;id='.$product['id'].'&amp;tab=inventory">{LNG_Inventory}</a>'
          ));
        }
        if ($tab == 'overview' && $product['id'] > 0) {
          // แสดงภาพรวมของสินค้า รูปแบบกราฟ
          $section->appendChild(createClass('Inventory\Overview\View')->render($request, $product));
        } elseif ($tab == 'inventory' && $product['id'] > 0) {
          // ตารางสต๊อคสินค้า
          $section->appendChild(createClass('Inventory\Inventory\View')->render($request, $product, $login));
        } else {
          // แสดงฟอร์ม write
          $section->appendChild(createClass('Inventory\Write\View')->render($request, $product, $login));
        }
        // คืนค่า
        return $section->render();
      }
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }

  /**
   * แสดงฟอร์มเพิ่มสินค้า
   *
   * @param Request $request
   */
  public function showModal(Request $request)
  {
    // สมาชิก
    if ($login = Login::isMember()) {
      // View
      $view = new \Gcms\View;
      // เพิ่ม
      $product = \Inventory\Write\Model::get(0);
      // แสดงผลฟอร์ม
      echo $view->renderHTML(createClass('Inventory\Write\View')->render($request, $product, $login));
    }
  }
}