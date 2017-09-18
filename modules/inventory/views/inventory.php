<?php
/**
 * @filesource modules/inventory/views/inventory.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Inventory;

use \Kotchasan\Http\Request;
use \Kotchasan\Html;
use \Kotchasan\DataTable;
use \Kotchasan\Date;
use \Kotchasan\Currency;
use \Kotchasan\Language;
use \Kotchasan\ArrayTool;
use \Inventory\Stock\Model AS Stock;

/**
 * module=inventory-write&tab=inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
  private $total = 0;
  private $quantity = 0;

  /**
   * ตารางสต๊อคสินค้า
   *
   * @param array $product
   * @return string
   */
  public function render(Request $request, $product)
  {
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
      'model' => \Inventory\Stock\Model::toDataTable($product['id']),
      /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
      'filters' => array(
        'status' => array(
          'name' => 'status',
          'default' => '',
          'text' => '{LNG_Status}',
          'options' => Language::get('INVENTORY_STATUS'),
          'value' => $request->request('status', 'OUT')->filter('A-Z')
        ),
        'YEAR(`create_date`)' => array(
          'name' => 'year',
          'default' => 0,
          'text' => '{LNG_year}',
          'options' => ArrayTool::merge(array('' => '{LNG_all items}'), Stock::listYears($product['id'])),
          'value' => $request->request('year', date('Y'))->toInt()
        ),
        'MONTH(`create_date`)' => array(
          'name' => 'month',
          'default' => 0,
          'text' => '{LNG_month}',
          'options' => ArrayTool::merge(array(0 => '{LNG_all items}'), Language::get('MONTH_LONG')),
          'value' => $request->request('month')->toInt()
        ),
      ),
      /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
      'onRow' => array($this, 'onRow'),
      /* ฟังก์ชั่นแสดงผล Footer */
      'onCreateFooter' => array($this, 'onCreateFooter'),
      /* คอลัมน์ที่ไม่ต้องแสดงผล */
      'hideColumns' => array('id', 'status', 'order_id'),
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
        'quantity' => array(
          'text' => '{LNG_Quantity}',
          'class' => 'center'
        ),
        'price' => array(
          'text' => '{LNG_Unit Price}',
          'class' => 'center'
        ),
        'total' => array(
          'text' => '{LNG_Total}',
          'class' => 'center',
          'sort' => 'total'
        ),
      ),
      /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
      'cols' => array(
        'quantity' => array(
          'class' => 'center'
        ),
        'price' => array(
          'class' => 'right'
        ),
        'total' => array(
          'class' => 'right'
        ),
      )
    ));
    // save cookie
    setcookie('inventory_perPage', $table->perPage, time() + 3600 * 24 * 365, '/');
    setcookie('inventory_sort', $table->sort, time() + 3600 * 24 * 365, '/');
    $table->script('initModal("inventory_table");');
    // คืนค่า section
    return Html::create('section', array(
          'id' => 'inventory',
          'innerHTML' => '<h3>{LNG_Inventory} '.$product['topic'].' {LNG_Product Code} '.$product['product_no'].'</h3>'.$table->render()
        ))
        ->render();
  }

  /**
   * จัดรูปแบบการแสดงผลในแต่ละแถว
   *
   * @param array $item
   * @return array
   */
  public function onRow($item, $o, $prop)
  {
    $this->total += $item['total'];
    $this->quantity += $item['quantity'];
    $item['create_date'] = Date::format($item['create_date'], 'd M Y');
    $item['quantity'] = '<span class=status'.($item['status'] == 'IN' ? 0 : 1).'>'.number_format($item['quantity']).'</span>';
    $item['price'] = Currency::format($item['price']);
    $item['total'] = Currency::format($item['total']);
    if ($item['order_id'] == 0) {
      // ยอดเริ่มต้น
      $item['order_no'] = '{LNG_Beginning Inventory}';
    } else {
      $item['order_no'] = '<a href="index.php?module=inventory-'.($item['status'] == 'IN' ? 'buy' : 'sell').'&id='.$item['order_id'].'">'.$item['order_no'].'</a>';
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
    return '<tr><td class=right colspan=2>{LNG_Total}</td><td class=center>'.number_format($this->quantity).'</td><td></td><td class=right>'.Currency::format($this->total).'</td></tr>';
  }
}