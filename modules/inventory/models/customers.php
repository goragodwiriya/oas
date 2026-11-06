<?php
/**
 * @filesource modules/inventory/models/customers.php
 *
 * ทะเบียนลูกค้า/ผู้ขาย (คนละตารางกับ user ซึ่งเป็นผู้ใช้ระบบ)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Customers;

/**
 * Model รายชื่อลูกค้า
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
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        // เมนูมีสองหน้า (ลูกค้า · คู่ค้า) ไม่ระบุ = หน้าลูกค้า · บริษัทที่ตั้งทั้งสองธงโผล่ทั้งสองหน้าด้วยแถวเดียว
        $where = [];
        if (isset($params['type']) && $params['type'] === 'supplier') {
            $where[] = ['C.is_supplier', 1];
        } else {
            $where[] = ['C.is_customer', 1];
        }
        $query = static::createQuery()
            ->select('C.id', 'C.customer_no', 'C.company', 'C.branch', 'C.name',
                'C.tax_id', 'C.phone', 'C.email', 'C.address', 'C.zipcode',
                'C.is_customer', 'C.is_supplier', 'C.is_active'
            )
            ->from('customer C')
            ->where($where);

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['C.customer_no', 'LIKE', $search],
                ['C.company', 'LIKE', $search],
                ['C.name', 'LIKE', $search],
                ['C.tax_id', 'LIKE', $search],
                ['C.phone', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }

    /**
     * ค้นหาลูกค้าสำหรับช่อง autocomplete ในเอกสาร
     *
     * ⚠️ value ต้องเป็นค่าที่ฝั่ง PHP ใช้หาลูกค้าได้ (id) และ text คือสิ่งที่ผู้ใช้
     * อ่านแล้วแยกออกว่าเป็นใคร — ช่องที่มี data-autocomplete จะกลายเป็น
     * "ข้อความ + hidden" อัตโนมัติ ฝั่ง PHP จึงอ่านได้ทั้ง field และ field_text
     *
     * @param string $q
     * @param string $by  customer_no = ค้นจากรหัสลูกค้า
     * @param int    $limit
     *
     * @return array
     */
    public static function search($q, $by = '', $limit = 20)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return [];
        }

        $query = static::createQuery()
            ->select('C.id', 'C.customer_no', 'C.company', 'C.name', 'C.branch')
            ->from('customer C')
            ->orderBy('C.company')
            ->limit((int) $limit);

        if ($by === 'customer_no') {
            $query->where([['C.customer_no', 'LIKE', "%$q%"]]);
        } else {
            $query->whereNested(function ($nested) use ($q) {
                $nested->where([['C.company', 'LIKE', "%$q%"]])
                    ->orWhere([['C.name', 'LIKE', "%$q%"]])
                    ->orWhere([['C.customer_no', 'LIKE', "%$q%"]]);
            });
        }

        $result = [];
        foreach ($query->execute(null, 'array')->fetchAll() as $row) {
            $text = $row['company'] !== '' ? $row['company'] : $row['name'];
            if ($row['branch'] !== '') {
                $text .= ' ('.$row['branch'].')';
            }
            $result[] = [
                'value' => $by === 'customer_no' ? $row['customer_no'] : (string) $row['id'],
                'text' => $text,
                'id' => (int) $row['id'],
                'customer_no' => $row['customer_no'],
                'company' => $row['company'],
                'name' => $row['name']
            ];
        }

        return $result;
    }

    /**
     * ลูกค้าหนึ่งราย
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function get($id)
    {
        $row = self::createQuery()
            ->select()
            ->from('customer')
            ->where(['id', (int) $id])
            ->first(true);
        if ($row) {
            $row['discount'] = $row['discount'] == 0 ? '' : $row['discount'];
            $row['expense_due_date'] = $row['expense_due_date'] == 0 ? '' : $row['expense_due_date'];
            $row['invoice_due_date'] = $row['invoice_due_date'] == 0 ? '' : $row['invoice_due_date'];
        }

        return $row;
    }

    /**
     * เลขที่ลูกค้าถัดไป ตามรูปแบบที่ไซต์ตั้งไว้
     *
     * @return string
     */
    public static function nextNumber()
    {
        return \Index\Number\Model::get(
            0,
            \Inventory\Base\Model::config('customer_no', 'CU%04d'),
            'customer',
            'customer_no'
        );
    }
}
