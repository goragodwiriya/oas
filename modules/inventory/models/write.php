<?php
/**
 * @filesource modules/inventory/models/write.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Write;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * ข้อมูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูลสินค้าที่ $id
   * $id = 0 หมายถึงสินค้าใหม่
   *
   * @param int $id
   * @return array|null คืนค่า Object ของข้อมูล ไม่พบคืนค่า null
   */
  public static function get($id)
  {
    if ($id > 0) {
      // query ข้อมูลที่เลือก
      $model = new \Kotchasan\Model;
      $query = $model->db()->createQuery();
      return $query->from('product P')
          ->join('category C', 'LEFT', array(
            array('C.type', 0),
            array('C.category_id', 'P.category_id')
          ))
          ->where(array(
            array('P.id', $id)
          ))
          ->toArray()
          ->first('P.*', 'C.topic category');
    } else {
      // ใหม่
      return array(
        'id' => 0,
        'product_no' => '',
        'topic' => '',
        'description' => '',
        'category' => '',
        'price' => 0,
        'unit' => '',
        'vat' => 0,
        'buy_price' => 0,
        'inventory' => 0,
        'count_stock' => 1
      );
    }
  }

  /**
   * บันทึกข้อมูล
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, can_manage_inventory, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
      if (Login::checkPermission($login, 'can_manage_inventory') && Login::notDemoMode($login)) {
        // รับค่าจากการ POST
        $product = array(
          'product_no' => $request->post('write_product_no')->topic(),
          'topic' => $request->post('write_topic')->topic(),
          'description' => $request->post('write_description')->topic(),
          'price' => $request->post('write_price')->toDouble(),
          'unit' => $request->post('write_unit')->topic(),
          'vat' => $request->post('write_vat')->toInt(),
          'count_stock' => $request->post('write_count_stock')->toInt(),
        );
        $inventory = array(
          'price' => $request->post('write_buy_price')->toDouble(),
          'quantity' => $request->post('write_quantity')->toInt(),
          'vat' => $request->post('write_buy_vat')->toInt(),
          'member_id' => $login['id'],
          'create_date' => $request->post('write_create_date')->date().date(' H:i:s'),
        );
        // database connection
        $db = $this->db();
        // อ่านข้อมูลที่เลือก
        $index = self::get($request->post('write_id')->toInt());
        if (!$index) {
          // ไม่พบข้อมูลที่แก้ไข
          $ret['alert'] = Language::get('Sorry, Item not found It&#39;s may be deleted');
        } else {
          // ตาราง product
          $table_product = $this->getTableName('product');
          if (empty($product['product_no'])) {
            // ถ้าไม่ได้กรอก product_no มา สร้างเลข running number
            $product['product_no'] = \Inventory\Number\Model::get($index['id'], 'product_no', $table_product, 'product_no');
          } else {
            // ตรวจสอบ product_no ซ้ำ
            $search = $db->first($table_product, array(
              array('product_no', $product['product_no'])
            ));
            if ($search !== false && $index['id'] != $search->id) {
              $ret['ret_write_product_no'] = Language::replace('This :name already exist', array(':name' => Language::get('Product Code')));
            }
          }
          // ตรวจสอบ topic ซ้ำ
          if (empty($product['topic'])) {
            $ret['ret_write_topic'] = 'Please fill in';
          } else {
            $search = $db->first($table_product, array(
              array('topic', $product['topic'])
            ));
            if ($search !== false && $index['id'] != $search->id) {
              $ret['ret_write_opic'] = Language::replace('This :name already exist', array(':name' => Language::get('Product name')));
            }
          }
          if (empty($ret)) {
            // หมวดหมู่สินค้า
            $product['category_id'] = \Inventory\Category\Model::newCategory($request->post('write_category')->topic());
            // หน่วยสินค้า
            \Inventory\Category\Model::newUnit($product['unit']);
            // save product
            $product['last_update'] = time();
            if ($index['id'] == 0) {
              // ใหม่
              $product['id'] = $db->insert($table_product, $product);
              // บันทึก inventory
              if ($inventory['quantity'] > 0) {
                $table_inventory = $this->getTableName('stock');
                $inventory['product_id'] = $product['id'];
                $inventory['order_id'] = 0;
                $inventory['status'] = 'IN';
                $inventory['total'] = $inventory['price'] * $inventory['quantity'];
                if ($inventory['vat'] > 0) {
                  $vat = (double)number_format(\Kotchasan\Currency::calcVat($inventory['total'], self::$cfg->vat, $inventory['vat'] == 1), 2);
                  if ($inventory['vat'] == 1) {
                    // ราคาสินค้าไม่รวม vat
                    $inventory['vat'] = (double)number_format(\Kotchasan\Currency::calcVat($inventory['total'], self::$cfg->vat, true), 2);
                  } else {
                    // ราคาสินค้ารวม vat
                    $inventory['vat'] = (double)number_format(\Kotchasan\Currency::calcVat($inventory['total'], self::$cfg->vat, false), 2);
                    $inventory['total'] = $inventory['total'] - $inventory['vat'];
                  }
                }
                // บันทึก
                $db->insert($table_inventory, $inventory);
              }
            } else {
              // แก้ไข
              $db->update($table_product, array(
                array('id', $index['id'])
                ), $product);
            }
            // คืนค่า
            $ret['alert'] = Language::get('Saved successfully');
            if ($request->post('modal')->toString() == 'xhr') {
              // ปิด modal
              $ret['modal'] = 'close';
              // คืนค่าข้อมูล
              $ret['product_no'] = $product['product_no'];
            } elseif ($index['id'] == 0) {
              // ไปหน้าแรก แสดงรายการใหม่
              $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-setup', 'id' => null, 'page' => null));
            } else {
              // ไปหน้าเดิม แสดงรายการ
              $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-setup', 'id' => null));
            }
            // เคลียร์
            $request->removeToken();
          }
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }
}