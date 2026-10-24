<?php
/**
 * @filesource modules/inventory/controllers/variants.php
 *
 * api/inventory/variants — ตารางแบบของสินค้าหนึ่งตัวในหน้าสินค้า (สี · ไซซ์ · รุ่นย่อย)
 *
 * ตารางอ่าน ?id=<แบบที่เปิดอยู่> จาก URL ของหน้า แล้วแสดงทุกแบบของสินค้าเดียวกัน
 * ปุ่มแก้ไข/เพิ่มเปิดหน้าต่างซ้อน inventory/variant-modal.html ผ่าน action=edit
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Variants;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * ตารางแบบสินค้า
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
    protected $allowedSortColumns = ['id', 'sku', 'topic', 'price', 'stock', 'sort_order'];

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
     * สินค้าของแบบที่เปิดอยู่
     *
     * @param Request $request
     * @param object  $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return ['product_id' => Model::productId($request->get('id')->toInt())];
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
     * ค่าตัวเลือกรวมเป็นข้อความเดียว และตัวเลข DECIMAL เป็นตัวเลข
     *
     * @param array       $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        foreach ($datas as $item) {
            $item->options = implode(' / ', array_filter([$item->option1, $item->option2, $item->option3], 'strlen'));
            $item->price = (float) $item->price;
            $item->cost = (float) $item->cost;
            $item->stock = (float) $item->stock;
        }

        return $datas;
    }

    /**
     * เปิดหน้าต่างแก้ไข/เพิ่มแบบ — id = แบบที่จะแก้ · id = 0 กับ variant = แบบใดก็ได้ของสินค้า (เพิ่มแบบใหม่)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleEditAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, 'can_manage_inventory')) {
            return $this->errorResponse('Permission required', 403);
        }
        $id = $request->request('id')->toInt();
        $productId = Model::productId($id > 0 ? $id : $request->request('variant')->toInt());
        $product = \Inventory\Product\Model::getProduct($productId);
        if ($product === null) {
            return $this->errorResponse('No data available', 404);
        }
        $data = [
            'id' => 0,
            'product_id' => $productId,
            'sku' => '',
            'option1' => '',
            'option2' => '',
            'option3' => '',
            'price' => 0,
            'is_active' => 1,
            // ของแบบใหม่เท่านั้น — หน่วยขายฐานและยอดยกมา
            'barcode' => '',
            'cost' => 0,
            'stock' => 0,
            'create_date' => date('Y-m-d')
        ];
        if ($id > 0) {
            $variant = null;
            foreach ($product['variants'] as $row) {
                if ((int) $row['id'] === $id) {
                    $variant = $row;
                }
            }
            if ($variant === null) {
                return $this->errorResponse('No data available', 404);
            }
            foreach (['sku', 'option1', 'option2', 'option3'] as $key) {
                $data[$key] = (string) $variant[$key];
            }
            $data['id'] = $id;
            $data['price'] = (float) $variant['price'];
            $data['is_active'] = (int) $variant['is_active'];
        }
        // ป้ายของช่องตัวเลือกคือชื่อตัวเลือกของสินค้า (สี · ไซซ์) — ไม่ได้ตั้งชื่อ = ไม่มีช่องนั้น
        foreach (['option1_name', 'option2_name', 'option3_name', 'count_stock', 'unit'] as $key) {
            $data[$key] = $product[$key] === null ? '' : $product[$key];
        }
        $data['count_stock'] = (int) $data['count_stock'];
        // ข้อมูลของฟอร์มแนบไปกับ action ด้วย — ปุ่ม "เพิ่มแบบ" (requestApi) ส่งคำตอบให้ ResponseHandler
        // โดยไม่มี context.data หน้าต่างจะได้ฟอร์มเปล่า (ปุ่มในแถวตารางมี context.data จึงไม่เจอ)
        $data['actions'] = [[
            'data' => $data,
            'type' => 'modal',
            'action' => 'show',
            'template' => 'inventory/variant-modal.html',
            'title' => Language::get($id > 0 ? 'Edit' : 'Add').' '.Language::get('Variant').' — '.$product['topic'],
            'titleClass' => 'icon-product'
        ]];

        return $this->successResponse($data, null);
    }

    /**
     * ลบแบบที่เลือก — แบบที่มีความเคลื่อนไหวหรือถูกใช้ในเอกสารลบไม่ได้ (ปิดการใช้งานแทน)
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
            $ids = [$request->request('id')->toInt()];
        }
        $removed = 0;
        $refused = [];
        foreach ($ids as $id) {
            try {
                if (\Inventory\Product\Model::removeVariant($id)) {
                    ++$removed;
                }
            } catch (\Exception $e) {
                $refused[] = $e->getMessage();
            }
        }
        if ($removed === 0) {
            return $this->errorResponse(empty($refused) ? 'Delete action failed' : $refused[0], 400);
        }
        \Index\Log\Model::add(0, 'inventory', 'Delete', '{LNG_Delete} {LNG_Variant} ID : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removed.' item(s)'.(empty($refused) ? '' : ' — '.$refused[0]),
            200, 0, 'inventoryVariants');
    }

    /**
     * เปิด/ปิดการขายแบบหนึ่ง (ของที่มีอยู่ยังอยู่ในสมุดบัญชีครบ)
     *
     * @param Request $request
     * @param object  $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleActiveAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, 'can_manage_inventory')) {
            return $this->errorResponse('Permission required', 403);
        }
        $id = $request->request('id')->toInt();
        $row = \Kotchasan\Model::createDB()->first(\Inventory\Base\Model::table('inventory'), ['id' => $id]);
        if (!$row) {
            return $this->errorResponse('No data available', 404);
        }
        $active = empty($row->is_active) ? 1 : 0;
        \Inventory\Product\Model::saveVariant((int) $row->product_id, $id, ['is_active' => $active]);

        return $this->successResponse(['id' => $id, 'is_active' => $active], 'OK');
    }
}
