<?php
/**
 * @filesource modules/index/models/company.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Company;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=company
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกการตั้งค่าข้อมูลบริษัท (company.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, can_config
        if ($request->initSession() && $request->isSafe() && $login = Login::checkPermission(Login::isMember(), 'can_config')) {
            if (empty($login['social'])) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    // รับค่าจากการ POST
                    $config->type = $request->post('company_type')->toInt();
                    $config->tax_id = $request->post('company_tax_id')->number();
                    $config->company_name = $request->post('company_name')->topic();
                    $config->branch = $request->post('company_branch')->topic();
                    $config->address = $request->post('company_address')->topic();
                    $config->provinceID = $request->post('company_provinceID')->number();
                    $config->province = $request->post('company_province')->topic();
                    $config->zipcode = $request->post('company_zipcode')->number();
                    $config->country = $request->post('company_country')->filter('A-Z');
                    $config->phone = $request->post('company_phone')->topic();
                    $config->fax = $request->post('company_fax')->topic();
                    // company_address
                    if (empty($config->address)) {
                        $ret['ret_company_address'] = 'Please fill in';
                    }
                    // ใช้จังหวัดจาก provinceID ถ้าเป็นประเทศไทย
                    if ($config->country == 'TH') {
                        $config->province = \Kotchasan\Province::get($config->provinceID);
                    }
                    // company_province
                    if (empty($config->province)) {
                        $ret['ret_company_province'] = 'Please fill in';
                    }
                    // company_zipcode
                    if (empty($config->zipcode)) {
                        $ret['ret_company_zipcode'] = 'Please fill in';
                    }
                    if (empty($ret)) {
                        // save config
                        if (Config::save($config, ROOT_PATH.'settings/config.php')) {
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
