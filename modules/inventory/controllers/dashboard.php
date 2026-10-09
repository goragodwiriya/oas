<?php
/**
 * @filesource modules/inventory/controllers/dashboard.php
 *
 * api/inventory/dashboard/* — ตารางที่โมดูลนี้ส่งไปแสดงบนหน้าแรก
 *
 * ⚠️ ไม่มีหน้า dashboard ของตัวเอง — หน้าแรกของแกนรับบล็อกจากโมดูลอยู่แล้ว
 * (hook initDashboardBlocks) การมีสองหน้าที่สรุปเรื่องเดียวกัน แปลว่าวันหนึ่ง
 * มันจะบอกตัวเลขไม่ตรงกัน แล้วไม่มีใครรู้ว่าหน้าไหนถูก
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Dashboard;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * API ตารางบนหน้าแรก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/dashboard/lowstock — สินค้าที่ใกล้หมด
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function lowstock(Request $request)
    {
        return $this->table($request, 'lowStock', [
            ['field' => 'topic', 'text' => Language::get('Product name')],
            ['field' => 'product_code', 'text' => Language::get('Product code'), 'class' => 'center'],
            ['field' => 'balance', 'text' => Language::get('Balance'), 'class' => 'right'],
            ['field' => 'unit', 'text' => Language::get('Unit'), 'class' => 'center']
        ]);
    }

    /**
     * GET api/inventory/dashboard/topproducts — สินค้าขายดีของเดือนนี้
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function topproducts(Request $request)
    {
        return $this->table($request, 'topProducts', [
            ['field' => 'topic', 'text' => Language::get('Product name')],
            ['field' => 'quantity', 'text' => Language::get('Quantity'), 'class' => 'right'],
            ['field' => 'amount', 'text' => Language::get('Total'), 'class' => 'right', 'format' => 'currency']
        ]);
    }

    /**
     * ตัวตอบร่วมของทั้งสองตาราง
     *
     * รูปแบบที่ตารางบนหน้าแรกต้องการคือ data + columns + meta (data-dynamic-columns)
     * ถ้าขาด columns หัวตารางจะว่างแล้วแถวไม่ถูกวาดเลย
     *
     * @param Request $request
     * @param string  $method  ชื่อเมธอดของ Model ที่จะเรียก
     * @param array   $columns
     *
     * @return \Kotchasan\Http\Response
     */
    protected function table(Request $request, $method, array $columns)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $datas = Model::$method(10);

            return $this->successResponse([
                'data' => $datas,
                'columns' => $columns,
                'meta' => [
                    'page' => 1,
                    'pageSize' => count($datas),
                    'total' => count($datas),
                    'totalPages' => 1
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
