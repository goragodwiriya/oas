<?php
/**
 * @filesource modules/index/models/loginpage.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Loginpage;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=index-loginpage
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * รับค่าจากฟอร์ม (loginpage.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, can_config, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && Login::checkPermission($login, 'can_config')) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    $config->login_message = $request->post('login_message')->textarea();
                    $config->login_message_style = $request->post('login_message_style')->filter('a-z');
                    foreach (array('login_header_color', 'login_footer_color', 'login_color', 'login_bg_color') as $key) {
                        $config->$key = $request->post($key)->filter('#ABCDEF0-9');
                    }
                    // save config
                    if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                        // log
                        \Index\Log\Model::add(0, 'index', 'Save', '{LNG_Settings} {LNG_Login page}', $login['id']);
                        // คืนค่า
                        $ret['alert'] = Language::get('Saved successfully');
                        $ret['url'] = 'index.php?module=loginpage&'.time();
                        // เคลียร์
                        $request->removeToken();
                    } else {
                        // ไม่สามารถบันทึก config ได้
                        $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
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
