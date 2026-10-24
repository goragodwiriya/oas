<?php
/**
 * @filesource modules/inventory/models/upgrade.php
 *
 * งานที่ตัวปรับรุ่นฝากไว้ให้แอปทำต่อ — ตัวปรับรุ่น (install/) ทำงานนอกแอป เรียก Posting API ไม่ได้
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Upgrade;

/**
 * Model งานหลังปรับรุ่น
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คำนวณ cache ของสต๊อกใหม่ครั้งเดียวหลังปรับรุ่น (ธง inventory_rebuild ที่ modules/inventory/install/upgrade.php ตั้งไว้)
     *
     * ล้างธงก่อนคำนวณ — ข้อมูลที่คำนวณไม่ผ่านจะไม่ทำให้ทุกหน้าล้มซ้ำ (บันทึกไว้ใน log ให้ตรวจ)
     * ล้างธงไม่ได้ (เขียนไฟล์ค่ากำหนดไม่ได้) = ไม่คำนวณ ไม่งั้นจะคำนวณซ้ำทุกครั้งที่เปิดหน้า
     *
     * @param int $memberId ผู้ที่เปิดหน้าแรกหลังปรับรุ่น (ลงใน log)
     *
     * @return array|null แถวที่ cache เดิมไม่ตรง · null = ไม่มีงานค้าง
     */
    public static function rebuildPending($memberId = 0)
    {
        if (empty(self::$cfg->inventory_rebuild)) {
            return null;
        }
        // แฟ้มที่ค่ากำหนดมาจริง — Kotchasan\Config อ่าน ROOT_PATH แล้วทับด้วย APP_PATH (ไซต์ที่ชี้ค่ากำหนดไปที่อื่น)
        if (defined('CONFIG')) {
            $file = \CONFIG;
        } elseif (ROOT_PATH !== APP_PATH && is_file(APP_PATH.'settings/config.php')) {
            $file = APP_PATH.'settings/config.php';
        } else {
            $file = ROOT_PATH.'settings/config.php';
        }
        $config = \Kotchasan\Config::load($file);
        unset($config->inventory_rebuild);
        if (!\Kotchasan\Config::save($config, $file)) {
            return null;
        }
        self::$cfg->inventory_rebuild = 0;
        try {
            $diff = \Inventory\Posting\Model::rebuild();
            \Index\Log\Model::add(0, 'inventory', 'Save', 'คำนวณยอดคงเหลือและต้นทุนใหม่หลังปรับรุ่น (แก้ '.count($diff).' แถว)', $memberId);

            return $diff;
        } catch (\Exception $e) {
            \Index\Log\Model::add(0, 'inventory', 'Error', 'คำนวณยอดคงเหลือใหม่หลังปรับรุ่นไม่สำเร็จ : '.$e->getMessage(), $memberId);

            return [];
        }
    }
}
