<?php
/**
 * @filesource modules/inventory/models/detail.php
 *
 * รายละเอียดเพิ่มเติมของสินค้า (inventory_meta) + รูปสินค้า
 * ระบบเดิมคือ module=inventory-write&tab=detail
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Detail;

use Inventory\Base\Model as Base;

/**
 * Model รายละเอียดเพิ่มเติมของสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * โฟลเดอร์เก็บรูปสินค้า (เส้นทางเดียวกับระบบเดิม datas/inventory/{id}.jpg)
     *
     * @return string
     */
    public static function imageDir()
    {
        return ROOT_PATH.DATA_FOLDER.'inventory/';
    }

    /**
     * ชื่อไฟล์รูปของสินค้าหนึ่งตัว
     *
     * @param int $id
     *
     * @return string
     */
    public static function imageName($id)
    {
        return ((int) $id).self::$cfg->stored_img_type;
    }

    /**
     * URL รูปสินค้า ถ้ามีไฟล์อยู่จริง
     *
     * @param int $id
     *
     * @return string|null
     */
    public static function imageUrl($id)
    {
        $file = self::imageDir().self::imageName($id);
        if (!is_file($file)) {
            return null;
        }

        // ต่อเวลาแก้ไขไฟล์ท้าย URL กันเบราว์เซอร์แสดงรูปเก่าหลังอัปโหลดทับ
        return WEB_URL.DATA_FOLDER.'inventory/'.self::imageName($id).'?'.filemtime($file);
    }

    /**
     * ลบรูปสินค้า
     *
     * @param int $id
     *
     * @return bool
     */
    public static function removeImage($id)
    {
        $file = self::imageDir().self::imageName($id);
        if (is_file($file)) {
            return @unlink($file);
        }

        return false;
    }

    /**
     * บันทึกรายละเอียดเพิ่มเติม
     *
     * เก็บลง inventory_meta เหมือนระบบเดิม และเขียนคอลัมน์ `inventory`.`description`
     * ให้ตรงกันด้วย เพราะข้อมูลจริงบางส่วนอยู่ที่คอลัมน์ ไม่ได้อยู่ใน meta
     * (ถ้าเขียนที่เดียวจะมีสองแหล่งที่ไม่ตรงกันอีก)
     *
     * @param int   $id
     * @param array $metas ['description' => ..., 'detail' => ...]
     *
     * @return void
     */
    /**
     * อ่านรายละเอียดเพิ่มเติมของสินค้า (คู่กับ save)
     *
     * @param int    $id
     * @param string $name ชื่อรายการ เช่น detail
     *
     * @return string ว่างเมื่อไม่มี
     */
    public static function meta($id, $name)
    {
        $row = static::createDB()->first(Base::table('inventory_meta'), [
            'inventory_id' => (int) $id,
            'name' => (string) $name
        ]);

        return $row ? (string) $row->value : '';
    }

    public static function save($id, array $metas)
    {
        $db = static::createDB();
        $table = Base::table('inventory_meta');
        $id = (int) $id;

        $db->delete($table, [
            ['inventory_id', $id],
            ['name', array_keys($metas)]
        ], 0);

        foreach ($metas as $name => $value) {
            if ($value !== '') {
                $db->insert($table, [
                    'inventory_id' => $id,
                    'name' => $name,
                    'value' => $value
                ]);
            }
        }

        if (array_key_exists('description', $metas)) {
            $db->update(Base::table('inventory'), ['id', $id], [
                'description' => $metas['description'],
                'last_update' => time()
            ]);
        }
    }
}
