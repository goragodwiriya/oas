<?php
/**
 * @filesource modules/customer/controllers/customer.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Customer\Customer;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Customer form controller.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/customer/customer/get
     * Get customer details by ID.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $id = $request->get('id')->toInt();
            $type = $request->get('type')->filter('a-z');
            $data = Model::get($id, $type);
            if (!$data) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }
            $type = $data->type === 'supplier' ? 'supplier' : 'customer';

            $data->context = $request->get('context', 'customers')->filter('a-z_');
            $data->types = Model::$types;

            $response = [
                'data' => $data,
                'options' => [
                    'type' => Model::getCustomerTypeOptions(),
                    'province_id' => \Kotchasan\Province::getOptions()
                ]
            ];

            $response['actions'] = [
                [
                    'type' => 'modal',
                    'action' => 'show',
                    'template' => '/customer/customer.html',
                    'title' => ($id > 0 ? '{LNG_Edit} ' : '{LNG_Create} ').Model::$types[$type],
                    'titleClass' => $type === 'supplier' ? 'icon-customer' : 'icon-user'
                ]
            ];

            return $this->successResponse($response, 'Customer details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/customer/customer/save
     * Save customer details.
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
            if (!ApiController::canModify($login, ['can_manage_customer', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $customer = Model::get($request->post('id')->toInt(), '');
            if ($customer === null) {
                return $this->errorResponse('No data available', 404);
            }

            $save = $this->parseInput($request);

            $errors = $this->validateFields($save);
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $id = Model::save($customer->id, $save);

            \Index\Log\Model::add($id, 'customer', 'Save', 'Saved customer: '.$save['name'], $login->id);

            $context = $request->post('context', 'customers')->filter('a-z_') ?: 'customers';

            if ($context === 'order') {
                return $this->successResponse([
                    'customer_id' => $id,
                    'context' => $context,
                    'actions' => [
                        [
                            'type' => 'notification',
                            'level' => 'success',
                            'message' => 'Saved successfully'
                        ],
                        [
                            'type' => 'form',
                            'form' => 'orderWrite',
                            'silent' => true,
                            'fields' => \Customer\Autocomplete\Model::getCustomerFormData($id)
                        ],
                        [
                            'type' => 'modal',
                            'action' => 'close'
                        ]
                    ]
                ], 'Saved successfully');
            }

            return $this->successResponse([
                'customer_id' => $id,
                'context' => $context,
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
     * Parse customer input from request.
     *
     * @param Request $request
     *
     * @return array<string,mixed>
     */
    private function parseInput(Request $request): array
    {
        $type = $request->post('type')->filter('a-z');

        return [
            'address' => $request->post('address')->textarea(),
            'bank_account' => $request->post('bank_account')->topic(),
            'bank_name' => $request->post('bank_name')->topic(),
            'code' => $request->post('code')->topic(),
            'contact' => $request->post('contact')->topic(),
            'email' => $request->post('email')->email(),
            'is_active' => $request->post('is_active')->toBoolean(),
            'province_id' => $request->post('province_id')->number(),
            'zipcode' => $request->post('zipcode')->number(),
            'name' => $request->post('name')->topic(),
            'note' => $request->post('note')->textarea(),
            'phone' => $request->post('phone')->number(),
            'tax_id' => $request->post('tax_id')->number(),
            'type' => $type === 'supplier' ? 'supplier' : 'customer'
        ];
    }

    /**
     * Validate customer fields.
     *
     * @param array<string,mixed> $save
     */
    private function validateFields(array $save): array
    {
        $errors = [];

        if ($save['name'] === '') {
            $errors['name'] = 'Please fill in';
        }

        return $errors;
    }
}
