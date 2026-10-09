<?php
/**
 * @filesource modules/inventory/controllers/templates.php
 *
 * api/inventory/templates — รายการแม่แบบเอกสาร
 *
 * แม่แบบเอกสารคือ "ข้อมูล" ไม่ใช่ "โค้ด" — แอดมินเพิ่ม แก้ หรือปิดชนิดเอกสาร
 * ได้เองจากหน้านี้ แล้วเมนู ตัวกรอง และพฤติกรรมสต๊อกจะตามทันทีทั้งระบบ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Templates;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * ตารางแม่แบบเอกสาร
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
    protected $allowedSortColumns = ['id', 'document_type', 'mode', 'topic', 'published'];

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
        if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
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
        return ['mode' => $request->get('mode')->filter('a-z')];
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
        $query = \Kotchasan\Model::createQuery()
            ->select('id', 'document_type', 'mode', 'topic', 'prefix', 'number_format', 'in_stock', 'cut_stock',
                'movement_type', 'due_date', 'published')
            ->from('inventory_template');
        if (in_array($params['mode'], ['buy', 'sell', 'borrow', 'repair'], true)) {
            $query->where(['mode', $params['mode']]);
        }

        return $query;
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
            'mode' => [
                ['value' => 'buy', 'text' => '{LNG_Purchase}'],
                ['value' => 'sell', 'text' => '{LNG_Sales}']
            ]
        ];
    }

    /**
     * ลบแม่แบบที่เลือก
     *
     * ⚠️ ระบบเดิมของ oms คัดลอกโค้ดหน้านี้มาจากหน้าสินค้าแล้วลืมแก้ชื่อตาราง
     * ผลคือ **กดลบแม่แบบเอกสารแล้วไปลบสินค้าทิ้งพร้อมรูป** ตรงนี้จึงระบุตาราง
     * ผ่าน Base::table('inventory_template') ให้ชัดเจนที่เดียว
     *
     * แม่แบบที่มีเอกสารใช้อยู่แล้วลบไม่ได้ ต้องปิดการใช้งานแทน ไม่งั้นเอกสารเก่า
     * จะกลายเป็นเอกสารไร้ชนิดที่เปิดดูไม่ได้
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
        $tableTemplate = \Inventory\Base\Model::table('inventory_template');
        $tableOrders = \Inventory\Base\Model::table('orders');
        $deleted = 0;
        $inUse = [];
        foreach ($ids as $id) {
            $row = $db->first($tableTemplate, ['id' => $id]);
            if (!$row) {
                continue;
            }
            if ($db->exists($tableOrders, ['document_type' => $row->document_type])) {
                $inUse[] = $row->document_type;
                continue;
            }
            $deleted += $db->delete($tableTemplate, ['id', $id]) ? 1 : 0;
        }
        \Inventory\Document\Model::clearCache();

        if ($deleted === 0) {
            return $this->errorResponse(empty($inUse)
                    ? 'Delete action failed'
                    : 'ลบไม่ได้เพราะยังมีเอกสารชนิด '.implode(', ', $inUse).' อยู่ในระบบ ให้ปิดการใช้งานแทน', 400);
        }

        return $this->redirectResponse('reload', 'Deleted '.$deleted.' item(s)', 200, 0, 'table');
    }
}
