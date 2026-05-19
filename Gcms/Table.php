<?php
/**
 * @filesource Gcms/Table.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

use Kotchasan\ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Base Table Controller
 *
 * Handles table data endpoints with pagination, sorting, and filtering
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Table extends \Kotchasan\ApiController
{
    /**
     * Allowed sort columns (empty = allow all)
     * Override in subclass to restrict sortable columns
     *
     * @var array
     */
    protected $allowedSortColumns = [];

    /**
     * GET /index/{module}
     * Get list of data with pagination
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $this->initLanguage($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check (subclass can override)
            $authCheck = $this->checkAuthorization($request, $login);
            if ($authCheck !== true) {
                return $authCheck; // Return error response from checkAuthorization
            }

            $params = $this->parseParams($request, $login);
            $response = $this->executeDataTable($params, $login);
            $data = $this->formatDatas($response['data'], $login);
            $options = $this->getOptions($params, $login);

            return $this->successResponse([
                'data' => $data,
                'columns' => $this->getColumns($params, $login),
                'filters' => $this->getFilters($params, $login),
                'options' => $options,
                'meta' => $response['meta']
            ], 'Data retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Check authorization for the current request
     * Override this method in subclass to implement custom authorization logic
     *
     * Examples:
     * - Check if user is admin
     * - Check if demo mode is disabled
     * - Check specific permissions
     *
     * @param Request $request
     * @param object $login Current logged-in user
     *
     * @return true|Response Return true if authorized, or error Response if not
     */
    protected function checkAuthorization(Request $request, $login)
    {
        // Default: allow all authenticated users
        // Override in subclass to add restrictions
        return true;
    }

    /**
     * POST /api/{module}/action
     * Handle bulk actions dynamically
     *
     * Action handlers are discovered by method naming convention:
     * - action 'delete' → handleDeleteAction($request, $login)
     * - action 'send_password' → handleSendPasswordAction($request, $login)
     * - action 'active_2' → handleActive2Action($request, $login)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function action(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->initLanguage($request);
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);

            // Check authentication first
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $action = $request->request('action')->filter('a-z_0-9');

            if (empty($action)) {
                return $this->errorResponse('Action is required', 400);
            }

            // Convert action to method name: delete → handleDeleteAction
            $methodName = 'handle'.$this->actionToMethodName($action).'Action';

            if (method_exists($this, $methodName)) {
                return $this->$methodName($request, $login);
            }

            return $this->errorResponse('Invalid action: '.$action, 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Convert action name to method name part
     * Examples:
     * - delete → Delete
     * - send_password → SendPassword
     * - active_2 → Active2
     *
     * @param string $action
     * @return string
     */
    protected function actionToMethodName(string $action): string
    {
        return str_replace('_', '', ucwords($action, '_'));
    }

    /**
     * GET /api/{module}/export
     * Handle export requests dynamically
     *
     * Export handlers are discovered by method naming convention:
     * - type 'csv' → handleCsvExport($request, $login)
     * - type 'pdf' → handlePdfExport($request, $login)
     * - type 'excel' → handleExcelExport($request, $login)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function export(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $this->initLanguage($request);

            $login = $this->authenticateRequest($request);

            // Check authentication first
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Check authorization
            $authCheck = $this->checkAuthorization($request, $login);
            if ($authCheck !== true) {
                return $authCheck;
            }

            $type = $request->get('type')->filter('a-z_0-9');

            // Default to CSV if no type specified
            if (empty($type)) {
                $type = 'csv';
            }

            // Convert type to method name: csv → handleCsvExport
            $methodName = 'handle'.$this->actionToMethodName($type).'Export';

            if (method_exists($this, $methodName)) {
                return $this->$methodName($request, $login);
            }

            return $this->errorResponse('Invalid export type: '.$type, 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Helper method to export data to CSV
     * Subclasses can call this method from their custom export handlers
     *
     * @param array $params Query parameters (from parseParams)
     * @param object $login Authenticated user
     * @param array $headers CSV column headers
     * @param callable $rowFormatter Function to format each row: function($row): array
     * @param string $filename CSV filename (without .csv extension)
     * @return void
     */
    protected function exportToCsv($params, $login, $headers, $rowFormatter, $filename)
    {
        // Build data query: use toJoinQuery() if defined, otherwise toDataTable()
        $base = $this->toDataTable($params, $login);
        $join = $this->toJoinQuery($base, $params, $login);
        $query = clone ($join ?? $base);

        // Parse and apply sort (supports multi-column)
        $sortData = $this->parseSort($params['sort'] ?? '');
        $sorts = $sortData['columns'];
        $sortOrders = $sortData['directions'];

        // Apply sorting
        foreach ($sorts as $key => $sort) {
            $query->orderBy($sort, $sortOrders[$key] ?? 'asc');
        }

        // Execute query without pagination (export all matching results)
        $results = $query->fetchAll();

        // Format rows using provided formatter
        $rows = array_map($rowFormatter, $results);

        // Send CSV
        \Kotchasan\Csv::send($filename, $headers, $rows);

        exit;
    }

    /**
     * Parse table query parameters
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function parseParams(Request $request, $login): array
    {
        $params = [
            'search' => $request->get('search')->topic(),
            'page' => max(1, $request->get('page', 1)->toInt()),
            'pageSize' => min(100, max(1, $request->get('pageSize', 25)->toInt())),
            'sort' => $request->get('sort')->toString()
        ];

        // Merge custom params from subclass
        return array_merge($params, $this->getCustomParams($request, $login));
    }

    /**
     * Get custom parameters from subclass
     * Override this method to add custom query parameters
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [];
    }

    /**
     * Format data for table response
     * Override this method to add custom data format
     *
     * @param array $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        return $datas;
    }

    /**
     * Base query for DataTable — defines WHERE/filter conditions only
     *
     * This query is always used as the COUNT source (wrapped as subquery).
     * When toJoinQuery() is not overridden, this query is also used for data fetch.
     *
     * Override this method to define the main table and filter/search conditions:
     *
     *   protected function toDataTable(array $params, $login)
     *   {
     *       $q = \Kotchasan\Model::createQuery()
     *           ->select('*')
     *           ->from('table_name');
     *       if (!empty($params['search'])) {
     *           $q->whereLike('value', $params['search']);
     *       }
     *       return $q;
     *   }
     *
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable(array $params, $login)
    {
        return \Kotchasan\Model::createQuery();
    }

    /**
     * Optional JOIN query for data fetch
     *
     * Override this method when JOINs are needed to retrieve related data.
     * The $inner parameter is the result of toDataTable() — use it as a subquery FROM.
     * When this method returns null, toDataTable() result is used directly for data fetch.
     *
     * Example:
     *
     *   protected function toJoinQuery($inner, array $params, $login)
     *   {
     *       return \Kotchasan\Model::createQuery()
     *           ->select('P.pro_id', 'P.dateadd', 'C.name customer_name', 'CAR.plate_no', ...)
     *           ->from([$inner, 'P'])
     *           ->leftJoin('crm_customers C', 'C.cus_id = P.cus_id')
     *           ->leftJoin('crm_vehicles CAR', 'CAR.car_id = P.car_id')
     *           ->leftJoin('crm_car_type T', 'T.id = CAR.vehicle_type')
     *           ->leftJoin('crm_car_brand B', 'B.id = CAR.brand');
     *   }
     *
     * @param \Kotchasan\QueryBuilder\QueryBuilderInterface $inner Result of toDataTable()
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface|null Return null to use toDataTable() directly
     */
    protected function toJoinQuery($inner, array $params, $login)
    {
        return null;
    }

    /**
     * Get filters for table response
     * Override this method to add custom filters
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters(array $params, $login)
    {
        return [];
    }

    /**
     * Get columns for table response
     * Override this method to add custom columns
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getColumns(array $params, $login)
    {
        return [];
    }

    /**
     * Get additional options for the table response.
     * Override this method to pass runtime table options or lookup maps.
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getOptions(array $params, $login)
    {
        return [];
    }

    /**
     * Parse sort string into arrays of columns and directions
     * Supports multi-column sort: "name asc,status desc"
     *
     * @param string $sortString
     * @return array ['columns' => [...], 'directions' => [...]]
     */
    protected function parseSort(string $sortString): array
    {
        $columns = [];
        $directions = [];

        if (empty($sortString)) {
            return ['columns' => $columns, 'directions' => $directions];
        }

        // Split by comma for multi-column sort
        $pairs = explode(',', $sortString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            // Match: column_name [asc|desc]
            if (preg_match('/^([a-z0-9_]+)(?:\s+(asc|desc))?$/i', $pair, $match)) {
                $column = $match[1];
                $direction = isset($match[2]) ? strtolower($match[2]) : 'asc';

                // Validate against allowed columns (SQL injection prevention)
                if (!empty($this->allowedSortColumns) && !in_array($column, $this->allowedSortColumns)) {
                    continue;
                }

                $columns[] = $column;
                $directions[] = $direction;
            }
        }

        return ['columns' => $columns, 'directions' => $directions];
    }

    /**
     * Execute DataTable query
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function executeDataTable(array $params, $login)
    {
        // Parse sort
        $sortData = $this->parseSort($params['sort'] ?? '');
        $sorts = $sortData['columns'];
        $sortOrders = $sortData['directions'];

        // Set page size
        $pageSize = !empty($params['pageSize']) ? min(100, max(1, (int) $params['pageSize'])) : 25;

        // Set page
        $page = !empty($params['page']) ? max(1, (int) $params['page']) : 1;
        $offset = ($page - 1) * $pageSize;

        // Base query (WHERE/filter) — always used for COUNT
        $base = $this->toDataTable($params, $login);

        // Count total records using base query (no JOIN overhead)
        $count = \Kotchasan\Model::createQuery()
            ->selectCount()
            ->from([$base->copy(), 'Q'])
            ->first();

        $totalRecords = $count->count ?? 0;
        $totalPages = $totalRecords > 0 ? ceil($totalRecords / $pageSize) : 1;

        // Auto-correct page if it exceeds total pages
        if ($page > $totalPages) {
            $page = max(1, $totalPages);
            $offset = ($page - 1) * $pageSize;
        }

        // Data query: use toJoinQuery() if defined, otherwise base query
        $join = $this->toJoinQuery($base, $params, $login);
        $query = clone ($join ?? $base);

        // Limit and offset
        $query = $query->limit($pageSize, $offset);

        // Sort (only if columns are specified)
        foreach ($sorts as $key => $sort) {
            $query->orderBy($sort, $sortOrders[$key] ?? 'asc');
        }

        // Return meta data
        $meta = $params;
        $meta['page'] = $page;
        $meta['pageSize'] = $pageSize;
        $meta['total'] = $totalRecords;
        $meta['totalPages'] = $totalPages;

        // Returns data for DataTables.
        return [
            'data' => $query->fetchAll(),
            'meta' => $meta
        ];
    }
}
