<?php
/**
 * @filesource modules/download/controllers/download.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Download\Download;

use Kotchasan\Http\Request;

/**
 * แสดงผลไฟล์ดาวน์โหลด
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\KBase
{
    /**
     * Controller สำหรับการดาวน์โหลดไฟล์
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $id = $request->get('id')->toString();
        if ($id) {
            try {
                // ถอดรหัส
                $paylaod = \Kotchasan\Password::decode($id, self::$cfg->password_key);
                // แปลงเป็น Array
                $json = json_decode($paylaod, true);
                // ตรวจสอบไฟล์
                if (is_file($json['file'])) {
                    // ดาวน์โหลดไฟล์
                    header('Content-Description: File Transfer');
                    header('Content-Type: '.$json['mime']);
                    // ชื่อไฟล์เดิมมีเฉพาะ payload ที่ส่งมาด้วย name ผู้เรียกเดิมไม่ส่งจึงข้ามไป
                    if (!empty($json['name'])) {
                        $name = str_replace(['"', "\r", "\n"], '', $json['name']);
                        header('Content-Disposition: attachment; filename="'.$name.'";'
                            ." filename*=UTF-8''".rawurlencode($json['name']));
                    }
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: '.filesize($json['file']));
                    readfile($json['file']);
                    exit;
                }
            } catch (\Exception $e) {
            }
        }
        // ไม่พบไฟล์
        header('HTTP/1.0 404 Not Found');
    }
}
