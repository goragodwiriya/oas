<?php
/**
 * @filesource modules/inventory/views/order.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Order;

use Kotchasan\Currency;
use Kotchasan\Html;
use Kotchasan\Language;

/**
 * module=inventory-order
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * เพิ่ม-แก้ไข Order
     *
     * @param Request $request
     * @param object $index
     *
     * @return string
     */
    public function render($request, $index)
    {
        $form = Html::create('form', array(
            'id' => 'order_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/order/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Details of} {LNG_Customer}/{LNG_Supplier}',
            'titleClass' => 'icon-profile'
        ));
        $groups = $fieldset->add('groups');
        // customer_no
        $groups->add('text', array(
            'id' => 'customer_no',
            'labelClass' => 'g-input icon-barcode',
            'itemClass' => 'width20',
            'label' => '{LNG_Customer}/{LNG_Supplier}<span class=tablet> (F2)</span>',
            'value' => $index->customer_no,
            'autofocus' => true
        ));
        // customer_id
        $groups->add('hidden', array(
            'id' => 'customer_id',
            'value' => $index->customer_id
        ));
        // customer
        $groups->add('text', array(
            'id' => 'customer',
            'labelClass' => 'g-input icon-customer',
            'itemClass' => 'width70',
            'label' => '&nbsp;',
            'placeholder' => Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Company name}, {LNG_Name}, {LNG_Email}, {LNG_Phone}')),
            'value' => $index->customer
        ));
        // add_customer
        $groups->add('button', array(
            'id' => 'add_customer',
            'itemClass' => 'width10',
            'labelClass' => 'g-input',
            'class' => 'green button wide center icon-register',
            'label' => '&nbsp;',
            'value' => '<span class=mobile>{LNG_Add} {LNG_Customer}/{LNG_Supplier}</span>'
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
            'itemClass' => $index->status == 'IN' ? 'width33' : 'width50',
            'label' => '{LNG_Order No.}',
            'placeholder' => '{LNG_Leave empty for generate auto}',
            'value' => $index->order_no
        ));
        // order_date
        $order_date = explode(' ', $index->order_date);
        $groups->add('date', array(
            'id' => 'order_date',
            'labelClass' => 'g-input icon-calendar',
            'itemClass' => $index->status == 'IN' ? 'width33' : 'width50',
            'label' => '{LNG_Transaction date}',
            'value' => $order_date[0]
        ));
        if ($index->status == 'IN') {
            // บันทึกรายจ่าย/เจ้าหนี้ due_date
            $groups->add('date', array(
                'id' => 'due_date',
                'labelClass' => 'g-input icon-calendar',
                'itemClass' => 'width33',
                'label' => '{LNG_Due date}',
                'value' => $index->due_date
            ));
        }
        $groups = $fieldset->add('groups');
        // product_quantity
        $groups->add('number', array(
            'id' => 'product_quantity',
            'labelClass' => 'g-input icon-number',
            'itemClass' => 'width20',
            'label' => '{LNG_Quantity}',
            'data-keyboard' => '0123456789.',
            'value' => 1
        ));
        // product_no
        $title = Language::replace('Fill some of the :name to find', array(':name' => '{LNG_Product code}, {LNG_Product name}'));
        $groups->add('text', array(
            'id' => 'product_no',
            'labelClass' => 'g-input icon-barcode',
            'itemClass' => 'width70',
            'label' => '{LNG_Product code}/{LNG_Barcode}<span class=tablet> (F4)</span>',
            'title' => $title,
            'placeholder' => $title
        ));
        // add_product
        $groups->add('button', array(
            'id' => 'add_product',
            'itemClass' => 'width10',
            'labelClass' => 'g-input',
            'class' => 'magenta button wide center icon-new',
            'label' => '&nbsp;',
            'value' => '<span class=mobile>{LNG_Add} {LNG_Product}</span><span class=tablet> (F7)</span>'
        ));
        $table = '<table class="fullwidth"><thead><tr>';
        $table .= '<th class="center nowrap">{LNG_Quantity}</th>';
        $table .= '<th class=nowrap>{LNG_Detail}</th>';
        $table .= '<th class="center nowrap">{LNG_Unit price}</th>';
        $table .= '<th></th>';
        $table .= '<th class="center nowrap">{LNG_Discount}</th>';
        // สกุลเงิน
        $currency_unit = Language::get('CURRENCY_UNITS', null, self::$cfg->currency_unit);
        $table .= '<th class="center nowrap" colspan=2>{LNG_Amount}</th>';
        $table .= '</tr></thead><tbody id=tb_products>';
        foreach (\Inventory\Stock\Model::get($index->id, $index->status) as $item) {
            $table .= '<tr'.($index->id == 0 ? ' class=hidden' : '').'>';
            $table .= '<td><label class="g-input"><input type=text name=quantity[] size=2 value="'.$item['quantity'].'" class=num title="{LNG_Quantity}"></label></td>';
            $table .= '<td><label class="g-input"><input type=text name=topic[] value="'.$item['topic'].'" title="{LNG_Detail}"></label></td>';
            $table .= '<td><label class="g-input"><input type=text name=price[] size=5 value="'.$item['price'].'" class=price title="{LNG_Unit price}"></label></td>';
            $table .= '<td class=center><label>{LNG_VAT} <input type=checkbox name=vat[]'.($item['vat'] > 0 ? ' checked ' : ' ').'value="'.$item['vat'].'" class=vat></label></td>';
            $table .= '<td class=wlabel><label class="g-input"><input type=text name=discount[] value="'.$item['discount'].'" size=5 class=price title="{LNG_Discount}"></label><span class=label>%</span></td>';
            $table .= '<td><label class="g-input"><input type=text name=total[] size=5 readonly></label></td>';
            $table .= '<td>';
            $table .= '<a class="button wide delete notext"><span class=icon-delete></span></a>';
            $table .= '<input type=hidden name=product_no[] value="'.$item['product_no'].'">';
            $table .= '</td>';
            $table .= '</tr>';
        }
        $table .= '</tbody><tfoot>';
        $table .= '<tr><td colspan=3 rowspan=5 class=top><label for=comment>{LNG_Annotation}</label><span class="g-input icon-file"><textarea rows=5 name=comment id=comment>'.$index->comment.'</textarea></span></td>';
        $table .= '<td class="right nowrap">{LNG_Total}</td><td colspan=2 class=right id=sub_total>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr><td class="right nowrap"><label for=discount_percent>{LNG_Discount}<span class=tablet> (F8)</span></label></td>';
        $table .= '<td class=wlabel><span class="g-input"><input type=text class=currency name=discount_percent id=discount_percent value="'.$index->discount_percent.'" title="{LNG_Discount} %" size=5></span><span class=label>%</span></td>';
        $table .= '<td><span class="g-input"><input type=text class=currency name=total_discount id=total_discount value="'.$index->discount.'" title="{LNG_Discount}" size=5></span></td>';
        $table .= '<td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr><td class="right nowrap" colspan=2>{LNG_Total Before Tax}</td><td><label class=g-input><input type=text class=result id=amount name=amount size=5 readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr><td class="right nowrap"><label for=vat_status>{LNG_VAT}</label></td><td><span class=g-input><select name=vat_status id=vat_status>';
        foreach (Language::get('TAX_STATUS') as $k => $v) {
            $sel = $index->vat_status == $k ? ' selected' : '';
            $table .= '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
        }
        $table .= '</select></span></td><td><label class=g-input><input type=text class=result id=vat_total name=vat_total size=5 value="'.Currency::format($index->vat).'" readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr><td class="right nowrap" colspan=2>{LNG_Grand total}</td><td class=right id=grand_total>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr><td class="right nowrap" colspan=4><label for=tax_status>{LNG_Withholding Tax}</label></td><td><span class=g-input><select name=tax_status id=tax_status>';
        foreach (Language::get('WH_TAX') as $k => $v) {
            $sel = $index->tax_status == $k ? ' selected' : '';
            $table .= '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
        }
        $table .= '</select></span></td><td><label class=g-input><input type=text class=result id=tax_total name=tax_total size=5 value="'.Currency::format($index->tax).'" readonly></label></td><td class=right>'.$currency_unit.'</td></tr>';
        $table .= '<tr class=due><td class="right nowrap" colspan=5>{LNG_Payment Amount}</td><td class="total right" id=payment_amount>0.00</td><td class=right>'.$currency_unit.'</td></tr>';
        // status
        $table .= '<tr><td class="right nowrap" colspan=4><label for=status>{LNG_Status}<span class=tablet> (F9)</span></label></td><td colspan=3><span class="g-input icon-star0"><select id=status name=status>';
        foreach ($index->order_status as $k => $v) {
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
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button ok large',
            'id' => 'order_submit',
            'value' => '{LNG_Save}<span class=tablet> (F10)</span>'
        ));
        // save_and_create
        $fieldset->add('checkbox', array(
            'id' => 'save_and_create',
            'label' => '&nbsp;{LNG_Save and create new}',
            'labelClass' => 'inline-block middle',
            'value' => 1,
            'checked' => $request->cookie('save_and_create')->toInt() == 1
        ));
        // id
        $fieldset->add('hidden', array(
            'id' => 'order_id',
            'value' => $index->id
        ));
        // Javascript
        $form->script('initInventoryOrder('.self::$cfg->vat.', "'.$index->menu.'");');
        // คืนค่า HTML
        return $form->render();
    }
}
