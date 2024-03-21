<?php
/**
 * @filesource modules/index/models/modules.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Modules;

use Gcms\Config;
use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=modules
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * อ่านข้อมูลสำหรับใส่ลงในตาราง
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable()
    {
        // ไดเร็คทอรี่ modules/
        $dir = ROOT_PATH.'modules/';
        // โหลด config
        $config = Config::load(ROOT_PATH.'settings/config.php');
        $result = [];
        // เมนูจาก config
        if (!empty($config->modules)) {
            foreach ($config->modules as $module => $published) {
                if (self::hasMenu($dir, $module)) {
                    $result[$module] = array(
                        'id' => $module,
                        'published' => $published
                    );
                }
            }
        }
        // เมนูจากไดเร็คทอรี่ที่ติดตั้ง
        $f = @opendir($dir);
        if ($f) {
            while (false !== ($text = readdir($f))) {
                if (!isset($result[$text]) && self::hasMenu($dir, $text)) {
                    $result[$text] = array(
                        'id' => $text,
                        'published' => 0
                    );
                }
            }
            closedir($f);
        }
        return $result;
    }

    /**
     * ตรวจสอบว่ามีเมนูหรือไม่
     * คืนค่า true ถ้ามีเมนู
     *
     * @param string $dir
     * @param string $module
     *
     * @return bool
     */
    private static function hasMenu($dir, $module)
    {
        if (is_file($dir.$module.'/controllers/initmenu.php')) {
            require_once $dir.$module.'/controllers/initmenu.php';
            $className = '\\'.ucfirst($module).'\\Initmenu\Controller';
            if (method_exists($className, 'execute')) {
                return true;
            }
        }
        return false;
    }

    /**
     * รับค่าจากตาราง (modules.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = [];
        // session, referer, admin, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                // โหลด config
                $config = Config::load(ROOT_PATH.'settings/config.php');
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                if ($action == 'move' && preg_match_all('/,?([a-z]+),?/', $request->post('data')->toString(), $match)) {
                    // เรียงลำดับ
                    $cfg = [];
                    foreach ($match[1] as $module) {
                        $cfg[$module] = isset($config->modules[$module]) ? $config->modules[$module] : 0;
                    }
                    $config->modules = $cfg;
                    // save config
                    if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                        // log
                        \Index\Log\Model::add(0, 'index', 'Save', '{LNG_Module} {LNG_Move}', $login['id']);
                        // บันทึกเรียบร้อย
                        $ret['save'] = true;
                    } else {
                        // ไม่สามารถบันทึก config ได้
                        $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
                    }
                } elseif (preg_match('/^published_([a-z]+)$/', $action, $match)) {
                    // เผยแพร่
                    if (isset($config->modules)) {
                        $config->modules[$match[1]] = empty($config->modules[$match[1]]) ? 1 : 0;
                    } else {
                        $config->modules = array(
                            $match[1] => 1
                        );
                    }
                    // save config
                    if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                        // log
                        \Index\Log\Model::add(0, 'index', 'Save', '{LNG_Module}', $login['id']);
                        // คืนค่า
                        $ret['elem'] = 'published_'.$match[1];
                        $ret['class'] = 'icon-valid '.($config->modules[$match[1]] == 1 ? 'access' : 'disabled');
                    } else {
                        // ไม่สามารถบันทึก config ได้
                        $ret['alert'] = Language::replace('File %s cannot be created or is read-only.', 'settings/config.php');
                    }
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่า JSON
        echo json_encode($ret);
    }
}
