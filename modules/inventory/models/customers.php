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
        $query = static::createQuery()
            ->select('C.id', 'C.customer_no', 'C.company', 'C.branch', 'C.name', 'C.tax_id',
                'C.phone', 'C.email', 'C.province', 'C.is_customer', 'C.is_supplier', 'C.is_active')
            ->from('customer C');

        // ⚠️ กรองด้วย "ธง" ไม่ใช่ "ประเภท" — บริษัทหนึ่งเป็นได้ทั้งลูกค้าและคู่ค้า
        // พร้อมกัน (ซื้อจากเขาแล้วขายให้เขาด้วย) แถวเดียวจึงต้องโผล่ทั้งสองหน้า
        // ระบบที่ใช้คอลัมน์ประเภทเดียวต้องสร้างแถวซ้ำ แล้วยอดลูกหนี้กับเจ้าหนี้
        // ของรายเดียวกันจะแยกกันอยู่คนละที่ตลอดไป
        if (!empty($params['type'])) {
            if ($params['type'] === 'customer') {
                $query->where(['C.is_customer', 1]);
            } elseif ($params['type'] === 'supplier') {
                $query->where(['C.is_supplier', 1]);
            }
        }

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            // ⚠️ ต้องครอบเงื่อนไขค้นหาเป็นวงเล็บเดียวก่อนแล้วค่อย AND กับตัวกรองอื่น
            // ถ้าปล่อยให้ OR กระจายออกมา การกรองประเภทข้างบนจะไม่มีผลเลยทันทีที่ค้นหา
            $query->whereNested(function ($q) use ($search) {
                $q->where([['C.customer_no', 'LIKE', $search]])
                    ->orWhere([['C.company', 'LIKE', $search]])
                    ->orWhere([['C.name', 'LIKE', $search]])
                    ->orWhere([['C.phone', 'LIKE', $search]])
                    ->orWhere([['C.tax_id', 'LIKE', $search]]);
            });
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
        $row = static::createDB()->first(\Inventory\Base\Model::table('customer'), ['id' => (int) $id]);

        return $row ? (array) $row : null;
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
