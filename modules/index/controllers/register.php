<?php
/**
 * @filesource modules/index/controllers/register.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Register;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=register
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * ลงทะเบียนสมาชิกใหม่
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // ข้อความ title bar
    $this->title = Language::get('Create new account');
    // เลือกเมนู
    $this->menu = 'member';
    // แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
    if ($login = Login::notDemoMode(Login::isAdmin())) {
      // แสดงผล
      $section = Html::create('section');
      // breadcrumbs
      $breadcrumbs = $section->add('div', array(
        'class' => 'breadcrumbs'
      ));
      $ul = $breadcrumbs->add('ul');
      $ul->appendChild('<li><span class="icon-user">{LNG_Users}</span></li>');
      $ul->appendChild('<li><a href="{BACKURL?module=member&id=0}">{LNG_Member list}</a></li>');
      $ul->appendChild('<li><span>{LNG_Register}</span></li>');
      $section->add('header', array(
        'innerHTML' => '<h2 class="icon-register">'.$this->title.'</h2>'
      ));
      // แสดงฟอร์ม
      $section->appendChild(createClass('Index\Register\View')->render());
      return $section->render();
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }
}
