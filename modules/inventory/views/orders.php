<?php
/**
 * @filesource modules/inventory/views/orders.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Orders;

use Kotchasan\Currency;
use Kotchasan\DataTable;
use Kotchasan\Date;
use Kotchasan\Http\Request;

/**
 * module=inventory-orders
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * @var float
     */
    private $total = 0;

    /**
     * รายการ Order
     *
     * @param Request $request
     * @param object  $owner
     *
     * @return string
     */
    public function render(Request $request, $owner)
    {
        // URL สำหรับส่งให้ตาราง
        $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
        // ตาราง
        $table = new DataTable(array(
            /* Uri */
            'uri' => $uri,
            /* Model */
            'model' => \Inventory\Orders\Model::toDataTable($owner),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('orders_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => $request->cookie('orders_sort', 'order_date desc')->toString(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* ฟังก์ชั่นแสดงผล Footer */
            'onCreateFooter' => array($this, 'onCreateFooter'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id', 'customer_id', 'status'),
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('order_no', 'company'),
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/inventory/model/orders/action',
            'actionCallback' => 'dataTableActionCallback',
            'actions' => array(
                array(
                    'id' => 'action',
                    'class' => 'ok',
                    'text' => '{LNG_With selected}',
                    'options' => array(
                        'delete' => '{LNG_Delete}'
                    )
                )
            ),
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => array(
                array(
                    'type' => 'date',
                    'name' => 'from',
                    'text' => '{LNG_From}',
                    'value' => $owner->from
                ),
                array(
                    'type' => 'date',
                    'name' => 'to',
                    'text' => '{LNG_To}',
                    'value' => $owner->to
                ),
                array(
                    'name' => 'status',
                    'text' => '{LNG_Type}',
                    'options' => $owner->order_status,
                    'value' => $owner->status
                )
            ),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'order_date' => array(
                    'text' => '{LNG_Transaction date}',
                    'sort' => 'order_date'
                ),
                'order_no' => array(
                    'text' => '{LNG_Order No.}',
                    'sort' => 'order_no'
                ),
                'company' => array(
                    'text' => '{LNG_Supplier}',
                    'sort' => 'company'
                ),
                'total' => array(
                    'text' => '{LNG_Amount}',
                    'class' => 'center'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'total' => array(
                    'class' => 'right'
                )
            ),
            /* ปุ่มแสดงในแต่ละแถว */
            'buttons' => array(
                'print' => array(
                    'class' => 'icon-print button brown notext',
                    'href' => WEB_URL.'export.php?module=inventory-export&typ=print&amp;id=:id',
                    'target' => '_export',
                    'title' => '{LNG_Print} '.$owner->order_status[$owner->status]
                ),
                'edit' => array(
                    'class' => 'icon-edit button green notext',
                    'href' => $uri->createBackUri(array('module' => 'inventory-order', 'id' => ':id')),
                    'title' => '{LNG_Edit}'
                )
            ),
            /* ปุ่มเพิ่ม */
            'addNew' => array(
                'class' => 'float_button icon-new',
                'href' => $uri->createBackUri(array('module' => 'inventory-order', 'id' => '0', 'status' => $owner->status)),
                'title' => '{LNG_Add} '.$owner->order_status[$owner->status]
            )
        ));
        // save cookie
        setcookie('orders_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('orders_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
        // คืนค่า HTML
        return $table->render();
    }

    /**
     * จัดรูปแบบการแสดงผลในแต่ละแถว
     *
     * @param array  $item ข้อมูลแถว
     * @param int    $o    ID ของข้อมูล
     * @param object $prop กำหนด properties ของ TR
     *
     * @return array คืนค่า $item กลับไป
     */
    public function onRow($item, $o, $prop)
    {
        $this->total += $item['total'];
        if ($item['order_date'] == date('Y-m-d')) {
            $prop->class = 'bg3';
        }
        $item['order_date'] = Date::format($item['order_date'], 'd M Y');
        $item['total'] = Currency::format($item['total']);
        if ($item['customer_id'] == 0) {
            $item['company'] = '{LNG_Cash}';
        }
        return $item;
    }

    /**
     * ฟังก์ชั่นสร้างแถวของ footer
     *
     * @return string
     */
    public function onCreateFooter()
    {
        return '<tr><td></td><td class="check-column"><a class="checkall icon-uncheck" title="{LNG_check all}"></a></td><td class=right colspan=2>{LNG_Total}</td><td class=right>'.Currency::format($this->total).'</td><td></td></tr>';
    }
}
