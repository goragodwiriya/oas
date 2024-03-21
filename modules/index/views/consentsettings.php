<?php
/**
 * @filesource modules/index/views/consentsettings.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Consentsettings;

use Kotchasan\Html;
use Kotchasan\Language;

/**
 * module=consentsettings
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * นโยบายคุกกี้
     *
     * @param object $config
     * @param array $login
     *
     * @return string
     */
    public function render($config, $login)
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/consentsettings/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-verfied',
            'title' => '{LNG_Settings}'
        ));
        // cookie_policy
        $fieldset->add('select', array(
            'id' => 'cookie_policy',
            'labelClass' => 'g-input icon-verfied',
            'itemClass' => 'item',
            'label' => '{LNG_Cookie Policy}',
            'comment' => '{LNG_When enabled, a cookies consent banner will be displayed.}',
            'options' => Language::get('BOOLEANS'),
            'value' => isset($config->cookie_policy) ? $config->cookie_policy : 0
        ));
        // data_controller
        $fieldset->add('email', array(
            'id' => 'data_controller',
            'labelClass' => 'g-input icon-email',
            'itemClass' => 'item',
            'label' => '{LNG_Data controller}',
            'comment' => '{LNG_The e-mail address of the person or entity that has the authority to make decisions about the collection, use or dissemination of personal data.}',
            'value' => isset($config->data_controller) ? $config->data_controller : ''
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
