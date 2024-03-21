<?php
/**
 * @filesource modules/index/models/categories.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Categories;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=categories
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านหมวดหมู่สำหรับใส่ลงใน DataTable
     * ถ้าไม่มีคืนค่าข้อมูลเปล่าๆ 1 แถว
     *
     * @param string $type
     *
     * @return array
     */
    public static function toDataTable($type)
    {
        // Query ข้อมูลหมวดหมู่จากตาราง category
        $query = static::createQuery()
            ->select('category_id', 'topic')
            ->from('category')
            ->where(array('type', $type))
            ->order('category_id');
        $result = [];
        foreach ($query->execute() as $item) {
            $result[$item->category_id] = array(
                'category_id' => $item->category_id,
                'topic' => $item->topic
            );
        }
        if (empty($result)) {
            $result[1] = array(
                'category_id' => 1,
                'topic' => ''
            );
        }
        return $result;
    }

    /**
     * บันทึกหมวดหมู่ (categories.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, can_config, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && Login::checkPermission($login, 'can_config')) {
                try {
                    // ค่าที่ส่งมา
                    $type = $request->post('type')->topic();
                    $save = [];
                    $category_exists = [];
                    foreach ($request->post('category_id', [])->topic() as $key => $value) {
                        if (isset($category_exists[$value])) {
                            $ret['ret_category_id_'.$key] = Language::replace('This :name already exist', array(':name' => 'ID'));
                        } else {
                            $category_exists[$value] = $value;
                            $save[$key]['category_id'] = $value;
                        }
                    }
                    foreach ($request->post('topic')->topic() as $key => $value) {
                        if (isset($save[$key]) && $value != '') {
                            $save[$key]['topic'] = $value;
                        }
                    }
                    if (empty($ret)) {
                        // ชื่อตาราง
                        $table_name = $this->getTableName('category');
                        // db
                        $db = $this->db();
                        // ลบข้อมูลเดิม
                        $db->delete($table_name, array('type', $type), 0);
                        // เพิ่มข้อมูลใหม่
                        foreach ($save as $item) {
                            if (isset($item['topic'])) {
                                $item['type'] = $type;
                                $db->insert($table_name, $item);
                            }
                        }
                        // log
                        \Index\Log\Model::add(0, 'index', 'Save', Language::get('CATEGORIES', $type, $type), $login['id']);
                        // คืนค่า
                        $ret['alert'] = Language::get('Saved successfully');
                        $ret['location'] = 'reload';
                        // เคลียร์
                        $request->removeToken();
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
            if (empty($ret)) {
                $ret['alert'] = Language::get('Unable to complete the transaction');
            }
            // คืนค่า JSON
            echo json_encode($ret);
        }
    }
}
