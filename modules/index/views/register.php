<?php
/**
 * @filesource modules/index/views/register.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Register;

use \Kotchasan\Html;
use \Gcms\Login;

/**
 * ลงทะเบียนสมาชิกใหม่
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * module=register
   *
   * @return string
   */
  public function render()
  {
    // register form
    $form = Html::create('form', array(
        'id' => 'setup_frm',
        'class' => 'setup_frm',
        'autocomplete' => 'off',
        'action' => 'index.php/index/model/register/submit',
        'onsubmit' => 'doFormSubmit',
        'ajax' => true,
        'token' => true
    ));
    $fieldset = $form->add('fieldset', array(
      'title' => '{LNG_Details of} {LNG_User}'
    ));
    $groups = $fieldset->add('groups');
    // username
    $groups->add('text', array(
      'id' => 'register_username',
      'itemClass' => 'width50',
      'labelClass' => 'g-input icon-email',
      'label' => '{LNG_Email}/{LNG_Username}',
      'comment' => '{LNG_Email address used for login or request a new password}',
      'maxlength' => 50,
      'validator' => array('keyup,change', 'checkUsername', 'index.php/index/model/checker/username')
    ));
    // name
    $groups->add('text', array(
      'id' => 'register_name',
      'labelClass' => 'g-input icon-customer',
      'itemClass' => 'width50',
      'label' => '{LNG_Name} {LNG_Surname}',
      'comment' => '{LNG_Please fill in} {LNG_Name} {LNG_Surname}',
      'maxlength' => 100,
    ));
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
    // status
    $fieldset->add('select', array(
      'id' => 'register_status',
      'itemClass' => 'item',
      'label' => '{LNG_Member status}',
      'labelClass' => 'g-input icon-star0',
      'options' => self::$cfg->member_status,
      'value' => 0
    ));
    $fieldset->add('checkboxgroups', array(
      'id' => 'register_permission',
      'label' => '{LNG_Permission}',
      'labelClass' => 'g-input icon-list',
      'options' => \Gcms\Controller::getPermissions(),
      'value' => array()
    ));
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
      'value' => 0
    ));
    return $form->render();
  }
}
