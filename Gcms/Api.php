<?php
/**
 * @filesource Gcms/Api.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * API class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Api extends \Kotchasan\KBase
{
    /**
     * Api สำหรับการ login
     *
     * @param string $username
     * @param string $password
     *
     * @return array
     */
    public static function login($username, $password)
    {
        $params = array(
            'username' => $username,
            'password' => $password
        );
        $result = self::post('/v1/user/login', $params);
        // คืนค่า Array
        return json_decode($result, true);
    }

    /**
     * อ่านข้อมูลคนที่ login
     *
     * @param array $login
     *
     * @return array
     */
    public static function me($login)
    {
        $params = array(
            'refreshToken' => $login['token']
        );
        $result = self::get('/v1/user/me', $params);
        // คืนค่า Array
        return json_decode($result, true);
    }

    /**
     * ส่งข้อมูล API แบบ POST
     *
     * @param string $api ชื่อ API เช่น /v1/user/login ต้อมี / ด้านหน้าเสมอ
     * @param array $params แอเรย์ของข้อมูลที่ต้องการส่ง ไม่รวม token
     *
     * @return string คืนค่า JSON string
     */
    public static function post($api, $params)
    {
        if (isset(self::$cfg->api_token) && isset(self::$cfg->api_secret)) {
            $params['token'] = self::$cfg->api_token;
            $params['sign'] = \Kotchasan\Password::generateSign($params, self::$cfg->api_secret);
            $curl = new \Kotchasan\Curl();
            return $curl->post(self::$cfg->api_url.$api, $params);
        } else {
            return json_encode(array(
                'code' => 401,
                'message' => 'Invalid token'
            ));
        }
    }
    /**
     * ส่งข้อมูล API แบบ GET
     *
     * @param string $api ชื่อ API เช่น /v1/user/login ต้อมี / ด้านหน้าเสมอ
     * @param array $params แอเรย์ของข้อมูลที่ต้องการส่ง ไม่รวม token
     *
     * @return string คืนค่า JSON string
     */
    public static function get($api, $params)
    {
        if (isset(self::$cfg->api_token) && isset(self::$cfg->api_secret)) {
            $params['token'] = self::$cfg->api_token;
            $params['sign'] = \Kotchasan\Password::generateSign($params, self::$cfg->api_secret);
            $curl = new \Kotchasan\Curl();
            return $curl->get(self::$cfg->api_url.$api, $params);
        } else {
            return json_encode(array(
                'code' => 401,
                'message' => 'Invalid token'
            ));
        }
    }
}
