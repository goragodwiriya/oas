<?php
/**
 * @filesource modules/index/models/category.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Category;

use \Kotchasan\Database\Sql;

/**
 * Model สำหรับจัดการหมวดหมู่ต่างๆ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
  private $datas = array();

  /**
   * อ่านรายชื่อหมวดหมู่จากฐานข้อมูลตามภาษาปัจจุบัน
   * สำหรับการแสดงผล
   *
   * @param int $type_id
   * @return \static
   */
  public static function init($type_id)
  {
    $obj = new static;
    // อ่านรายชื่อตำแหน่งจากฐานข้อมูล
    foreach (self::generate($type_id) as $item) {
      $obj->datas[$item['category_id']] = $item['topic'];
    }
    return $obj;
  }

  /**
   * Query ข้อมูลหมวดหมู่จากฐานข้อมูล
   *
   * @param int $type_id
   * @return array
   */
  public static function generate($type_id)
  {
    // Model
    $model = new static;
    // Query
    $query = $model->db()->createQuery()
      ->select('id', 'category_id', 'topic')
      ->from('category')
      ->where(array(
        array('type', $type_id),
      ))
      ->order('category_id')
      ->toArray()
      ->cacheOn();
    $result = array();
    foreach ($query->execute() as $item) {
      $result[$item['category_id']] = array(
        'id' => $item['id'],
        'category_id' => $item['category_id'],
        'topic' => $item['topic'],
      );
    }
    return $result;
  }

  /**
   * อ่านหมวดหมู่สำหรับใส่ลงใน DataTable
   * ถ้าไม่มีคืนค่าข้อมูลเปล่าๆ 1 แถว
   *
   * @param int $type_id
   * @return array
   */
  public static function toDataTable($type_id)
  {
    // Query ข้อมูลหมวดหมู่จากฐานข้อมูล
    $result = self::generate($type_id);
    if (empty($result)) {
      $result = array(array('id' => 0, 'category_id' => 1, 'topic' => ''));
    }
    return $result;
  }

  /**
   * ลิสต์รายการหมวดหมู่
   * สำหรับใส่ลงใน select
   *
   * @return array
   */
  public function toSelect()
  {
    return $this->datas;
  }

  /**
   * อ่านหมวดหมู่จาก $category_id
   * ไม่พบ คืนค่าว่าง
   *
   * @param int $category_id
   * @return string
   */
  public function get($category_id)
  {
    return isset($this->datas[$category_id]) ? $this->datas[$category_id] : '';
  }

  /**
   * ฟังก์ชั่นอ่านหมวดหมู่ หรือ บันทึก ถ้าไม่มีหมวดหมู่
   *
   * @param int $type_id
   * @param string $topic
   * @return int คืนค่า category_id
   */
  protected static function check($type_id, $topic)
  {
    $topic = trim($topic);
    if ($topic == '') {
      return 0;
    } else {
      $model = new static;
      $search = $model->db()->createQuery()
        ->from('category')
        ->where(array(
          array('type', $type_id),
          array('topic', $topic),
        ))
        ->toArray()
        ->first('category_id');
      if ($search) {
        // มีหมวดหมู่อยู่แล้ว
        return $search['category_id'];
      } else {
        // ไม่มีหมวดหมู่ ตรวจสอบ category_id ใหม่
        $search = $model->db()->createQuery()
          ->from('category')
          ->where(array('type', $type_id))
          ->toArray()
          ->first(Sql::MAX('category_id', 'category_id'));
        $category_id = empty($search['category_id']) ? 1 : (1 + (int)$search['category_id']);
        // save
        $model->db()->insert($model->getTableName('category'), array(
          'type' => $type_id,
          'category_id' => $category_id,
          'topic' => $topic,
        ));
        return $category_id;
      }
    }
  }
}