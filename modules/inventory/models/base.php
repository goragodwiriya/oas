<?php
/**
 * @filesource modules/inventory/models/base.php
 *
 * ฐานร่วมของโมดูล inventory กลาง — ชื่อตาราง ค่าขององค์กร และทะเบียนกลาง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Base;

/**
 * ฐานร่วมของโมดูล inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชนิดการเคลื่อนไหวที่อนุญาต และทิศทางที่ถูกต้องของแต่ละชนิด
     *
     * ทะเบียนกลาง (หัวข้อ 4.6 ของแผน) — ชนิดที่ไม่อยู่ในนี้ถือว่าเขียนผิด
     * ไม่ใช่ "ชนิดใหม่ที่ยังไม่ได้ลงทะเบียน" เพราะรายงานข้ามระบบต้องหมายถึง
     * เรื่องเดียวกัน ถ้าจะเพิ่มชนิดใหม่ให้เพิ่มที่นี่พร้อมกับในเอกสารแผน
     *
     * @var array
     */
    protected static $movementTypes = [
        'opening' => 'in',
        'purchase' => 'in',
        'sale' => 'out',
        'return_in' => 'in',
        'return_out' => 'out',
        'borrow' => 'out',
        'borrow_return' => 'in',
        'repair_out' => 'out',
        'repair_in' => 'in',
        'adjust_in' => 'in',
        'adjust_out' => 'out'
    ];

    /**
     * ทำงานชุดหนึ่งใน transaction เดียว — ซ้อนกันได้
     *
     * ⚠️ PDO เปิด transaction ซ้อนไม่ได้ : ตัวใน `beginTransaction()` จะได้ false แล้ว
     * `commit()` ของตัวในจะไป "ปิด" transaction ของตัวนอกก่อนเวลา ทำให้ครึ่งแรกของ
     * งานตัวนอกถูกบันทึกทั้งที่ครึ่งหลังยังไม่จบ — เมธอดนี้จึงเปิดเฉพาะเมื่อยังไม่มี
     * ใครเปิด และให้ตัวนอกสุดเท่านั้นเป็นคน commit/rollback
     *
     * (เอกสารถูกสร้างจาก Payment::record ที่เปิด transaction อยู่แล้ว · ใบสั่งขาย
     * แปลงเป็นใบเสร็จระหว่างรับเงิน — ทั้งคู่ต้องเป็นก้อนเดียวกับเงิน)
     *
     * @param callable $fn
     *
     * @throws \Exception ส่งต่อจาก $fn หลัง rollback แล้ว
     *
     * @return mixed ค่าที่ $fn คืน
     */
    public static function transaction(callable $fn)
    {
        $db = static::createDB();
        // มีคนเปิดอยู่แล้ว (ทั้งของเราเองที่ซ้อนกัน หรือของผู้เรียกข้างนอก) → ทำต่อในก้อนเดิม
        if (self::$transactionDepth > 0 || !$db->beginTransaction()) {
            ++self::$transactionDepth;
            try {
                return $fn();
            } finally {
                --self::$transactionDepth;
            }
        }

        self::$transactionDepth = 1;
        try {
            $result = $fn();
            $db->commit();

            return $result;
        } catch (\Exception $e) {
            $db->rollback();

            throw $e;
        } finally {
            self::$transactionDepth = 0;
        }
    }

    /**
     * ความลึกของ transaction ที่เปิดผ่าน transaction()
     *
     * @var int
     */
    protected static $transactionDepth = 0;

    /**
     * ชื่อตารางเต็มพร้อม prefix
     *
     * ⚠️ `orders` `customer` `stock` เป็นชื่อสามัญที่ชนกับตารางของโมดูลอื่นได้
     * ทุกที่ในโมดูลจึงต้องเรียกผ่านเมธอดนี้ ห้ามเขียนชื่อตารางเต็มไว้ในโค้ด
     *
     * @param string $table
     *
     * @return string
     */
    public static function table($table)
    {
        return static::create()->getTableName($table);
    }

    /**
     * ยอดที่ลูกค้าต้องจ่ายจริงของเอกสารหนึ่งใบ
     *
     * ⚠️ `orders`.`total` **ไม่รวมภาษี** ตามธรรมเนียมของสคีมานี้ ยอดที่ต้องจ่าย
     * คือ total + vat - tax เสมอ — ที่ไหนเทียบยอดชำระกับ `total` เปล่า ๆ
     * เอกสารที่จ่ายขาดไปเท่ากับ vat จะถูกทำเครื่องหมายว่าจ่ายครบ แล้วไม่มีใครตามเก็บ
     *
     * @param object|array $order
     *
     * @return float
     */
    public static function payable($order)
    {
        $order = (array) $order;
        $value = function ($key) use ($order) {
            return isset($order[$key]) ? (float) $order[$key] : 0.0;
        };

        return round($value('total') + $value('vat') - $value('tax'), 2);
    }

    /**
     * ทิศทางที่ถูกต้องของชนิดการเคลื่อนไหวหนึ่ง
     *
     * @param string $type
     *
     * @return string|null null = ไม่รู้จักชนิดนี้
     */
    public static function movementDirection($type)
    {
        return isset(self::$movementTypes[$type]) ? self::$movementTypes[$type] : null;
    }

    /**
     * ชนิดการเคลื่อนไหวทั้งหมดที่ลงทะเบียนไว้
     *
     * @return array [ชนิด => ทิศทาง]
     */
    public static function movementTypes()
    {
        return self::$movementTypes;
    }

    /**
     * ชนิดเอกสารที่เปิดใช้งาน แยกตามโหมด — ใช้สร้างเมนูและตัวกรอง
     *
     * อ่านจากแม่แบบในฐานข้อมูลเสมอ ไม่ได้ประกาศชนิดเอกสารไว้ในโค้ด แอดมินเพิ่ม
     * หรือปิดชนิดเอกสารเองได้แล้วเมนูตามทันที ซึ่งเป็นเหตุผลที่ต้องมีตารางแม่แบบ
     *
     * @return array [mode => [document_type => ชื่อเอกสาร]]
     */
    public static function statusesByMode()
    {
        $result = ['buy' => [], 'sell' => [], 'borrow' => [], 'repair' => []];
        foreach (\Inventory\Document\Model::templates() as $type => $template) {
            if (empty($template['published'])) {
                continue;
            }
            $mode = isset($result[$template['mode']]) ? $template['mode'] : 'sell';
            $result[$mode][$type] = $template['topic'];
        }

        return $result;
    }

    /**
     * ชนิดเอกสารของโหมดหนึ่ง
     *
     * @param string $mode buy | sell | borrow | repair
     *
     * @return array รายการ document_type
     */
    public static function statuses($mode)
    {
        $all = self::statusesByMode();

        return isset($all[$mode]) ? array_keys($all[$mode]) : [];
    }

    /**
     * ชนิดเอกสารที่รับของเข้าสต๊อก / ที่ตัดของออกจากสต๊อก
     *
     * @return array
     */
    public static function inStockStatuses()
    {
        return self::stockStatuses('in_stock');
    }

    /**
     * @return array
     */
    public static function outStockStatuses()
    {
        return self::stockStatuses('cut_stock');
    }

    /**
     * ชนิดเอกสารที่ตั้งธงหนึ่งไว้
     *
     * @param string $flag in_stock | cut_stock
     *
     * @return array
     */
    protected static function stockStatuses($flag)
    {
        $result = [];
        foreach (\Inventory\Document\Model::templates() as $type => $template) {
            if (!empty($template[$flag])) {
                $result[] = $type;
            }
        }

        return $result;
    }

    /**
     * สถานะภาษีของราคาสินค้า — ค่าเดียวกับที่ระบบเดิมเก็บในคอลัมน์ vat
     *
     * @return array
     */
    public static function taxStatuses()
    {
        return [
            0 => '{LNG_No tax}',
            1 => '{LNG_Tax excluded}',
            2 => '{LNG_Tax included}'
        ];
    }

    /**
     * ค่าขององค์กรหนึ่งค่า (ข้อมูลบริษัท รูปแบบเลขเอกสาร ฯลฯ)
     *
     * ⚠️ ต้องอ่านผ่านเมธอดนี้จุดเดียวเท่านั้น ห้ามอ่าน self::$cfg->xxx กระจาย
     * ทั่วโมดูล — เมื่อถึงวันที่ทำระบบผู้เช่า การเปลี่ยนให้ค่าขึ้นกับผู้เช่าจะได้
     * แก้ที่เดียว ไม่ต้องไล่ตามทั้งโมดูล (ข้อบังคับ 4.5 ของแผน)
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function config($key, $default = '')
    {
        $company = isset(self::$cfg->company) && is_array(self::$cfg->company) ? self::$cfg->company : [];
        if (isset($company[$key]) && $company[$key] !== '') {
            return $company[$key];
        }
        // ค่ากำหนดแบบคีย์แบนของระบบเดิม (company_name, vat, PO_prefix ...)
        if (isset(self::$cfg->{$key}) && self::$cfg->{$key} !== '') {
            return self::$cfg->{$key};
        }

        return $default;
    }
}
