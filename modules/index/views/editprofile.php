<?php
/**
 * @filesource modules/index/views/editprofile.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Editprofile;

use \Kotchasan\Html;
use \Gcms\Login;

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
   * @param array $user
   * @param array $login
   * @return string
   */
  public function render($user, $login)
  {
    $login_admin = Login::isAdmin();
    // register form
    $form = Html::create('form', array(
        'id' => 'setup_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/index/model/editprofile/submit',
        'onsubmit' => 'doFormSubmit',
        'ajax' => true,
        'token' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Login information}'
    ));
    if (empty($user['fb'])) {
      // username
      $fieldset->add('text', array(
        'id' => 'register_username',
        'itemClass' => 'item',
        'labelClass' => 'g-input icon-email',
        'label' => '{LNG_Email}',
        'comment' => '{LNG_Email address used for login or request a new password}',
        'maxlength' => 50,
        'value' => $user['username'],
        'readonly' => $login_admin || empty($user['id']) ? false : true,
        'validator' => array('keyup,change', 'checkEmail', 'index.php/index/model/checker/username')
      ));
    } else {
      // username Facebook
      $fieldset->add('text', array(
        'id' => 'register_username',
        'itemClass' => 'item',
        'labelClass' => 'g-input icon-facebook',
        'label' => '{LNG_Facebook ID}',
        'readonly' => true,
        'value' => $user['username'],
      ));
    }
    if (empty($user['id'])) {
      // ใหม่
      $groups = $fieldset->add('groups');
      // password
      $groups->add('password', array(
        'id' => 'register_password',
        'itemClass' => 'width50',
        'labelClass' => 'g-input icon-password',
        'label' => '{LNG_Password}',
        'comment' => '{LNG_Passwords must be at least four characters}',
        'maxlength' => 20,
        'validator' => array('keyup,change', 'checkPassword')
      ));
      // repassword
      $groups->add('password', array(
        'id' => 'register_repassword',
        'itemClass' => 'width50',
        'labelClass' => 'g-input icon-password',
        'label' => '{LNG_Repassword}',
        'comment' => '{LNG_Enter your password again}',
        'maxlength' => 20,
        'validator' => array('keyup,change', 'checkPassword')
      ));
    } elseif (empty($user['fb'])) {
      // แก้ไข และ ไม่ใช่ fb
      $groups = $fieldset->add('groups', array(
        'comment' => '{LNG_To change your password, enter your password to match the two inputs}',
      ));
      // password
      $groups->add('password', array(
        'id' => 'register_password',
        'itemClass' => 'width50',
        'labelClass' => 'g-input icon-password',
        'label' => '{LNG_Password}',
        'placeholder' => '{LNG_Passwords must be at least four characters}',
        'maxlength' => 20,
        'validator' => array('keyup,change', 'checkPassword')
      ));
      // repassword
      $groups->add('password', array(
        'id' => 'register_repassword',
        'itemClass' => 'width50',
        'labelClass' => 'g-input icon-password',
        'label' => '{LNG_Repassword}',
        'placeholder' => '{LNG_Enter your password again}',
        'maxlength' => 20,
        'validator' => array('keyup,change', 'checkPassword')
      ));
    }
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Details of} {LNG_User}'
    ));
    // name
    $fieldset->add('text', array(
      'id' => 'register_name',
      'itemClass' => 'item',
      'labelClass' => 'g-input icon-customer',
      'label' => '{LNG_Name}',
      'maxlength' => 150,
      'value' => $user['name']
    ));
    // phone
    $fieldset->add('text', array(
      'id' => 'register_phone',
      'itemClass' => 'item',
      'labelClass' => 'g-input icon-phone',
      'label' => '{LNG_Phone}',
      'maxlength' => 20,
      'value' => $user['phone']
    ));
    if ($login_admin) {
      $fieldset = $form->add('fieldset', array(
        'title' => '{LNG_Other}'
      ));
      // status
      $fieldset->add('select', array(
        'id' => 'register_status',
        'itemClass' => 'item',
        'label' => '{LNG_Member status}',
        'labelClass' => 'g-input icon-star0',
        'disabled' => $login_admin['id'] == $user['id'] ? true : false,
        'options' => self::$cfg->member_status,
        'value' => $user['status']
      ));
      // permission
      $fieldset->add('checkboxgroups', array(
        'id' => 'register_permission',
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
      'class' => 'button save large',
      'value' => '{LNG_Save}'
    ));
    $fieldset->add('hidden', array(
      'id' => 'register_id',
      'value' => $user['id']
    ));
    return $form->render();
  }
}