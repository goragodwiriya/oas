<?php
/**
 * @filesource modules/index/models/number.php
 *
 * @copyright 2024 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Number;

/**
 * คลาสสำหรับจัดการ Running Number
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * คืนค่าข้อมูล running number
     * ตรวจสอบข้อมูลซ้ำด้วย
     * ถ้ายังไม่เคยกำหนดรหัสรูปแบบ จะคืนค่ารหัสแบบสุ่ม
     *
     * @param int    $id         ID สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $order_no   รูปแบบรหัส เช่น %04d, INV-%04d
     * @param string $table_name ชื่อตาราง สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $field      ชื่อฟิลด์ สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $prefix     สำหรับเติมด้านรหัส เช่น XX- จะได้เป็น XX-0001
     *
     * @return string
     */
    public static function get($id, $order_no, $table_name, $field, $prefix = '')
    {
        // Database
        $db = \Kotchasan\DB::create();
        // รูปแบบรหัส
        if ($order_no !== '') {
            if ($prefix != '') {
                // จัดรูปแบบของ prefix
                $prefix = \Kotchasan\Number::printf($prefix, 0);
            }
        } elseif ($prefix != '') {
            $order_no = $prefix;
        } else {
            $order_no = '%04d';
        }
        // ตรวจสอบรายการที่เลือก
        $number = $db->first('number', [
            ['type', $order_no],
            ['prefix', $prefix]
        ]);
        $next_id = $id > 0 ? (int) $id : ($number ? (int) ($number->auto_increment ?? 0) + 1 : 1);
        while (true) {
            $result = $prefix.\Kotchasan\Number::printf($order_no, $next_id);
            $search = $db->first($table_name, [$field, $result]);
            if (!$search || ($id > 0 && $search->id == $id)) {
                break;
            } else {
                $next_id++;
            }
        }
        // อัปเดต running number
        if ($number) {
            $db->update('number', [
                ['type', $order_no],
                ['prefix', $prefix]
            ], ['auto_increment' => $next_id]);
        } else {
            $db->insert('number', [
                'type' => $order_no,
                'prefix' => $prefix,
                'auto_increment' => $next_id,
                'updated_at' => date('Y-m-d')
            ]);
        }
        // คืนค่า
        return $result;
    }
}
