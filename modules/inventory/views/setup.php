<?php
/**
 * @filesource modules/inventory/views/setup.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Setup;

use Kotchasan\Currency;
use Kotchasan\DataTable;
use Kotchasan\Http\Request;

/**
 * module=inventory-setup
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
     * ตาราง Inventory
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // หมวดหมู่
        $this->category = \Inventory\Category\Model::init(false);
        // ค่าที่ส่งมา
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
            'model' => \Inventory\Setup\Model::toDataTable($params),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('inventorySetup_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => $request->cookie('inventorySetup_sort', 'id desc')->toString(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id', 'count_stock', 'product_no'),
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('topic', 'product_no'),
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/inventory/model/setup/action',
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
                    'name' => 'category_id',
                    'text' => '{LNG_Category}',
                    'options' => array(0 => '{LNG_all items}') + $this->category->toSelect('category_id'),
                    'value' => $params['category_id']
                )
            ),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'topic' => array(
                    'text' => '{LNG_Topic}',
                    'sort' => 'topic'
                ),
                'category_id' => array(
                    'text' => '{LNG_Category}'
                ),
                'price' => array(
                    'text' => '{LNG_Unit price}',
                    'class' => 'center',
                    'sort' => 'price'
                ),
                'cost' => array(
                    'text' => '{LNG_Cost}',
                    'class' => 'right',
                    'sort' => 'cost'
                ),
                'stock' => array(
                    'text' => '{LNG_Stock}',
                    'class' => 'center',
                    'sort' => 'stock'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'topic' => array(
                    'class' => 'topic'
                ),
                'category_id' => array(
                    'class' => 'nowrap'
                ),
                'price' => array(
                    'class' => 'right'
                ),
                'cost' => array(
                    'class' => 'right'
                ),
                'stock' => array(
                    'class' => 'center'
                )
            ),
            /* ปุ่มแสดงในแต่ละแถว */
            'buttons' => array(
                array(
                    'class' => 'icon-report button orange',
                    'href' => $uri->createBackUri(array('module' => 'inventory-write', 'tab' => 'overview', 'id' => ':id')),
                    'text' => '{LNG_Detail}'
                ),
                array(
                    'class' => 'icon-edit button green',
                    'href' => $uri->createBackUri(array('module' => 'inventory-write', 'tab' => 'product', 'id' => ':id')),
                    'text' => '{LNG_Edit}'
                )
            ),
            /* ปุ่มเพิ่ม */
            'addNew' => array(
                'class' => 'float_button icon-new',
                'href' => $uri->createBackUri(array('module' => 'inventory-write', 'id' => 0)),
                'title' => '{LNG_Add} {LNG_Product}'
            )
        ));
        // save cookie
        setcookie('inventorySetup_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('inventorySetup_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
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
        $item['topic'] = '<span class=two_lines title="'.$item['topic'].'">'.$item['topic'].'</span>';
        $item['cost'] = Currency::format($item['cost']);
        $item['category_id'] = $this->category->get('category_id', $item['category_id']);
        $item['stock'] = $item['count_stock'] == 0 ? '{LNG_Unlimited}' : number_format($item['stock'] === null ? 0 : $item['stock']);
        return $item;
    }
}
