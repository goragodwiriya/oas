<?php
/**
 * @filesource modules/index/models/accsettings.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Accsettings;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;
use \Gcms\Config;

/**
 * ตั้งค่าระบบบัญชี
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{

  /**
   * บันทึกการตั้งค่าระบบบัญชี (accsettings.php)
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, can_config
    if ($request->initSession() && $request->isSafe() && $login = Login::checkPermission(Login::isMember(), 'can_config')) {
      if (empty($login['fb'])) {
        // โหลด config
        $config = Config::load(ROOT_PATH.'settings/config.php');
        // รับค่าจากการ POST
        $config->authorized = $request->post('company_authorized')->topic();
        $config->email = $request->post('company_email')->url();
        $config->product_no = $request->post('product_no')->topic();
        $config->order_no = $request->post('order_no')->topic();
        $config->billing_no = $request->post('billing_no')->topic();
        $config->currency_unit = $request->post('currency_unit')->filter('A-Z');
        $config->bank = $request->post('bank')->topic();
        $config->bank_name = $request->post('bank_name')->topic();
        $config->bank_no = $request->post('bank_no')->topic();
        // save config
        if (Config::save($config, ROOT_PATH.'settings/config.php')) {
          $ret['alert'] = Language::get('Saved successfully');
          $ret['location'] = 'reload';
          // เคลียร์
          $request->removeToken();
        } else {
          // ไม่สามารถบันทึก config ได้
          $ret['alert'] = sprintf(Language::get('File %s cannot be created or is read-only.'), 'settings/config.php');
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }
}