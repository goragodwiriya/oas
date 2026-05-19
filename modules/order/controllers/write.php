<?php
/**
 * @filesource modules/order/controllers/write.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Write;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Login;
use Order\Helper\Model as OrderHelper;

/**
 * API Order Write Controller
 *
 * Handles single order view/edit + status updates + payment + shipping
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * Check whether the current user can access order write flows.
     *
     * @param object $login
     *
     * @return bool
     */
    private static function canAccessOrder($login): bool
    {
        return ApiController::isAdmin($login)
        || Login::hasPermission(['can_view_order', 'can_edit_order'], $login);
    }

    /**
     * GET /api/order/write/get
     * Get order details by ID for admin view/edit
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
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            if (!self::canAccessOrder($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->get('id')->toInt();
            $defaultDocumentType = strtoupper($request->get('document_type')->filter('a-zA-Z_'));
            $order = Model::get($id, $defaultDocumentType);
            if (!$order) {
                return $this->errorResponse('No data available', 404);
            }
            $order->document_profile = OrderHelper::getDocumentProfile($order->document_type ?? $defaultDocumentType);

            return $this->successResponse([
                'data' => $order,
                'options' => [
                    'document_type' => OrderHelper::getDocumentTypeOptions(),
                    'document_status' => OrderHelper::getDocumentStatusOptions(),
                    'value_decimals' => OrderHelper::getValueDecimals(),
                    'value_step' => OrderHelper::getStepValue()
                ]
            ], 'Order details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/order/write/save
     * Save order (create or update)
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
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            if (!ApiController::isAdmin($login) && !Login::hasPermission('can_edit_order', $login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $order = Model::get($id);
            if ($order === null) {
                return $this->errorResponse('No data available', 404);
            }

            // List of selected products (raw from form)
            $rawItems = $request->post('items')->toArray();
            // Order information
            $data = $this->parseInput($request);

            $errors = $this->validateFields($order, $data, $rawItems);
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $existingProducts = [];
            foreach ($order->items as $item) {
                $existingProducts[$item->product_code] = $item;
            }

            // Build item snapshots from DB before saving
            $taxRate = max(0, (float) ($data['tax_rate'] ?? 0));
            $items = Model::buildItemsFromParts($rawItems, $existingProducts, $taxRate);
            if (empty($items)) {
                return $this->formErrorResponse(['items' => 'The selected product was not found in the system.'], 400);
            }

            if ($request->post('saveAsNewDocument')->toBoolean()) {
                $data['id'] = 0;
                $data['order_no'] = '';
                $data['source_document_id'] = (int) $order->id;
                $data['reference_document_no'] = (string) $order->order_no;
            }

            $orderId = Model::save($data, $items, $login->id);
            Model::recalculateTotals($orderId);

            $clearDraftTarget = empty($id) ? 'new' : 'order:'.$orderId;

            if (empty($id)) {
                return $this->redirectResponse('/order?id='.$orderId.'&clear_items_draft='.rawurlencode($clearDraftTarget), 'Order created successfully: '.\Order\Write\Model::get($orderId)->order_no, 200);
            }

            return $this->redirectResponse('/order?id='.$orderId.'&clear_items_draft='.rawurlencode($clearDraftTarget), 'Order saved successfully', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Parse customer input from request.
     *
     * @param Request $request
     *
     * @return array<string,mixed>
     */
    private function parseInput(Request $request): array
    {
        return [
            'customer_id' => $request->post('customer_id')->toInt(),
            'discount_amount' => $request->post('discount_amount')->toDouble(),
            'document_type' => strtoupper($request->post('document_type')->filter('a-zA-Z_')),
            'due_date' => $request->post('due_date')->date(),
            'id' => $request->post('id')->toInt(),
            'internal_note' => $request->post('internal_note')->textarea(),
            'note' => $request->post('note')->textarea(),
            'reference_document_no' => $request->post('reference_document_no')->topic() ?: null,
            'root_document_id' => $request->post('root_document_id')->toInt() ?: null,
            'source_document_id' => $request->post('source_document_id')->toInt() ?: null,
            'subtotal' => $request->post('subtotal')->toDouble(),
            'tax_amount' => $request->post('tax_amount')->toDouble(),
            'tax_rate' => $request->post('tax_rate')->toDouble(),
            'total' => $request->post('total')->toDouble(),
            'document_status' => 'issued'
        ];
    }

    /**
     * Validate order fields.
     *
     * @param object $order
     * @param array $data
     * @param array $rawItems
     *
     * @return array
     */
    private function validateFields(object $order, array &$data, array $rawItems): array
    {
        $errors = [];

        // ถ้าเป็น order แผนกคลังสินค้า ต้องมีคู่ค้า
        if (isset(\Order\Helper\Model::$purchaseDocumentTypes[$data['document_type']]) && $data['customer_id'] === 0) {
            $errors['customer_id'] = 'Please select';
        }

        if ($data['customer_id'] !== $order->customer_id) {
            $customer = \Customer\Customer\Model::get($data['customer_id'], '');
            if (!$customer) {
                $errors['customer_id'] = 'Customer not found';
            } else {
                $data['customer_company'] = $customer->company;
                $data['customer_name'] = $customer->name;
                $data['customer_tax_id'] = $customer->tax_id;
                $data['customer_phone'] = $customer->phone;
                $data['customer_email'] = $customer->email;
                $data['customer_address'] = $customer->address;
                $data['customer_province'] = $customer->province;
                $data['customer_zipcode'] = $customer->zipcode;
                $data['customer_contact'] = $customer->contact;
            }
        }

        if (empty($rawItems)) {
            $errors['items'] = 'Please select at least one item';
        }

        return $errors;
    }
}
