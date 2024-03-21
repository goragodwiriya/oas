<?php
/**
 * @filesource modules/index/views/editprofile.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Editprofile;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=editprofile
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * ฟอร์มแก้ไขสมาชิก
     *
     * @param Request $request
     * @param array   $user
     * @param array   $login
     *
     * @return string
     */
    public function render(Request $request, $user, $login)
    {
        // แอดมิน
        $isAdmin = Login::isAdmin();
        // หมวดหมู่
        $category = \Index\Category\Model::init(false);
        // form
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm',
            'autocomplete' => 'off',
            'action' => 'index.php/index/model/editprofile/submit',
            'onsubmit' => 'doFormSubmit',
            'ajax' => true,
            'token' => true
        ));
        if ($user['active'] == 1) {
            $fieldset = $form->add('fieldset', array(
                'title' => '{LNG_Login information}'
            ));
            $groups = $fieldset->add('groups');
            // username
            $groups->add('text', array(
                'id' => 'register_username',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-email',
                'label' => '{LNG_Email}',
                'comment' => '{LNG_Email address used for login or request a new password}',
                'disabled' => $isAdmin ? false : true,
                'maxlength' => 255,
                'value' => $user['username'],
                'validator' => array('keyup,change', 'checkUsername', 'index.php/index/model/checker/username')
            ));
            // password, repassword
            $groups = $fieldset->add('groups', array(
                'comment' => '{LNG_To change your password, enter your password to match the two inputs}'
            ));
            // password
            $groups->add('password', array(
                'id' => 'register_password',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-password',
                'label' => '{LNG_Password}',
                'placeholder' => '{LNG_Passwords must be at least four characters}',
                'maxlength' => 50,
                'showpassword' => true,
                'validator' => array('keyup,change', 'checkPassword')
            ));
            // repassword
            $groups->add('password', array(
                'id' => 'register_repassword',
                'itemClass' => 'width50',
                'labelClass' => 'g-input icon-password',
                'label' => '{LNG_Confirm password}',
                'placeholder' => '{LNG_Enter your password again}',
                'maxlength' => 50,
                'showpassword' => true,
                'validator' => array('keyup,change', 'checkPassword')
            ));
        }
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Details of} {LNG_User}'
        ));
        $groups = $fieldset->add('groups');
        // name
        $groups->add('text', array(
            'id' => 'register_name',
            'labelClass' => 'g-input icon-customer',
            'itemClass' => 'width50',
            'label' => '{LNG_Name}',
            'value' => $user['name']
        ));
        // sex
        $groups->add('select', array(
            'id' => 'register_sex',
            'labelClass' => 'g-input icon-sex',
            'itemClass' => 'width50',
            'label' => '{LNG_Sex}',
            'options' => Language::get('SEXES'),
            'value' => $user['sex']
        ));
        // หมวดหมู่
        $a = 0;
        foreach ($category->items() as $k => $label) {
            if ($isAdmin || !$category->isEmpty($k)) {
                if (in_array($k, self::$cfg->categories_multiple)) {
                    if (!$category->isEmpty($k)) {
                        $fieldset->add('checkboxgroups', array(
                            'id' => 'register_'.$k,
                            'itemClass' => 'item',
                            'label' => $category->name($k),
                            'labelClass' => 'g-input icon-group',
                            'options' => $category->toSelect($k),
                            'value' => empty($user[$k]) ? [] : $user[$k],
                            'disabled' => !$isAdmin && in_array($k, self::$cfg->categories_disabled)
                        ));
                    }
                } else {
                    if ($a % 2 == 0) {
                        $groups = $fieldset->add('groups');
                    }
                    $a++;
                    if ($isAdmin) {
                        $groups->add('text', array(
                            'id' => 'register_'.$k,
                            'labelClass' => 'g-input icon-menus',
                            'itemClass' => 'width50',
                            'label' => $label,
                            'datalist' => $category->toSelect($k),
                            'value' => empty($user[$k]) ? '' : $user[$k][0],
                            'text' => true
                        ));
                    } else {
                        $groups->add('select', array(
                            'id' => 'register_'.$k,
                            'labelClass' => 'g-input icon-menus',
                            'itemClass' => 'width50',
                            'label' => $label,
                            'options' => $category->toSelect($k),
                            'value' => empty($user[$k]) ? '' : $user[$k][0],
                            'disabled' => !$isAdmin && in_array($k, self::$cfg->categories_disabled)
                        ));
                    }
                }
            }
        }
        $groups = $fieldset->add('groups');
        // id_card
        $groups->add('number', array(
            'id' => 'register_id_card',
            'labelClass' => 'g-input icon-profile',
            'itemClass' => 'width50',
            'label' => '{LNG_Identification No.}',
            'maxlength' => 13,
            'value' => $user['id_card'],
            'validator' => array('keyup,change', 'checkIdcard')
        ));
        // phone
        $groups->add('text', array(
            'id' => 'register_phone',
            'labelClass' => 'g-input icon-phone',
            'itemClass' => 'width50',
            'label' => '{LNG_Phone}',
            'maxlength' => 32,
            'value' => $user['phone']
        ));
        // address
        $fieldset->add('text', array(
            'id' => 'register_address',
            'labelClass' => 'g-input icon-address',
            'itemClass' => 'item',
            'label' => '{LNG_Address}',
            'maxlength' => 150,
            'value' => $user['address']
        ));
        $groups = $fieldset->add('groups');
        // country
        $groups->add('text', array(
            'id' => 'register_country',
            'labelClass' => 'g-input icon-world',
            'itemClass' => 'width33',
            'label' => '{LNG_Country}',
            'datalist' => \Kotchasan\Country::all(),
            'value' => $user['country']
        ));
        // provinceID
        $groups->add('text', array(
            'id' => 'register_province',
            'name' => 'register_provinceID',
            'labelClass' => 'g-input icon-location',
            'itemClass' => 'width33',
            'label' => '{LNG_Province}',
            'datalist' => [],
            'text' => $user['province'],
            'value' => $user['provinceID']
        ));
        // zipcode
        $groups->add('number', array(
            'id' => 'register_zipcode',
            'labelClass' => 'g-input icon-number',
            'itemClass' => 'width33',
            'label' => '{LNG_Zipcode}',
            'maxlength' => 10,
            'value' => $user['zipcode']
        ));
        if (!empty(self::$cfg->line_official_account) && !empty(self::$cfg->line_channel_access_token) && $user['social'] != 3) {
            // line_uid
            $fieldset->add('text', array(
                'id' => 'register_line_uid',
                'itemClass' => 'item',
                'labelClass' => 'g-input icon-line',
                'label' => '{LNG_LINE user ID}',
                'placeholder' => 'U1234abc...',
                'comment' => '{LNG_Enter the LINE user ID you received when adding friends. Or type userId sent to the official account to request a new user ID. This information is used for receiving private messages from the system via LINE.}',
                'maxlength' => 33,
                'value' => $user['line_uid']
            ));
        }
        // avatar
        if (is_file(ROOT_PATH.DATA_FOLDER.'avatar/'.$user['id'].'.jpg')) {
            $img = WEB_URL.DATA_FOLDER.'avatar/'.$user['id'].'.jpg?'.time();
        } else {
            $img = WEB_URL.'skin/img/noicon.png';
        }
        $fieldset->add('file', array(
            'id' => 'avatar',
            'labelClass' => 'g-input icon-image',
            'itemClass' => 'item',
            'label' => '{LNG_Avatar}',
            'comment' => '{LNG_Browse image uploaded, type :type} ({LNG_resized automatically})',
            'dataPreview' => 'avatarImage',
            'previewSrc' => $img,
            'accept' => self::$cfg->member_img_typies
        ));
        // delete_avatar
        $fieldset->add('checkbox', array(
            'id' => 'delete_avatar',
            'itemClass' => 'subitem',
            'label' => '{LNG_Remove} {LNG_Avatar}',
            'value' => 1
        ));
        $fieldset = $form->add('fieldset', array(
            'title' => '{LNG_Other}'
        ));
        // status
        $fieldset->add('select', array(
            'id' => 'register_status',
            'itemClass' => 'item',
            'label' => '{LNG_Member status}',
            'labelClass' => 'g-input icon-star0',
            'disabled' => $isAdmin && $user['id'] != $login['id'] && $user['id'] != 1 ? false : true,
            'options' => self::$cfg->member_status,
            'value' => $user['status']
        ));
        if ($isAdmin) {
            // permission
            $fieldset->add('checkboxgroups', array(
                'id' => 'register_permission',
                'itemClass' => 'item',
                'label' => '{LNG_Permission}',
                'labelClass' => 'g-input icon-list',
                'options' => \Gcms\Controller::getPermissions(),
                'value' => $user['permission']
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
        $fieldset->add('hidden', array(
            'id' => 'register_id',
            'value' => $user['id']
        ));
        \Gcms\Controller::$view->setContentsAfter(array(
            '/:type/' => implode(', ', self::$cfg->member_img_typies)
        ));
        // Javascript
        $form->script('initEditProfile("register");');
        // คืนค่า HTML
        return $form->render();
    }
}
