<?php
/**
 * @filesource Gcms/Sms.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 *  Send SMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Sms
{
    /**
     * เมธอดส่งข้อความไปยัง SMS
     * คืนค่าข้อความว่างถ้าสำเร็จ หรือ คืนค่าข้อความผิดพลาด
     *
     * @param string $msisdn หมายเลขโทรศัพท์
     * @param string $message ข้อความที่จะส่ง
     *
     * @return string
     */
    public static function send($msisdn, $message)
    {
        // เขียนคำสั่งเพื่อส่ง SMS ที่นี่
        return \Thaibluksms\Sms::send($msisdn, strip_tags($message));
    }

    /**
     * ตรวจสอบเครดิต
     *
     * @param  $premium
     *
     * @return string
     */
    public static function check_credit()
    {
        return [
            'standard' => 'Standard ('.\Thaibluksms\Sms::check_credit(false).')',
            'premium' => 'Premium ('.\Thaibluksms\Sms::check_credit(true).')'
        ];
    }
}
