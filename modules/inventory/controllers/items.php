<?php
/**
 * @filesource modules/inventory/controllers/items.php
 *
 * api/inventory/items/get|save — รหัสสินค้า/บาร์โค้ดของสินค้าหนึ่งตัว
 * ระบบเดิมคือ module=inventory-write&tab=items
 *
 * ใช้แพทเทิร์นตารางแก้ไขทั้งตารางแล้วกดบันทึกครั้งเดียว (เหมือน categories)
 * ผู้ใช้จึงเพิ่ม/แก้/ลบหลายรหัสพร้อมกันได้แบบระบบเดิม
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Items;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API รหัสสินค้า/บาร์โค้ด
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * นิยามคอลัมน์ของตารางแก้ไข
     *
     * @param array $product
     *
     * @return array
     */
    protected function columns(array $product)
    {
        $columns = [
            [
                'field' => 'barcode',
                'label' => '{LNG_Barcode}',
                'class' => 'center',
                'cellClass' => 'center',
                'template' => '<img class="inventory-barcode" src="${barcode}" alt="">'
            ],
            [
                'field' => 'product_no',
                'label' => '{LNG_Product code}',
                'cellElement' => 'text',
                'maxLength' => 150
            ],
            [
                'field' => 'topic',
                'label' => '{LNG_Detail}',
                'cellElement' => 'text',
                'maxLength' => 150
            ],
            [
                'field' => 'price',
                'label' => '{LNG_Selling price}',
                'class' => 'center',
                'cellClass' => 'right',
                'cellElement' => 'currency'
            ],
            [
                'field' => 'unit',
                'label' => '{LNG_Unit}',
                'class' => 'center',
                'cellElement' => 'text',
                'size' => 5,
                'maxLength' => 50
            ]
        ];

        // สินค้านับสต๊อกแยกรายชิ้น ตัดสต๊อกชิ้นละ 1 เสมอ ไม่ต้องให้แก้
        if ((int) $product['count_stock'] !== 2) {
            $columns[] = [
                'field' => 'cut_stock',
                'label' => '{LNG_Cut stock}',
                'class' => 'center',
                'cellClass' => 'center',
                'cellElement' => 'number',
                'size' => 5
            ];
        }

        return $columns;
    }

    /**
     * GET api/inventory/items/get?id=N
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->get('id')->toInt();
            $product = \Inventory\Product\Model::get($id);
            if ($product === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            return $this->successResponse([
                'data' => [
                    'id' => (int) $product['id'],
                    'topic' => $product['topic'],
                    'product_no' => $product['product_no'],
                    'count_stock' => (int) $product['count_stock'],
                    'options' => [
                        'columns' => $this->columns($product),
                        'data' => Model::toDataTable($product)
                    ]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/items/save
     *
     * ตารางแก้ไขส่งค่ามาเป็นอาเรย์รายคอลัมน์ (product_no[], topic[], ...)
     * แบบเดียวกับหน้าหมวดหมู่ของแกน ไม่ใช่ items[0][field]
     *
     * @param Request $request
     *
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::canModify($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $product = \Inventory\Product\Model::get($id);
            if ($product === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            $productNos = $request->post('product_no', [])->topic();
            $topics = $request->post('topic', [])->topic();
            $prices = $request->post('price', [])->toFloat();
            $units = $request->post('unit', [])->topic();
            $cutStocks = $request->post('cut_stock', [])->toFloat();

            $rows = [];
            foreach ($productNos as $index => $productNo) {
                $rows[$index] = [
                    'product_no' => $productNo,
                    'topic' => isset($topics[$index]) ? $topics[$index] : '',
                    'price' => isset($prices[$index]) ? $prices[$index] : 0,
                    'unit' => isset($units[$index]) ? $units[$index] : '',
                    'cut_stock' => isset($cutStocks[$index]) ? $cutStocks[$index] : 1
                ];
            }

            $errors = Model::save($product, $rows, $login->id);
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            \Index\Log\Model::add($id, 'inventory', 'Save',
                '{LNG_Barcode} ID : '.$id, $login->id);

            return $this->redirectResponse('reload',
                Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
