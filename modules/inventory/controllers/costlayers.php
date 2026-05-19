<?php
/**
 * @filesource modules/inventory/controllers/costlayers.php
 */

namespace Inventory\Costlayers;

use Gcms\Api as ApiController;
use Inventory\Helper\Controller as InventoryHelper;
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
        'sku',
        'reference_type',
        'reference_no',
        'received_qty',
        'remaining_qty',
        'unit_cost',
        'currency',
        'received_at'
    ];

    /**
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
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'inventory_id' => $request->get('inventory_id')->toInt(),
            'inventory_item_id' => $request->get('inventory_item_id')->toInt(),
            'sku' => $request->get('sku')->topic(),
            'reference_type' => $request->get('reference_type')->filter('a-z_'),
            'layer_state' => $request->get('layer_state')->filter('a-z')
        ];
    }

    /**
     * @param array $params
     * @param object|null $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return \Inventory\Costlayers\Model::toDataTable($params);
    }

    /**
     * @param array $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $valueDecimals = InventoryHelper::getValueDecimals();
        foreach ($datas as $item) {
            $item->reference_type = empty($item->reference_type) ? '' : \Inventory\Costlayers\Model::humanizeToken((string) $item->reference_type);
            $item->layer_state = (float) $item->remaining_qty > 0 ? 'Open' : 'Closed';
            $item->received_qty = number_format((float) $item->received_qty, $valueDecimals, '.', ',');
            $item->remaining_qty = number_format((float) $item->remaining_qty, $valueDecimals, '.', ',');
            $item->unit_cost = number_format((float) $item->unit_cost, $valueDecimals, '.', ',');
        }

        return $datas;
    }

    /**
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'inventory_id' => array_merge([
                ['value' => '', 'text' => '{LNG_All items}']
            ], \Inventory\Helper\Controller::getInventoryOptions()),
            'inventory_item_id' => \Inventory\Costlayers\Model::getProductOptions(),
            'reference_type' => \Inventory\Costlayers\Model::getReferenceTypeOptions(),
            'layer_state' => \Inventory\Costlayers\Model::getLayerStateOptions()
        ];
    }
}