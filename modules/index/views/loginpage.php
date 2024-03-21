<?php
/**
 * @filesource modules/index/views/loginpage.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Loginpage;

use Kotchasan\Html;
use Kotchasan\Language;

/**
 * module=index-loginpage
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มตั้งค่า
     *
     * @return string
     */
    public function render()
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/loginpage/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-signin',
            'title' => '{LNG_Login page}'
        ));
        $groups = $fieldset->add('groups');
        // login_header_color
        $groups->add('color', array(
            'id' => 'login_header_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Text color} {LNG_Header}',
            'value' => self::$cfg->login_header_color
        ));
        // login_footer_color
        $groups->add('color', array(
            'id' => 'login_footer_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Text color} {LNG_Footer}',
            'value' => self::$cfg->login_footer_color
        ));
        $groups = $fieldset->add('groups');
        // login_bg_color
        $groups->add('color', array(
            'id' => 'login_bg_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Background color}',
            'value' => self::$cfg->login_bg_color
        ));
        // login_color
        $groups->add('color', array(
            'id' => 'login_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Text color}',
            'value' => self::$cfg->login_color
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-comments',
            'title' => '{LNG_Message displayed on login page}'
        ));
        // login_message
        $fieldset->add('textarea', array(
            'id' => 'login_message',
            'labelClass' => 'g-input icon-file',
            'itemClass' => 'item',
            'label' => '{LNG_Message}',
            'rows' => 5,
            'value' => isset(self::$cfg->login_message) ? self::$cfg->login_message : ''
        ));
        // login_message_style
        $fieldset->add('select', array(
            'id' => 'login_message_style',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'item',
            'label' => '{LNG_Style}',
            'options' => array('hidden' => Language::get('BOOLEANS', 'Disabled', 0), 'tip' => 'Tip', 'warning' => 'Warning', 'message' => 'Message'),
            'value' => self::$cfg->login_message_style
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
