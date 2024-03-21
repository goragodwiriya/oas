<?php
/**
 * @filesource modules/inventory/models/stock.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Stock;

use Kotchasan\Database\Sql;

/**
 * ข้อมูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * อ่านรายการสินค้าในใบเสร็จ
     * ถ้าไมมีคืนค่ารายการว่าง 1 รายการ
     *
     * @param int $order_id
     * @param string $status
     *
     * @return array
     */
    public static function get($order_id, $status)
    {
        if ($order_id > 0) {
            $result = \Kotchasan\Model::createQuery()
                ->select('id', 'quantity', 'price', 'vat', 'discount', 'inventory_id', 'product_no', 'topic', 'unit')
                ->from('stock')
                ->where(array(
                    array('order_id', $order_id),
                    array('status', $status)
                ))
                ->order('id')
                ->toArray()
                ->execute();
        }
        if (empty($result)) {
            // ถ้าไม่มีผลลัพท์ คืนค่ารายการเปล่าๆ 1 รายการ
            return array(
                0 => array(
                    'id' => 0,
                    'quantity' => 0,
                    'price' => 0,
                    'vat' => 0,
                    'discount' => 0,
                    'total' => 0,
                    'inventory_id' => 0,
                    'product_no' => '',
                    'topic' => '',
                    'unit' => 0
                )
            );
        } else {
            return $result;
        }
    }

    /**
     * สรุปรายละเอียดของสินค้าคงคลัง (เข้า, ออก, คงเหลือ)
     * รายเดือน ตามปีที่เลือก
     *
     * @param int $id
     * @param int $year
     *
     * @return array
     */
    public static function monthlyReport($id, $year)
    {
        $q1 = \Kotchasan\Model::createQuery()
            ->select(Sql::MONTH('S.create_date', 'm'), 'S.status', Sql::create('SUM(S.`quantity`*S.`cut_stock`) AS `quantity`'))
            ->from('stock S')
            ->join('orders O', 'LEFT', array('O.id', 'S.order_id'))
            ->where(array(
                array('S.inventory_id', $id),
                array(Sql::YEAR('S.create_date'), $year),
                Sql::create('(S.`order_id`=0 OR O.`status`=S.`status`)')
            ))
            ->groupBy('m', 'S.status');
        $query = \Kotchasan\Model::createQuery()
            ->select(
                'm',
                Sql::create("SUM(IF(`status` IN ('".implode("','", self::$cfg->in_stock_status)."'), `quantity`, NULL)) AS `Buy`"),
                Sql::create("SUM(IF(`status` IN ('".implode("','", self::$cfg->out_stock_status)."'), `quantity`, NULL)) AS `Sell`")
            )
            ->from(array($q1, 'Q'))
            ->groupBy('m')
            ->toArray();
        $result = [];
        foreach ($query->execute() as $item) {
            $result['Sell'][$item['m']] = $item['Sell'];
            $result['Buy'][$item['m']] = $item['Buy'];
        }
        return $result;
    }

    /**
     * อ่านรายการปี ที่มีการทำรายการ สินค้าที่เลือก
     * สำหรับใส่ลงใน select
     *
     * @param int $id
     *
     * @return array
     */
    public static function listYears($id)
    {
        $query = \Kotchasan\Model::createQuery()
            ->select(Sql::create('DISTINCT YEAR(S.`create_date`) AS `y`'))
            ->from('stock S')
            ->where(array('S.inventory_id', $id));
        $year_offset = \Kotchasan\Language::get('YEAR_OFFSET');
        $result = [];
        foreach ($query->execute() as $item) {
            $result[$item->y] = $item->y + $year_offset;
        }
        // ปีนี้
        $y = date('Y');
        $result[$y] = $y + $year_offset;
        return $result;
    }
}
