<?php
/**
 * @filesource modules/inventory/models/searchcustomer.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Searchcustomer;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * ค้นหาลูกค้าจาก customer_no
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ค้นหาลูกค้าจาก customer_no
     * คืนค่าเป็น JSON เพียงรายการเดียว
     *
     * @param Request $request
     */
    public function fromCustomerno(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && Login::isMember()) {
            try {
                $customer_no = $request->post('customer_no')->topic();
                if ($customer_no != '') {
                    $search = $this->db()->createQuery()
                        ->from('customer')
                        ->where(array('customer_no', $customer_no))
                        ->toArray()
                        ->first('id', 'company', 'customer_no');
                    // คืนค่า JSON
                    if ($search) {
                        echo json_encode($search);
                    }
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
    }
}
