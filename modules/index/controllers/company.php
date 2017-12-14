<?php
/**
 * @filesource modules/index/controllers/company.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Company;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=company
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * ตั้งค่าบริษัท
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // ข้อความ title bar
    $this->title = Language::get('Company Profile');
    // เลือกเมนู
    $this->menu = 'settings';
    // สามารถตั้งค่าระบบได้
    if ($login = Login::checkPermission(Login::isMember(), 'can_config')) {
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
      $ul->appendChild('<li><span>{LNG_Settings}</span></li>');
      $ul->appendChild('<li><span>{LNG_Company}</span></li>');
      $section->add('header', array(
        'innerHTML' => '<h2 class="icon-config">'.$this->title.'</h2>'
      ));
      // แสดงฟอร์ม
      $section->appendChild(createClass('Index\Company\View')->render());
      return $section->render();
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }
}