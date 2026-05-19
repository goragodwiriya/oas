<?php
/**
 * @filesource modules/customer/controllers/autocomplete.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Customer\Autocomplete;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Customer Autocomplete Controller
 *
 * Handles customer autocomplete functionality
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/customer/autocomplete
     * Hydrate customer form fields from an existing customer.
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
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            $customerId = $request->get('customer_id')->toInt();
            if ($customerId < 1) {
                return $this->successResponse([], 'No customer selected');
            }

            $fields = Model::getCustomerFormData($customerId);
            if (empty($fields)) {
                return $this->errorResponse('Customer not found', 404);
            }

            return $this->successResponse([
                'actions' => [
                    [
                        'type' => 'form',
                        'form' => 'current',
                        'silent' => true,
                        'fields' => $fields
                    ]
                ]
            ], 'Customer details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
