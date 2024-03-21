<?php
/**
 * @filesource modules/index/controllers/linewebhook.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Linewebhook;

use Kotchasan\Curl;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * linewebhook.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * Controller สำหรับรับ callback จาก LINE
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        // รับค่าที่ส่งมา
        $content = file_get_contents('php://input');
        if ($content != '') {
            $contents = json_decode($content, true);
        } else {
            $contents = [];
        }
        // userId, type
        $userId = isset($contents['events'][0]['source']['userId']) ? $contents['events'][0]['source']['userId'] : '';
        $type = isset($contents['events'][0]['type']) ? $contents['events'][0]['type'] : '';
        if (preg_match('/^U[a-z0-9]{32,32}$/', $userId)) {
            $messages = [];
            if ($type === 'follow') {
                // มาจากการติดตาม ตรวจสอบสมาชิกกับ db
                $search = \Kotchasan\Model::createQuery()
                    ->from('user')
                    ->where(array(
                        array('username', $userId),
                        array('line_uid', $userId)
                    ), 'OR')
                    ->toArray()
                    ->first();
                if ($search) {
                    // มี userId อยู่แล้ว
                    $messages[] = Language::replace('LINE_FOLLOW_MESSAGE', array(':name' => $search['name'], ':title' => strip_tags(self::$cfg->web_title)));
                    if ($search['social'] == 3) {
                        // บัญชีไลน์
                        $messages[] = Language::get('Please login').' '.\Index\Linelogin\Model::url(WEB_URL);
                    } else {
                        // เข้าระบบอื่นๆ
                        $messages[] = Language::get('Please login').' '.WEB_URL.'index.php?module=welcome';
                    }
                } else {
                    // อ่านข้อมูล Profile และส่งลิงค์เข้าระบบ
                    $url = 'https://api.line.me/v2/bot/profile/'.$userId;
                    $headers = array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.self::$cfg->line_channel_access_token
                    );
                    $curl = new Curl();
                    $curl->setHeaders($headers);
                    $content = $curl->get($url);
                    $user = json_decode($content, true);
                    // ส่งลิงค์สำหรับเข้าระบบ
                    $messages[] = Language::replace('LINE_FOLLOW_MESSAGE', array(':name' => $user['displayName'], ':title' => strip_tags(self::$cfg->web_title)));
                    $messages[] = Language::get('You can enter your LINE user ID below on your personal information page. to link your account to this official account');
                    $messages[] = $user['userId'];
                    $messages[] = Language::get('Please login').' '.\Index\Linelogin\Model::url(WEB_URL);
                }
            } elseif ($type === 'message') {
                if (isset($contents['events'][0]['message']['text']) && $contents['events'][0]['message']['text'] === 'userId') {
                    // คืนค่า userId เมื่อพิมพ์ userId ส่งมาในไลน์
                    $messages[] = Language::get('You can enter your LINE user ID below on your personal information page. to link your account to this official account');
                    $messages[] = $userId;
                } else {
                    // ขออภัยไม่สามารถตอบกลับข้อความนี้ได้
                    $messages[] = Language::get('LINE_REPLY_MESSAGE');
                }
            }
            // ส่งข้อความ
            if (!empty($messages)) {
                \Gcms\Line::sendTo($userId, $messages);
            }
        }
    }
}
