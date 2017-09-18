<?php
/**
 * @filesource modules/index/models/system.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\System;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;
use \Gcms\Config;

/**
 * ตั้งค่าเว็บไซต์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{

  /**
   * บันทึกการตั้งค่าเว็บไซต์ (system.php)
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, admin
    if ($request->initSession() && $request->isSafe() && $login = Login::isAdmin()) {
      if (empty($login['fb'])) {
        // โหลด config
        $config = Config::load(ROOT_PATH.'settings/config.php');
        foreach (array('web_title', 'web_description') as $key) {
          $value = $request->post($key)->quote();
          if (empty($value)) {
            $ret['ret_'.$key] = 'Please fill in';
          } else {
            $config->$key = $value;
          }
        }
        $config->timezone = $request->post('timezone')->text();
        if (empty($ret)) {
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
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }
}
