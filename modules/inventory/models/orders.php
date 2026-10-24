<?php
/**
 * @filesource modules/inventory/models/orders.php
 *
 * รายการเอกสารซื้อ/ขาย/ยืม/ซ่อม
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Orders;

/**
 * Model รายการเอกสาร
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query สำหรับตาราง
     *
     * ยอดรวมท้ายเอกสารคือ total + vat - tax (tax คือภาษีหัก ณ ที่จ่าย ซึ่งหักออก
     * จากยอดที่ต้องชำระ) ต้องใช้สูตรเดียวกับหน้าพิมพ์เป๊ะ ไม่งั้นตัวเลขที่ผู้ใช้
     * เห็นในตารางจะไม่ตรงกับที่พิมพ์ออกมา
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];
        if (!empty($params['status'])) {
            $where[] = ['O.document_type', $params['status']];
        }
        if (!empty($params['from'])) {
            $where[] = ['O.order_date', '>=', $params['from'].' 00:00:00'];
        }
        if (!empty($params['to'])) {
            $where[] = ['O.order_date', '<=', $params['to'].' 23:59:59'];
        }
        if (!empty($params['customer_id'])) {
            $where[] = ['O.customer_id', (int) $params['customer_id']];
        }

        $query = static::createQuery()
            ->select('O.id', 'O.order_no', 'O.order_date', 'O.due_date', 'O.document_type',
                'O.document_status', 'O.customer_id', 'O.total', 'O.vat', 'O.tax', 'O.paid',
                'C.company', 'C.customer_no')
            ->selectRaw('(O.`total` + O.`vat` - O.`tax`) AS `grand_total`')
            // ชื่อลูกค้าใช้ค่าที่บันทึกไว้ในเอกสาร (snapshot) ก่อนเสมอ แล้วค่อยถอย
            // ไปอ่านจากทะเบียนลูกค้า — ใบเก่าจึงยังแสดงชื่อ ณ วันออกเอกสาร
            ->selectRaw('IFNULL(NULLIF(O.`customer_company`, \'\'), C.`company`) AS `customer`')
            ->from('orders O')
            ->join('customer C', ['C.id', 'O.customer_id'], 'LEFT');
        if (!empty($where)) {
            $query->where($where);
        }

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            // where() ครั้งที่สองจะ AND กับเงื่อนไขแรก ส่วนภายในกลุ่มเป็น OR
            $query->where([
                ['O.order_no', 'LIKE', $search],
                ['O.customer_company', 'LIKE', $search],
                ['C.company', 'LIKE', $search],
                ['C.customer_no', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }

    /**
     * อ่านเอกสารหนึ่งใบพร้อมข้อมูลลูกค้า
     *
     * ⚠️ select() เขียนทับรายการคอลัมน์เดิมทั้งชุด (ไม่ใช่ต่อท้าย) ส่วน selectRaw()
     * ต่อท้าย จึงต้องเรียก select() ครั้งเดียวให้ครบก่อนเสมอ เคยพลาดตรงนี้มาแล้ว
     * ผลคือเอกสารที่อ่านกลับมาไม่มีคอลัมน์ที่จำเป็นโดยไม่มีอะไรฟ้อง
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function get($id)
    {
        $rows = static::createQuery()
            ->select('O.*', 'C.branch', 'C.address', 'C.province', 'C.zipcode', 'C.country', 'C.phone', 'C.email', 'C.tax_id')
            ->selectRaw('C.`company` AS `customer`')
            ->selectRaw('C.`customer_no` AS `customer_no`')
            ->selectRaw('C.`name` AS `contactor`')
            ->from('orders O')
            ->join('customer C', ['C.id', 'O.customer_id'], 'LEFT')
            ->where(['O.id', (int) $id])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : $rows[0];
    }

    /**
     * อ่านเอกสารจากกุญแจสาธารณะ (ลิงก์ที่ส่งให้ลูกค้าทางอีเมล)
     *
     * ตรวจรูปแบบกุญแจก่อนเสมอ เพื่อไม่ให้ค่าที่เดามาสุ่มยิงหาเอกสารได้
     *
     * @param string $key
     *
     * @return array|null
     */
    public static function getByKey($key)
    {
        if (!preg_match('/^[0-9a-zA-Z]{32}$/', (string) $key)) {
            return null;
        }

        $rows = static::createQuery()
            ->select('O.*')
            ->from('orders O')
            ->where(['O.public_key', $key])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : $rows[0];
    }

    /**
     * กุญแจสาธารณะของเอกสาร สร้างครั้งเดียวแล้วใช้ตลอด
     *
     * ⚠️ ต้องสุ่มจริง ไม่ใช่ md5(id) ที่เดาได้ทั้งระบบ — ลิงก์นี้เปิดได้โดยไม่ต้อง
     * เข้าระบบ ใครเดากุญแจได้ก็อ่านเอกสารของลูกค้ารายอื่นได้หมด
     *
     * @param int $orderId
     *
     * @return string
     */
    public static function ensureKey($orderId)
    {
        $db = static::createDB();
        $table = \Inventory\Base\Model::table('orders');
        $row = $db->first($table, ['id' => (int) $orderId]);
        if (!$row) {
            return '';
        }
        if (!empty($row->public_key)) {
            return $row->public_key;
        }
        $key = bin2hex(random_bytes(16));
        $db->update($table, ['id', (int) $orderId], ['public_key' => $key, 'order' => $key]);

        return $key;
    }
}
