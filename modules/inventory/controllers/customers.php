<?php
/**
 * @filesource modules/inventory/controllers/customers.php
 *
 * api/inventory/customers — ตารางลูกค้า/ผู้ขาย และช่องค้นหาในเอกสาร
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Customers;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * ตารางลูกค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * คอลัมน์ที่ยอมให้เรียงได้
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'customer_no', 'company', 'name', 'province'];

    /**
     * สิทธิ์
     *
     * @param Request $request
     * @param object  $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * ตัวกรอง — type คือมุมที่เปิดดู (ลูกค้า / คู่ค้า / ทั้งหมด)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return ['type' => $request->get('type')->filter('a-z')];
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
        return Model::toDataTable($params);
    }

    /**
     * GET api/inventory/customers/autocomplete — ช่องค้นหาลูกค้าในเอกสาร
     *
     * ⚠️ ต้องคืน array ตรง ๆ ผ่าน successResponse ไม่ห่อคีย์เพิ่ม
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function autocomplete(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return $this->successResponse(
            Model::search($request->get('q')->topic(), $request->get('by')->filter('a-z_'))
        );
    }

    /**
     * ลบลูกค้าที่เลือก
     *
     * ลูกค้าที่มีเอกสารอยู่แล้วลบไม่ได้ — เอกสารเก่าต้องยังเปิดและพิมพ์ได้เสมอ
     * (หัวเอกสารเก็บ snapshot ไว้ก็จริง แต่การอ้าง customer_id ที่ไม่มีอยู่
     * ทำให้หน้าแก้ไขเอกสารเดิมพัง)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, 'can_manage_inventory')) {
            return $this->errorResponse('Permission required', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No data to delete', 400);
        }

        $db = \Kotchasan\Model::createDB();
        $deleted = 0;
        $inUse = [];
        foreach ($ids as $id) {
            if ($db->exists(\Inventory\Base\Model::table('orders'), ['customer_id' => $id])) {
                $inUse[] = $id;
                continue;
            }
            $deleted += $db->delete(\Inventory\Base\Model::table('customer'), ['id', $id]) ? 1 : 0;
        }

        if ($deleted === 0) {
            return $this->errorResponse(empty($inUse)
                ? 'Delete action failed'
                : 'ลบไม่ได้เพราะยังมีเอกสารที่อ้างถึงลูกค้ารายนี้อยู่', 400);
        }

        return $this->redirectResponse('reload', 'Deleted '.$deleted.' item(s)', 200, 0, 'table');
    }
}
