<?php
/**
 * @filesource modules/inventory/views/inventory.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Inventory;

use Kotchasan\Currency;
use Kotchasan\DataTable;
use Kotchasan\Date;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Number;

/**
 * module=inventory-write&tab=inventory
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
     * @var float
     */
    private $quantity = 0;
    /**
     * @var float
     */
    private $vat = 0;
    /**
     * @var string
     */
    private $status;
    /**
     * @var array
     */
    private $inventory_status;
    /**
     * ตารางสต๊อกสินค้า
     *
     * @param object $product
     *
     * @return string
     */
    public function render(Request $request, $product)
    {
        $this->inventory_status = Language::get('INVENTORY_STATUS');
        $this->status = $request->request('status')->filter('A-Z');
        $params = array(
            'id' => $product->id,
            'status' => $this->status,
            'year' => $request->request('year', date('Y'))->toInt(),
            'month' => $request->request('month')->toInt()
        );
        // ตาราง
        $table = new DataTable(array(
            'id' => 'inventory_table',
            /* Uri */
            'uri' => $request->createUriWithGlobals(WEB_URL.'index.php'),
            /* แบ่งหน้า */
            'perPage' => $request->cookie('inventory_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => $request->cookie('inventory_sort', 'create_date desc')->toString(),
            /* Model */
            'model' => \Inventory\Inventory\Model::toDataTable($params),
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => array(
                array(
                    'name' => 'status',
                    'text' => '{LNG_Status}',
                    'options' => array('' => '{LNG_all items}') + $this->inventory_status,
                    'value' => $params['status']
                ),
                array(
                    'name' => 'year',
                    'text' => '{LNG_year}',
                    'options' => array('' => '{LNG_all items}')+\Inventory\Stock\Model::listYears($product->id),
                    'value' => $params['year']
                ),
                array(
                    'name' => 'month',
                    'text' => '{LNG_month}',
                    'options' => array(0 => '{LNG_all items}') + Language::get('MONTH_LONG'),
                    'value' => $params['month']
                )
            ),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* ฟังก์ชั่นแสดงผล Footer */
            'onCreateFooter' => array($this, 'onCreateFooter'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id', 'status', 'order_id', 'cut_stock'),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'create_date' => array(
                    'text' => '{LNG_Transaction date}',
                    'sort' => 'create_date'
                ),
                'order_no' => array(
                    'text' => '{LNG_Order No.}',
                    'sort' => 'order_no'
                ),
                'product_no' => array(
                    'text' => '{LNG_Product code}/{LNG_Barcode}',
                    'sort' => 'product_no'
                ),
                'quantity' => array(
                    'text' => '{LNG_Quantity}',
                    'class' => 'center'
                ),
                'unit' => array(
                    'text' => '{LNG_Unit}',
                    'class' => 'center'
                ),
                'price' => array(
                    'text' => '{LNG_Unit price}',
                    'class' => 'center'
                ),
                'vat' => array(
                    'text' => '{LNG_VAT}',
                    'class' => 'center'
                ),
                'total' => array(
                    'text' => '{LNG_Amount}',
                    'class' => 'center',
                    'sort' => 'total'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'quantity' => array(
                    'class' => 'center'
                ),
                'price' => array(
                    'class' => 'right'
                ),
                'unit' => array(
                    'class' => 'center'
                ),
                'vat' => array(
                    'class' => 'right'
                ),
                'total' => array(
                    'class' => 'right'
                )
            )
        ));
        // save cookie
        setcookie('inventory_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('inventory_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
        // คืนค่า section
        return Html::create('section', array(
            'id' => 'inventory',
            'innerHTML' => '<h3>{LNG_Inventory} '.$product->topic.'</h3>'.$table->render()
        ))->render();
    }

    /**
     * จัดรูปแบบการแสดงผลในแต่ละแถว
     *
     * @param array $item
     *
     * @return array
     */
    public function onRow($item, $o, $prop)
    {
        $this->vat += $item['vat'];
        $item['create_date'] = Date::format($item['create_date'], 'd M Y');
        $item['price'] = Currency::format($item['price']);
        $item['vat'] = Currency::format($item['vat']);
        $total = $item['total'];
        $quantity = $item['quantity'];
        if ($this->status == '') {
            if (in_array($item['status'], self::$cfg->in_stock_status)) {
                $total = -$item['total'];
                $item['quantity'] = '<span class=status'.(in_array($item['status'], self::$cfg->in_stock_status) ? 0 : 1).'>'.Number::format($item['quantity']).'</span>';
            } else {
                $quantity = -($item['quantity'] * $item['cut_stock']);
                $item['quantity'] = '<span class=status'.(in_array($item['status'], self::$cfg->in_stock_status) ? 0 : 1).'>-'.Number::format($item['quantity']).'</span>';
            }
        }
        $this->total += $total;
        $this->quantity += $quantity;
        $item['total'] = Currency::format($total);
        if ($item['order_id'] == 0) {
            // ยอดเริ่มต้น
            $item['order_no'] = empty($item['status']) ? '' : $this->inventory_status[$item['status']];
        } else {
            $item['order_no'] = '<a href="index.php?module=inventory-order&id='.$item['order_id'].'">'.$item['order_no'].'</a>';
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
        return '<tr><td class=right colspan=3>{LNG_Total}</td><td class=center>'.$this->quantity.'</td><td colspan=3 class=right>'.Currency::format($this->vat).'</td><td class=right>'.Currency::format($this->total).'</td></tr>';
    }
}
