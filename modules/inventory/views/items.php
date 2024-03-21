<?php
/**
 * @filesource modules/inventory/views/items.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Items;

use Kotchasan\DataTable;
use Kotchasan\Form;
use Kotchasan\Html;
use Kotchasan\Http\Request;

/**
 * module=inventory-write&tab=items
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
     *
     * @return string
     */
    public function render(Request $request, $product)
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/items/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '<h3>{LNG_Product code}/{LNG_Barcode} '.$product->topic.'</h3>'
        ));
        // ตาราง
        $table = new DataTable(array(
            /* Data */
            'datas' => \Inventory\Items\Model::toDataTable($product),
            /* แสดงเส้นกรอบ */
            'border' => true,
            /* แสดงตารางแบบ Responsive */
            'responsive' => true,
            /* ไม่ต้องแสดง caption */
            'showCaption' => false,
            /* แสดงปุ่ม บวก-ลบ ในแถว */
            'pmButton' => true,
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* เมื่อมีการสร้างแถว */
            'onInitRow' => 'initInventoryItems',
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'barcode' => array(
                    'text' => '{LNG_Product code}/{LNG_Barcode}',
                    'colspan' => 2
                ),
                'topic' => array(
                    'text' => '{LNG_Detail}',
                    'class' => 'center'
                ),
                'price' => array(
                    'text' => '{LNG_Selling price}',
                    'class' => 'center'
                ),
                'stock' => array(
                    'text' => '{LNG_Stock}',
                    'class' => 'center'
                ),
                'unit' => array(
                    'text' => '{LNG_Unit}',
                    'class' => 'center'
                ),
                'cut_stock' => array(
                    'text' => '{LNG_Cut stock}',
                    'class' => 'center'
                )
            )
        ));
        $fieldset->add('div', array(
            'class' => 'item',
            'innerHTML' => $table->render()
        ));
        // fieldset
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button save large icon-save',
            'value' => '{LNG_Save}'
        ));
        // inventory_id
        $fieldset->add('hidden', array(
            'id' => 'inventory_id',
            'value' => $product->id
        ));
        // คืนค่า HTML
        return $form->render();
    }

    /**
     * จัดรูปแบบการแสดงผลในแต่ละแถว
     *
     * @param array  $item ข้อมูลแถว
     * @param int    $o    ID ของข้อมูล
     * @param object $prop กำหนด properties ของ TR
     *
     * @return array คืนค่า $item กลับไป
     */
    public function onRow($item, $o, $prop)
    {
        $item['barcode'] = '<img style="max-width:none" src="data:image/png;base64,'.base64_encode(\Kotchasan\Barcode::create($item['barcode'], 34, 9)->toPng()).'">';
        $item['product_no'] = Form::text(array(
            'name' => 'product_no[]',
            'labelClass' => 'g-input',
            'value' => $item['product_no']
        ))->render();
        $item['topic'] = Form::text(array(
            'name' => 'topic[]',
            'labelClass' => 'g-input',
            'value' => $item['topic']
        ))->render();
        $item['price'] = Form::text(array(
            'name' => 'price[]',
            'size' => 1,
            'labelClass' => 'g-input',
            'value' => $item['price']
        ))->render();
        if (isset($item['cut_stock'])) {
            $item['cut_stock'] = Form::text(array(
                'name' => 'cut_stock[]',
                'size' => 1,
                'labelClass' => 'g-input',
                'readonly' => true,
                'value' => $item['cut_stock']
            ))->render();
        }
        $item['unit'] = Form::text(array(
            'name' => 'unit[]',
            'size' => 5,
            'labelClass' => 'g-input',
            'value' => $item['unit']
        ))->render();
        return $item;
    }
}
