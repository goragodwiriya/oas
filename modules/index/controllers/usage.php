<?php
/**
 * @filesource modules/index/controllers/usage.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Usage;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Usage Controller
 *
 * Handles usage management endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * Allowed sort columns for SQL injection prevention
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'created_at', 'topic', 'name'];

    /**
     * Get custom parameters for data table
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'src' => $request->get('src')->filter('a-z'),
            'from' => $request->get('from')->date(),
            'to' => $request->get('to')->date(),
            'member_id' => ApiController::hasPermission($login, ['can_view_usage_history']) ? 0 : $login->id
        ];
    }

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login)
    {
        return \Index\Usage\Model::toDataTable($params);
    }

    /**
     * Get filters for table response
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters($params, $login)
    {
        return [
            'src' => \Index\Usage\Model::getModuleOptions()
        ];
    }

    /**
     * Runtime table options.
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getOptions(array $params, $login)
    {
        $isSuperAdmin = ApiController::isSuperAdmin($login);

        return [
            '_table' => [
                'showCheckbox' => $isSuperAdmin,
                'actions' => $isSuperAdmin ? [
                    'delete' => 'Delete'
                ] : [],
                'actionButton' => $isSuperAdmin ? 'Process|btn-success' : null
            ]
        ];
    }

    /**
     * Handle delete action
     *
     * @param Request $request
     * @param object $login
     * @return Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        $removeCount = \Index\Usage\Model::remove($ids);

        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'index', 'Delete', 'Delete Log ID(s) : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removeCount.' log(s) successfully');
    }
}
