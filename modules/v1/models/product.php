<?php
/**
 * @filesource modules/v1/models/product.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace V1\Product;

use Kotchasan\ApiController;
use Kotchasan\Http\Request;

/**
 * api.php/v1/product
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คืนค่าหมวดหมู่ทั้งหมด
     *
     * @param Request $request
     *
     * @return array
     */
    public function categories(Request $request)
    {
        if (ApiController::validateMethod($request, 'GET')) {
            return \Inventory\Api\Model::categories($request);
        }
    }

    /**
     * คืนค่าสินค้าตามหมวดหมู่
     *
     * @param Request $request
     *
     * @return array
     */
    public function products(Request $request)
    {
        if (ApiController::validateMethod($request, 'GET')) {
            return \Inventory\Api\Model::products($request);
        }
    }

    /**
     * คืนค่าสินค้าที่เลือก
     *
     * @param Request $request
     *
     * @return array
     */
    public function get(Request $request)
    {
        if (ApiController::validateMethod($request, 'GET')) {
            return \Inventory\Api\Model::get($request);
        }
    }

    /**
     * ค้นหาสินค้า
     *
     * @param Request $request
     *
     * @return array
     */
    public function search(Request $request)
    {
        if (ApiController::validateMethod($request, 'GET')) {
            return \Inventory\Api\Model::products($request);
        }
    }
}
