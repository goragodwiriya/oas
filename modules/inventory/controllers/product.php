<?php
/**
 * @filesource modules/inventory/controllers/product.php
 */

namespace Inventory\Product;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Load product form data.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $data = Model::get($request->get('id')->toInt());
            if ($data === null) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }
            $data->tableid = $request->get('tableid')->filter('a-zA-Z_');

            $categories = \Inventory\Category\Controller::init();

            $response = [
                'data' => $data,
                'options' => [
                    'category_id' => $categories->toOptions('category_id', true, null, ['' => '{LNG_Please select}']),
                    'unit' => $categories->toOptions('unit', false, null, ['' => '{LNG_Please select}']),
                    'value_decimals' => \Inventory\Helper\Controller::getValueDecimals(),
                    'value_step' => \Inventory\Helper\Controller::getStepValue()
                ],
                'actions' => [
                    [
                        'type' => 'modal',
                        'action' => 'show',
                        'template' => '/inventory/product.html',
                        'title' => ($data->id > 0 ? '{LNG_Edit} {LNG_Product}' : '{LNG_Add} {LNG_Product}'),
                        'titleClass' => 'icon-product'
                    ]
                ]
            ];

            return $this->successResponse($response, 'Inventory details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Save product details.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }
            if (!ApiController::canModify($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            if ($id > 0 && Model::getRecord($id) === null) {
                return $this->errorResponse('No data available', 404);
            }

            $save = [
                'allow_negative' => $request->post('allow_negative')->toBoolean(),
                'category_id' => $request->post('category_id')->topic(),
                'cost' => \Inventory\Helper\Controller::roundValue($request->post('cost')->toDouble()),
                'description' => $request->post('description')->textarea(),
                'inuse' => $request->post('inuse')->toBoolean(),
                'product_code' => $request->post('product_code')->topic(),
                'topic' => $request->post('topic')->topic(),
                'stockable' => $request->post('stockable')->toBoolean()
            ];
            $meta = [
                'location' => $request->post('location')->topic()
            ];
            $item = [
                'inventory_item_id' => $request->post('inventory_item_id')->toInt(),
                'sku' => $request->post('sku')->topic(),
                'barcode' => $request->post('barcode')->topic(),
                'unit' => $request->post('unit')->topic(),
                'stock' => \Inventory\Helper\Controller::roundValue($request->post('stock')->toDouble()),
                'price' => \Inventory\Helper\Controller::roundValue($request->post('price')->toDouble())
            ];

            $errors = [];
            if ($save['topic'] === '') {
                $errors['topic'] = 'Please fill in';
            }
            if ($save['category_id'] === '') {
                $errors['category_id'] = 'Please select';
            }
            if ($save['product_code'] !== '' && Model::findDuplicateProductCode($save['product_code'], $id) !== null) {
                $errors['product_code'] = 'This product code already exists';
            }
            if ($id === 0) {
                if ($item['unit'] === '') {
                    $errors['unit'] = 'Please select';
                }
                if ($item['stock'] < 0) {
                    $errors['stock'] = 'Please fill in';
                }
                if ($item['sku'] !== '' && Model::findDuplicateSKU($item['sku'], $id, (int) $item['inventory_item_id']) !== null) {
                    $errors['sku'] = 'This SKU already exists';
                }
                if ($item['barcode'] !== '' && Model::findDuplicateBarcode($item['barcode'], (int) $item['inventory_item_id']) !== null) {
                    $errors['barcode'] = 'This barcode already exists';
                }
            }

            if (empty($errors)) {
                // Save
                $id = Model::save($id, $save, $meta, $item, (int) $login->id);

                // Upload
                \Download\Upload\Model::execute(
                    $errors,
                    $request,
                    $id,
                    'inventory',
                    self::$cfg->img_typies,
                    0,
                    (int) (self::$cfg->inventory_w ?? 800)
                );
            }

            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            // Log
            \Index\Log\Model::add($id, 'inventory', 'Save', 'Saved inventory product: '.$save['topic'], $login->id);

            // Response
            return $this->successResponse([
                'context' => $request->post('tableid')->filter('a-zA-Z_'),
                'actions' => [
                    [
                        'type' => 'notification',
                        'level' => 'success',
                        'message' => 'Saved successfully'
                    ],
                    [
                        'type' => 'redirect',
                        'url' => 'reload',
                        'target' => 'table'
                    ],
                    [
                        'type' => 'modal',
                        'action' => 'close'
                    ]
                ]
            ], 'Saved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Remove uploaded image.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function removeImage(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }
            if (!ApiController::canModify($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $json = json_decode($request->post('id')->toString());
            if (!$json || !isset($json->id, $json->file)) {
                return $this->errorResponse('No data available', 404);
            }
            if (Model::getRecord((int) $json->id) === null) {
                return $this->errorResponse('No data available', 404);
            }

            $file = ROOT_PATH.DATA_FOLDER.'inventory/'.$json->id.'/'.$json->file;
            if (!is_file($file)) {
                return $this->errorResponse('No data available', 404);
            }

            @unlink($file);

            \Index\Log\Model::add((int) $json->id, 'inventory', 'Delete', 'Removed inventory image: '.$json->file, $login->id);

            return $this->successResponse([], 'Image removed successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
