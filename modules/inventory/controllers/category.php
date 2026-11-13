<?php
/**
 * @filesource modules/inventory/controllers/category.php
 *
 * ชนิดหมวดหมู่ที่โมดูล inventory ใช้
 *
 * \Gcms\Category ตัวกลางประกาศไว้แค่ department โมดูลที่มีหมวดหมู่ของตัวเอง
 * ต้องสืบทอดแล้วประกาศเพิ่ม ค่าคีย์ต้องตรงกับคอลัมน์ `type` ในตาราง category
 * ของฐานข้อมูลเดิม ไม่งั้นหมวดหมู่ที่ลูกค้าตั้งไว้จะหายไปทั้งหมด
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Category;

/**
 * หมวดหมู่ของโมดูล inventory
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Category
{
    /**
     * ชนิดหมวดหมู่ที่โมดูลนี้ดูแล
     *
     * category_id = หมวดหมู่สินค้า · unit = หน่วยนับ
     * model_id / type_id มีเฉพาะผลิตภัณฑ์ที่ใช้ทะเบียนครุภัณฑ์ (inventory, borrow)
     * ประกาศไว้ทั้งหมดได้ เพราะหมวดหมู่ที่ไม่มีข้อมูลก็แค่เป็นรายการว่าง
     *
     * @var array
     */
    protected $categories = [
        'category_id' => '{LNG_Category}',
        'unit' => '{LNG_Unit}',
        'model_id' => '{LNG_Model}',
        'type_id' => '{LNG_Type}'
    ];
}
