<?php
/**
 * @filesource modules/index/models/theme.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Theme;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=theme
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกการตั้งค่าเว็บไซต์ (theme.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, member, can_config, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_config') && Login::notDemoMode($login)) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    // ค่าที่ส่งมา
                    $config->skin = $request->post('skin')->filter('a-z\/');
                    foreach (array('show_title_logo', 'new_line_title') as $key) {
                        $config->$key = $request->post($key)->toBoolean();
                    }
                    if ($request->post('theme_option')->toBoolean()) {
                        // ใช้ค่าเริ่มต้นของ template
                        $settings = include ROOT_PATH.$config->skin.'/settings.php';
                        foreach ($settings as $key => $value) {
                            if ($key === 'name') {
                                continue;
                            }
                            $config->$key = $value;
                        }
                    } else {
                        // ใช้ค่าที่ส่งมา
                        foreach (array('header_bg_color', 'warpper_bg_color', 'content_bg', 'header_color', 'footer_color', 'logo_color') as $key) {
                            $config->$key = $request->post($key)->filter('#ABCDEF0-9');
                        }
                        $config->theme_width = $request->post('theme_width')->filter('a-z');
                    }
                    if (empty($ret)) {
                        // อัปโหลดไฟล์
                        $dir = ROOT_PATH.DATA_FOLDER.'images/';
                        foreach ($request->getUploadedFiles() as $item => $file) {
                            if (preg_match('/^file_(logo|bg_image)$/', $item, $match)) {
                                /* @var $file \Kotchasan\Http\UploadedFile */
                                if (!File::makeDirectory($dir)) {
                                    // ไดเรคทอรี่ไม่สามารถสร้างได้
                                    $ret['ret_file_'.$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'images/');
                                } elseif ($request->post('delete_'.$match[1])->toBoolean() == 1) {
                                    // ลบ
                                    if (is_file($dir.$match[1].'.png')) {
                                        unlink($dir.$match[1].'.png');
                                    }
                                } elseif ($file->hasUploadFile()) {
                                    if (!$file->validFileExt(array('jpg', 'jpeg', 'png'))) {
                                        // ชนิดของไฟล์ไม่รองรับ
                                        $ret['ret_file_'.$match[1]] = Language::get('The type of file is invalid');
                                    } else {
                                        try {
                                            $file->moveTo($dir.$match[1].'.png');
                                        } catch (\Exception $exc) {
                                            // ไม่สามารถอัปโหลดได้
                                            $ret['ret_file_'.$match[1]] = Language::get($exc->getMessage());
                                        }
                                    }
                                } elseif ($file->hasError()) {
                                    // ข้อผิดพลาดการอัปโหลด
                                    $ret['ret_file_'.$match[1]] = Language::get($file->getErrorMessage());
                                }
                            }
                        }
                    }
                    if (empty($ret)) {
                        // อัปเดทเลขเวอร์ชั่นของไฟล์
                        $config->reversion = time();
                        // save config
                        if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                            // log
                            \Index\Log\Model::add(0, 'index', 'Save', '{LNG_General site settings}', $login['id']);
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            $ret['url'] = 'index.php?module=theme&'.time();
                            // เคลียร์
                            $request->removeToken();
                        } else {
                            // ไม่สามารถบันทึก config ได้
                            $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
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
