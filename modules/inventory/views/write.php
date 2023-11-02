<?php
/**
 * @filesource modules/inventory/views/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Write;

use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-write&tab=product
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มเพิ่ม/แก้ไข Inventory
     *
     * @param Request $request
     * @param object $product
     * @param bool $modal
     *
     * @return string
     */
    public function render(Request $request, $product, $modal = false)
    {
        $form = Html::create('form', array(
            'id' => 'product',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/write/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        if ($modal) {
            $form->add('header', array(
                'innerHTML' => '<h3 class=icon-new>{LNG_Add} {LNG_Product}</h3>'
            ));
            $fieldset = $form->add('fieldset');
        } else {
            $fieldset = $form->add('fieldset', array(
                'title' => '{LNG_Details of} {LNG_Product}'
            ));
        }
        if ($product->id == 0) {
            $groups = $fieldset->add('groups');
            // product_no
            $groups->add('text', array(
                'id' => 'write_product_no',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-barcode',
                'label' => '{LNG_Product code}/{LNG_Barcode}',
                'maxlength' => 150,
                'autofocus' => true,
                'value' => $product->product_no,
                'placeholder' => '{LNG_Leave empty for generate auto}'
            ));
            // create_date
            $groups->add('date', array(
                'id' => 'write_create_date',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-calendar',
                'label' => '{LNG_Transaction date}',
                'value' => date('Y-m-d')
            ));
        }
        // topic
        $fieldset->add('text', array(
            'id' => 'write_topic',
            'itemClass' => 'item',
            'labelClass' => 'g-input icon-product',
            'label' => '{LNG_Product name}/{LNG_Service}',
            'maxlength' => 150,
            'value' => $product->topic
        ));
        // category
        $category = \Inventory\Category\Model::init(false);
        $n = 0;
        $groups = $fieldset->add('groups');
        foreach (Language::get('INVENTORY_CATEGORIES', array()) as $key => $label) {
            $groups->add('text', array(
                'id' => $key,
                'labelClass' => 'g-input icon-category',
                'itemClass' => 'width50',
                'label' => $label,
                'datalist' => $category->toSelect($key),
                'value' => isset($product->{$key}) ? $product->{$key} : 0,
                'text' => ''
            ));
            $n++;
            if ($n % 2 == 0) {
                $groups = $fieldset->add('groups');
            }
        }
        // count_stock
        $groups->add('select', array(
            'id' => 'write_count_stock',
            'itemClass' => 'width50',
            'labelClass' => 'g-input icon-number',
            'label' => '{LNG_Type}',
            'options' => Language::get('COUNT_STOCK'),
            'value' => $product->count_stock
        ));
        if ($product->id == 0) {
            // ใหม่
            $groups = $fieldset->add('groups', array(
                'comment' => '{LNG_No need to fill in the purchase price if the product is not counting stock}'
            ));
            // cost
            $groups->add('currency', array(
                'id' => 'write_cost',
                'itemClass' => 'width33',
                'labelClass' => 'g-input icon-money',
                'label' => '{LNG_Purchase price} ({LNG_Cost})'
            ));
            // stock
            $groups->add('number', array(
                'id' => 'write_stock',
                'itemClass' => 'width33'.($product->count_stock == 1 ? '' : ' hidden'),
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
        } else {
            $groups = $fieldset->add('groups');
            // cost
            $groups->add('currency', array(
                'id' => 'write_cost',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-money',
                'label' => '{LNG_Purchase price} ({LNG_Cost})',
                'value' => $product->cost
            ));
            if ($product->count_stock > 0) {
                // stock
                $groups->add('number', array(
                    'id' => 'write_stock',
                    'itemClass' => 'width50',
                    'labelClass' => 'g-input icon-number',
                    'label' => '{LNG_Stock}',
                    'value' => $product->stock
                ));
            }
        }
        if ($product->id == 0) {
            $groups = $fieldset->add('groups');
            // price
            $groups->add('currency', array(
                'id' => 'write_price',
                'itemClass' => 'width33',
                'labelClass' => 'g-input icon-money',
                'label' => '{LNG_Selling price}',
                'value' => $product->price
            ));
            // unit
            $groups->add('text', array(
                'id' => 'write_unit',
                'itemClass' => 'width33',
                'labelClass' => 'g-input icon-edit',
                'label' => '{LNG_Unit}',
                'value' => $product->unit
            ));
            // vat
            $groups->add('select', array(
                'id' => 'write_vat',
                'itemClass' => 'width33',
                'labelClass' => 'g-input icon-money',
                'label' => '{LNG_VAT}',
                'options' => Language::get('TAX_STATUS'),
                'value' => $product->vat
            ));
        }
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button save large icon-save',
            'value' => '{LNG_Save}'
        ));
        if ($product->id == 0 && !$modal) {
            // save_and_create
            $fieldset->add('checkbox', array(
                'id' => 'save_and_create',
                'label' => '&nbsp;{LNG_Save and create new}',
                'labelClass' => 'inline',
                'value' => 1,
                'checked' => self::$request->cookie('save_and_create')->toInt() == 1
            ));
        }
        // id
        $fieldset->add('hidden', array(
            'id' => 'write_id',
            'value' => $product->id
        ));
        $fieldset->add('hidden', array(
            'id' => 'modal',
            'value' => MAIN_INIT
        ));
        // Javascript
        $form->script('initInventoryWrite();');
        // คืนค่า HTML
        return $form->render();
    }
}
