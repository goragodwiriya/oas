<?php
/**
 * @filesource modules/inventory/views/image.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Image;

use Kotchasan\Html;

/**
 * module=inventory-image
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ตั้งค่าบริษัท
     *
     * @return string
     */
    public function render()
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/inventory/model/image/submit',
            'onsubmit' => 'doFormSubmit',
            'token' => true,
            'ajax' => true
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-thumbnail',
            'title' => '{LNG_Pictures for a receipt}'
        ));
        // delete_logo
        $fieldset->add('checkbox', array(
            'id' => 'delete_logo',
            'itemClass' => 'item',
            'label' => '{LNG_remove this photo}',
            'value' => 1
        ));
        // logo
        $fieldset->add('file', array(
            'id' => 'logo',
            'labelClass' => 'g-input icon-upload',
            'itemClass' => 'item',
            'label' => '{LNG_Company logo}',
            'comment' => '{LNG_Select an image size 500 * 500 pixel jpg, png types}',
            'dataPreview' => 'logoImage',
            'previewSrc' => is_file(ROOT_PATH.DATA_FOLDER.'logo.jpg') ? WEB_URL.DATA_FOLDER.'logo.jpg' : WEB_URL.'skin/img/blank.gif'
        ));
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button ok large',
            'value' => '{LNG_Save}'
        ));
        // คืนค่า HTML
        return $form->render();
    }
}
