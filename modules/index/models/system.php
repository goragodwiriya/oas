<?php
/**
 * @filesource modules/index/models/system.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\System;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=system
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
        $ret = [];
        // session, token, member, can_config, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_config') && Login::notDemoMode($login)) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    // ค่าที่ส่งมา
                    foreach (array('web_title', 'web_description') as $key) {
                        $value = $request->post($key)->quote();
                        if (empty($value)) {
                            $ret['ret_'.$key] = 'Please fill in';
                        } else {
                            $config->$key = $value;
                        }
                    }
                    foreach (array('user_forgot', 'user_register', 'welcome_email', 'demo_mode', 'activate_user', 'new_members_active') as $key) {
                        $config->$key = $request->post($key)->toBoolean();
                    }
                    $config->login_fields = $request->post('login_fields', [])->filter('a-z0-9_');
                    if (!in_array('username', $config->login_fields) && !in_array('email', $config->login_fields)) {
                        $config->login_fields[] = 'username';
                    }
                    $config->default_department = $request->post('default_department')->topic();
                    $config->timezone = $request->post('timezone')->text();
                    $config->facebook_appId = $request->post('facebook_appId')->text();
                    $config->google_client_id = explode('.', $request->post('google_client_id')->text())[0];
                    if (empty($ret)) {
                        // save config
                        if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                            // log
                            \Index\Log\Model::add(0, 'index', 'Save', '{LNG_General site settings}', $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            $ret['location'] = 'reload';
                            // เคลียร์
                            $request->removeToken();
                        } else {
                            // ไม่สามารถบันทึก config ได้
                            $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
                        }
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
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
