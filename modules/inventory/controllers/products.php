<?php
/**
 * @filesource modules/inventory/controllers/products.php
 *
 * api/inventory/products — รายการสินค้าสำหรับเลือกใส่เอกสาร
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Products;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * ตารางสินค้า
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
    protected $allowedSortColumns = ['id', 'topic', 'product_code', 'sku', 'category_id', 'price', 'stock'];

    /**
     * สิทธิ์ — คนที่ทำเอกสารได้ต้องเห็นรายการสินค้า
     *
     * @param Request $request
     * @param object  $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory', 'can_sell'])) {
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
        return ['category_id' => $request->get('category_id')->topic()];
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
     * ตัวเลือกของตัวกรอง
     *
     * @param array       $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return ['category_id' => \Inventory\Category\Controller::init()->toOptions('category_id')];
    }

    /**
     * GET api/inventory/products/detail — ข้อมูลสินค้าหนึ่งแถวสำหรับใส่ในเอกสาร
     *
     * LineItemsManager เรียก endpoint นี้หลังผู้ใช้เลือกจากช่องค้นหา โดยส่ง
     * พารามิเตอร์ตามชื่อ data-role ของช่อง แล้วเอา `data` ที่ได้ไปสร้างแถวใหม่
     * คีย์ของ data ต้องตรงกับ data-field ของคอลัมน์ทุกตัว
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function detail(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory', 'can_sell'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $code = $request->get('q')->topic();
        if ($code === '') {
            $code = $request->get('product_code')->topic();
        }
        if ($code === '') {
            $code = $request->get('product_no')->topic();
        }

        $item = Model::detail($code);
        if ($item === null) {
            return $this->errorResponse('No data available', 404);
        }

        $quantity = $request->get('quantity')->toDouble();
        if ($quantity > 0) {
            $item['quantity'] = $quantity;
            $item['total'] = $quantity * $item['price'];
        }

        return $this->successResponse($item, 'OK');
    }

    /**
     * GET api/inventory/products/autocomplete — ช่องค้นหาสินค้าในเอกสาร
     *
     * ⚠️ ต้องคืน array ตรง ๆ ผ่าน successResponse ไม่ห่อคีย์เพิ่ม ไม่งั้น
     * Utils.options.normalizeSource ฝั่ง JS แกะไม่ออกแล้วช่องค้นหาจะว่างเปล่า
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
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory', 'can_sell'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return $this->successResponse(Model::search($request->get('q')->topic()));
    }
}
