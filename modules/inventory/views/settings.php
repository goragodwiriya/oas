<?php
/**
 * @filesource modules/inventory/views/settings.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Settings;

use Kotchasan\Html;
use Kotchasan\Language;

/**
 * module=inventory-settings
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ตั้งค่าโมดูล
     *
     * @return string
     */
    public function render()
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/settings/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-config',
            'title' => '{LNG_Details of} {LNG_Accounting}'
        ));
        // authorized
        $fieldset->add('text', array(
            'id' => 'company_authorized',
            'labelClass' => 'g-input icon-user',
            'itemClass' => 'item',
            'label' => '{LNG_Authorized}',
            'comment' => '{LNG_Authorized signatory receipt}',
            'placeholder' => '{LNG_Name}',
            'maxlength' => 150,
            'value' => self::$cfg->authorized
        ));
        // email
        $fieldset->add('text', array(
            'id' => 'company_email',
            'labelClass' => 'g-input icon-email',
            'itemClass' => 'item',
            'label' => '{LNG_Email}',
            'comment' => '{LNG_The contact email Used to send documents by email}',
            'maxlength' => 50,
            'value' => self::$cfg->email
        ));
        // product_no
        $fieldset->add('text', array(
            'id' => 'product_no',
            'labelClass' => 'g-input icon-number',
            'itemClass' => 'item',
            'label' => '{LNG_Product code}',
            'comment' => '{LNG_Number such as %04d (%04d means 4 digits, maximum 11 digits)}',
            'placeholder' => 'P%04d',
            'value' => self::$cfg->product_no
        ));
        $comment = '{LNG_Prefix, if changed The number will be counted again. You can enter %Y%M (year, month).}';
        $comment .= ', {LNG_Number such as %04d (%04d means 4 digits, maximum 11 digits)}';
        foreach (Language::get('ORDER_STATUS') as $s => $label) {
            $groups = $fieldset->add('groups', array(
                'comment' => $comment
            ));
            // prefix
            $groups->add('text', array(
                'id' => $s.'_prefix',
                'labelClass' => 'g-input icon-number',
                'itemClass' => 'width50',
                'label' => $label.' ({LNG_Prefix})',
                'placeholder' => $s.'%Y%M-',
                'value' => isset(self::$cfg->{$s.'_prefix'}) ? self::$cfg->{$s.'_prefix'} : ''
            ));
            // no
            $groups->add('text', array(
                'id' => $s.'_NO',
                'labelClass' => 'g-input icon-number',
                'itemClass' => 'width50',
                'label' => '{LNG_Order No.}',
                'placeholder' => $s.'%04d',
                'value' => isset(self::$cfg->{$s.'_NO'}) ? self::$cfg->{$s.'_NO'} : $s.'%04d'
            ));
        }
        // customer_no
        $fieldset->add('text', array(
            'id' => 'customer_no',
            'labelClass' => 'g-input icon-number',
            'itemClass' => 'item',
            'label' => '{LNG_Customer No.}',
            'comment' => '{LNG_Number such as %04d (%04d means 4 digits, maximum 11 digits)}',
            'placeholder' => 'CU%04d',
            'value' => isset(self::$cfg->customer_no) ? self::$cfg->customer_no : 'CU%04d'
        ));
        // currency_unit
        $fieldset->add('select', array(
            'id' => 'currency_unit',
            'labelClass' => 'g-input icon-currency',
            'itemClass' => 'item',
            'label' => '{LNG_Currency unit}',
            'comment' => '{LNG_Currency for goods and services}',
            'options' => Language::get('CURRENCY_UNITS'),
            'value' => self::$cfg->currency_unit
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-money',
            'title' => '{LNG_Finance}'
        ));
        $groups = $fieldset->add('groups');
        // bank
        $groups->add('select', array(
            'id' => 'bank',
            'itemClass' => 'width33',
            'labelClass' => 'g-input icon-office',
            'label' => '{LNG_Bank}',
            'options' => array('' => '{LNG_Please select}') + Language::get('BANKS'),
            'value' => self::$cfg->bank
        ));
        // bank_name
        $groups->add('text', array(
            'id' => 'bank_name',
            'itemClass' => 'width33',
            'label' => '{LNG_Account name}',
            'labelClass' => 'g-input icon-customer',
            'maxlength' => 100,
            'value' => self::$cfg->bank_name
        ));
        // bank_no
        $groups->add('text', array(
            'id' => 'bank_no',
            'itemClass' => 'width33',
            'label' => '{LNG_Account number}',
            'labelClass' => 'g-input icon-number',
            'maxlength' => 20,
            'value' => self::$cfg->bank_no
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-thumbnail',
            'title' => '{LNG_Size of} {LNG_Image}'
        ));
        // inventory_w
        $fieldset->add('text', array(
            'id' => 'inventory_w',
            'labelClass' => 'g-input icon-width',
            'itemClass' => 'item',
            'label' => '{LNG_Width}',
            'comment' => '{LNG_Image size is in pixels} ({LNG_resized automatically})',
            'value' => self::$cfg->inventory_w
        ));
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button save large icon-save',
            'value' => '{LNG_Save}'
        ));
        // คืนค่า HTML
        return $form->render();
    }
}
