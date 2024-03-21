<?php
/**
 * @filesource modules/inventory/views/customer.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Customer;

use Kotchasan\Html;

/**
 * module=inventory-customer
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มเพิ่ม-แก้ไข ลูกค้า/ผู้จำหน่าย
     *
     * @param array  $customer
     * @param string $type Customer, Supplier
     * @param bool  $modal
     *
     * @return string
     */
    public function render($customer, $type, $modal = false)
    {
        $form = Html::create('form', array(
            'id' => 'customer_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/customer/submit',
            'onsubmit' => 'doFormSubmit',
            'token' => true,
            'ajax' => true
        ));
        if ($modal) {
            $form->add('header', array(
                'innerHTML' => '<h3 class=icon-register>{LNG_Add} '.$type.'</h3>'
            ));
            $fieldset = $form->add('fieldset');
        } else {
            $fieldset = $form->add('fieldset', array(
                'title' => '{LNG_Details of} '.$type
            ));
        }
        // customer_no
        $fieldset->add('text', array(
            'id' => 'register_customer_no',
            'itemClass' => 'item',
            'labelClass' => 'g-input icon-barcode',
            'label' => '{LNG_Customer No.}',
            'placeholder' => '{LNG_Leave empty for generate auto}',
            'maxlength' => 20,
            'value' => $customer['customer_no']
        ));
        $groups = $fieldset->add('groups');
        // company
        $groups->add('text', array(
            'id' => 'register_company',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-office',
            'label' => '{LNG_Name}/{LNG_Company name}',
            'comment' => '{LNG_Name of the person or company&#039;s name}',
            'maxlength' => 150,
            'value' => $customer['company']
        ));
        // branch
        $groups->add('text', array(
            'id' => 'register_branch',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-office',
            'label' => '{LNG_Branch name}',
            'comment' => '{LNG_Office name or Branch name eg head office}',
            'maxlength' => 150,
            'value' => $customer['branch']
        ));
        $groups = $fieldset->add('groups');
        // tax_id
        $groups->add('number', array(
            'id' => 'register_tax_id',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-profile',
            'label' => '{LNG_Tax ID}',
            'comment' => '{LNG_Tax ID 13 digit}',
            'maxlength' => 13,
            'value' => $customer['tax_id']
        ));
        // name
        $groups->add('text', array(
            'id' => 'register_name',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-customer',
            'label' => '{LNG_Contactor}',
            'comment' => '{LNG_Contact name If the customer is an agency or company}',
            'maxlength' => 50,
            'value' => $customer['name']
        ));
        // address
        $fieldset->add('text', array(
            'id' => 'register_address',
            'itemClass' => 'item',
            'label' => '{LNG_Address}',
            'labelClass' => 'g-input icon-address',
            'maxlength' => 150,
            'value' => $customer['address']
        ));
        $groups = $fieldset->add('groups', array(
            'comment' => '{LNG_Address details} {LNG_show on receipt}'
        ));
        // country
        $groups->add('text', array(
            'id' => 'register_country',
            'labelClass' => 'g-input icon-world',
            'itemClass' => 'width33',
            'label' => '{LNG_Country}',
            'datalist' => \Kotchasan\Country::all(),
            'value' => $customer['country']
        ));
        // provinceID
        $groups->add('text', array(
            'id' => 'register_province',
            'name' => 'register_provinceID',
            'labelClass' => 'g-input icon-location',
            'itemClass' => 'width33',
            'label' => '{LNG_Province}',
            'datalist' => [],
            'text' => $customer['province'],
            'value' => $customer['provinceID']
        ));
        // zipcode
        $groups->add('number', array(
            'id' => 'register_zipcode',
            'labelClass' => 'g-input icon-number',
            'itemClass' => 'width33',
            'label' => '{LNG_Zipcode}',
            'maxlength' => 10,
            'value' => $customer['zipcode']
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Other details}'
        ));
        $groups = $fieldset->add('groups');
        // phone
        $groups->add('number', array(
            'id' => 'register_phone',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-phone',
            'label' => '{LNG_Phone}',
            'value' => $customer['phone']
        ));
        // fax
        $groups->add('number', array(
            'id' => 'register_fax',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-print',
            'label' => '{LNG_Phone}/{LNG_Fax}',
            'value' => $customer['fax']
        ));
        $groups = $fieldset->add('groups');
        // email
        $groups->add('email', array(
            'id' => 'register_email',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-email',
            'label' => '{LNG_Email}',
            'comment' => '{LNG_The contact email Used to send documents by email}',
            'maxlength' => 50,
            'value' => $customer['email'],
            'validator' => array('keyup,change', 'checkEmail')
        ));
        // website
        $groups->add('url', array(
            'id' => 'register_website',
            'itemClass' => 'width50',
            'label' => '{LNG_Website}',
            'labelClass' => 'g-input icon-world',
            'maxlength' => 150,
            'value' => $customer['website']
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
            'id' => 'register_id',
            'value' => $customer['id']
        ));
        $fieldset->add('hidden', array(
            'id' => 'modal',
            'value' => MAIN_INIT
        ));
        // Javascript
        $form->script('initEditProfile("register");');
        // คืนค่า HTML
        return $form->render();
    }
}
