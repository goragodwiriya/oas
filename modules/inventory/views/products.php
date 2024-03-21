<?php
/**
 * @filesource modules/inventory/views/products.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Products;

use Kotchasan\Currency;
use Kotchasan\DataTable;
use Kotchasan\Http\Request;

/**
 * module=inventory-products
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * @var object
     */
    private $category;

    /**
     * ตารางรายการ สินค้า
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // หมวดหมู่
        $this->category = \Inventory\Category\Model::init(false);
        $params = array(
            'category_id' => $request->request('category_id')->toInt()
        );
        // URL สำหรับส่งให้ตาราง
        $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
        // ตาราง
        $table = new DataTable(array(
            /* Uri */
            'uri' => $uri,
            /* Model */
            'model' => \Inventory\Products\Model::toDataTable($params),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('inventoryProducts_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => $request->cookie('inventoryProducts_sort', 'id desc')->toString(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id', 'unit', 'count_stock'),
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('product_no', 'topic'),
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => array(
                array(
                    'name' => 'category_id',
                    'text' => '{LNG_Category}',
                    'options' => array(0 => '{LNG_all items}') + $this->category->toSelect('category_id'),
                    'value' => $params['category_id']
                )
            ),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'topic' => array(
                    'text' => '{LNG_Product name}',
                    'sort' => 'topic'
                ),
                'product_no' => array(
                    'text' => '{LNG_Product code}/{LNG_Barcode}'
                ),
                'category_id' => array(
                    'text' => '{LNG_Category}'
                ),
                'price' => array(
                    'text' => '{LNG_Unit price}',
                    'class' => 'center',
                    'sort' => 'price'
                ),
                'stock' => array(
                    'text' => '{LNG_Stock}',
                    'class' => 'center',
                    'sort' => 'stock'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'category_id' => array(
                    'class' => 'nowrap'
                ),
                'price' => array(
                    'class' => 'right nowrap'
                ),
                'stock' => array(
                    'class' => 'center'
                )
            )
        ));
        // save cookie
        setcookie('inventoryProducts_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('inventoryProducts_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
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
        $item['product_no'] = '<img style="max-width:none" src="data:image/png;base64,'.base64_encode(\Kotchasan\Barcode::create($item['product_no'], 40, 9)->toPng()).'">';
        $item['topic'] = '<span class=two_lines title="'.$item['topic'].'">'.$item['topic'].'</span>';
        $item['price'] = Currency::format($item['price']).(empty($item['unit']) ? '' : ' / '.$item['unit']);
        $item['category_id'] = $this->category->get('category_id', $item['category_id']);
        $item['stock'] = $item['count_stock'] == 0 ? '{LNG_Unlimited}' : number_format($item['stock'] === null ? 0 : $item['stock']);
        return $item;
    }
}
