<?php
/**
 * @filesource modules/inventory/controllers/stockmovements.php
 */

namespace Inventory\Stockmovements;

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
        'movement_direction',
        'movement_type',
        'reference_type',
        'reference_no',
        'quantity',
        'unit_cost',
        'total_cost',
        'occurred_at'
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
            'movement_direction' => $request->get('movement_direction')->filter('a-z'),
            'movement_type' => $request->get('movement_type')->filter('a-z_'),
            'reference_type' => $request->get('reference_type')->filter('a-z_')
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
        return \Inventory\Stockmovements\Model::toDataTable($params);
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
            $item->movement_direction = strtoupper((string) $item->movement_direction);
            $item->movement_type = \Inventory\Stockmovements\Model::humanizeToken((string) $item->movement_type);
            $item->reference_type = empty($item->reference_type) ? '' : \Inventory\Stockmovements\Model::humanizeToken((string) $item->reference_type);
            $item->quantity = number_format((float) $item->quantity, $valueDecimals, '.', ',');
            $item->unit_cost = number_format((float) $item->unit_cost, $valueDecimals, '.', ',');
            $item->total_cost = number_format((float) $item->total_cost, $valueDecimals, '.', ',');
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
            'inventory_id' => \Inventory\Stockmovements\Model::getInventoryOptions(),
            'inventory_item_id' => \Inventory\Stockmovements\Model::getProductOptions(),
            'movement_direction' => \Inventory\Stockmovements\Model::getDirectionOptions(),
            'movement_type' => \Inventory\Stockmovements\Model::getMovementTypeOptions(),
            'reference_type' => \Inventory\Stockmovements\Model::getReferenceTypeOptions()
        ];
    }
}