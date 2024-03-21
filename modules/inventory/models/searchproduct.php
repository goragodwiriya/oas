<?php
/**
 * @filesource modules/inventory/models/searchproduct.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Searchproduct;

use Gcms\Login;
use Kotchasan\Database\Sql;
use Kotchasan\Http\Request;

/**
 * ค้นหาสินค้าจาก product_no
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ค้นหาสินค้าจาก product_no
     * คืนค่าเป็น JSON เพียงรายการเดียว
     *
     * @param Request $request
     */
    public function fromProductno(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                $product_no = $request->post('product_no')->topic();
                if ($product_no != '') {
                    $query = $this->db()->createQuery()
                        ->from('inventory V')
                        ->join('inventory_items I', 'INNER', array('I.inventory_id', 'V.id'))
                        ->where(array('I.product_no', $product_no))
                        ->toArray();
                    if ($request->post('typ')->toString() == 'buy') {
                        $search = $query->first('V.id', 'I.product_no', Sql::CONCAT(array('V.topic', 'I.topic'), 'topic', ' '), 'V.cost price', 'I.unit', 'V.vat');
                    } else {
                        $search = $query->first('V.id', 'I.product_no', Sql::CONCAT(array('V.topic', 'I.topic'), 'topic', ' '), 'I.price', 'I.unit', 'V.vat');
                    }
                }
                // คืนค่า JSON
                if ($search) {
                    echo json_encode($search);
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }
}
