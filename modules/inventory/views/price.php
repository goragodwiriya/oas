<?php
/**
 * @filesource modules/inventory/views/price.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Price;

use Kotchasan\DataTable;
use Kotchasan\Form;
use Kotchasan\Html;
use Kotchasan\Http\Request;

/**
 * module=inventory-write&tab=price
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
        // หมวดหมู่
        $this->category = \Inventory\Category\Model::init(false);
        // form
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/price/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Selling price} '.$product->topic
        ));
        // ตาราง
        $table = new DataTable(array(
            /* Data */
            'datas' => \Inventory\Price\Model::toDataTable($product->id),
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
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'topic' => array(
                    'text' => '{LNG_Detail}',
                    'class' => 'center'
                ),
                'price' => array(
                    'text' => '{LNG_Selling price}',
                    'class' => 'center'
                ),
                'cut_stock' => array(
                    'text' => '{LNG_Cut stock}',
                    'class' => 'center'
                ),
                'unit' => array(
                    'text' => '{LNG_Unit}',
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
        $item['cut_stock'] = Form::text(array(
            'name' => 'cut_stock[]',
            'size' => 1,
            'labelClass' => 'g-input',
            'value' => $item['cut_stock']
        ))->render();
        $item['unit'] = Form::text(array(
            'name' => 'unit[]',
            'size' => 1,
            'labelClass' => 'g-input',
            'value' => $item['unit']
        ))->render();
        return $item;
    }
}
