<?php
/**
 * @filesource modules/inventory/models/dashboard.php
 *
 * ตัวเลขสรุปของโมดูล inventory ที่ไปแสดงบนหน้าแรก
 *
 * ตัวเลขทุกตัวมาจากข้อมูลจริง ไม่มีค่าสมมติ และแต่ละใบมีลิงก์ไปหน้าที่ดู
 * รายละเอียดต่อได้ เพื่อให้หน้าแรกเป็นทางเข้างาน ไม่ใช่แค่ตัวเลขลอย ๆ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Dashboard;

use Inventory\Base\Model as Base;
use Kotchasan\Currency;
use Kotchasan\Language;

/**
 * Model การ์ดหน้าแรกของโมดูล inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * นิพจน์เครื่องหมายของเอกสารฝั่งขาย : ใบคืนสินค้าจากลูกค้า (ฝั่งขายที่รับของเข้า) = −1 · ใบอื่น = 1
     *
     * @return string นิพจน์ SQL ที่อ้าง O.document_type
     */
    protected static function returnSign()
    {
        $returns = [];
        foreach (Base::revenueStatuses()['returns'] as $type) {
            // ชนิดเอกสารผ่าน filter A-Z0-9 ตอนบันทึกแม่แบบแล้ว กรองซ้ำก่อนฝังในคำสั่ง
            $returns[] = "'".preg_replace('/[^A-Z0-9]/', '', (string) $type)."'";
        }

        return empty($returns) ? '1' : 'IF(O.`document_type` IN ('.implode(',', $returns).'), -1, 1)';
    }

    /**
     * การ์ดทั้งหมดของโมดูลนี้
     *
     * @return array
     */
    public static function cards()
    {
        $month = date('Y-m-01');
        $nextMonth = date('Y-m-01', strtotime('+1 month'));

        $revenue = Base::revenueStatuses();
        $sellStatuses = array_merge($revenue['sales'], $revenue['returns']);
        $firstSell = empty($revenue['sales']) ? '' : $revenue['sales'][0];
        $sign = self::returnSign();

        $sales = ['amount' => 0, 'documents' => 0];
        if (!empty($revenue['sales'])) {
            $rows = static::createQuery()
                // ใบคืนสินค้าจากลูกค้าหักออกจากยอดขาย ไม่นับเป็นใบขาย
                ->selectRaw('SUM('.$sign.' > 0) AS `documents`')
                ->selectRaw('SUM('.$sign.' * (O.`total` + O.`vat` - O.`tax`)) AS `amount`')
                ->from('orders O')
                ->where([
                    ['O.document_type', $sellStatuses],
                    // ยอดขายเกิดเมื่อใบออกแล้วเท่านั้น — ร่าง (ตะกร้าที่พักไว้) และใบที่ยกเลิกไม่นับ
                    ['O.document_status', 'issued'],
                    ['O.order_date', '>=', $month],
                    ['O.order_date', '<', $nextMonth]
                ])
                ->execute(null, 'array')
                ->fetchAll();
            if (!empty($rows)) {
                $sales['amount'] = (float) $rows[0]['amount'];
                $sales['documents'] = (int) $rows[0]['documents'];
            }
        }

        $db = static::createDB();

        // สินค้าที่นับสต๊อกแล้วเหลือน้อยกว่าหรือเท่ากับศูนย์ ต้องเห็นตั้งแต่หน้าแรก
        // ยอดอ่านจากตารางยอดคงเหลือ ซึ่งเป็นผลสรุปของสมุดบัญชี ไม่ใช่ค่าที่ใครก็เขียนได้
        // ⚠️ having() รับ ($column, $operator, $value) — ส่งนิพจน์ทั้งก้อนเป็นอาร์กิวเมนต์
        // เดียวไม่ได้ ตัวสร้างคำสั่งจะเติม "= NULL" ต่อท้ายให้เอง กลายเป็น
        // `HAVING IFNULL(SUM(S.`qty`), 0) <= 0 = NULL` ซึ่งเป็นเท็จเสมอ
        // การ์ด "สินค้าหมด" จึงขึ้นเลข 0 ตลอดกาลโดยไม่มีข้อผิดพลาดให้เห็น
        // ต้องตั้งชื่อผลรวมไว้ใน SELECT แล้ว having ที่ชื่อนั้นแทน
        $outOfStock = static::createQuery()
            ->select('V.id')
            ->selectRaw('IFNULL(SUM(S.`qty`), 0) AS `balance`')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->join('inventory_stock S', ['S.inventory_id', 'V.id'], 'LEFT')
            ->where([
                ['P.count_stock', '>', 0],
                ['P.is_active', 1],
                ['V.is_active', 1]
            ])
            ->groupBy('V.id')
            ->having('balance', '<=', 0)
            ->execute(null, 'array')
            ->fetchAll();

        return [
            [
                'key' => 'inventory_sales',
                'title' => Language::get('Sales').' ('.Language::get('This month').')',
                'value' => Currency::format($sales['amount']),
                'unit' => Language::get('Baht'),
                'icon' => 'icon-wallet',
                'url' => $firstSell === '' ? '/inventory-orders' : '/inventory-orders?status='.$firstSell,
                'hint' => Language::replace(':count documents', [':count' => number_format($sales['documents'])])
            ],
            [
                'key' => 'inventory_customers',
                'title' => Language::get('Customer list').'-'.Language::get('Supplier'),
                'value' => number_format($db->count(Base::table('customer'))),
                'unit' => '',
                'icon' => 'icon-users',
                'url' => '/inventory-customers',
                'hint' => ''
            ],
            [
                'key' => 'inventory_products',
                'title' => Language::get('Inventory'),
                'value' => number_format($db->count(Base::table('inventory'), ['is_active', 1])),
                'unit' => '',
                'icon' => 'icon-product',
                'url' => '/inventory-products',
                'hint' => ''
            ],
            [
                'key' => 'inventory_out_of_stock',
                'title' => Language::get('Out of stock'),
                'value' => number_format(count($outOfStock)),
                'unit' => '',
                'icon' => 'icon-warning',
                'url' => '/inventory-setup',
                'hint' => ''
            ]
        ];
    }
    /**
     * บล็อกตารางของโมดูลนี้ ไปแสดงบนหน้าแรกใต้การ์ด
     *
     * ⚠️ ไม่สร้างหน้า dashboard แยกของตัวเอง — หน้าแรกของแกนรับบล็อกจากโมดูลอยู่แล้ว
     * (initDashboardBlocks) การมีสองหน้าที่สรุปเรื่องเดียวกันแปลว่าวันหนึ่งมันจะ
     * บอกตัวเลขไม่ตรงกัน แล้วไม่มีใครรู้ว่าหน้าไหนถูก
     *
     * @return array
     */
    public static function blocks()
    {
        return [
            [
                'kind' => 'table',
                'title' => Language::get('Low stock'),
                // id ขึ้นต้นด้วยชื่อโมดูลเสมอ — สองโมดูลที่ส่งตารางมาหน้าเดียวกัน
                // แล้วใช้ id ซ้ำ จะทับกันใน TableManager.state.tables
                'id' => 'inventoryLowStock',
                'url' => 'api/inventory/dashboard/lowstock',
                'size' => 'block6'
            ],
            [
                'kind' => 'table',
                'title' => Language::get('Best selling products'),
                'id' => 'inventoryTopProducts',
                'url' => 'api/inventory/dashboard/topproducts',
                'size' => 'block6'
            ]
        ];
    }

    /**
     * สินค้าที่ยอดคงเหลือใกล้หมดหรือหมดแล้ว
     *
     * ⚠️ อ่านยอดจาก `inventory_stock` ซึ่งเป็นผลสรุปของสมุดบัญชี ไม่ใช่คอลัมน์
     * `inventory.stock` ที่เป็น cache — ถ้า cache เพี้ยน หน้าแรกต้องบอกความจริง
     *
     * @param int $limit
     *
     * @return array
     */
    public static function lowStock($limit = 10)
    {
        $rows = static::createQuery()
            ->select('V.id', 'V.sku', 'V.topic', 'P.unit')
            ->selectRaw('IFNULL(SUM(S.`qty`), 0) AS `balance`')
            ->from('inventory V')
            ->join('inventory_product P', ['P.id', 'V.product_id'], 'INNER')
            ->join('inventory_stock S', ['S.inventory_id', 'V.id'], 'LEFT')
            ->where([
                ['P.count_stock', '>', 0],
                ['P.is_active', 1],
                ['V.is_active', 1]
            ])
            ->groupBy('V.id')
            ->orderBy('balance')
            ->limit((int) $limit)
            ->execute(null, 'array')
            ->fetchAll();

        $datas = [];
        foreach ($rows as $row) {
            $datas[] = [
                'topic' => $row['topic'],
                // รหัสของกองสต๊อก (sku ของ variant) — ยอดนับต่อ variant ไม่ใช่ต่อสินค้า
                'sku' => $row['sku'],
                'balance' => (float) $row['balance'],
                'unit' => (string) $row['unit']
            ];
        }

        return $datas;
    }

    /**
     * สินค้าที่ขายดีที่สุดในเดือนนี้
     *
     * นับจากบรรทัดของเอกสารขายที่ไม่ได้ถูกยกเลิก — ไม่ใช่จากสมุดบัญชีสต๊อก
     * เพราะสินค้าที่ไม่นับสต๊อก (งานบริการ) ก็ต้องติดอันดับได้
     *
     * @param int $limit
     *
     * @return array
     */
    public static function topProducts($limit = 10)
    {
        // นับจากใบที่ส่งมอบสินค้าแล้ว หักของที่ลูกค้าคืน (Base::revenueStatuses)
        $revenue = Base::revenueStatuses();
        if (empty($revenue['sales'])) {
            return [];
        }
        $sellStatuses = array_merge($revenue['sales'], $revenue['returns']);
        $month = date('Y-m-01');
        $nextMonth = date('Y-m-01', strtotime('+1 month'));

        $rows = static::createQuery()
            ->select('T.sku')
            ->selectRaw('MAX(T.`topic`) AS `topic`')
            // จำนวนเป็นหน่วยฐาน — ขายเป็นโหลกับเป็นฟองรวมกันได้ (จำนวนหน่วยขายบวกกันไม่ได้)
            // ของที่ลูกค้าคืนหักออก
            ->selectRaw('SUM('.self::returnSign().' * T.`base_qty`) AS `quantity`')
            ->selectRaw('SUM('.self::returnSign().' * T.`total`) AS `amount`')
            ->from('order_items T')
            ->join('orders O', ['O.id', 'T.order_id'])
            ->where([
                ['O.document_type', $sellStatuses],
                ['O.document_status', 'issued'],
                ['O.order_date', '>=', $month],
                ['O.order_date', '<', $nextMonth]
            ])
            // ขายดีราย variant (สี/ไซซ์) — ขายเป็นชิ้นหรือเป็นลังก็นับเป็นสินค้าตัวเดียวกัน
            ->groupBy('T.sku')
            ->orderBy('amount', 'DESC')
            ->limit((int) $limit)
            ->execute(null, 'array')
            ->fetchAll();

        $datas = [];
        foreach ($rows as $row) {
            $datas[] = [
                'topic' => $row['topic'],
                'sku' => $row['sku'],
                'quantity' => (float) $row['quantity'],
                'amount' => (float) $row['amount']
            ];
        }

        return $datas;
    }

}
