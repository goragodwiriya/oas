<?php
/**
 * @filesource Gcms/Line.php
 *
 * @copyright 2026 Goragod.com
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
     * Send a raw LINE Messaging API request.
     *
     * @param string $url
     * @param array  $datas
     *
     * @return array
     */
    private static function apiRequest($url, array $datas)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.self::$cfg->line_channel_access_token
        ];
        $ch = new \Kotchasan\Curl();
        $ch->setHeaders($headers);
        $content = $ch->post($url, json_encode($datas));

        return json_decode($content, true) ?: [];
    }

    /**
     * Normalize text messages to LINE text payload items.
     *
     * @param string|array $message
     *
     * @return array
     */
    private static function normalizeTextMessages($message)
    {
        $messages = [];
        foreach (is_array($message) ? $message : [$message] as $msg) {
            if ($msg === null || $msg === '') {
                continue;
            }
            $messages[] = [
                'type' => 'text',
                'text' => self::toText($msg)
            ];
        }

        return $messages;
    }

    /**
     * Send structured payload to one or many LINE users.
     *
     * @param string|array $uid
     * @param array        $messages
     *
     * @return string
     */
    public static function sendPayload($uid, array $messages)
    {
        if (empty(self::$cfg->line_channel_access_token)) {
            return 'Access token can not be empty';
        }
        if (empty($uid) || empty($messages)) {
            return 'message can not be blank';
        }

        $users = is_array($uid) ? $uid : [$uid];
        $result = self::apiRequest('https://api.line.me/v2/bot/message/multicast', [
            'to' => $users,
            'messages' => array_values($messages)
        ]);

        return !empty($result['message']) ? $result['message'] : '';
    }

    /**
     * Reply with a structured LINE payload.
     *
     * @param string $replyToken
     * @param array  $messages
     *
     * @return string
     */
    public static function replyPayload($replyToken, array $messages)
    {
        if (empty(self::$cfg->line_channel_access_token)) {
            return 'Access token can not be empty';
        }
        if ($replyToken === '' || empty($messages)) {
            return 'message can not be blank';
        }

        $result = self::apiRequest('https://api.line.me/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages' => array_values($messages)
        ]);

        return !empty($result['message']) ? $result['message'] : '';
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
        return self::sendPayload($uid, self::normalizeTextMessages($message));
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
        return self::replyPayload($replyToken, self::normalizeTextMessages($message));
    }

    /**
     * คืนค่าข้อความ ตัด tag
     * ลบข้อความนอก td, th เพื่อรักษาแถวของตารางไว้
     * แปลง <br> เป็น \n
     *
     * @param string $message
     *
     * @return string
     */
    private static function toText($message)
    {
        // ใช้ preg_replace_callback เพื่อจับคู่เฉพาะ <tr> แล้วลบช่องว่างที่ไม่อยู่ใน <td> และ <th>
        $message = preg_replace_callback(
            '/<tr\b[^>]*>(.*?)<\/tr>/s',
            function ($matches) {
                // ดึงเนื้อหาภายใน <tr>
                $trContent = $matches[1];

                // ใช้ preg_replace_callback เพื่อจับคู่ <td> และ <th>
                $cleanedTrContent = preg_replace_callback(
                    '/<\/?(td|th)\b[^>]*>(.*?)<\/\2>/s',
                    function ($cellMatches) {
                        // เก็บเนื้อหาของ <td> และ <th> ไว้
                        return '<td>'.$cellMatches[1].'</td>';
                    },
                    $trContent
                );

                // ลบช่องว่างนอก <td> และ <th>
                $cleanedTrContent = preg_replace('/\n+/', '', $cleanedTrContent);

                // คืนค่า <tr> ที่ถูกแก้ไขแล้ว
                return '<tr>'.$cleanedTrContent.'</tr>';
            },
            str_replace(["\r", "\t"], '', $message)
        );
        // แปลง <br> เป็น \n สำหรับขึ้นบรรทัดใหม่
        $message = str_replace(['<br>', '<br />'], "\n", $message);
        // ข้อความ ตัด tag
        $msg = [];
        foreach (explode("\n", strip_tags($message)) as $row) {
            $row = trim($row);
            if ($row != '') {
                $msg[] = $row;
            }
        }
        return \Kotchasan\Text::unhtmlspecialchars(implode("\n", $msg));
    }
}
