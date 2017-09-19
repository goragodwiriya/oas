<?php
/**
 * @filesource modules/index/models/memberstatus.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Memberstatus;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;
use \Gcms\Config;

/**
 * บันทึกสถานะสมาชิก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{

  /**
   * บันทึกสถานะสมาชิก (memberstatus.php)
   *
   * @param Request $request
   */
  public function action(Request $request)
  {
    $ret = array();
    // session, token, member, can_config, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
      if (Login::checkPermission($login, 'can_config') && Login::notDemoMode($login)) {
        // โหลด config
        $config = Config::load(ROOT_PATH.'settings/config.php');
        // รับค่าจากการ POST
        $action = $request->post('action')->toString();
        if (preg_match('/^list_(add|delete|color|name)_([0-9]+)$/', $action, $match)) {
          // do not saved
          $save = false;
          // default
          if (!isset($config->member_status[0])) {
            $config->member_status[0] = self::$cfg->member_status[0];
            $save = true;
          }
          if (!isset($config->member_status[1])) {
            $config->member_status[1] = self::$cfg->member_status[1];
            $save = true;
          }
          if (!isset($config->color_status[0])) {
            $config->color_status[0] = self::$cfg->color_status[0];
            $save = true;
          }
          if (!isset($config->color_status[1])) {
            $config->color_status[1] = self::$cfg->color_status[1];
            $save = true;
          }
          if ($match[1] == 'add') {
            // เพิ่มสถานะสมาชิกใหม่
            $i = sizeof($config->member_status);
            $config->member_status[$i] = Language::get('click to edit');
            $config->color_status[$i] = '#000000';
            // คืนค่าแถวใหม่
            $ret['data'] = Language::trans(\Index\Memberstatus\View::createRow($i, $config->member_status[$i], $config->color_status[$i]));
            $ret['newId'] = 'list_'.$i;
            $save = true;
          } elseif ($match[1] == 'delete') {
            // ลบ
            $save1 = array();
            $save2 = array();
            foreach ($config->member_status as $key => $value) {
              if ($key < 2 || $key != $match[2]) {
                $save1[] = $value;
                $save2[] = $config->color_status[$key];
              }
            }
            $config->member_status = $save1;
            $config->color_status = $save2;
            // รายการที่ลบ
            $ret['del'] = str_replace('delete_', '', $action);
            $save = true;
          } elseif ($match[1] == 'color' || $match[1] == 'name') {
            // แก้ไขชื่อสถานะหรือสี
            $value = $request->post('value')->text();
            $match[2] = (int)$match[2];
            if ($value == '' && $match[1] == 'name') {
              $value = $config->member_status[$match[2]];
            } elseif ($value == '' && $match[1] == 'color') {
              $value = $config->color_status[$match[2]];
            } elseif ($match[1] == 'name') {
              $config->member_status[$match[2]] = $value;
              $save = true;
            } else {
              $config->color_status[$match[2]] = $value;
              $save = true;
            }
            // ส่งข้อมูลใหม่ไปแสดงผล
            $ret['edit'] = $value;
            $ret['editId'] = $action;
          }
        }
        // save config
        if ($save && !Config::save($config, ROOT_PATH.'settings/config.php')) {
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
