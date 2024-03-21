<?php
/**
 * @filesource modules/inventory/models/api.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Api;

use Kotchasan\Database\Sql;
use Kotchasan\Http\Request;

/**
 * api.php/v1/product/
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model
{
    /**
     * api.php/v1/product/categories
     * คืนค่าหมวดหมู่ทั้งหมด
     * สำหรับส่งให้ API
     *
     * @param  Request $request
     *
     * @return array
     */
    public static function categories(Request $request)
    {
        return \Kotchasan\Model::createQuery()
            ->select('category_id', 'topic')
            ->from('category')
            ->where(array('type', 'category_id'))
            ->order('topic')
            ->toArray()
            ->cacheOn()
            ->execute();
    }

    /**
     * api.php/v1/product/products/category_id/page
     * คืนค่ารายการสินค้า ถ้ามีการระบุ id มา หมายถึงสินค้าในหมวดที่เลือก
     *
     * @param  Request $request
     * @return array
     */
    public static function products(Request $request)
    {
        try {
            // ค่าที่ส่งมา
            $q = $request->get('q')->topic();
            $category_id = $request->get('category_id')->toInt();
            $page = $request->get('page')->toInt();
            $list_per_page = $request->get('limit', 30)->toInt();
            // ตัวแปรสำหรับส่งค่ากลับ
            $result = [];
            $query = \Kotchasan\Model::createQuery()
                ->from('inventory V')
                ->join('inventory_items I', 'INNER', array('I.inventory_id', 'V.id'))
                ->join('inventory_meta D', 'LEFT', array(array('D.inventory_id', 'V.id'), array('D.name', 'detail')));
            // หมวดหมู่
            if ($category_id > 0) {
                $query->where(array(
                    array('V.category_id', $category_id),
                    array('V.inuse', 1)
                ));
                $result['category_id'] = $category_id;
                $result['category'] = self::category($category_id);
            }
            $where = [];
            if ($q != '') {
                foreach (explode(' ', $q) as $item) {
                    $where[] = array('I.product_no', 'LIKE', "%$item%");
                    $where[] = array('V.topic', 'LIKE', "%$item%");
                    $where[] = array('D.value', 'LIKE', "%$item%");
                }
            } else {
                $where[] = array('I.cut_stock', 1);
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
            $result['items'] = $query->select(
                'V.id',
                'I.product_no',
                'V.topic',
                'N.value description',
                'D.value detail',
                'I.price',
                'U.value url',
                'V.category_id',
                'V.count_stock',
                Sql::create('IFNULL(M.`value`,"'.WEB_URL.'skin/img/noicon.png") AS image'),
                'I.unit'
            )
                ->join('inventory_meta N', 'LEFT', array(array('N.inventory_id', 'V.id'), array('N.name', 'description')))
                ->join('inventory_meta M', 'LEFT', array(array('M.inventory_id', 'V.id'), array('M.name', 'image')))
                ->join('inventory_meta U', 'LEFT', array(array('U.inventory_id', 'V.id'), array('U.name', 'url')))
                ->order('V.topic', 'V.product_no')
                ->limit($list_per_page, $result['start'])
                ->order('V.id')
                ->cacheOn()
                ->toArray()
                ->execute();
            // คืนค่า
        } catch (\Kotchasan\InputItemException $e) {
            throw new \Kotchasan\ApiException($e->getMessage(), 400);
        }
        return $result;
    }

    /**
     * api.php/v1/product/get/id
     * คืนค่ารายละเอียดของสินค้าที่ id
     *
     * @param  Request $request
     * @return array
     */
    public static function get(Request $request)
    {
        $product = \Kotchasan\Model::createQuery()
            ->from('inventory V')
            ->join('inventory_items I', 'INNER', array('I.inventory_id', 'V.id'))
            ->join('inventory_meta N', 'LEFT', array(array('N.inventory_id', 'V.id'), array('N.name', 'description')))
            ->join('inventory_meta D', 'LEFT', array(array('D.inventory_id', 'V.id'), array('D.name', 'detail')))
            ->join('inventory_meta M', 'LEFT', array(array('M.inventory_id', 'V.id'), array('M.name', 'image')))
            ->join('inventory_meta U', 'LEFT', array(array('U.inventory_id', 'V.id'), array('U.name', 'url')))
            ->where(array('V.id', $request->get('id')->toInt()))
            ->cacheOn()
            ->toArray()
            ->first(
                'V.id',
                'I.product_no',
                'V.topic',
                'N.value description',
                'D.value detail',
                'I.price',
                'U.value url',
                'V.category_id',
                'V.count_stock',
                Sql::create('IFNULL(M.`value`,"'.WEB_URL.'skin/img/noicon.png") AS image'),
                'I.unit'
            );
        if ($product) {
            $product['description'] = self::removeTags($product['description']);
        } else {
            $product = array(
                'error' => array(
                    'code' => 404,
                    'message' => 'Not Found'
                )
            );
        }
        return $product;
    }

    /**
     * อ่านชื่อหมวดหมู่ที่ต้องการ
     *
     * @param  int      $id
     * @return string
     */
    private static function category($id)
    {
        $search = \Kotchasan\Model::createQuery()
            ->from('category')
            ->where(array(
                array('category_id', $id),
                array('type', 'category_id')
            ))
            ->toArray()
            ->cacheOn()
            ->first('topic');
        return $search ? $search['topic'] : '';
    }

    /**
     * ลบ Tag ออกจากข้อความ สำหรับ description
     *
     * @param string $text
     *
     * @return string
     */
    public static function removeTags($text)
    {
        $text = preg_replace('/<(style|script).*\\1>/isU', '', $text);
        $text = preg_replace(array('/&nbsp;/', '/[\r\n\s\t]{1,}/isU', '/[\s]+/'), ' ', strip_tags($text));
        return trim($text);
    }
}
