<?php
/**
 * @filesource modules/inventory/controllers/movements.php
 *
 * api/inventory/movements — สมุดบัญชีสต๊อกรวมทุกสินค้า (หน้า /inventory-movements)
 *
 * ⚠️ ต่างจาก api/inventory/stocks ที่ดูได้ทีละสินค้า — หน้านี้คือมุมของผู้ตรวจ
 * "เดือนนี้ของออกไปไหนบ้าง" ตอบไม่ได้ถ้าต้องเปิดดูทีละสินค้า
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Movements;

use Gcms\Api as ApiController;
use Inventory\Ledger\Model as Ledger;
use Kotchasan\Http\Request;

/**
 * ตารางสมุดบัญชีสต๊อก
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
    protected $allowedSortColumns = ['id', 'sku', 'movement_type', 'quantity', 'occurred_at'];

    /**
     * สิทธิ์ — สมุดบัญชีคือหลักฐาน คนที่ดูแลสต๊อกต้องเปิดดูได้
     *
     * @param Request $request
     * @param object  $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_stock', 'can_inventory_order'])) {
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
            'movement_direction' => $request->get('movement_direction')->filter('a-z'),
            'movement_type' => $request->get('movement_type')->filter('a-z_'),
            'reference_type' => $request->get('reference_type')->filter('a-z_'),
            'from' => $request->get('from')->date(),
            'to' => $request->get('to')->date()
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
        return Ledger::movements($params);
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
            $item->movement_type_text = Ledger::movementTypeLabel((string) $item->movement_type);
            $item->quantity = (float) $item->quantity;
            $item->unit_cost = $item->unit_cost === null ? null : (float) $item->unit_cost;
            $item->total_cost = $item->total_cost === null ? null : (float) $item->total_cost;
            // เครื่องหมายบอกทิศทาง — ตัวเลขในตารางเก็บเป็นบวกเสมอ ทิศทางอยู่คนละคอลัมน์
            // ถ้าไม่แสดงเครื่องหมาย คนอ่านจะบวกทุกแถวแล้วได้ยอดที่ไม่มีความหมาย
            $item->signed_quantity = $item->movement_direction === 'out'
                ? -$item->quantity
                : $item->quantity;
            // ลิงก์กลับไปที่เอกสารต้นทาง — สมุดบัญชีที่ตามกลับไม่ได้ ตรวจสอบไม่จบ
            $item->reference_url = $item->reference_type === 'order' && !empty($item->reference_id)
                ? '/inventory-order?id='.(int) $item->reference_id
                : '';
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
            'movement_direction' => Ledger::directionOptions(),
            'movement_type' => Ledger::movementTypeOptions(),
            'reference_type' => Ledger::referenceTypeOptions()
        ];
    }
}
