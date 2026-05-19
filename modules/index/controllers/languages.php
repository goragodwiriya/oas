<?php
/**
 * @filesource modules/index/controllers/languages.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Languages;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * API Language Controller
 *
 * Handles language management endpoints
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
    protected $allowedSortColumns = ['id', 'key', 'th', 'en'];

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
        // Authorization check
        if (!ApiController::hasPermission($login, ['can_config'])) {
            return $this->errorResponse('Forbidden', 403);
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
    protected function toDataTable($params = [], $login = null)
    {
        return \Index\Languages\Model::toDataTable($params);
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
        foreach ($datas as $row => $rows) {
            foreach ($rows as $key => $value) {
                if ($rows->type === 'array' && !in_array($key, ['id', 'key', 'type'])) {
                    $data = json_decode($value, true);
                    if (is_array($data)) {
                        $datas[$row]->$key = implode(', ', $data);
                    } else {
                        $datas[$row]->$key = $value === null ? '' : (string) $value;
                    }
                }
            }
        }
        return $datas;
    }

    /**
     * Get columns for table response
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getColumns($params = [], $login = null)
    {
        $this->allowedSortColumns = ['id', 'key'];

        $columns = [
            [
                'field' => 'id',
                'label' => 'ID',
                'sort' => 'id',
                'type' => 'number',
                'i18n' => true
            ],
            [
                'field' => 'key',
                'label' => 'Key',
                'sort' => 'key',
                'searchable' => true,
                'formatter' => 'copyToClipboard',
                'i18n' => true
            ]
        ];

        foreach (\Index\Languages\Model::getLanguageColumns() as $language) {
            $columns[] = [
                'field' => $language,
                'label' => strtoupper($language),
                'sort' => $language,
                'i18n' => true
            ];
            $this->allowedSortColumns[] = $language;
        }

        return $columns;
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
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        $removeCount = \Index\Languages\Model::remove($ids);

        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'index', 'Delete', 'Delete Language ID(s) : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removeCount.' language(s) successfully');
    }

    /**
     * Handle edit action
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleEditAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();

        return $this->redirectResponse('/language?id='.$id);
    }

    /**
     * Handle import action - Import translations from JSON files and regenerate outputs.
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleImportAction(Request $request, $login)
    {
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $result = \Index\Languages\Model::importFromFile();

        if ($result['success']) {
            \Index\Log\Model::add(0, 'index', 'Import', 'Import Language: '.$result['message'], $login->id);
            return $this->redirectResponse('reload', $result['message'], 200, 1000, 'table');
        }

        return $this->errorResponse($result['message'], 400);
    }
}
