<?php
/**
 * @filesource modules/inventory/models/image.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Image;

use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-image
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกการตั้งค่ารูปภาพแสดงในใบเสร็จ (image.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, can_config
        if ($request->initSession() && $request->isSafe() && $login = Login::checkPermission(Login::isMember(), 'can_config')) {
            if (empty($login['social'])) {
                // ไดเร็คทอรี่ของ user
                $dir = ROOT_PATH.DATA_FOLDER;
                // อัปโหลดไฟล์
                foreach ($request->getUploadedFiles() as $item => $file) {
                    if (!File::makeDirectory($dir)) {
                        // ไดเรคทอรี่ไม่สามารถสร้างได้
                        $ret['ret_'.$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER);
                    } elseif ($request->post('delete_'.$item)->toBoolean() == 1) {
                        // ลบรูปภาพ
                        if (is_file($dir.$item.'.jpg')) {
                            @unlink($dir.$item.'.jpg');
                        }
                    } elseif ($file->hasUploadFile()) {
                        // ตรวจสอบไฟล์อัปโหลด
                        if (!$file->validFileExt(array('jpg', 'jpeg', 'png'))) {
                            $ret['ret_'.$item] = Language::get('The type of file is invalid');
                        } else {
                            try {
                                $file->moveTo($dir.$item.'.jpg');
                            } catch (\Exception $exc) {
                                // ไม่สามารถอัปโหลดได้
                                $ret['ret_'.$item] = Language::get($exc->getMessage());
                            }
                        }
                    } elseif ($err = $file->getErrorMessage()) {
                        // upload error
                        $ret['ret_'.$item] = $err;
                    }
                }
                if (empty($ret)) {
                    // log
                    \Index\Log\Model::add(0, 'inventory', 'Save', '{LNG_Pictures for a receipt}', $login['id']);
                    // คืนค่า
                    $ret['alert'] = Language::get('Saved successfully');
                    $ret['location'] = 'reload';
                    // เคลียร์
                    $request->removeToken();
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
