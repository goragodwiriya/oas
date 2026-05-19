<?php
/**
 * @filesource modules/order/controllers/setup.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Setup;

use Gcms\Api as ApiController;
use Kotchasan\Currency;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Order\Helper\Model as OrderHelper;

/**
 * API Orders List Controller (DataTable)
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
        'id',
        'order_no',
        'document_type',
        'total',
        'created_at',
        'updated_at'
    ];

    /**
     * Get custom parameters for table
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        $dateFrom = $request->get('date_from')->date() ?: $request->get('from')->date();
        $dateTo = $request->get('date_to')->date() ?: $request->get('to')->date();
        $documentType = strtoupper($request->get('document_type')->filter('a-zA-Z_'));

        return [
            'document_type' => $documentType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];
    }

    /**
     * Check authorization
     *
     * @param Request $request
     * @param object  $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_view_order', 'can_edit_order'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * Query data for DataTable
     *
     * @param array  $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params);
    }

    /**
     * Filters for the table response
     *
     * @param array  $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'document_type' => \Order\Helper\Model::getDocumentTypeOptions()
        ];
    }

    /**
     * Format rows for the order list table.
     *
     * @param array  $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $data = [];
        foreach ($datas as $row) {
            $row->total_display = Currency::format($row->total ?? 0, OrderHelper::getValueDecimals());
            $row->created_date = empty($row->created_at) ? '' : Date::format($row->created_at, 'd M Y H:i');
            $row->document_type_text = \Order\Helper\Model::getDocumentTypeText($row->document_type ?? '');

            $data[] = $row;
        }

        return $data;
    }

    /**
     * @param array $params
     * @param $login
     */
    protected function getOptions(array $params, $login)
    {
        return [
            'document_type' => \Order\Helper\Model::getDocumentType($params['document_type'])
        ];
    }

    /**
     * Get order status options
     *
     * @return array
     */
    public static function getOrderStatusOptions()
    {
        return [];
    }

    /**
     * Handle print action — open receipt in new tab
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handlePrintAction(Request $request, $login)
    {
        $row = json_decode($request->post('row')->toJson());
        if ($row && !empty($row->id)) {
            return $this->redirectResponse(
                WEB_URL.'export.php?module=order&typ=print&id='.(int) $row->id,
                '',
                200,
                0,
                'export'
            );
        }

        return $this->errorResponse('No order selected', 400);
    }

    /**
     * Handle edit action — redirect to order detail
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleEditAction(Request $request, $login)
    {
        $row = json_decode($request->post('row')->toJson());
        if ($row) {
            return $this->redirectResponse('/order?id='.$row->id);
        }
    }

    /**
     * Handle delete action
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Admin only', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No items selected', 400);
        }

        $removed = \Order\Setup\Model::remove($ids);

        return $this->successResponse(null, 'Deleted '.$removed.' order(s).');
    }

    /**
     * Handle status change actions
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleStatusAction(Request $request, $login)
    {
        return $this->errorResponse('Status workflow is disabled for order documents.', 400);
    }
}
