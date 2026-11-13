<?php
/**
 * @filesource modules/index/controllers/dashboard.php
 *
 * api/index/dashboard — ตัวเลขสรุปของหน้าแรก
 *
 * ตัวหน้าเองไม่รู้จักโมดูลไหนเลย มันรวบรวมของสองชนิดจากทุกโมดูลที่ติดตั้งอยู่
 * ผ่าน hook แบบเดียวกับที่เมนูและสิทธิ์ทำ (ระบบเดิมของ oms ก็ใช้วิธีนี้ผ่าน
 * addCard/addMenu/addBlock) ถอดโมดูลออกแล้วของของโมดูลนั้นหายไปเอง
 * ไม่ต้องแก้หน้าแรก
 *
 *   initDashboardCards   การ์ดตัวเลข  {title, title_month, value, decimals, unit, icon, url, hint, class}
 *   initDashboardBlocks  บล็อกกราฟ    {title, type, url, size}
 *
 * การ์ดเป็นข้อมูลล้วน — หน้าเว็บ (templates/index.html) จัดรูปและแปลเอง ไม่ประกอบประโยคพร้อมตัวเลข/วันที่ที่ server
 *   title        ป้ายหรือคีย์ภาษา (ไม่ต่อตัวเลข/วันที่)
 *   title_month  'Y-m' (ไม่บังคับ) แสดงต่อท้ายชื่อเป็นเดือน ปี ตามภาษา
 *   value        ตัวเลขดิบ (ข้อความที่ไม่ใช่ตัวเลขแสดงตามเดิม) · decimals 0 (ค่าปริยาย) หรือ 2
 *   unit         ป้ายหรือคีย์ภาษา
 *   hint         บรรทัดล่าง เป็นรายการชิ้นส่วนเรียงตามลำดับ ([] = ไม่มี)
 *                ['text' => ป้าย/คีย์] · ['number' => 3] · ['decimal' => 1234.5] · ['percent' => 12.5] · ['date' => 'Y-m-d']
 *                เช่น "เลยกำหนด 3" = [['text' => 'Overdue'], ['number' => 3]]
 *
 * ปฏิทินบนหน้าแรกมีอันเดียวของแกน (api/index/calendar) อยู่บนสุดของบล็อก
 * เมื่อมีโมดูลตอบ hook initCalendarSources — โมดูลไม่ส่งบล็อกปฏิทินเอง
 *
 * `url` ของการ์ดส่งเป็น null ได้ ถ้าตัวเลขนั้นไม่มีหน้าให้เจาะดูต่อ
 * (data-attr ถอดแอตทริบิวต์ href ออกให้เมื่อค่าเป็น null) และ `class`
 * ใส่ positive/negative เพื่อให้บรรทัดล่างของการ์ดเป็นสีเขียว/แดง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Dashboard;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API หน้าแรก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/index/dashboard
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

            $cards = array_map([self::class, 'card'], array_values(\Gcms\Controller::initModule([], 'initDashboardCards', $login)));
            $blocks = array_values(\Gcms\Controller::initModule([], 'initDashboardBlocks', $login));
            if (!empty(\Index\Calendar\Controller::sources($login))) {
                // ไม่มี event_click_url — แต่ละรายการมี clickApi ของโมดูลตัวเอง
                array_unshift($blocks, [
                    'kind' => 'calendar',
                    'title' => 'Calendar',
                    'title_class' => 'icon-calendar',
                    'id' => 'dashboardCalendar',
                    'url' => 'api/index/calendar',
                    'size' => 'block12',
                    'view' => 'month',
                    'show_today' => 'true',
                    'show_switcher' => 'true',
                    'show_period_picker' => 'true'
                ]);
            }

            return $this->successResponse([
                'cards' => $cards,
                'has_cards' => !empty($cards),
                'blocks' => array_values($blocks),
                'has_blocks' => !empty($blocks)
            ], 'OK');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * การ์ดที่มีครบทุกคีย์ — แม่แบบอ้างคีย์ที่โมดูลไม่ได้ส่งไม่ได้
     * (นิพจน์ของ Now.js ที่อ้าง path ที่ไม่มีอยู่ได้ undefined ทั้งนิพจน์: `!card.decimals` เป็นเท็จเมื่อไม่มี decimals)
     *
     * @param array $card
     *
     * @return array
     */
    private static function card(array $card)
    {
        return $card + ['title' => '', 'title_month' => '', 'value' => 0, 'decimals' => 0, 'unit' => '', 'icon' => '', 'url' => null, 'hint' => [], 'class' => ''];
    }
}
