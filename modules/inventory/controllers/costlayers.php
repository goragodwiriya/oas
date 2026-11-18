<?php
/**
 * @filesource modules/inventory/controllers/costlayers.php
 *
 * api/inventory/costlayers — ชั้นต้นทุน FIFO (หน้า /inventory-cost-layers)
 *
 * ⚠️ ต้นทุนขายที่ระบบคิดให้ มาจากชั้นพวกนี้ล้วน ๆ ถ้าเปิดดูไม่ได้ ก็ยืนยันกำไร
 * ไม่ได้ — "ทำไมต้นทุนชิ้นนี้ 120 ทั้งที่ซื้อมา 100" ตอบได้ที่หน้านี้หน้าเดียว
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Costlayers;

use Gcms\Api as ApiController;
use Inventory\Ledger\Model as Ledger;
use Kotchasan\Http\Request;

/**
 * ตารางชั้นต้นทุน
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * คอลัมน์ที่เรียงได้ (กัน SQL injection)
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'sku', 'received_qty', 'remaining_qty', 'unit_cost', 'received_at'];

    /**
     * สิทธิ์ — ต้นทุนเป็นข้อมูลทางการเงิน ไม่เปิดให้คนขายหน้าร้าน
     *
     * @param Request $request
     * @param object  $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_stock'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * ตัวกรอง
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'inventory_id' => $request->get('inventory_id')->toInt(),
            'remaining' => $request->get('remaining')->filter('a-z')
        ];
    }

    /**
     * Query ข้อมูล
     *
     * @param array       $params
     * @param object|null $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Ledger::costLayers($params);
    }

    /**
     * แปลงข้อมูลให้อ่านออก
     *
     * @param array       $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        foreach ($datas as $item) {
            foreach (['received_qty', 'remaining_qty', 'unit_cost', 'remaining_value'] as $column) {
                $item->{$column} = (float) $item->{$column};
            }
            $item->is_open = $item->remaining_qty > 0;
        }

        return $datas;
    }

    /**
     * ตัวเลือกของตัวกรอง
     *
     * @param array       $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'inventory_id' => Ledger::productOptions(),
            'remaining' => [
                ['value' => '', 'text' => '{LNG_All}'],
                ['value' => 'open', 'text' => '{LNG_Layers still holding stock}'],
                ['value' => 'closed', 'text' => '{LNG_Fully consumed layers}']
            ]
        ];
    }
}
