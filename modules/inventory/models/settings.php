<?php
/**
 * @filesource modules/inventory/models/settings.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Settings;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-settings
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกข้อมูล (settings.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, can_config, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && Login::checkPermission($login, 'can_config')) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    // รับค่าจากการ POST
                    $config->authorized = $request->post('company_authorized')->topic();
                    $config->email = $request->post('company_email')->url();
                    $config->product_no = $request->post('product_no')->topic();
                    $config->customer_no = $request->post('customer_no')->topic();
                    $config->currency_unit = $request->post('currency_unit')->filter('A-Z');
                    $config->bank = $request->post('bank')->topic();
                    $config->bank_name = $request->post('bank_name')->filter('a-z');
                    $config->bank_no = $request->post('bank_no')->topic();
                    $config->inventory_w = $request->post('inventory_w')->toInt();
                    foreach (Language::get('ORDER_STATUS') as $s => $label) {
                        $config->{$s.'_NO'} = $request->post($s.'_NO')->topic();
                    }
                    // save config
                    if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                        // log
                        \Index\Log\Model::add(0, 'inventory', 'Save', '{LNG_Module settings} {LNG_Inventory}', $login['id']);
                        // คืนค่า
                        $ret['alert'] = Language::get('Saved successfully');
                        $ret['location'] = 'reload';
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
            if (empty($ret)) {
                $ret['alert'] = Language::get('Unable to complete the transaction');
            }
            // คืนค่าเป็น JSON
            echo json_encode($ret);
        }
    }
}
