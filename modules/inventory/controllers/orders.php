<?php
/**
 * @filesource modules/inventory/controllers/orders.php
 *
 * api/inventory/orders — ตารางเอกสาร
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Orders;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * ตารางเอกสาร
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
    protected $allowedSortColumns = ['id', 'order_no', 'order_date', 'due_date', 'grand_total'];

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
     * ตัวกรอง — status มาจาก query string ของเมนู (หนึ่งเมนูต่อชนิดเอกสาร)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'status' => $request->get('status')->filter('A-Z'),
            'from' => $request->get('from')->date(),
            'to' => $request->get('to')->date(),
            'customer_id' => $request->get('customer_id')->toInt()
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
        return Model::toDataTable($params);
    }

    /**
     * เติมข้อมูลที่หน้าเว็บต้องใช้ลงในแต่ละแถว
     *
     * ชื่อชนิดเอกสารเป็นข้อความจากฐานข้อมูล (แม่แบบ) ไม่ใช่คีย์ภาษา จึงส่งเป็น
     * ข้อความสำเร็จรูปได้เลย
     *
     * @param array       $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $templates = \Inventory\Document\Model::templates();
        foreach ($datas as $item) {
            $item->document_type_text = isset($templates[$item->document_type])
                ? $templates[$item->document_type]['topic']
                : $item->document_type;
        }

        return $datas;
    }

    /**
     * GET api/inventory/orders/page — ข้อมูลหัวหน้าของหน้ารายการเอกสาร
     *
     * หนึ่งหน้าคือเอกสารหนึ่งชนิดเสมอ (เหมือนระบบเดิม) หัวข้อ ปุ่มเพิ่ม และ
     * รายการชนิดในกล่องเลือก จึงต้องมาจากที่เดียวกันคือแม่แบบในฐานข้อมูล
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function page(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $status = $request->get('status')->filter('A-Z');
        $templates = \Inventory\Document\Model::templates();
        if ($status === '' || !isset($templates[$status])) {
            // ไม่ได้ระบุชนิด (หรือชนิดที่ไม่มีแม่แบบ) ให้ใช้ชนิดแรกที่เปิดใช้งาน
            foreach ($templates as $type => $template) {
                if (!empty($template['published'])) {
                    $status = $type;
                    break;
                }
            }
        }
        if ($status === '' || !isset($templates[$status])) {
            return $this->errorResponse('No data available', 404);
        }

        $mode = $templates[$status]['mode'];
        $statuses = [];
        foreach (\Inventory\Base\Model::statusesByMode() as $group => $items) {
            // กล่องเลือกมีเฉพาะชนิดฝั่งเดียวกัน (ซื้อ↔ซื้อ ขาย↔ขาย) และไม่มี
            // ตัวเลือก "ทั้งหมด" เพราะหนึ่งหน้าคือเอกสารหนึ่งชนิดเสมอ
            if ($group !== $mode) {
                continue;
            }
            foreach ($items as $type => $topic) {
                $statuses[] = ['value' => $type, 'text' => $topic, 'selected' => $type === $status];
            }
        }

        return $this->successResponse([
            'status' => $status,
            'topic' => $templates[$status]['topic'],
            'mode' => $mode,
            'statuses' => $statuses,
            'new_url' => '/inventory-order?status='.$status
        ], 'OK');
    }

    /**
     * ลบเอกสารที่เลือก
     *
     * ⚠️ ต้องลบผ่าน Document\Model::remove() เท่านั้น เพราะมันถอนผลของเอกสาร
     * ออกจากสมุดบัญชีให้ก่อน — ลบแถวตรง ๆ จะทำให้ยอดคงเหลือค้างผลของเอกสาร
     * ที่ไม่มีอยู่แล้ว ซึ่งเป็นข้อบกพร่องที่ระบบเดิมมีจริง
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, 'can_inventory_order')) {
            return $this->errorResponse('Permission required', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No data to delete', 400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (\Inventory\Document\Model::remove($id, $login->id)) {
                ++$deleted;
            }
        }
        if ($deleted === 0) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'inventory', 'Delete',
            '{LNG_Delete} {LNG_Document} ID : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload',
            Language::replace('Deleted :count items', [':count' => $deleted]), 200, 0, 'table');
    }

    /**
     * ยกเลิกเอกสารที่เลือก — ยอดกลับคืนแต่เอกสารยังอยู่ให้เปิดดูและพิมพ์ได้
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleCancelAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, 'can_inventory_order')) {
            return $this->errorResponse('Permission required', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            $id = $request->request('id')->toInt();
            $ids = $id > 0 ? [$id] : [];
        }
        if (empty($ids)) {
            return $this->errorResponse('No data available', 400);
        }

        $cancelled = 0;
        foreach ($ids as $id) {
            if (\Inventory\Document\Model::cancel($id, $login->id)) {
                ++$cancelled;
            }
        }

        return $this->redirectResponse('reload',
            Language::replace('Cancelled :count items', [':count' => $cancelled]), 200, 0, 'table');
    }
}
