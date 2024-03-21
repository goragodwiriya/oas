<?php
/**
 * @filesource modules/inventory/models/detail.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Detail;

use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-write&id=xx&tab=detail
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลสินค้าที่ $id
     *
     * @param int $id
     *
     * @return object
     */
    public static function get($id)
    {
        $result = array(
            'image' => '',
            'detail' => '',
            'description' => ''
        );
        $query = static::createQuery()
            ->select('name', 'value')
            ->from('inventory_meta')
            ->where(array(
                array('inventory_id', $id),
                array('name', array_keys($result))
            ));
        foreach ($query->execute() as $item) {
            $result[$item->name] = $item->value;
        }
        return (object) $result;
    }

    /**
     * บันทึกข้อมูล (detail.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, can_manage_inventory, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_manage_inventory') && Login::notDemoMode($login)) {
                try {
                    // meta
                    $meta = [];
                    foreach (Language::get('INVENTORY_METAS', []) as $key => $label) {
                        if ($key == 'detail') {
                            $meta[$key] = $request->post($key)->textarea();
                        } else {
                            $meta[$key] = $request->post($key)->topic();
                        }
                    }
                    // อ่านข้อมูลที่เลือก
                    $index = \Inventory\Write\Model::get($request->post('write_id')->toInt());
                    if ($index) {
                        // ไดเร็คทอรี่เก็บไฟล์
                        $dir = ROOT_PATH.DATA_FOLDER.'inventory/';
                        // อัปโหลดไฟล์
                        foreach ($request->getUploadedFiles() as $item => $file) {
                            /* @var $file \Kotchasan\Http\UploadedFile */
                            if ($item == 'write_image') {
                                if ($file->hasUploadFile()) {
                                    if (!File::makeDirectory($dir)) {
                                        // ไดเรคทอรี่ไม่สามารถสร้างได้
                                        $ret['ret_'.$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'inventory/');
                                    } else {
                                        try {
                                            $meta['image'] = WEB_URL.DATA_FOLDER.'inventory/'.$index->id.'.jpg';
                                            $file->resizeImage(self::$cfg->inventory_img_typies, $dir, $index->id.'.jpg', self::$cfg->inventory_w);
                                        } catch (\Exception $exc) {
                                            // ไม่สามารถอัปโหลดได้
                                            $ret['ret_'.$item] = Language::get($exc->getMessage());
                                        }
                                    }
                                } elseif ($file->hasError()) {
                                    // ข้อผิดพลาดการอัปโหลด
                                    $ret['ret_'.$item] = Language::get($file->getErrorMessage());
                                }
                            }
                        }
                        if (empty($ret)) {
                            // แก้ไข
                            $table = $this->getTableName('inventory_meta');
                            // Database
                            $db = $this->db();
                            // meta
                            $db->delete($table, array(
                                array('inventory_id', $index->id),
                                array('name', array_keys($meta))
                            ), 0);
                            foreach ($meta as $key => $value) {
                                if ($value != '') {
                                    $db->insert($table, array(
                                        'inventory_id' => $index->id,
                                        'name' => $key,
                                        'value' => $value
                                    ));
                                }
                            }
                            // log
                            \Index\Log\Model::add($index->id, 'inventory', 'Save', '{LNG_Other details} ID : '.$index->id, $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            $ret['location'] = 'reload';
                            // เคลียร์
                            $request->removeToken();
                        }
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
