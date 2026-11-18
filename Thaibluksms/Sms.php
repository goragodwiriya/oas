<?php
/**
 * @filesource ThaiBlukSMS/SMS.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Thaibluksms;

/**
 * ThaiBlukSMS.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Sms extends \Kotchasan\KBase
{
    /**
     * ส่ง SMS.
     *
     * @param  $msisdn
     * @param  $message
     *
     * @return string
     */
    public static function send($msisdn, $message)
    {
        if (!empty(self::$cfg->sms_username) && !empty(self::$cfg->sms_password)) {
            include_once 'sms.class.php';

            return \sms::send_sms(self::$cfg->sms_username, self::$cfg->sms_password, $msisdn, $message, self::$cfg->sms_sender, '', self::$cfg->sms_type);
        }

        return '';
    }

    /**
     * ตรวจสอบเครดิต
     *
     * @param  $premium
     *
     * @return string
     */
    public static function check_credit($premium)
    {
        if (!empty(self::$cfg->sms_username) && !empty(self::$cfg->sms_password)) {
            include_once 'sms.class.php';
            $type = $premium ? 'credit_remain_premium' : 'credit_remain';

            $result = \sms::check_credit(self::$cfg->sms_username, self::$cfg->sms_password, $type);

            return strip_tags($result);
        }

        return '';
    }
}
