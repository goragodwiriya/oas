<?php
/**
 * @filesource modules/inventory/views/write.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Write;

use \Kotchasan\Http\Request;
use \Kotchasan\Html;
use \Kotchasan\Language;

/**
 * module=inventory-write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * ฟอร์มเพิ่ม แก้ไข สินค้า
   *
   * @param Request $request
   * @param array $product
   * @param array $login
   * @return string
   */
  public function render(Request $request, $product, $login)
  {
    // form
    $form = Html::create('form', array(
        'id' => 'product',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/inventory/model/write/submit',
        'onsubmit' => 'doInventorySubmit',
        'token' => true,
        'ajax' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Details of} {LNG_Product}'
    ));
    $groups = $fieldset->add('groups');
    // product_no
    $groups->add('text', array(
      'id' => 'write_product_no',
      'itemClass' => 'width50',
      'labelClass' => 'g-input icon-number',
      'label' => '{LNG_Product Code}/{LNG_Barcode}',
      'maxlength' => 150,
      'value' => $product['product_no'],
      'placeholder' => '{LNG_Leave empty for generate auto}'
    ));
    // topic
    $groups->add('text', array(
      'id' => 'write_topic',
      'itemClass' => 'width50',
      'labelClass' => 'g-input icon-product',
      'label' => '{LNG_Product name}/{LNG_Service}',
      'maxlength' => 150,
      'value' => $product['topic']
    ));
    // description
    $fieldset->add('text', array(
      'id' => 'write_description',
      'itemClass' => 'item',
      'labelClass' => 'g-input icon-edit',
      'label' => '{LNG_Description}',
      'maxlength' => 255,
      'value' => $product['description']
    ));
    $groups = $fieldset->add('groups');
    // category
    $groups->add('text', array(
      'id' => 'write_category',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-category',
      'label' => '{LNG_Category}',
      'placeholder' => Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Category}')),
      'value' => $product['category']
    ));
    // count_stock
    $groups->add('select', array(
      'id' => 'write_count_stock',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-number',
      'label' => '{LNG_Type}',
      'options' => Language::get('COUNT_STOCK'),
      'value' => $product['count_stock']
    ));
    if ($product['id'] == 0) {
      // create_date
      $groups->add('date', array(
        'id' => 'write_create_date',
        'itemClass' => 'width33',
        'labelClass' => 'g-input icon-calendar',
        'label' => '{LNG_Transaction date}',
        'value' => date('Y-m-d')
      ));
      // ใหม่
      $groups = $fieldset->add('groups', array(
        'comment' => '{LNG_Do not enter the Buy Price and Stock If a product is unlimited}'
      ));
      // buy_price
      $groups->add('currency', array(
        'id' => 'write_buy_price',
        'itemClass' => 'width33',
        'labelClass' => 'g-input icon-money',
        'label' => '{LNG_Buy Price}'
      ));
      // quantity
      $groups->add('number', array(
        'id' => 'write_quantity',
        'itemClass' => 'width33'.($product['count_stock'] == 1 ? '' : ' hidden'),
        'labelClass' => 'g-input icon-number',
        'label' => '{LNG_Stock}'
      ));
      // buy_vat
      $groups->add('select', array(
        'id' => 'write_buy_vat',
        'itemClass' => 'width33',
        'labelClass' => 'g-input icon-money',
        'label' => '{LNG_VAT}',
        'options' => Language::get('TAX_STATUS')
      ));
    }
    $groups = $fieldset->add('groups');
    // price
    $groups->add('currency', array(
      'id' => 'write_price',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-money',
      'label' => '{LNG_Sell Price}',
      'value' => $product['price']
    ));
    // unit
    $groups->add('text', array(
      'id' => 'write_unit',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-edit',
      'label' => '{LNG_Unit}',
      'placeholder' => Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Unit}')),
      'value' => $product['unit']
    ));
    // vat
    $groups->add('select', array(
      'id' => 'write_vat',
      'itemClass' => 'width33',
      'labelClass' => 'g-input icon-money',
      'label' => '{LNG_VAT}',
      'options' => Language::get('TAX_STATUS'),
      'value' => $product['vat']
    ));
    $fieldset = $form->add('fieldset', array(
      'class' => 'submit'
    ));
    // submit
    $fieldset->add('submit', array(
      'class' => 'button save large icon-save',
      'value' => '{LNG_Save}'
    ));
    $fieldset->add('hidden', array(
      'id' => 'write_id',
      'value' => $product['id']
    ));
    $fieldset->add('hidden', array(
      'id' => 'modal',
      'value' => MAIN_INIT
    ));
    // Javascript
    $form->script('initInventoryWrite();');
    // คืนค่าฟอร์ม
    return $form->render();
  }
}