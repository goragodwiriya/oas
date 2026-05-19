<?php
/**
 * @filesource modules/inventory/controllers/products.php
 */

namespace Inventory\Products;

use Gcms\Api as ApiController;
use Kotchasan\Currency;
use Kotchasan\Http\Request;

class Controller extends \Gcms\Table
{
    /**
     * Allowed sort columns.
     *
     * @var array
     */
    protected $allowedSortColumns = [
        'id',
        'topic',
        'category_id',
        'first_sku',
        'item_count',
        'total_stock',
        'inuse'
    ];

    /**
     * Permission check.
     *
     * @param Request $request
     * @param object $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * Request filters.
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'category_id' => $request->get('category_id')->topic(),
            'inuse' => $request->get('inuse')->filter('0-1')
        ];
    }

    /**
     * Query data.
     *
     * @param array $params
     * @param object|null $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params);
    }

    /**
     * Append the first room image to each row.
     *
     * @param array $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        foreach ($datas as $item) {
            $item->cost = Currency::format($item->cost, self::$cfg->value_decimals);
            $item->item_count = number_format((float) $item->item_count);
            $item->total_stock = number_format((float) $item->total_stock, self::$cfg->value_decimals);
            $item->first_image_url = \Inventory\Helper\Controller::getInventoryFirstImageUrl((int) $item->id);
        }

        return $datas;
    }

    /**
     * Filter definitions.
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        $categories = \Inventory\Category\Controller::init();

        return [
            'category_id' => $categories->toOptions('category_id', true, null, ['' => '{LNG_All items}']),
            'inuse' => \Inventory\Helper\Controller::getInuseOptions()
        ];
    }

    /**
     * Get product details for edit modal.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleEditAction(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $data = \Inventory\Product\Model::get($request->post('id')->toInt());
        if ($data === null) {
            return $this->redirectResponse('/404', 'No data available', 404);
        }

        $data->tableid = $request->post('tableId')->filter('a-zA-Z_');

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
    }

    /**
     * Open item rows modal.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleItemsAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();
        if (\Inventory\Product\Model::getRecord($id) === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->redirectResponse('/inventory-items?id='.$id);
    }

    /**
     * Open stock movement page filtered to an product.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleStockMovementsAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();
        if (\Inventory\Product\Model::getRecord($id) === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->redirectResponse('/inventory-stock-movements?inventory_id='.$id);
    }

    /**
     * Open cost layers page filtered to an product.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleCostLayersAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();
        if (\Inventory\Product\Model::getRecord($id) === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->redirectResponse('/inventory-cost-layers?inventory_id='.$id);
    }

    /**
     * Delete products.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_manage_inventory', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No data to delete', 400);
        }

        $removed = \Inventory\Product\Model::remove($ids);
        if ($removed === 0) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'inventory', 'Delete', 'Deleted inventory ID(s): '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removed.' inventory product(s) successfully', 200, 0, 'table');
    }

    /**
     * Toggle active status.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleActiveAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_manage_inventory', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $product = \Inventory\Product\Model::toggleInuse($request->post('id')->toInt());
        if ($product === null) {
            return $this->errorResponse('No data available', 404);
        }

        \Index\Log\Model::add($product->id, 'inventory', 'Status', ((int) $product->inuse === 1 ? 'Activated product' : 'Deactivated product').': '.$product->topic, $login->id);

        return $this->redirectResponse('reload', 'Saved successfully', 200, 0, 'table');
    }
}
