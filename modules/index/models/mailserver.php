<?php
/**
 * @filesource modules/index/models/mailserver.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Mailserver;

use Gcms\Login;
use Kotchasan\Config;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Validator;

/**
 * module=mailserver
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * บันทึกการตั้งค่าระบบอีเมล (mailserver.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, admin, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                try {
                    // โหลด config
                    $config = Config::load(ROOT_PATH.'settings/config.php');
                    // รับค่าจากการ POST
                    $config->noreply_email = $request->post('noreply_email')->url();
                    if ($config->noreply_email != '' && !Validator::email($config->noreply_email)) {
                        // noreply_email ไม่ถูกต้อง
                        $ret['ret_noreply_email'] = str_replace(':name', Language::get('Email'), Language::get('Invalid :name'));
                    } else {
                        $email_charset = $request->post('email_charset')->text();
                        $config->email_charset = $email_charset == '' ? 'utf-8' : strtolower($email_charset);
                        $email_Host = $request->post('email_Host')->text();
                        if (empty($email_Host)) {
                            $config->email_Host = 'localhost';
                            $config->email_Port = 25;
                            $config->email_SMTPSecure = '';
                            $config->email_Username = '';
                            $config->email_Password = '';
                        } else {
                            $config->email_Host = $email_Host;
                            $config->email_Port = $request->post('email_Port', 25)->toInt();
                            $config->email_SMTPSecure = $request->post('email_SMTPSecure')->text();
                            $config->email_Username = $request->post('email_Username')->quote();
                            $email_Password = $request->post('email_Password')->quote();
                            if (!empty($email_Password)) {
                                $config->email_Password = $email_Password;
                            }
                        }
                        $config->email_use_phpMailer = $request->post('email_use_phpMailer')->toInt();
                        $config->email_SMTPAuth = $request->post('email_SMTPAuth')->toBoolean();
                        if (empty($ret)) {
                            // save config
                            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                                // log
                                \Index\Log\Model::add(0, 'index', 'Save', '{LNG_Setting up the email system}', $login['id']);
                                // คืนค่า
                                $ret['alert'] = Language::get('Saved successfully');
                                $ret['location'] = 'reload';
                                // เคลียร์
                                $request->removeToken();
                            } else {
                                // ไม่สามารถบันทึก config ได้
                                $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
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
