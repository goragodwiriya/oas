<?php
/**
 * @filesource modules/index/models/consent.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Consent;

use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * init PDPA Consent
     *
     * @param Request $request
     */
    public function execute(Request $request)
    {
        if ($request->initSession() && $request->isReferer()) {
            // อ่านการตั้งค่า PDPA Consent จาก cookie
            $pdpa_consent = $request->cookie('pdpa_consent')->toString();
            if (empty($pdpa_consent)) {
                $privacy = '<a href="'.WEB_URL.'index.php?module=privacy">'.Language::get('Privacy Policy').'</a>';
                echo Language::replace('This website uses cookies to provide our services. To find out more about our use of cookies, please see our :privacy.', array(':privacy' => $privacy));
            }
        }
    }

    /**
     * รับค่าจาก action (pdpa.js)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        if ($request->initSession() && $request->isReferer()) {
            // ค่าที่ส่งมา
            $action = $request->post('action')->toString();
            if ($action == 'settings') {
                // ฟอร์ม cookie settings
                $ret = array(
                    'modal' => \Index\Consent\View::create()->render($request)
                );
                // คืนค่า JSON
                echo json_encode($ret);
            } elseif ($action == 'accept') {
                $pdpa_consent = json_encode(array(
                    'accept' => 1,
                    'create_date' => date('Y-m-d H:i:s')
                ));
                setcookie('pdpa_consent', base64_encode($pdpa_consent), time() + 2592000, '/', HOST, HTTPS, true);
            }
        }
    }

    /**
     * รับค่าจากฟอร์ม (consent.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && $request->isSafe()) {
            // ค่าที่ส่งมา
            $pdpa_consent = json_encode(array(
                'accept' => 1,
                'create_date' => date('Y-m-d H:i:s')
            ));
            setcookie('pdpa_consent', base64_encode($pdpa_consent), time() + 2592000, '/', HOST, HTTPS, true);
            // clear
            $request->removeToken();
            // close Modal
            echo json_encode(array(
                'modal' => 'close'
            ));
        }
    }
}
