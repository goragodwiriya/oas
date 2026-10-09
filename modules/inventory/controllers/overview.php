<?php
/**
 * @filesource modules/inventory/controllers/overview.php
 *
 * api/inventory/overview/get|chart — ภาพรวมของสินค้าหนึ่งตัว
 * ระบบเดิมคือ module=inventory-write&tab=overview
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Overview;

use Gcms\Api as ApiController;
use Inventory\Stocks\Model as Stocks;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API ภาพรวมสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * ตรวจสิทธิ์ + อ่านสินค้าที่เลือก
     *
     * @param Request $request
     *
     * @return array|Response array = ผ่าน, Response = ตอบกลับด้วยข้อผิดพลาด
     */
    protected function resolveProduct(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $id = $request->get('id')->toInt();
        $product = \Inventory\Product\Model::get($id);
        if ($product === null || $id <= 0) {
            return $this->errorResponse('No data available', 404);
        }

        return $product;
    }

    /**
     * GET api/inventory/overview/get?id=N&year=YYYY
     *
     * ตัวเลขชุดเดียวกับระบบเดิม: ยอดขาย ต้นทุนของที่ขายไป กำไรขั้นต้น
     * และมูลค่าสินค้าคงคลัง พร้อมราคาเฉลี่ยต่อหน่วยของแต่ละช่อง
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $product = $this->resolveProduct($request);
            if ($product instanceof Response) {
                return $product;
            }

            $id = (int) $product['id'];
            $year = $request->get('year', date('Y'))->toInt();
            $summary = Stocks::summary($id);
            $profit = $summary['circulation'] - $summary['cost'];

            // การ์ดสรุปไม่ใช่ตาราง จึงไม่มี data-format ของ TableManager ให้ใช้
            // จัดรูปแบบตัวเลขมาจากฝั่งนี้ เหมือนที่ระบบเดิมทำในหน้า overview
            $card = function ($key, $label, $hint, $amount, $quantity) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'hint' => $hint,
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'average' => empty($quantity) ? 0 : $amount / $quantity,
                    'amount_text' => \Kotchasan\Currency::format($amount),
                    'quantity_text' => number_format($quantity),
                    'average_text' => \Kotchasan\Currency::format(empty($quantity) ? 0 : $amount / $quantity)
                ];
            };

            return $this->successResponse([
                'id' => $id,
                'topic' => $product['topic'],
                'product_no' => $product['product_no'],
                'year' => $year,
                'chart_url' => 'api/inventory/overview/chart?id='.$id.'&year='.$year,
                'cards' => [
                    $card('sell', Language::get('Sell'),
                        Language::get('Calculated from sale price, excluding tax, multiplied by the number of items sold. At that time (FIFO)'),
                        $summary['circulation'], $summary['sell']),
                    $card('cost', Language::get('Cost'),
                        Language::get('Calculated from purchase price, excluding tax, multiplied by the number of items sold. At that time (FIFO)'),
                        $summary['cost'], $summary['sell']),
                    $card('profit', Language::get('Gross Profit'),
                        Language::get('Sales minus the cost'),
                        $profit, $summary['sell']),
                    $card('balances', Language::get('Inventory'),
                        Language::get('Calculated from purchase price, excluding tax. multiplied by the number of inventories. At that time (FIFO)'),
                        $summary['balances'], $summary['instock'])
                ],
                'months' => Stocks::monthlyReport($id, $year),
                // ตัวเลือกปีส่งเป็นรายการพร้อมธง selected เพราะเทมเพลตสร้าง <option>
                // ด้วย data-for เอง (data-options-key ใช้ได้เฉพาะในฟอร์ม)
                'years' => array_map(function ($item) use ($year) {
                    $item['selected'] = (int) $item['value'] === $year;
                    return $item;
                }, Stocks::listYears($id))
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * GET api/inventory/overview/chart?id=N&year=YYYY
     *
     * รูปร่างที่ data-component="graph" ต้องการ: [{name, data:[{label, value}]}]
     *
     * @param Request $request
     *
     * @return Response
     */
    public function chart(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $product = $this->resolveProduct($request);
            if ($product instanceof Response) {
                return $product;
            }

            $year = $request->get('year', date('Y'))->toInt();
            $months = Stocks::monthlyReport((int) $product['id'], $year);

            $buy = [];
            $sell = [];
            foreach ($months as $month) {
                $buy[] = ['label' => $month['label'], 'value' => $month['buy']];
                $sell[] = ['label' => $month['label'], 'value' => $month['sell']];
            }

            return $this->successResponse([
                'data' => [
                    ['name' => Language::get('Buy'), 'data' => $buy],
                    ['name' => Language::get('Sell'), 'data' => $sell]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
