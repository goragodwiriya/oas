<?php
/**
 * @filesource modules/inventory/controllers/variant.php
 *
 * api/inventory/variant/save — บันทึกแบบสินค้าจากหน้าต่างซ้อน (inventory/variant-modal.html)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Variant;

use Gcms\Api as ApiController;
use Inventory\Product\Model as Product;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API บันทึกแบบสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * POST api/inventory/variant/save
     *
     * แบบใหม่ได้หน่วยขายฐาน (บาร์โค้ดที่กรอก หรือ sku) และยอดยกมาได้ (สินค้านับจำนวน)
     * — ยอดยกมาลงสมุดบัญชีพร้อมทุนและวันที่ เป็นชั้นต้นทุนแรกของแบบนั้น
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
            $productId = $id > 0 ? \Inventory\Variants\Model::productId($id) : $request->post('product_id')->toInt();
            $product = Product::getProduct($productId);
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }
            $data = [
                'sku' => $request->post('sku')->topic(),
                'option1' => $request->post('option1')->topic(),
                'option2' => $request->post('option2')->topic(),
                'option3' => $request->post('option3')->topic(),
                'price' => $request->post('price')->toDouble(),
                'is_active' => $request->post('is_active')->toBoolean() ? 1 : 0
            ];
            if ($data['sku'] === '') {
                return $this->formErrorResponse(['sku' => 'Please fill in'], 400);
            }
            if ($id === 0) {
                $data['barcode'] = $request->post('barcode')->topic();
            }
            $stock = $request->post('stock')->toDouble();
            $cost = $request->post('cost')->toDouble();
            $date = $request->post('create_date')->date();

            $variantId = \Inventory\Base\Model::transaction(function () use ($productId, $id, $data, $product, $stock, $cost, $date) {
                $variantId = Product::saveVariant($productId, $id, $data);
                // ยอดยกมาของแบบใหม่ — สินค้านับรายชิ้นรับเข้าเป็นเครื่อง (ใบรับ) ไม่ใช่ตัวเลข
                if ($id === 0 && $stock > 0 && (int) $product['count_stock'] === 1) {
                    Product::openingBalance($variantId, 0, $stock, $cost, 0, $date === '' ? null : $date.' 00:00:00');
                }

                return $variantId;
            });

            \Index\Log\Model::add($variantId, 'inventory', 'Save', '{LNG_Variant} '.$data['sku'].' ID : '.$variantId, $login->id);

            return $this->successResponse([
                'actions' => [
                    ['type' => 'notification', 'level' => 'success', 'message' => Language::get('Saved successfully')],
                    ['type' => 'redirect', 'url' => 'reload', 'target' => 'inventoryVariants'],
                    ['type' => 'modal', 'action' => 'close']
                ]
            ], null);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
