<?php
/**
 * @filesource modules/index/views/theme.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Theme;

use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=theme
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มตั้งค่า theme
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/theme/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        // อ่านไดเร็คทอรี่ skin
        $skin = 'skin/';
        $dir = ROOT_PATH.$skin;
        $files = [];
        $dirHandle = @opendir($dir);
        if ($dirHandle !== false) {
            while (false !== ($file = readdir($dirHandle))) {
                if (is_dir($dir.$file) && is_file($dir.$file.'/settings.php')) {
                    $settings = include $dir.$file.'/settings.php';
                    $files[$skin.$file] = $settings['name'];
                }
            }
            closedir($dirHandle);
        }
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-layout',
            'title' => '{LNG_Theme}'
        ));
        $groups = $fieldset->add('groups');
        // skin
        $groups->add('select', array(
            'id' => 'skin',
            'labelClass' => 'g-input icon-layout',
            'itemClass' => 'width50',
            'label' => '{LNG_Website template}',
            'options' => $files,
            'value' => self::$cfg->skin
        ));
        // theme_width
        $groups->add('select', array(
            'id' => 'theme_width',
            'labelClass' => 'g-input icon-width',
            'itemClass' => 'width50',
            'label' => '{LNG_Width}',
            'options' => Language::get('THEME_WIDTH'),
            'value' => self::$cfg->theme_width
        ));
        // theme_option
        $fieldset->add('checkbox', array(
            'id' => 'theme_option',
            'itemClass' => 'subitem',
            'label' => '{LNG_Use the theme&#039;s default settings.}',
            'value' => 1
        ));
        $groups = $fieldset->add('groups');
        // header_bg_color
        $groups->add('color', array(
            'id' => 'header_bg_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Background color} {LNG_Header}',
            'value' => self::$cfg->header_bg_color
        ));
        // header_color
        $groups->add('color', array(
            'id' => 'header_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Text color} {LNG_Header}',
            'value' => self::$cfg->header_color
        ));
        $groups = $fieldset->add('groups');
        // warpper_bg_color
        $groups->add('color', array(
            'id' => 'warpper_bg_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Background color} ({LNG_Body})',
            'value' => self::$cfg->warpper_bg_color
        ));
        // content_bg
        $groups->add('color', array(
            'id' => 'content_bg',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Background color} ({LNG_Content})',
            'value' => self::$cfg->content_bg
        ));
        $groups = $fieldset->add('groups');
        // footer_color
        $groups->add('color', array(
            'id' => 'footer_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'width50',
            'label' => '{LNG_Text color} {LNG_Footer}',
            'value' => self::$cfg->footer_color
        ));
        // bg_image
        if (is_file(ROOT_PATH.DATA_FOLDER.'images/bg_image.png')) {
            $img = WEB_URL.DATA_FOLDER.'images/bg_image.png?'.time();
        } else {
            $img = WEB_URL.'skin/img/blank.gif';
        }
        // bg_image
        $fieldset->add('file', array(
            'id' => 'file_bg_image',
            'labelClass' => 'g-input icon-image',
            'itemClass' => 'item',
            'label' => '{LNG_Background image}',
            'comment' => '{LNG_Browse image uploaded, type :type} {LNG_no larger than :size}',
            'accept' => array('jpg', 'jpeg', 'png'),
            'dataPreview' => 'bgImage',
            'previewSrc' => $img
        ));
        // delete_bg_image
        $fieldset->add('checkbox', array(
            'id' => 'delete_bg_image',
            'itemClass' => 'subitem',
            'label' => '{LNG_Remove} {LNG_Background image}',
            'value' => 1
        ));
        // logo_color
        $fieldset->add('color', array(
            'id' => 'logo_color',
            'labelClass' => 'g-input icon-color',
            'itemClass' => 'item',
            'label' => '{LNG_Text color} {LNG_Logo}',
            'value' => self::$cfg->logo_color
        ));
        // logo
        if (is_file(ROOT_PATH.DATA_FOLDER.'images/logo.png')) {
            $img = WEB_URL.DATA_FOLDER.'images/logo.png?'.time();
        } else {
            $img = WEB_URL.'skin/img/blank.gif';
        }
        // logo
        $fieldset->add('file', array(
            'id' => 'file_logo',
            'labelClass' => 'g-input icon-image',
            'itemClass' => 'item',
            'label' => '{LNG_Logo}',
            'comment' => '{LNG_Browse image uploaded, type :type} {LNG_size :width*:height pixel}',
            'accept' => array('jpg', 'jpeg', 'png'),
            'dataPreview' => 'logoImage',
            'previewSrc' => $img
        ));
        // delete_logo
        $fieldset->add('checkbox', array(
            'id' => 'delete_logo',
            'itemClass' => 'subitem',
            'label' => '{LNG_Remove} {LNG_Logo}',
            'value' => 1
        ));
        // show_title_logo
        $fieldset->add('checkbox', array(
            'id' => 'show_title_logo',
            'itemClass' => 'subitem',
            'label' => '{LNG_Show web title with logo}',
            'value' => 1,
            'checked' => !empty(self::$cfg->show_title_logo)
        ));
        // new_line_title
        $fieldset->add('checkbox', array(
            'id' => 'new_line_title',
            'itemClass' => 'subitem',
            'label' => '{LNG_Start a new line with the web name}',
            'value' => 1,
            'checked' => !empty(self::$cfg->new_line_title)
        ));
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        // submit
        $fieldset->add('submit', array(
            'class' => 'button save large icon-save',
            'value' => '{LNG_Save}'
        ));
        \Gcms\Controller::$view->setContentsAfter(array(
            '/:type/' => 'jpg, jpeg, png',
            '/:width/' => 144,
            '/:height/' => 51
        ));
        // คืนค่า HTML
        return $form->render();
    }
}
