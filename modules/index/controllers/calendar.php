<?php
/**
 * @filesource modules/index/controllers/calendar.php
 *
 * api/index/calendar — ปฏิทินรวมของทุกโมดูล (จองห้อง · จองรถ · ...)
 *
 * แบบเดียวกับหน้าแรก (dashboard.php): ตัวปฏิทินไม่รู้จักโมดูลไหนเลย มันรวบรวม
 * จากทุกโมดูลที่ติดตั้งและไซต์เปิดใช้อยู่ ผ่าน hook ถอดโมดูลออกหรือปิดโมดูลของไซต์
 * (Gcms\Tenant\Modules) แล้วรายการของโมดูลนั้นหายไปเอง ไม่ต้องแก้ที่นี่
 *
 *   initCalendarSources  ชื่อโมดูลที่มีรายการบนปฏิทิน (เช่น 'booking')
 *                        ไม่มีสักแหล่ง = ไม่มีบล็อกปฏิทินบนหน้าแรก (ปฏิทินรวมอยู่ที่หน้าแรกที่เดียว)
 *   initCalendarEvents   รายการในช่วง $params['start'] ถึง $params['end'] (Y-m-d)
 *                        ตามรูปแบบ event ของ EventCalendar พร้อม
 *                        icon      คลาสไอคอนของโมดูล แสดงหน้าชื่อรายการ (บอกว่ามาจากโมดูลไหน)
 *                        clickApi  API ที่เปิดรายละเอียดเมื่อคลิก (ของโมดูลเอง)
 *                        id        ต้องไม่ซ้ำข้ามโมดูล — ใส่ชื่อโมดูลนำหน้า เช่น booking-12
 *
 * ผู้เยี่ยมชมเห็นปฏิทินเมื่อเปิด dashboard_guest (ค่าเดียวกับหน้าแรก) — hook ได้ $login
 * เป็น null แต่ละโมดูลตัดสินเองว่าจะส่งอะไรให้ผู้เยี่ยมชม
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Calendar;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API ปฏิทินรวม
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * แหล่งข้อมูลของปฏิทินจากทุกโมดูล — ว่าง = ไม่มีปฏิทิน (static — router ของ API ไม่เรียกเมธอด static)
     *
     * @param object|null $login
     *
     * @return array ชื่อโมดูล เช่น ['booking', 'car']
     */
    public static function sources($login)
    {
        return array_values(\Gcms\Controller::initModule([], 'initCalendarSources', $login));
    }

    /**
     * GET api/index/calendar?start=Y-m-d&end=Y-m-d — รายการของ EventCalendar
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login && empty(self::$cfg->dashboard_guest)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $start = (string) $request->get('start')->date();
            $end = (string) $request->get('end')->date();
            if ($start === '' || $end === '' || $start > $end) {
                return $this->errorResponse('Invalid date range', 400);
            }

            $params = ['start' => $start, 'end' => $end];
            $events = array_values(\Gcms\Controller::initModule([], 'initCalendarEvents', $login, $params));
            usort($events, function ($a, $b) {
                return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
            });

            return $this->successResponse([
                'data' => $events
            ], 'Calendar data retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
