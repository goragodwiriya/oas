<?php
/**
 * @filesource Gcms/Line.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 *  LINE API Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Line extends \Kotchasan\KBase
{
    /**
     * เมธอดส่งข้อความไปยัง LINE Notify
     * คืนค่าข้อความว่างถ้าสำเร็จ หรือ คืนค่าข้อความผิดพลาด
     *
     * @param string $message      ข้อความที่จะส่ง
     * @param string $line_api_key
     *
     * @return string
     */
    public static function send($message, $line_api_key = null)
    {
        if (empty($line_api_key)) {
            $line_api_key = empty(self::$cfg->line_api_key) ? '' : self::$cfg->line_api_key;
        }
        return self::notify($message, $line_api_key);
    }

    /**
     * เมธอดส่งข้อความไปยัง LINE Notify
     * คืนค่าข้อความว่างถ้าสำเร็จ หรือ คืนค่าข้อความผิดพลาด
     *
     * @param string $message      ข้อความที่จะส่ง
     * @param string $line_api_key
     *
     * @return string
     */
    public static function notify($message, $line_api_key)
    {
        if (empty($line_api_key)) {
            return 'API key can not be empty';
        } elseif ($message == '') {
            return 'message can not be blank';
        } else {
            // cUrl
            $ch = new \Kotchasan\Curl();
            $ch->setHeaders(array(
                'Authorization' => 'Bearer '.$line_api_key
            ));
            $result = $ch->post('https://notify-api.line.me/api/notify', array(
                'message' => self::toText($message)
            ));
            if ($ch->error()) {
                return $ch->errorMessage();
            } else {
                $result = json_decode($result, true);
                if ($result['status'] != 200) {
                    return $result['message'];
                }
            }
        }
        return '';
    }

    /**
     * ส่ง LINE ไปยัง $uid
     *
     * @param string|array $uid
     * @param string|array $message
     *
     * @return string
     */
    public static function sendTo($uid, $message)
    {
        if (empty(self::$cfg->line_channel_access_token)) {
            return 'Access token can not be empty';
        } elseif (empty($message)) {
            return 'message can not be blank';
        } else {
            $users = is_array($uid) ? $uid : array($uid);
            $messages = [];
            foreach (is_array($message) ? $message : array($message) as $msg) {
                $messages[] = array(
                    'type' => 'text',
                    'text' => self::toText($msg)
                );
            }
            $datas = array(
                'to' => $users,
                'messages' => $messages
            );
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.self::$cfg->line_channel_access_token
            );
            $url = 'https://api.line.me/v2/bot/message/multicast';
            $ch = new \Kotchasan\Curl();
            $ch->setHeaders($headers);
            $content = $ch->post($url, json_encode($datas));
            $result = json_decode($content, true);
            if (empty($result['message'])) {
                return '';
            } else {
                return $result['message'];
            }
        }
    }

    /**
     * ตอบกลับข้อความไปยัง replyToken (bot)
     *
     * @param string $replyToken
     * @param string|array $message
     *
     * @return string
     */
    public static function replyTo($replyToken, $message)
    {
        if (empty(self::$cfg->line_channel_access_token)) {
            return 'Access token can not be empty';
        } elseif (empty($message)) {
            return 'message can not be blank';
        } else {
            $messages = [];
            foreach (is_array($message) ? $message : array($message) as $msg) {
                $messages[] = array(
                    'type' => 'text',
                    'text' => self::toText($msg)
                );
            }
            $datas = array(
                'replyToken' => $replyToken,
                'messages' => $messages
            );
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.self::$cfg->line_channel_access_token
            );
            $url = 'https://api.line.me/v2/bot/message/reply';
            $ch = new \Kotchasan\Curl();
            $ch->setHeaders($headers);
            $content = $ch->post($url, json_encode($datas));
            $result = json_decode($content, true);
            if (empty($result['message'])) {
                return '';
            } else {
                return $result['message'];
            }
        }
    }

    /**
     * คืนค่าข้อความ ตัด tag
     *
     * @param string $message
     *
     * @return string
     */
    private static function toText($message)
    {
        // ข้อความ ตัด tag
        $msg = [];
        foreach (explode("\n", strip_tags($message)) as $row) {
            $msg[] = trim($row);
        }
        return \Kotchasan\Text::unhtmlspecialchars(implode("\n", $msg));
    }
}
