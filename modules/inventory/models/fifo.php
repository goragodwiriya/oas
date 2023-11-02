<?php
/**
 * @filesource modules/inventory/models/fifo.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Fifo;

/**
 * คลาสสำหรับจัดการสินค้าแบบ FIFO
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อัปเดต stock และ ราคา แบบ FIFO
     *
     * @param int|array $products inventory_id
     */
    public static function update($products)
    {
        // Model
        $model = new static;
        // Database
        $db = $model->db();
        // รับเข้า (นับสต๊อกแยก เช่นสินค้าที่มีซีเรียลนัมเบอร์)
        $q1 = $db->createQuery()
            ->select('S.id', 'I.inventory_id', 'I.product_no', 'S.status', 'S.quantity', 'S.cut_stock', 'S.price', 'V.count_stock')
            ->from('stock S')
            ->join('inventory V', 'INNER', array('V.id', 'S.inventory_id'))
            ->join('inventory_items I', 'INNER', array(array('I.inventory_id', 'S.inventory_id'), array('I.product_no', 'S.product_no')))
            ->where(array(
                array('S.inventory_id', $products),
                array('S.status', self::$cfg->in_stock_status),
                array('V.count_stock', 2)
            ))
            ->order('S.create_date');
        // รับเข้า (นับสต๊อกรวม)
        $q2 = $db->createQuery()
            ->select('S.id', 'S.inventory_id', 'S.product_no', 'S.status', 'S.quantity', 'S.cut_stock', 'S.price', 'V.count_stock')
            ->from('stock S')
            ->join('inventory V', 'INNER', array('V.id', 'S.inventory_id'))
            ->where(array(
                array('S.inventory_id', $products),
                array('S.status', self::$cfg->in_stock_status),
                array('V.count_stock', 1)
            ))
            ->order('S.create_date');
        // ขาย (ตัดสต๊อก)
        $q3 = $db->createQuery()
            ->select('S.id', 'I.inventory_id', 'I.product_no', 'S.status', 'S.quantity', 'S.cut_stock', 'S.price', 'V.count_stock')
            ->from('stock S')
            ->join('inventory V', 'INNER', array('V.id', 'S.inventory_id'))
            ->join('inventory_items I', 'INNER', array(array('I.inventory_id', 'S.inventory_id'), array('I.product_no', 'S.product_no')))
            ->where(array(
                array('S.inventory_id', $products),
                array('S.status', self::$cfg->out_stock_status)
            ))
            ->order('S.create_date');
        $query = $db->createQuery()
            ->unionAll($q1, $q2, $q3);
        $order = array();
        $used = array();
        $items = array();
        $product = array();
        foreach ($query->execute() as $item) {
            if ($item->count_stock == 2 && !isset($items[$item->product_no])) {
                // สินค้ามี serial เช่น คอมพิวเตอร์
                $items[$item->product_no] = array(
                    'inventory_id' => $item->inventory_id,
                    'count_stock' => $item->count_stock,
                    'price' => $item->price,
                    'stock' => 0
                );
            } elseif ($item->count_stock == 1 && !isset($product[$item->inventory_id])) {
                // สินค้าไม่มีซีเรียล เช่น ไข่
                $product[$item->inventory_id] = array(
                    'inventory_id' => $item->inventory_id,
                    'count_stock' => $item->count_stock,
                    'cost' => null,
                    'stock' => null
                );
            }
            if (in_array($item->status, self::$cfg->in_stock_status)) {
                // In Stock
                $order[$item->inventory_id][$item->id]['quantity'] = ($item->quantity * $item->cut_stock);
                $order[$item->inventory_id][$item->id]['price'] = $item->status == 'IN' ? $item->price : null;
                $order[$item->inventory_id][$item->id]['count_stock'] = $item->count_stock;
                if (isset($items[$item->product_no])) {
                    $items[$item->product_no]['stock']++;
                }
            } else {
                // Out Stock
                if (isset($used[$item->inventory_id])) {
                    $used[$item->inventory_id] += ($item->quantity * $item->cut_stock);
                } else {
                    $used[$item->inventory_id] = $item->quantity * $item->cut_stock;
                }
                if (isset($items[$item->product_no])) {
                    $items[$item->product_no]['stock']--;
                }
            }
        }
        foreach ($order as $inventory_id => $products) {
            foreach ($products as $stock_id => $item) {
                if ($item['count_stock'] == 2) {
                    if (isset($used[$inventory_id])) {
                        if ($item['quantity'] < $used[$inventory_id]) {
                            $order[$inventory_id][$stock_id]['used'] = $item['quantity'];
                            $used[$inventory_id] -= $item['quantity'];
                        } elseif ($used[$inventory_id] > 0) {
                            $order[$inventory_id][$stock_id]['used'] = $used[$inventory_id];
                            $product[$inventory_id] = array(
                                'count_stock' => $item['count_stock'],
                                'cost' => array(),
                                'stock' => $item['quantity'] - $used[$inventory_id]
                            );
                            $used[$inventory_id] = 0;
                        } else {
                            $order[$inventory_id][$stock_id]['used'] = 0;
                            if (isset($product[$inventory_id])) {
                                $product[$inventory_id]['stock'] += $item['quantity'];
                            } else {
                                $product[$inventory_id]['stock'] = $item['quantity'];
                            }
                        }
                        if ($item['price'] !== null) {
                            $product[$inventory_id]['cost'][] = $item['price'];
                        }
                    } elseif (isset($product[$inventory_id])) {
                        $product[$inventory_id]['stock'] += $item['quantity'];
                    } else {
                        $product[$inventory_id] = array(
                            'count_stock' => $item['count_stock'],
                            'cost' => array(),
                            'stock' => $item['quantity']
                        );
                        if ($item['price'] !== null) {
                            $product[$inventory_id]['cost'][] = $item['price'];
                        }
                    }
                } else {
                    if (isset($product[$inventory_id]) && $product[$inventory_id]['cost'] === null) {
                        $product[$inventory_id]['cost'] = $item['price'];
                    }
                    if (isset($used[$inventory_id])) {
                        if ($item['quantity'] < $used[$inventory_id]) {
                            $order[$inventory_id][$stock_id]['used'] = $item['quantity'];
                            $used[$inventory_id] -= $item['quantity'];
                        } elseif ($used[$inventory_id] > 0) {
                            $order[$inventory_id][$stock_id]['used'] = $used[$inventory_id];
                            $product[$inventory_id] = array(
                                'count_stock' => $item['count_stock'],
                                'cost' => $item['price'],
                                'stock' => $item['quantity'] - $used[$inventory_id]
                            );
                            $used[$inventory_id] = 0;
                        } else {
                            $order[$inventory_id][$stock_id]['used'] = 0;
                            if (isset($product[$inventory_id])) {
                                $product[$inventory_id]['stock'] += $item['quantity'];
                            } else {
                                $product[$inventory_id]['stock'] = $item['quantity'];
                            }
                        }
                        if ($used[$inventory_id] == 0) {
                            $product[$inventory_id]['cost'] = null;
                        }
                    } elseif (isset($product[$inventory_id])) {
                        $product[$inventory_id]['stock'] += $item['quantity'];
                    } else {
                        $product[$inventory_id] = array(
                            'cost' => $item['price'],
                            'stock' => $item['quantity']
                        );
                    }
                }
            }
            if (isset($product[$inventory_id]) && $product[$inventory_id]['cost'] === null) {
                $product[$inventory_id]['cost'] = $item['price'];
            }
        }
        // อัปเดต inventory
        $table = $model->getTableName('inventory');
        $table_items = $model->getTableName('inventory_items');
        foreach ($product as $id => $item) {
            $db->update($table, $id, array(
                'cost' => is_array($item['cost']) ? array_sum($item['cost']) / count($item['cost']) : $item['cost'],
                'stock' => $item['stock']
            ));
            if ($item['count_stock'] == 1) {
                // สินค้าไม่มีซีเรียล เช่น ไข่
                $db->update($table_items, array('inventory_id', $id), array(
                    'instock' => empty($item['stock']) ? 0 : 1
                ));
            }
        }
        // อัปเดต inventory_items สินค้ามีซีเรียล
        foreach ($items as $product_no => $item) {
            $db->update($table_items, array(
                array('inventory_id', $item['inventory_id']),
                array('product_no', $product_no)
            ), array('instock' => empty($item['stock']) ? 0 : 1));
        }
        // อัปเดต Stock เฉพาะ IN
        $table = $model->getTableName('stock');
        foreach ($order as $inventory_id => $products) {
            foreach ($products as $id => $item) {
                if (isset($item['used'])) {
                    $db->update($table, array(
                        array('id', $id),
                        array('status', 'IN')
                    ), array('used' => $item['used']));
                }
            }
        }
    }
}
