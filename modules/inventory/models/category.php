<?php
/**
 * @filesource modules/inventory/models/category.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Category;

/**
 * Model สำหรับจัดการหมวดหมู่ต่างๆ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Index\Category\Model
{

  /**
   * คืนค่าหมวดหมู่สินค้า
   *
   * @return \static
   */
  public static function categories()
  {
    return self::init(0);
  }

  /**
   * หมวดหมู่สินค้า
   *
   * @param string $topic
   * @return int คืนค่า category_id
   */
  public static function newCategory($topic)
  {
    return self::check(0, $topic);
  }

  /**
   * หน่วยของสินค้า
   *
   * @param string $topic
   * @return int คืนค่า category_id
   */
  public static function newUnit($topic)
  {
    return self::check(3, $topic);
  }
}