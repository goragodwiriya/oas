<?php
/**
 * @filesource modules/index/models/api.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Api;

use \Kotchasan\Http\Request;

/**
 * Model สำหรับโหลดข้อมูลส่งให้ API
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * คืนค่ารายการหมวดหมู่ทั้งหมด
   *
   * @param Request $request
   * @return array
   */
  public static function categories(Request $request)
  {
    $query = static::create()->db()->createQuery()
      ->select('category_id', 'topic')
      ->from('category')
      ->where(array('type', 0))
      ->order('category_id')
      ->toArray()
      ->cacheOn();
    $result = array();
    foreach ($query->execute() as $item) {
      $result[] = array(
        'category_id' => $item['category_id'],
        'topic' => $item['topic'],
      );
    }
    return $result;
  }

  /**
   * อ่านชื่อหมวดหมู่ที่ต้องการ
   *
   * @param int $id
   * @return string
   */
  private static function category($id)
  {
    $search = static::create()->db()->createQuery()
      ->from('category')
      ->where(array(
        array('category_id', $id),
        array('type', 0)
      ))
      ->toArray()
      ->cacheOn()
      ->first('topic');
    return $search ? $search['topic'] : '';
  }

  /**
   * คืนค่ารายการสินค้า ถ้ามีการระบุ id มา หมายถึงสินค้าในหมวดที่เลือก
   *
   * @param Request $request
   * @return array
   */
  public static function products(Request $request)
  {
    // ค่าที่ส่งมา
    $q = $request->get('q')->topic();
    $category_id = $request->get('category_id')->toInt();
    $page = $request->get('page')->toInt();
    $list_per_page = $request->get('limit', 30)->toInt();
    // ตัวแปรสำหรับส่งค่ากลับ
    $result = array();
    // Model
    $model = new static;
    $query = $model->db()->createQuery()->from('product');
    // หมวดหมู่
    if ($category_id > 0) {
      $query->where(array('category_id', $category_id));
      $result['category_id'] = $category_id;
      $result['category'] = self::category($category_id);
    }
    $where = array();
    if ($q != '') {
      foreach (explode(' ', $q) as $item) {
        $where[] = array('product_no', 'LIKE', "%$item%");
        $where[] = array('topic', 'LIKE', "%$item%");
        $where[] = array('description', 'LIKE', "%$item%");
      }
    }
    if (!empty($where)) {
      $query->andWhere(\Kotchasan\Database\Sql::WHERE($where, 'OR'));
      $result['q'] = $q;
    }
    // จำนวน
    $result['total'] = $query->cacheOn()->count();
    $result['totalpage'] = ceil($result['total'] / $list_per_page);
    $result['page'] = max(1, ($page > $result['totalpage'] ? $result['totalpage'] : $page));
    $result['start'] = $list_per_page * ($result['page'] - 1);
    // query
    $result['items'] = $query->order('id')
      ->select()
      ->order('topic', 'product_no')
      ->limit($list_per_page, $result['start'])
      ->cacheOn()
      ->toArray()
      ->execute();
    // คืนค่า
    return $result;
  }

  /**
   * ค้นหารายการสินค้า จาก product_no topic description
   *
   * @param Request $request
   * @return array
   */
  public static function search(Request $request)
  {
    return self::products($request);
  }

  /**
   * คืนค่ารายละเอียดของสินค้าที่ id
   *
   * @param Request $request
   * @return array
   */
  public static function product(Request $request)
  {
    return static::create()->db()->createQuery()
        ->from('product')
        ->where(array('id', $request->get('id')->toInt()))
        ->cacheOn()
        ->toArray()
        ->first();
  }
}
