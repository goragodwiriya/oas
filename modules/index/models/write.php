<?php
/**
 * @filesource modules/index/models/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Write;

use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * บันทึกข้อมูล (write.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                try {
                    // รับค่าจากการ POST
                    $src = $request->post('write_src')->filter('a-z');
                    $language = $request->post('write_language')->filter('a-z');
                    $detail = $request->post('write_detail')->detail();
                    $pages = Language::get('PAGES');
                    if (array_key_exists($src, $pages) && in_array($language, Language::installedLanguage())) {
                        if (!File::makeDirectory(ROOT_PATH.DATA_FOLDER.'pages/')) {
                            // ไม่สามารถสร้างไดเร็คทอรี่ได้
                            $ret['alert'] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'pages/');
                        } else {
                            // บันทึก
                            $f = @fopen(ROOT_PATH.DATA_FOLDER.'pages/'.$src.'_'.$language.'.html', 'w');
                            if ($f) {
                                fwrite($f, $detail);
                                fclose($f);
                                // log
                                \Index\Log\Model::add(0, 'index', 'Save', '{LNG_Details of} '.$pages[$src], $login['id']);
                                // คืนค่า
                                $ret['alert'] = Language::get('Saved successfully');
                                // reload
                                $ret['location'] = 'reload';
                                // เคลียร์
                                $request->removeToken();
                            } else {
                                // ไม่สามารถเขียนไฟล์ได้
                                $ret['alert'] = Language::replace('Directory %s cannot be created or is read-only.', $src.'_'.$language.'.html');
                            }
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
