<?php
/**
 * @filesource modules/index/views/accsettings.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Accsettings;

use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=accsettings
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * ตั้งค่าระบบบัญชี
   *
   * @return string
   */
  public function render()
  {
    // form
    $form = Html::create('form', array(
        'id' => 'setup_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/index/model/accsettings/submit',
        'onsubmit' => 'doFormSubmit',
        'token' => true,
        'ajax' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Details of} {LNG_Accounting}'
    ));
    // authorized
    $fieldset->add('text', array(
      'id' => 'company_authorized',
      'labelClass' => 'g-input icon-user',
      'itemClass' => 'item',
      'label' => '{LNG_Authorized}',
      'comment' => '{LNG_Authorized signatory Receipt}',
      'placeholder' => '{LNG_Name}',
      'maxlength' => 150,
      'value' => isset(self::$cfg->authorized) ? self::$cfg->authorized : ''
    ));
    // email
    $fieldset->add('text', array(
      'id' => 'company_email',
      'labelClass' => 'g-input icon-email',
      'itemClass' => 'item',
      'label' => '{LNG_Email}',
      'comment' => '{LNG_The contact email Used to send documents by email}',
      'maxlength' => 50,
      'value' => isset(self::$cfg->email) ? self::$cfg->email : ''
    ));
    // product_no
    $fieldset->add('text', array(
      'id' => 'product_no',
      'labelClass' => 'g-input icon-number',
      'itemClass' => 'item',
      'label' => '{LNG_Product Code}',
      'comment' => '{LNG_number format such as %04d (%04d means the number on 4 digits, up to 11 digits)}',
      'placeholder' => 'P%04d',
      'value' => isset(self::$cfg->product_no) ? self::$cfg->product_no : 'P%04d',
    ));
    // order_no
    $fieldset->add('text', array(
      'id' => 'order_no',
      'labelClass' => 'g-input icon-number',
      'itemClass' => 'item',
      'label' => '{LNG_Order No.} ({LNG_Buy})',
      'comment' => '{LNG_number format such as %04d (%04d means the number on 4 digits, up to 11 digits)}',
      'placeholder' => 'O%04d',
      'value' => isset(self::$cfg->order_no) ? self::$cfg->order_no : 'O%04d',
    ));
    // billing_no
    $fieldset->add('text', array(
      'id' => 'billing_no',
      'labelClass' => 'g-input icon-number',
      'itemClass' => 'item',
      'label' => '{LNG_Billing No.} ({LNG_Sell})',
      'comment' => '{LNG_number format such as %04d (%04d means the number on 4 digits, up to 11 digits)}',
      'placeholder' => 'R%04d',
      'value' => isset(self::$cfg->billing_no) ? self::$cfg->billing_no : 'R%04d',
    ));
    // currency_unit
    $fieldset->add('select', array(
      'id' => 'currency_unit',
      'labelClass' => 'g-input icon-currency',
      'itemClass' => 'item',
      'label' => '{LNG_Currency Unit}',
      'comment' => '{LNG_Currency for goods and services}',
      'options' => Language::get('CURRENCY_UNITS'),
      'value' => isset(self::$cfg->currency_unit) ? self::$cfg->currency_unit : 'THB'
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Finance}'
    ));
    $groups = $fieldset->add('groups');
    // bank
    $groups->add('text', array(
      'id' => 'bank',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-office',
      'label' => '{LNG_Bank}',
      'maxlength' => 100,
      'value' => isset(self::$cfg->bank) ? self::$cfg->bank : ''
    ));
    // bank_name
    $groups->add('text', array(
      'id' => 'bank_name',
      'itemClass' => 'width33',
      'label' => '{LNG_Account name}',
      'labelClass' => 'g-input icon-customer',
      'maxlength' => 100,
      'value' => isset(self::$cfg->bank_name) ? self::$cfg->bank_name : ''
    ));
    // bank_no
    $groups->add('text', array(
      'id' => 'bank_no',
      'itemClass' => 'width33',
      'label' => '{LNG_Account number}',
      'labelClass' => 'g-input icon-number',
      'maxlength' => 20,
      'value' => isset(self::$cfg->bank_no) ? self::$cfg->bank_no : ''
    ));
    $fieldset = $form->add('fieldset', array(
      'class' => 'submit'
    ));
    // submit
    $fieldset->add('submit', array(
      'class' => 'button ok large',
      'value' => '{LNG_Save}'
    ));
    return $form->render();
  }
}