<?php
/**
 * @filesource modules/inventory/views/sell.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Sell;

use \Kotchasan\Html;
use \Kotchasan\Language;
use \Kotchasan\Currency;

/**
 * ฟอร์มเพิ่ม/แก้ไข ออเดอร์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * module=inventory-sell
   *
   * @param object $index
   * @param array $login
   * @return string
   */
  public function render($index, $login)
  {
    // form
    $form = Html::create('form', array(
        'id' => 'order_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/inventory/model/sell/submit',
        'onsubmit' => 'doFormSubmit',
        'ajax' => true,
        'token' => true,
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Details of} {LNG_Customer}',
      'titleClass' => 'icon-profile'
    ));
    $groups = $fieldset->add('groups');
    // customer
    $groups->add('text', array(
      'id' => 'customer',
      'labelClass' => 'g-input icon-customer',
      'itemClass' => 'width90',
      'label' => '{LNG_Customer}<span class=tablet> (F2)</span>',
      'placeholder' => Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Company name}, {LNG_Name}, {LNG_Email}, {LNG_Phone}')),
      'title' => '{LNG_Customer}',
      'value' => $index->customer,
      'autofocus' => true,
    ));
    // add_customer
    $groups->add('button', array(
      'id' => 'add_customer',
      'itemClass' => 'width10',
      'labelClass' => 'g-input',
      'class' => 'green button wide center icon-register',
      'label' => '&nbsp;',
      'value' => '<span class=mobile>{LNG_Add New} {LNG_Customer}</span>',
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Transaction details}',
      'titleClass' => 'icon-cart'
    ));
    $groups = $fieldset->add('groups');
    // order_no
    $groups->add('text', array(
      'id' => 'order_no',
      'labelClass' => 'g-input icon-number',
      'itemClass' => 'width50',
      'label' => '{LNG_Order No.}',
      'placeholder' => '{LNG_Leave empty for generate auto}',
      'value' => $index->order_no
    ));
    // order_date
    $order_date = explode(' ', $index->order_date);
    $groups->add('date', array(
      'id' => 'order_date',
      'labelClass' => 'g-input icon-calendar',
      'itemClass' => 'width50',
      'label' => '{LNG_Transaction date}',
      'value' => $order_date[0]
    ));
    $groups = $fieldset->add('groups');
    // product_quantity
    $groups->add('number', array(
      'id' => 'product_quantity',
      'labelClass' => 'g-input icon-number',
      'itemClass' => 'width20',
      'label' => '{LNG_Quantity}',
      'value' => 1,
    ));
    // product_no
    $groups->add('text', array(
      'id' => 'product_no',
      'labelClass' => 'g-input icon-addtocart',
      'itemClass' => 'width70',
      'label' => '{LNG_Product Code}/{LNG_Barcode}<span class=tablet> (F4)</span>',
      'title' => '{LNG_Product}',
      'placeholder' => Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Product Code}, {LNG_Product name}')),
    ));
    // add_product
    $groups->add('button', array(
      'id' => 'add_product',
      'itemClass' => 'width10',
      'labelClass' => 'g-input',
      'class' => 'magenta button wide center icon-new',
      'label' => '&nbsp;',
      'value' => '<span class=mobile>{LNG_Add New} {LNG_Product}</span><span class=tablet> (F7)</span>',
    ));
    $table = '<table class="fullwidth"><thead><tr>';
    $table .= '<th class=center>{LNG_Quantity}</th>';
    $table .= '<th>{LNG_Detail}</th>';
    $table .= '<th class=center>{LNG_Unit Price}</th>';
    $table .= '<th class=center></th>';
    $table .= '<th class=center>{LNG_Discount}</th>';
    // สกุลเงิน
    $currency_unit = Language::find('CURRENCY_UNITS', null, self::$cfg->currency_unit);
    $table .= '<th class=center colspan=2>{LNG_Amount} ('.$currency_unit.')</th>';
    $table .= '</tr></thead><tbody id=tb_products>';
    foreach (\Inventory\Stock\Model::get($index->id, 'OUT') as $item) {
      $table .= '<tr'.($index->id == 0 ? ' class=hidden' : '').'>';
      $table .= '<td><label class="g-input"><input type=text name=quantity[] size=2 value="'.$item['quantity'].'" class=num></label></td>';
      $table .= '<td><label class="g-input"><input type=text name=topic[] value="'.$item['topic'].'"></label></td>';
      $table .= '<td><label class="g-input"><input type=text name=price[] size=5 value="'.$item['price'].'" class=price></label></td>';
      $table .= '<td class=center><label class="g-input">{LNG_VAT} <input type=checkbox name=vat[]'.($item['vat'] > 0 ? ' checked ' : ' ').'value="'.$item['vat'].'" class=vat></label></td>';
      $table .= '<td class=wlabel><label class="g-input"><input type=text name=discount[] value="'.$item['discount'].'" size=5 class=price></label><span class=label>%</span></td>';
      $table .= '<td><label class="g-input"><input type=text name=total[] size=5 readonly></label></td>';
      $table .= '<td><a class="button wide delete notext"><span class=icon-delete></span></a><input type=hidden name=id[] value="'.$item['product_id'].'"></td>';
      $table .= '</tr>';
    }
    $table .= '</tbody><tfoot>';
    $table .= '<tr><td colspan=3 rowspan=8 class=top><label for=comment>{LNG_Annotation}</label><span class="g-input icon-file"><textarea rows=6 name=comment id=comment>'.$index->comment.'</textarea></span></td>';
    $table .= '<td class=right>{LNG_Total}</td><td colspan=2 class=right id=sub_total>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr><td class=right><label for=discount_percent>{LNG_Discount}<span class=tablet> (F8)</span></label></td>';
    $table .= '<td class=wlabel><span class="g-input"><input type=text class=currency name=discount_percent id=discount_percent value="'.$index->discount_percent.'" title="{LNG_Discount} %" size=5></span><span class=label>%</span></td>';
    $table .= '<td><span class="g-input"><input type=text class=currency name=total_discount id=total_discount value="'.$index->discount.'" title="{LNG_Discount}" size=5></span></td>';
    $table .= '<td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr><td class=right>{LNG_Total Before Tax}</td><td></td><td><label class=g-input><input type=text class=result id=amount name=amount size=5 readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr><td class=right><label for=vat_status>{LNG_VAT}</label></td><td><span class=g-input><select name=vat_status id=vat_status>';
    foreach (Language::get('TAX_STATUS') as $k => $v) {
      $sel = $index->vat_status == $k ? ' selected' : '';
      $table .= '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
    }
    $table .= '</select></span></td><td><label class=g-input><input type=text class=result id=vat_total name=vat_total size=5 value="'.Currency::format($index->vat).'" readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr><td class=right>{LNG_Grand total}</td><td colspan=2 class=right id=grand_total>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr><td class=right><label for=tax_status>{LNG_Withholding Tax}</label></td><td><span class=g-input><select name=tax_status id=tax_status>';
    foreach (Language::get('WH_TAX') as $k => $v) {
      $sel = $index->tax_status == $k ? ' selected' : '';
      $table .= '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
    }
    $table .= '</select></span></td><td><label class=g-input><input type=text class=result id=tax_total name=tax_total size=5 value="'.Currency::format($index->tax).'" readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
    $table .= '<tr class=due><td class=right>{LNG_Payment Amount}</td><td colspan=2 class="total right" id=payment_amount>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
    // status
    $table .= '<tr><td class=right><label for=status>{LNG_Status}<span class=tablet> (F9)</span></label></td><td colspan=3><span class="g-input icon-star0"><select id=status name=status>';
    foreach (Language::get('SELL_TYPIES') as $k => $v) {
      $sel = $k == $index->status ? ' selected' : '';
      $table .= '<option value='.$k.$sel.'>'.$v.'</option>';
    }
    $table .= '</select></span></td></tr>';
    $table .= '</tfoot></table>';
    $fieldset->add('div', array(
      'class' => 'item',
      'innerHTML' => $table
    ));
    $fieldset = $form->add('fieldset', array(
      'class' => 'submit right'
    ));
    // save_and_create
    $fieldset->add('checkbox', array(
      'id' => 'save_and_create',
      'label' => '{LNG_Save and Create New}&nbsp;',
      'value' => 1,
      'checked' => self::$request->cookie('sell_save_and_create')->toInt() == 1
    ));
    // submit
    $fieldset->add('submit', array(
      'class' => 'button ok large',
      'id' => 'order_submit',
      'value' => '{LNG_Save}<span class=tablet> (F10)</span>'
    ));
    // id
    $fieldset->add('hidden', array(
      'id' => 'order_id',
      'value' => $index->id
    ));
    // customer_id
    $fieldset->add('hidden', array(
      'id' => 'customer_id',
      'value' => $index->customer_id
    ));
    // Javascript
    $form->script('initInventoryInOut('.self::$cfg->vat.', "sell");');
    // คืนค่าฟอร์ม
    return $form->render();
  }
}