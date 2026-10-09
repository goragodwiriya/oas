<?php
/**
 * @filesource modules/inventory/controllers/stocks.php
 *
 * api/inventory/stocks — ความเคลื่อนไหวสต๊อกของสินค้าหนึ่งตัว (หน้า /inventory-stock)
 * ระบบเดิมของ oms คือ module=inventory-write&tab=stock
 *
 * ⚠️ ตัวกรอง "ชนิด" คือ movement_type ของสมุดบัญชี ไม่ใช่ชนิดเอกสาร
 *    เอกสารหนึ่งใบทำให้เกิดการเคลื่อนไหวชนิดใดก็ได้ตามที่แม่แบบตั้งไว้ และ
 *    ยอดยกมา/การปรับยอดไม่มีเอกสารเลย การกรองด้วยชนิดเอกสารจึงเห็นข้อมูลไม่ครบ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Stocks;

use Gcms\Api as ApiController;
use Inventory\Base\Model as Base;
use Inventory\Posting\Model as Posting;
use Inventory\Product\Model as Product;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * API ความเคลื่อนไหวสต๊อก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * คอลัมน์ที่เรียงลำดับได้ (กัน SQL injection)
     *
     * @var array
     */
    protected $allowedSortColumns = ['create_date', 'order_no', 'quantity', 'price', 'total'];

    /**
     * ตรวจสิทธิ์
     *
     * @param Request $request
     * @param object  $login
     *
     * @return bool|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * ตัวกรอง
     *
     * ค่าปริยายของชนิดเป็น "ทั้งหมด" ไม่ใช่ขาออกอย่างเดียว ไม่งั้นเปิดหน้ามาแล้ว
     * เห็นข้อมูลไม่ครบทั้งที่มีอยู่ (ของเดิมตั้ง OUT ไว้ ซึ่งซ่อนรายการส่วนใหญ่ไป)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        $status = $request->get('status')->filter('a-z_');
        if ($status !== '' && Base::movementDirection($status) === null) {
            $status = '';
        }

        $direction = $request->get('direction')->filter('a-z');
        if ($direction !== 'in' && $direction !== 'out') {
            $direction = '';
        }

        return [
            'inventory_id' => $request->get('id')->toInt(),
            'status' => $status,
            'direction' => $direction,
            'year' => $request->get('year')->toInt(),
            'month' => $request->get('month')->toInt()
        ];
    }

    /**
     * ชนิดการเคลื่อนไหวที่เลือกกรองได้ (คีย์คือค่าใน movement_type)
     *
     * @return array [movement_type => ข้อความ]
     */
    protected function statusOptions()
    {
        $result = [];
        foreach (Base::movementTypes() as $type => $direction) {
            // แปลที่เดียวกับสมุดบัญชีรวม ไม่งั้นสองหน้าเรียกของอย่างเดียวกันคนละชื่อ
            $result[$type] = \Inventory\Ledger\Model::movementTypeLabel($type);
        }

        return $result;
    }

    /**
     * Query ของตาราง
     *
     * @param array  $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\SelectBuilder
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params);
    }

    /**
     * ตัวเลือกของตัวกรอง (ทั้งคอลัมน์ในตารางและฟอร์มกรองด้านบน)
     *
     * @param array  $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        $statuses = [];
        foreach ($this->statusOptions() as $value => $text) {
            $statuses[] = ['value' => $value, 'text' => $text];
        }

        $months = [];
        foreach (Language::get('MONTH_LONG') as $value => $text) {
            $months[] = ['value' => (string) $value, 'text' => $text];
        }

        return [
            'status' => $statuses,
            'direction' => [
                ['value' => 'in', 'text' => Language::get('Stock in')],
                ['value' => 'out', 'text' => Language::get('Stock out')]
            ],
            'year' => Model::listYears($params['inventory_id']),
            'month' => $months
        ];
    }

    /**
     * จัดข้อมูลให้พร้อมแสดง
     *
     * แปลงตัวเลขจากสตริง DECIMAL ให้เป็นตัวเลขจริง และเติมข้อความของเลขที่เอกสาร
     * ให้กับแถวที่ไม่ได้มาจากเอกสาร (ยอดยกมา / ปรับปรุงสต๊อก) เหมือนระบบเดิม
     *
     * @param array  $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $statuses = $this->statusOptions();

        foreach ($datas as $item) {
            $item->quantity = (float) $item->quantity;
            $item->price = (float) $item->price;
            $item->total = (float) $item->total;

            $source = Model::describeSource($item->order_no, $item->order_id,
                $item->reference_type, $item->topic);
            $item->order_no = $source['order_no'];
            $item->order_url = $source['order_url'];
            $item->note = $source['note'];

            $item->status_text = isset($statuses[$item->status])
                ? $statuses[$item->status]
                : (string) $item->status;

            // ⚠️ ทิศทางอ่านจากคอลัมน์ของสมุดบัญชี ไม่ใช่เดาจากชนิดเอกสารเหมือนรุ่นเดิม
            // (รุ่นเดิมเทียบกับ $cfg->out_stock_status ซึ่งเป็นชนิด "เอกสาร"
            //  พอ status กลายเป็นชนิด "การเคลื่อนไหว" การเทียบนั้นจะเป็นเท็จเสมอ
            //  และรายการจ่ายออกทุกแถวจะแสดงเป็นค่าบวก อ่านไม่ออกว่าเข้าหรือออก)
            $item->movement = $item->movement_direction === 'out'
                ? -$item->quantity
                : $item->quantity;
        }

        return $datas;
    }

    /**
     * GET api/inventory/stocks/modal — ฟอร์มปรับยอดสต๊อกในรูปหน้าต่างซ้อน
     *
     * ระบบเดิมของ oms มีปุ่ม "ปรับยอดสินค้าคงคลัง" อยู่บนตารางนี้เหมือนกัน แต่
     * ฟอร์มของมัน query ตาราง `product` ซึ่งไม่มีอยู่จริงในสคีมาของ oms ปุ่มนั้น
     * จึงพังทุกครั้งที่กด — รุ่นนี้เขียนใหม่ให้เดินผ่าน Posting API เหมือนทุกอย่าง
     * ที่แตะสต๊อก จะได้มีประวัติว่าใครปรับ ปรับเมื่อไร และเพราะอะไร
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function modal(Request $request)
    {
        try {
            // ⚠️ รับได้ทั้ง GET และ POST — ปุ่มที่เปิดหน้าต่างซ้อนด้วย data-modal-api
            // ยิงมาเป็น POST เสมอ (Now/js/ModalDataBinder.js : loadModalFromApi ใช้
            // client.post) บังคับ GET อย่างเดียวแล้วปุ่มจะได้ 405 แล้วกดไม่ขึ้น
            if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
                return $this->errorResponse('Method not allowed', 405);
            }

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->request('id')->toInt();
            $product = $id > 0 ? Product::get($id) : null;
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }
            if ((int) $product['count_stock'] === 0) {
                // สินค้าที่ไม่นับสต๊อก (ค่าบริการ ค่าแรง) ไม่มียอดให้ปรับ — Posting::post()
                // จะไม่บันทึกอะไรเลยและไม่ถือเป็นข้อผิดพลาด ถ้าปล่อยให้เปิดฟอร์มได้
                // ผู้ใช้จะกดบันทึกแล้วไม่มีอะไรเกิดขึ้นโดยไม่รู้สาเหตุ
                return $this->errorResponse(Language::get('This product does not count stock'), 400);
            }

            $units = Model::unitOptions($id, $product['count_stock']);
            $itemId = empty($units) ? 0 : (int) $units[0]['value'];

            return $this->successResponse([
                'data' => [
                    'id' => $id,
                    'topic' => $product['topic'],
                    'product_no' => $product['product_no'],
                    'inventory_item_id' => $itemId,
                    'instock' => Posting::balance($id, $itemId),
                    // สินค้าที่มีหน่วยย่อยมียอดคนละยอดต่อหน่วย ช่อง "ยอดปัจจุบัน" ช่องเดียว
                    // จึงบอกความจริงไม่ได้ ต้องให้ยอดไปอยู่ในตัวเลือกของแต่ละหน่วยแทน
                    'has_units' => !empty($units),
                    'single_unit' => empty($units)
                ],
                'options' => [
                    'inventory_item_id' => $units
                ],
                'actions' => [
                    [
                        'type' => 'modal',
                        'action' => 'show',
                        'template' => 'inventory/stock-adjust.html',
                        'title' => Language::get('Inventory Adjust'),
                        'titleClass' => 'icon-exchange'
                    ]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/stocks/adjust — ปรับยอดคงเหลือให้ตรงกับที่นับได้จริง
     *
     * ตรวจให้ครบก่อนเรียกโมเดล เพื่อให้ผู้ใช้เห็นข้อความผิดพลาดที่ "ช่องที่ผิด"
     * ไม่ใช่แถบแจ้งเตือนรวม ๆ — ส่วนโมเดลตรวจซ้ำเองอีกชั้นเพราะมันเป็นที่เดียว
     * ที่คำนวณส่วนต่าง และต้องถูกเสมอแม้ถูกเรียกจากที่อื่น
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function adjust(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::canModify($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $product = $id > 0 ? Product::get($id) : null;
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }
            if ((int) $product['count_stock'] === 0) {
                return $this->errorResponse(Language::get('This product does not count stock'), 400);
            }

            $itemId = $request->post('inventory_item_id')->toInt();
            if (!Model::ownsItem($id, $itemId)) {
                return $this->errorResponse('No data available', 404);
            }

            $note = $request->post('note')->topic();
            $quantity = $request->post('quantity')->toDouble();

            $errors = [];
            if ($note === '') {
                $errors['note'] = Language::get('Please fill in');
            }
            if (abs($quantity - Posting::balance($id, $itemId)) < 0.0000001) {
                $errors['quantity'] = Language::get('New Stock').' '.Language::get('equal to')
                    .' '.Language::get('Quantity On Hand');
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $result = Model::adjust($id, $itemId, $quantity, $note, (int) $login->id);

            return $this->successResponse([
                'id' => $result['movement_id'],
                'inventory_id' => $id,
                'inventory_item_id' => $itemId,
                'balance' => $result['balance'],
                'actions' => [
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'redirect', 'url' => 'reload', 'target' => 'stocks', 'delay' => 0]
                ]
            ], Language::get('Saved successfully'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
