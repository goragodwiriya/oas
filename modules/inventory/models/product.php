<?php
/**
 * @filesource modules/inventory/models/product.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Product;

/**
 * เพิ่ม/แก้ไข ข้อมูล Inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * เพิ่มสินค้าใหม่
     *
     * @param array $save
     *
     * @return int
     */
    public static function create($save)
    {
        // product
        $product = array(
            'topic' => $save['topic'],
            'cost' => isset($save['cost']) ? $save['cost'] : 0,
            'vat' => isset($save['vat']) ? $save['vat'] : 0,
            'count_stock' => isset($save['count_stock']) ? $save['count_stock'] : 1,
            'inuse' => 1,
            'create_date' => isset($save['create_date']) ? $save['create_date'] : date('Y-m-d'),
            'stock' => $save['stock']
        );
        // หมวดหมู่สินค้า
        if (isset($save['category'])) {
            $product['category_id'] = \Inventory\Category\Model::save('category_id', $save['category']);
        } elseif (isset($save['category_id'])) {
            $product['category_id'] = $save['category_id'];
        }
        // Model
        $model = new \Kotchasan\Model;
        // save product
        $inventory_id = $model->db()->insert($model->getTableName('inventory'), $product);
        if (!empty($save['product_no']) && (!empty($save['stock']) || empty($save['count_stock']))) {
            // inventory_items
            $model->db()->insert($model->getTableName('inventory_items'), array(
                'product_no' => $save['product_no'],
                'inventory_id' => $inventory_id,
                'topic' => '',
                // ราคาขาย
                'price' => $save['price'],
                'cut_stock' => 1,
                'unit' => $save['unit'],
                'instock' => 1
            ));
            if ($save['stock'] > 0) {
                $stock = array(
                    'order_id' => 0,
                    'member_id' => $save['member_id'],
                    'inventory_id' => $inventory_id,
                    'product_no' => $save['product_no'],
                    'status' => 'IN',
                    'create_date' => date('Y-m-d H:i:s'),
                    'topic' => $product['topic'],
                    'quantity' => $product['stock'],
                    'cut_stock' => 1,
                    'unit' => $save['unit'],
                    'used' => 0,
                    'price' => $product['cost'],
                    'vat' => 0,
                    'discount' => 0,
                    'total' => $product['stock'] * $product['cost']
                );
                if (!empty($save['buy_vat'])) {
                    if ($save['buy_vat'] == 1) {
                        // ราคาสินค้าไม่รวม vat
                        $stock['vat'] = (float) number_format(\Kotchasan\Currency::calcVat($stock['total'], self::$cfg->vat, true), 2);
                    } else {
                        // ราคาสินค้ารวม vat
                        $stock['vat'] = (float) number_format(\Kotchasan\Currency::calcVat($stock['total'], self::$cfg->vat, false), 2);
                        $stock['total'] -= $stock['vat'];
                    }
                }
                // บันทึก
                $model->db()->insert($model->getTableName('stock'), $stock);
            }
        }
        // คืนค่า inventory_id
        return $inventory_id;
    }

    /**
     * อัปเดตข้อมูลสินค้า
     *
     * @param object $src
     * @param array $save
     *
     * @return int
     */
    public static function update($src, $save)
    {
        $product_columns = array('topic', 'count_stock', 'stock', 'cost');
        $product = [];
        foreach ($save as $key => $value) {
            if ($key == 'category') {
                $product['category_id'] = \Inventory\Category\Model::save('category_id', $value);
            } elseif ($key == 'unit') {
                $product['unit'] = \Inventory\Category\Model::save('unit', $value);
            } elseif (in_array($key, $product_columns)) {
                $product[$key] = $value;
            }
        }
        // Model
        $model = new \Kotchasan\Model;
        // save product
        $model->db()->update($model->getTableName('inventory'), $src->id, $product);
        // อัปเดท Stock
        $stockUpdated = $src->count_stock != $product['count_stock'];
        // อัปเดท Stock สำหรับสินค้าสต๊อกรวม
        if ($src->stock != $product['stock'] && $product['count_stock'] == 1) {
            // ค้นหา product_no ที่ขายทีละ 1 หน่วย
            $item = $model->db()->first($model->getTableName('inventory_items'), array(
                array('inventory_id', $src->id),
                array('instock', 1),
                array('cut_stock', 1)
            ));
            if ($item) {
                $stockUpdated = true;
                // Stock
                $stock = array(
                    'order_id' => 0,
                    'member_id' => $save['member_id'],
                    'inventory_id' => $src->id,
                    'product_no' => $src->product_no,
                    'create_date' => date('Y-m-d H:i:s'),
                    'topic' => $src->topic,
                    'unit' => $item->unit,
                    'cut_stock' => 1,
                    'used' => 0,
                    'vat' => 0,
                    'discount' => 0,
                    'total' => $product['stock'] * $product['cost']
                );
                if ($src->stock > $product['stock']) {
                    // ขาย
                    $stock['status'] = 'OUT';
                    $stock['quantity'] = $src->stock - $product['stock'];
                    $stock['price'] = $item->price;
                } else {
                    // ซื้อ
                    $stock['status'] = 'IN';
                    $stock['quantity'] = $product['stock'] - $src->stock;
                    $stock['price'] = $product['cost'];
                }
                $stock['total'] = $stock['quantity'] * $stock['price'];
                // บันทึก
                $model->db()->insert($model->getTableName('stock'), $stock);
            }
        }
        if ($stockUpdated) {
            // อัปเดท Stock
            \Inventory\Fifo\Model::update($src->id);
        }
        // คืนค่า inventory_id
        return $src->id;
    }
}
