<?php
/**
 * @filesource modules/customer/controllers/customers.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Customer\Customers;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Customers Controller
 *
 * Handles customer management endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * Allowed sort columns
     *
     * @var array
     */
    protected $allowedSortColumns = [
        'id', 'name'
    ];

    /**
     * Get custom filter params from request
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'type' => $request->get('type')->filter('a-z'),
            'search' => $request->get('search')->topic()
        ];
    }

    /**
     * Check authorization for user management
     * Only admins can access, demo mode is blocked
     *
     * @param Request $request
     * @param object  $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_view_customer', 'can_manage_customer', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params);
    }

    /**
     * Get filters for table response
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'types' => \Customer\Customer\Model::getCustomerTypeOptions()
        ];
    }

    /**
     * Format customer list with additional display fields
     *
     * @param array $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $data = [];
        foreach ($datas as $row) {
            $line = [];
            if (!empty($row->line_id)) {
                $line[] = $row->line_id;
            }
            if (!empty($row->line_name)) {
                $line[] = '('.$row->line_name.')';
            }
            $row->line_display = implode(' ', $line);

            $data[] = $row;
        }
        return $data;
    }

    /**
     * Handle edit action (redirect to customer form)
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleEditAction(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_customer', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $id = $request->post('id')->toInt();
        $type = $request->post('type')->filter('a-z');

        $customer = \Customer\Customer\Model::get($id, $type);
        if ($customer === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->successResponse([
            'data' => array_merge((array) $customer, [
                'context' => 'customers'
            ]),
            'options' => [
                'type' => \Customer\Customer\Model::getCustomerTypeOptions(),
                'province_id' => \Kotchasan\Province::getOptions()
            ],
            'actions' => [
                'type' => 'modal',
                'template' => 'customer/customer.html',
                'title' => '{LNG_Edit} '.($customer->type === 'supplier' ? '{LNG_Supplier}' : '{LNG_Customer}'),
                'titleClass' => $customer->type === 'supplier' ? 'icon-customer' : 'icon-user'
            ]
        ], 'Customer details retrieved');
    }

    /**
     * Handle delete action
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_delete_customer', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        $removeCount = Model::remove($ids);

        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'customer', 'Delete', 'Delete Customer ID(s) : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removeCount.' customer(s) successfully');
    }

    /**
     * Handle is_active action
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleActiveAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_delete_customer', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $db = \Kotchasan\DB::create();

        // Get selected user IDs
        $id = $request->post('id')->toInt();
        $customer = $db->first('customer', ['id', $id]);
        if (!$customer) {
            return $this->errorResponse('Customer not found', 404);
        }

        $active = $customer->is_active == 1 ? 0 : 1;
        $db->update('customer', ['id', $id], ['is_active' => $active]);

        // Log the action
        $msg = $active ? 'Activated customer: '.$customer->name : 'Deactivated customer: '.$customer->name;
        \Index\Log\Model::add($customer->id, 'customer', 'Action', $msg, $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', $msg, 200, 0, 'table');
    }

    /**
     * GET /api/customer/customers/recent
     * Get recent customers (for Dashboard)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function recent(Request $request)
    {
        try {
            // Validate request method and CSRF token
            ApiController::validateMethod($request, 'GET');

            // get recent customers
            $result = Model::recent(5);

            // return response
            return $this->successResponse([
                'data' => $result
            ], 'Recent customers retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * GET /api/customer/customers/search
     * Search customers for autocomplete controls.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function search(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            if (!ApiController::isAdmin($login) && !ApiController::hasPermission($login, ['can_view_customer', 'can_manage_customer', 'can_config', 'can_view_order', 'can_edit_order'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $search = $request->get('q')->topic();
            $limit = min($request->get('limit', 20)->toInt(), 50);
            $type = $request->get('type')->filter('a-z');

            return $this->successResponse(Model::search($search, $limit, $type), 'Customers retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
