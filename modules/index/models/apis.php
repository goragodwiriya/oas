<?php
/**
 * @filesource modules/index/models/apis.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Apis;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=apis
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกการตั้งค่าเว็บไซต์ (apis.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                // โหลด config
                $config = Config::load(ROOT_PATH.'settings/config.php');
                $config->api_url = $request->post('api_url')->url();
                $config->api_token = $request->post('api_token')->password();
                $config->api_secret = $request->post('api_secret')->password();
                $config->api_cors = $request->post('api_cors')->url();
                $config->api_ips = [];
                foreach (explode("\n", $request->post('api_ips')->textarea()) as $ip) {
                    if (preg_match('/([0-9\.]+)/', $ip, $match)) {
                        $config->api_ips[$match[1]] = $match[1];
                    }
                }
                $config->api_ips = array_keys($config->api_ips);
                // save config
                if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                    // log
                    \Index\Log\Model::add(0, 'index', 'Save', '{LNG_API settings}', $login['id']);
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
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
