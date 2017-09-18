<?php
/**
 * @filesource modules/index/controllers/languageedit.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Languageedit;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=languageedit
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{

  /**
   * ฟอร์มเขียน/แก้ไข ภาษา
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // ข้อความ title bar
    $this->title = Language::get('Manage languages');
    // เลือกเมนู
    $this->menu = 'settings';
    // สามารถตั้งค่าระบบได้
    if (Login::checkPermission(Login::isMember(), 'can_config')) {
      // ภาษาที่ติดตั้ง
      $languages = Language::installedLanguage();
      // รายการที่แก้ไข (id)
      $id = $request->request('id')->toInt();
      if ($id > 0) {
        $title = '{LNG_Edit}';
        // แก้ไข อ่านรายการที่เลือก
        $model = new \Kotchasan\Model();
        $language = $model->db()->first($model->getTableName('language'), $id);
        if ($language && $language->type == 'array') {
          foreach ($languages as $lng) {
            if ($language->$lng != '') {
              $ds = @unserialize($language->$lng);
              if (is_array($ds)) {
                foreach ($ds as $key => $value) {
                  $language->datas[$key]['key'] = $key;
                  $language->datas[$key][$lng] = $value;
                }
              } else {
                $language->datas[0]['key'] = '';
                $language->datas[0][$lng] = $language->$lng;
              }
            }
            unset($language->$lng);
          }
          // ตรวจสอบข้อมูลให้มีทุกภาษา
          foreach ($language->datas as $key => $values) {
            foreach ($languages as $lng) {
              if (!isset($language->datas[$key][$lng])) {
                $language->datas[$key][$lng] = '';
              }
            }
          }
        } else {
          $language->datas[0]['key'] = '';
          foreach ($languages as $lng) {
            $language->datas[0][$lng] = $language->$lng;
            unset($language->$lng);
          }
        }
      } else {
        $title = '{LNG_Add New}';
        // ใหม่
        $language = array(
          'id' => 0,
          'key' => '',
          'js' => $request->request('type')->toBoolean(),
          'owner' => 'index',
          'type' => 'text'
        );
        $language['datas'][0]['key'] = '';
        foreach ($languages as $lng) {
          $language['datas'][0][$lng] = '';
        }
        $language = (object)$language;
      }
      // แสดงผล
      $section = Html::create('section');
      // breadcrumbs
      $breadcrumbs = $section->add('div', array(
        'class' => 'breadcrumbs'
      ));
      $ul = $breadcrumbs->add('ul');
      $ul->appendChild('<li><span class="icon-settings">{LNG_Settings}</span></li>');
      $ul->appendChild('<li><a href="{BACKURL?module=language&id=0}">{LNG_Language}</a></li>');
      $ul->appendChild('<li><span>'.$title.'</span></li>');
      $section->add('header', array(
        'innerHTML' => '<h2 class="icon-language">'.$this->title.'</h2>'
      ));
      // แสดงฟอร์ม
      $section->appendChild(createClass('Index\Languageedit\View')->render($request, $language));
      return $section->render();
    }
    // 404.html
    return \Index\Error\Controller::page404();
  }
}
