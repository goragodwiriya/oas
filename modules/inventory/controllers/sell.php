<?php
/**
 * @filesource modules/inventory/controllers/sell.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Sell;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=inventory-sell
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * เพิ่ม-แก้ไข รายการขาย
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // เลือกเมนู
    $this->menu = 'sell';
    // member, can_sell
    if ($login = Login::checkPermission(Login::isMember(), 'can_sell')) {
      // ค่าที่ส่งมา
      $typ = $request->request('typ')->toInt();
      $id = $request->request('id')->toInt();
      // ประเภทการขาย
      $typies = Language::get('SELL_TYPIES');
      // ไม่พบประเภทที่ต้องการ ใช้รายการแรก
      $typ = isset($typies[$typ]) ? $typ : reset((array_keys($typies)));
      // ข้อมูลที่ต้องการ
      $index = \Inventory\Order\Model::get($id, 'OUT', $typ);
      if ($index) {
        // ข้อความ title bar
        $title = Language::get($id > 0 ? 'Edit' : 'Create');
        $this->title = $title.' '.$typies[$typ];
        // แสดงผล
        $section = Html::create('section');
        // breadcrumbs
        $breadcrumbs = $section->add('div', array(
          'class' => 'breadcrumbs'
        ));
        $ul = $breadcrumbs->add('ul');
        $ul->appendChild('<li><a href="index.php" class="icon-home">{LNG_Home}</a></li>');
        $ul->appendChild('<li><span>{LNG_Sell}</span></li>');
        $ul->appendChild('<li><span>'.$title.'</span></li>');
        $section->add('header', array(
          'innerHTML' => '<h2 class="icon-file">'.$this->title.'</h2>'
        ));
        // แสดงตาราง
        $section->appendChild(createClass('Inventory\Sell\View')->render($index, $login));
        return $section->render();
      }
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }
}