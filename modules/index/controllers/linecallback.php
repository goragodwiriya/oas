<?php
/**
 * @filesource modules/index/controllers/linecallback.php
 *
 * @copyright 2024 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Linecallback;

use Gcms\Api as ApiController;
use Kotchasan\Curl;
use Kotchasan\Http\Request;

/**
 * linecallback.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * Controller รับค่าการ Login ด้วย LINE
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $code = $request->get('code', '')->toString();
        $ret_url = base64_decode($request->get('state', '')->toString());
        // state มาจาก URL ที่ใครก็สร้างได้ — กลับไปได้เฉพาะหน้าในเว็บนี้
        if (strpos($ret_url, WEB_URL) !== 0) {
            $ret_url = WEB_URL;
        }

        if ($code !== '') {
            // get refresh token
            $url = "https://api.line.me/oauth2/v2.1/token";
            $curl = new Curl();
            $content = $curl->post($url, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                // ต้องตรงกับ redirect_uri ที่ SocialLogin.js ส่งให้ LINE ทุกตัวอักษร = callback ของโฮสต์ที่ผู้ใช้อยู่
                // เดิมตัด www. ออก ผู้ที่เข้าจาก www.example.com จึงเข้าระบบไม่ได้ และต่อให้ใช้ callback ไม่มี www
                // ทั้งสองขา cookie ที่ตั้งบน example.com ก็ไม่ถูกส่งไปที่ www.example.com — ไซต์ที่เปิดได้ทั้งสองโฮสต์
                // ต้องลงทะเบียน Callback URL ทั้งสองแบบไว้ใน LINE Developers Console (ใส่ได้หลาย URL บรรทัดละหนึ่ง)
                'redirect_uri' => WEB_URL.'line/callback.php',
                'client_id' => self::$cfg->line_channel_id,
                'client_secret' => self::$cfg->line_channel_secret
            ]);
            $result = json_decode($content, true);
            // get user info
            $url = 'https://api.line.me/oauth2/v2.1/verify';
            $curl = new Curl();
            $content = $curl->post($url, [
                'id_token' => $result['id_token'] ?? '',
                'client_id' => self::$cfg->line_channel_id
            ]);
            $user = json_decode($content, true);
            if (!empty($user['sub'])) {
                $data = [
                    'username' => empty($user['email']) ? 'LINE'.$user['sub'] : $user['email'],
                    'name' => $user['name'] ?? '',
                    'picture' => $user['picture'] ?? '',
                    'line_uid' => $user['sub'],
                    'social' => 'line'
                ];

                // WEB_URL = ฐานของลิงก์ในอีเมลแจ้งแอดมิน (คำขอนี้มาจาก LINE ไม่มี Referer ของเว็บ)
                $result = \Index\Social\Model::authenticate(
                    $data,
                    WEB_URL,
                    'LINE',
                    $request->getClientIp()
                );
                if (!empty($result['success']) && !empty($result['token'])) {
                    \Index\Auth\Model::setCookie('auth_token', $result['token']);
                } else {
                    // สมัครแล้วต้องรออนุมัติ (สำเร็จแต่ไม่มี token) หรือเข้าระบบไม่ได้ด้วยเหตุผลอื่น
                    self::setResultCookie(empty($result['success']) ? ($result['reason'] ?? 'failed') : 'pending');
                }

                header('Location: '.($ret_url !== '' ? $ret_url : WEB_URL));
                exit;
            }
            // LINE ส่ง code มาแต่ยืนยันตัวตนไม่ได้ (code หมดอายุ, ตั้งค่า channel ผิด)
            self::setResultCookie('failed');
        }
        // redirect
        header('Location: '.WEB_URL);
        exit;
    }

    /**
     * ฝากผลของการเข้าระบบด้วย LINE ที่ไม่สำเร็จไว้ให้หน้าถัดไปแสดง
     * (js/components/SocialLogin.js showRedirectResult()) — ไฟล์นี้ทำได้แค่ redirect
     * เดิมผู้ใช้ถูกพากลับหน้าเว็บเฉย ๆ ไม่รู้ว่าทำไมยังไม่ได้เข้าระบบ
     *
     * ไม่ใช่ HttpOnly เพราะหน้าเว็บต้องอ่าน · เก็บแค่รหัส ข้อความอยู่ฝั่งหน้าเว็บ
     *
     * @param string $reason pending, inactive, exists, disabled หรือ failed
     */
    private static function setResultCookie($reason)
    {
        setcookie('social_login', $reason, [
            'expires' => time() + 60,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'samesite' => 'Lax'
        ]);
    }
}
