<?php
/**
 * @filesource modules/index/views/system.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\System;

use \Kotchasan\Html;

/**
 * ฟอร์มตั้งค่า system
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * module=system
   *
   * @param object $config
   * @return string
   */
  public function render($config)
  {
    // form
    $form = Html::create('form', array(
        'id' => 'setup_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/index/model/system/submit',
        'onsubmit' => 'doFormSubmit',
        'ajax' => true,
        'token' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_General}'
    ));
    // web_title
    $fieldset->add('text', array(
      'id' => 'web_title',
      'labelClass' => 'g-input icon-home',
      'itemClass' => 'item',
      'label' => '{LNG_Website title}',
      'comment' => '{LNG_Site Name}',
      'maxlength' => 255,
      'value' => isset($config->web_title) ? $config->web_title : self::$cfg->web_title
    ));
    // web_description
    $fieldset->add('text', array(
      'id' => 'web_description',
      'labelClass' => 'g-input icon-home',
      'itemClass' => 'item',
      'label' => '{LNG_Description}',
      'comment' => '{LNG_Short description about your website}',
      'maxlength' => 255,
      'value' => isset($config->web_description) ? $config->web_description : self::$cfg->web_description
    ));
    // timezone
    $datas = array();
    foreach (\DateTimeZone::listIdentifiers() as $item) {
      $datas[$item] = $item;
    }
    $fieldset->add('select', array(
      'id' => 'timezone',
      'labelClass' => 'g-input icon-clock',
      'itemClass' => 'item',
      'label' => '{LNG_Time zone}&nbsp;({LNG_Server time}&nbsp;<em id=server_time>'.date('H:i:s').'</em>&nbsp;{LNG_Local time}&nbsp;<em id=local_time></em>)',
      'comment' => '{LNG_Settings the timing of the server to match the local time}',
      'options' => $datas,
      'value' => isset($config->timezone) ? $config->timezone : self::$cfg->timezone
    ));
    $fieldset = $form->add('fieldset', array(
      'class' => 'submit'
    ));
    // submit
    $fieldset->add('submit', array(
      'class' => 'button save large',
      'value' => '{LNG_Save}'
    ));
    $form->script('initSystem();');
    return $form->render();
  }
}
