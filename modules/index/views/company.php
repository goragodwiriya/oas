<?php
/**
 * @filesource modules/index/views/company.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Company;

use \Kotchasan\Html;

/**
 * module=company
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * ตั้งค่าบริษัท
   * r
   * @return string
   */
  public function render()
  {
    // form
    $form = Html::create('form', array(
        'id' => 'setup_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/index/model/company/submit',
        'onsubmit' => 'doFormSubmit',
        'token' => true,
        'ajax' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Corporate information and Contacts}'
    ));
    $groups = $fieldset->add('groups', array(
      'comment' => '{LNG_If you are a corporate enter your 13-digit Tax identification number or if you are a person enter your 13-digit Personal identification number}'
    ));
    // type
    $groups->add('select', array(
      'id' => 'company_type',
      'labelClass' => 'g-input icon-star0',
      'itemClass' => 'width50',
      'label' => '{LNG_Person Type}',
      'options' => array(0 => '{LNG_Natural Person}', 1 => '{LNG_Juristic Person}'),
      'value' => isset(self::$cfg->type) ? self::$cfg->type : 0
    ));
    // tax_id
    $groups->add('number', array(
      'id' => 'tax_id',
      'name' => 'company_tax_id',
      'labelClass' => 'g-input icon-profile',
      'itemClass' => 'width50',
      'label' => '{LNG_Tax ID}',
      'placeholder' => '{LNG_Tax ID 13 digit}',
      'value' => isset(self::$cfg->tax_id) ? self::$cfg->tax_id : ''
    ));
    // idcard
    $groups->add('number', array(
      'id' => 'idcard',
      'name' => 'company_tax_id',
      'labelClass' => 'g-input icon-profile',
      'itemClass' => 'width50',
      'label' => '{LNG_Identification number}',
      'placeholder' => '{LNG_13-digit identification number}',
      'value' => isset(self::$cfg->tax_id) ? self::$cfg->tax_id : ''
    ));
    // company_name
    $fieldset->add('text', array(
      'id' => 'company_name',
      'labelClass' => 'g-input icon-office',
      'itemClass' => 'item',
      'label' => '{LNG_Name of Establishment}',
      'comment' => '{LNG_Name of establishment registered VAT}',
      'maxlength' => 150,
      'value' => isset(self::$cfg->company_name) ? self::$cfg->company_name : ''
    ));
    // branch
    $fieldset->add('text', array(
      'id' => 'company_branch',
      'itemClass' => 'item',
      'labelClass' => 'g-input icon-office',
      'label' => '{LNG_Branch name}',
      'comment' => '{LNG_Office name or Branch name eg head office} ({LNG_not required})',
      'maxlength' => 150,
      'value' => isset(self::$cfg->branch) ? self::$cfg->branch : ''
    ));
    // address
    $fieldset->add('text', array(
      'id' => 'company_address',
      'labelClass' => 'g-input icon-location',
      'itemClass' => 'item',
      'label' => '{LNG_Address}',
      'maxlength' => 150,
      'value' => isset(self::$cfg->address) ? self::$cfg->address : ''
    ));
    $groups = $fieldset->add('groups');
    // provinceID
    $groups->add('select', array(
      'id' => 'company_provinceID',
      'labelClass' => 'g-input icon-location',
      'itemClass' => 'width33',
      'label' => '{LNG_Province}',
      'options' => \Kotchasan\Province::all(),
      'value' => isset(self::$cfg->provinceID) ? self::$cfg->provinceID : 102
    ));
    // province
    $groups->add('text', array(
      'id' => 'company_province',
      'labelClass' => 'g-input icon-location',
      'itemClass' => 'width33',
      'label' => '{LNG_Province}',
      'maxlength' => 64,
      'value' => isset(self::$cfg->province) ? self::$cfg->province : ''
    ));
    // zipcode
    $groups->add('text', array(
      'id' => 'company_zipcode',
      'labelClass' => 'g-input icon-location',
      'itemClass' => 'width33',
      'label' => '{LNG_Zipcode}',
      'pattern' => '[0-9]+',
      'maxlength' => 10,
      'value' => isset(self::$cfg->zipcode) ? self::$cfg->zipcode : 10000
    ));
    // country
    $groups->add('select', array(
      'id' => 'company_country',
      'labelClass' => 'g-input icon-world',
      'itemClass' => 'width33',
      'label' => '{LNG_Country}',
      'options' => \Kotchasan\Country::all(),
      'value' => isset(self::$cfg->country) ? self::$cfg->country : 'TH'
    ));
    $groups = $fieldset->add('groups');
    // phone
    $groups->add('text', array(
      'id' => 'company_phone',
      'labelClass' => 'g-input icon-phone',
      'itemClass' => 'width50',
      'label' => '{LNG_Phone}',
      'maxlength' => 20,
      'value' => isset(self::$cfg->phone) ? self::$cfg->phone : ''
    ));
    // fax
    $groups->add('text', array(
      'id' => 'company_fax',
      'labelClass' => 'g-input icon-print',
      'itemClass' => 'width50',
      'label' => '{LNG_Fax}',
      'maxlength' => 20,
      'value' => isset(self::$cfg->fax) ? self::$cfg->fax : ''
    ));
    $fieldset = $form->add('fieldset', array(
      'class' => 'submit'
    ));
    // submit
    $fieldset->add('submit', array(
      'class' => 'button ok large',
      'value' => '{LNG_Save}'
    ));
    $form->script('initCompany();');
    return $form->render();
  }
}