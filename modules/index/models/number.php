<?php
/**
 * @filesource modules/index/models/number.php
 *
 * @copyright 2016 Goragod.com
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
class Model extends \Kotchasan\Model
{
    /**
     * คืนค่าข้อมูล running number
     * ตรวจสอบข้อมูลซ้ำด้วย
     * ถ้ายังไม่เคยกำหนดรหัสรูปแบบ จะคืนค่ารหัสแบบสุ่ม
     *
     * @param int    $id         ID สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $name       ชื่อตัวแปร config ที่เก็บรูปแบบรหัส
     * @param string $table_name ชื่อตาราง สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $field      ชื่อฟิลด์ สำหรับตรวจสอบข้อมูลซ้ำ
     * @param string $prefix     สำหรับเติมด้านรหัส เช่น XX- จะได้เป็น XX-0001
     *
     * @return string
     */
    public static function get($id, $name, $table_name, $field, $prefix = '')
    {
        // Model
        $model = new static;
        // Database
        $db = $model->db();
        // number table
        $table_number = $model->getTableName('number');
        if (!empty(self::$cfg->$name)) {
            if ($prefix != '') {
                // จัดรูปแบบของ prefix
                $prefix = \Kotchasan\Number::printf($prefix, 0);
            }
            $order_no = self::$cfg->$name;
        } elseif ($prefix != '') {
            $order_no = $prefix;
        } else {
            $order_no = '%04d';
        }
        // ตรวจสอบรายการที่เลือก
        $number = $db->first($table_number, array(
            array('type', $name),
            array('prefix', $prefix)
        ));
        if ($number) {
            $next_id = 1 + (int) $number->auto_increment;
        } else {
            $next_id = 1;
        }
        $next_tmp = $next_id;
        // ตรวจสอบข้อมูลซ้ำ
        $n = 0;
        while (true) {
            $result = $prefix.\Kotchasan\Number::printf($order_no, $next_id);
            $search = $db->first($table_name, array($field, $result));
            if (!$search || ($id > 0 && $search->id == $id)) {
                break;
            } else {
                $next_id++;
                if ($n > 10) {
                    $result = null;
                    $next_id = $next_tmp;
                    break;
                } else {
                    $n++;
                }
            }
        }
        if ($result === null) {
            // สร้างเลขที่ต่อจากเลขที่เดิม
            $result = $order_no.'-'.$next_id;
            // ตรวจสอบข้อมูลซ้ำ
            while ($db->first($table_name, array($field, $result))) {
                $next_id++;
                $result = $order_no.'-'.$next_id;
            }
        }
        // อัปเดต running number
        if ($number) {
            $db->update($table_number, array(
                array('type', $name),
                array('prefix', $prefix)
            ), array('auto_increment' => $next_id));
        } else {
            $db->insert($table_number, array(
                'type' => $name,
                'prefix' => $prefix,
                'auto_increment' => $next_id,
                'last_update' => date('Y-m-d')
            ));
        }
        // คืนค่า
        return $result;
    }
}
