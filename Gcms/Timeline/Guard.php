<?php
/**
 * @filesource Gcms/Timeline/Guard.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * ด่านตรวจของทุก endpoint ใน modules/timeline
 *
 * ไม่มีระบบยืนยันตัวตนของตัวเอง — ใช้ของที่ Kotchasan\ApiController มีอยู่แล้ว
 * ทั้งหมด ($cfg->api_tokens, $cfg->api_ips) มีอยู่เพื่อไม่ให้ controller ทั้งสาม
 * ตัวเขียนลำดับการตรวจซ้ำกันคนละแบบ ซึ่งเป็นทางที่ด่านหนึ่งจะหล่นหายไปเงียบ ๆ
 * ตอนแก้โค้ดครั้งหน้า
 *
 * @see TIMELINE-PROTOCOL.md §3.2
 *
 * @since 1.0
 */
class Guard extends \Kotchasan\KBase
{
    /**
     * ตรวจให้ครบทุกด่านก่อนเข้าถึงข้อมูล
     *
     * @param Request $request
     * @param string  $method HTTP method ที่ endpoint นี้รับ
     *
     * @throws ApiException 401 token ผิด · 403 IP ไม่ได้รับอนุญาต · 405 method ผิด
     *                      503 ยังไม่ได้ตั้งค่า
     */
    public static function check(Request $request, $method)
    {
        \Gcms\Api::validateMethod($request, $method);
        \Gcms\Api::validateIpAddress($request);
        \Gcms\Api::validateTokenBearer($request);

        // slug คือสิ่งที่ Hub ใช้ยืนยันว่าต่อถูกระบบ ไม่ตั้งไว้เท่ากับยังไม่ได้ติดตั้ง
        if (empty(self::$cfg->timeline_slug)) {
            throw new ApiException(Language::get('timeline_slug is not configured on this system'), 503);
        }
        if (empty(self::$cfg->timeline_mappers)) {
            throw new ApiException(Language::get('timeline_mappers are not declared on this system'), 503);
        }
    }
}
