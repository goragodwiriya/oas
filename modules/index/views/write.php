<?php
/**
 * @filesource modules/index/views/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Write;

use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มแก้ไขหน้าเพจ
     *
     * @param Request $request
     * @param array $params
     *
     * @return string
     */
    public function render(Request $request, $params)
    {
        // ภาษา
        $language = $request->request('language', 'th')->filter('a-z');
        if (file_exists(ROOT_PATH.DATA_FOLDER.'pages/'.$params['src'].'_'.$language.'.html')) {
            // ภาษาที่เลือก
            $content = file_get_contents(ROOT_PATH.DATA_FOLDER.'pages/'.$params['src'].'_'.$language.'.html');
        } elseif (file_exists(ROOT_PATH.self::$cfg->skin.'/'.$params['src'].'.html')) {
            // เนื้อหาเริ่มต้น
            $content = file_get_contents(ROOT_PATH.self::$cfg->skin.'/'.$params['src'].'.html');
        } else {
            // หน้าเปล่าๆ
            $content = '<h1 class="center">Topic</h1>Xxxxxxx Yyyyyyy';
        }
        // ฟอร์ม
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/write/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Page details} '.$params['pages'][$params['src']]
        ));
        // language
        $fieldset->add('select', array(
            'id' => 'write_language',
            'label' => '{LNG_Language}',
            'labelClass' => 'g-input icon-language',
            'itemClass' => 'item',
            'options' => Language::installedLanguage(),
            'value' => $language
        ));
        // detail
        $fieldset->add('ckeditor', array(
            'id' => 'write_detail',
            'itemClass' => 'item',
            'height' => 300,
            'language' => Language::name(),
            'toolbar' => 'Document',
            'upload' => true,
            'label' => '{LNG_Detail}',
            'value' => $content
        ));
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // src
        $fieldset->add('hidden', array(
            'name' => 'write_src',
            'id' => 'write_src',
            'value' => $params['src']
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button save large icon-save',
            'value' => '{LNG_Save}'
        ));
        $form->script('initPageWrite();');
        // คืนค่า HTML
        return $form->render();
    }
}
