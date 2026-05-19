<?php
/**
 * @filesource modules/download/models/action.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Download\Action;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * ลบไฟล์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ลบไฟล์
     *
     * @param Request $request
     */
    public function delete(Request $request)
    {
        $ret = [];
        // session, referer, member, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && preg_match('/delete_(.*)$/', $request->post('id', '')->toString(), $match)) {
                try {
                    // ถอดรหัส
                    $paylaod = \Kotchasan\Password::decode($match[1], self::$cfg->password_key);
                    // แปลงเป็น Array
                    $json = json_decode($paylaod, true);
                    if ($json['owner_id'] > 0 && $json['owner_id'] == $login['id'] && is_file($json['file'])) {
                        @unlink($json['file']);
                        // คืนค่ารายการที่ลบ
                        $ret['remove'] = 'item_'.$match[1];
                    }
                } catch (\Exception $e) {
                    header('HTTP/1.0 404 Not Found');
                }
            }
        }
        // คืนค่า JSON
        if (!empty($ret)) {
            echo json_encode($ret);
        }
    }
}
