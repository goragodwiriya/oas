<?php
/**
 * @filesource modules/index/views/languageedit.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Languageedit;

use Kotchasan\DataTable;
use Kotchasan\Form;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * ฟอร์มเขียน/แก้ไข ภาษา
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * module=languageedit
     *
     * @param Request $request
     * @param object $language
     * @param bool $fromKey true รับค่ามาจาก key, false รับค่ามาจาก ID
     *
     * @return string
     */
    public function render(Request $request, $language, $fromKey)
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/languageedit/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        // fieldset
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-write',
            'title' => '{LNG_Detail}'
        ));
        if ($fromKey) {
            // js
            $fieldset->add('hidden', array(
                'id' => 'write_js',
                'value' => $language->js
            ));
            // type
            $fieldset->add('hidden', array(
                'id' => 'write_type',
                'value' => $language->type
            ));
            // owner
            $fieldset->add('hidden', array(
                'id' => 'write_owner',
                'value' => $language->owner
            ));
            // key
            $fieldset->add('hidden', array(
                'id' => 'write_key',
                'value' => $language->key
            ));
        } else {
            // js
            $fieldset->add('select', array(
                'id' => 'write_js',
                'labelClass' => 'g-input icon-file',
                'label' => '{LNG_File}',
                'itemClass' => 'item',
                'options' => array(0 => 'php', 1 => 'js'),
                'value' => $language->js
            ));
            // type
            $fieldset->add('select', array(
                'id' => 'write_type',
                'labelClass' => 'g-input icon-config',
                'label' => '{LNG_Type}',
                'itemClass' => 'item',
                'options' => array('text' => 'Text', 'int' => 'Integer', 'array' => 'Array'),
                'value' => $language->type
            ));
            // owner
            $fieldset->add('select', array(
                'id' => 'write_owner',
                'labelClass' => 'g-input icon-modules',
                'label' => '{LNG_Module}',
                'itemClass' => 'item',
                'options' => \Index\Languageedit\Model::getOwners(),
                'value' => $language->owner
            ));
            // key
            $fieldset->add('text', array(
                'id' => 'write_key',
                'labelClass' => 'g-input icon-edit',
                'label' => '{LNG_Key}',
                'itemClass' => 'item',
                'autofocus' => true,
                'value' => $language->key
            ));
        }
        // table
        $table = new DataTable(array(
            'datas' => $language->datas,
            'onRow' => array($this, 'onRow'),
            'border' => true,
            'responsive' => true,
            'showCaption' => false,
            'pmButton' => true,
            'headers' => array(
                'key' => array(
                    'text' => '{LNG_Key}'
                )
            )
        ));
        $div = $fieldset->add('div', array(
            'class' => 'item',
            'innerHTML' => $table->render()
        ));
        $div->add('div', array(
            'class' => 'comment',
            'innerHTML' => '{LNG_No need to fill in English text. If the English text matches the Key}'
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
        // id
        $fieldset->add('hidden', array(
            'id' => 'write_id',
            'value' => $language->id
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
        $item['key'] = Form::text(array(
            'name' => 'datas[key][]',
            'labelClass' => 'g-input',
            'value' => $item['key']
        ))->render();
        foreach (Language::installedLanguage() as $key) {
            $item[$key] = Form::textarea(array(
                'name' => 'datas['.$key.'][]',
                'labelClass' => 'g-input',
                'value' => isset($item[$key]) ? $item[$key] : ''
            ))->render();
        }
        return $item;
    }
}
