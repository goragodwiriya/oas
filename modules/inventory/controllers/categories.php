<?php
/**
 * @filesource modules/inventory/controllers/categories.php
 *
 * api/inventory/categories/get|save — หมวดหมู่ของโมดูล inventory
 *
 * ใช้ตัวจัดการหมวดหมู่ของแกนทั้งดุ้น เปลี่ยนแค่ "ชนิดหมวดหมู่ที่ดูแล"
 * ตัวแกนอ่าน/เขียนตาราง category ตัวเดียวกันอยู่แล้ว ต่างกันแค่คอลัมน์ `type`
 * จึงไม่มีเหตุผลให้เขียน get()/save() ขึ้นมาใหม่ให้ต้องตามแก้สองที่
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Categories;

/**
 * หมวดหมู่ของโมดูล inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Index\Categories\Controller
{
    /**
     * ชนิดหมวดหมู่ที่หน้านี้ดูแล — ต้องตรงกับ Inventory\Category\Controller
     *
     * @var array
     */
    protected $categories = [
        'category_id' => '{LNG_Category}',
        'unit' => '{LNG_Unit}',
        'model_id' => '{LNG_Model}',
        'type_id' => '{LNG_Type}'
    ];

    /**
     * ชนิดหมวดหมู่ที่โมดูลนี้ดูแล สำหรับให้เมนูวนสร้างรายการ
     * ประกาศไว้ที่เดียวคือ $categories ด้านบน เมนูจึงไม่มีทางหลุดจากของจริง
     *
     * @return array [type => label]
     */
    public static function types()
    {
        return (new static())->categories;
    }
}
