<?php
/**
 * @filesource modules/index/controllers/permission.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Permission;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Permission Controller
 *
 * Handles permission management endpoints
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
    protected $allowedSortColumns = ['id', 'name'];
    /**
     * Check authorization for user management
     * Only admins can access, demo mode is blocked
     *
     * @param Request $request
     * @param $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        // Check if user is admin
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

        return true;
    }

    /**
     * Get custom parameters for permission table
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'status' => $request->get('status')->number()
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
    protected function toDataTable($params, $login = null)
    {
        return \Index\Permission\Model::toDataTable($params);
    }

    /**
     * Get columns for table response
     * Override this method to add custom columns
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getColumns(array $params, $login)
    {
        $columns = [['field' => 'name', 'label' => 'Name', 'i18n' => true, 'searchable' => true, 'sort' => true]];
        foreach (\Gcms\Controller::getPermissionOptions($login) as $item) {
            $columns[] = ['field' => $item['value'], 'label' => $item['text'], 'i18n' => true, 'cellElement' => 'checkbox', 'class' => 'center', 'cellClass' => 'checkbox'];
        }
        return $columns;
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
            'status' => \Gcms\Controller::getUserStatusOptions()
        ];
    }

    /**
     * Handle inactive action -send login approval notification
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleUpdateAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        // Get selected user IDs
        $ids = $request->post('ids', [])->toInt();
        $permission = $request->post('field')->filter('a-z0-9_.');
        $value = $request->post('value')->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        // Update permission
        $user = \Index\Permission\Model::updatePermission((int) $ids[0], $permission, $value);

        if (!$user) {
            return $this->errorResponse('No users selected', 400);
        }

        // Log the action
        \Index\Log\Model::add(0, 'index', 'Save', 'Update permission: '.$user, $login->id);

        // Return success message
        return $this->notificationResponse('Updated permission: '.$user);
    }

    /**
     * Format user list with additional display fields
     *
     * @param array $users
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $result = [];
        foreach ($datas as $row) {
            $item = [
                'id' => $row->id,
                'name' => $row->name
            ];
            $permission = \Index\Auth\Model::parsePermission($row->permission);
            foreach (\Gcms\Controller::getPermissionOptions($login) as $options) {
                // Guard against missing keys to avoid PHP notices (which can send output before headers)
                if (is_array($options) && isset($options['value'])) {
                    $key = $options['value'];
                    $item[$key] = in_array($key, $permission) ? 'true' : 'false';
                }
            }
            $result[] = $item;
        }
        return $result;
    }
}
