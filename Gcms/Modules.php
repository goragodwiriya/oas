<?php
/**
 * @filesource Gcms/Modules.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * Config Class สำหรับ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Modules extends \Kotchasan\KBase
{
    /**
     * Singleton สำหรับเรียกใช้ class นี้เพียงครั้งเดียวเท่านั้น
     *
     * @var static
     */
    private static $instance = null;
    /**
     * @var array
     */
    private $modules = [];
    /**
     * ไดเร็คทอรี่ของโมดูล
     *
     * @var string
     */
    private $dir;

    /**
     * ตรวจสอบโมดูลที่ติดตั้งแล้ว
     */
    private function __construct()
    {
        $this->dir = ROOT_PATH.'modules/';
        if (!empty(self::$cfg->modules)) {
            foreach (self::$cfg->modules as $module => $published) {
                if ($published) {
                    $this->modules[] = $module;
                }
            }
        }
        $f = @opendir($this->dir);
        if ($f) {
            while (false !== ($text = readdir($f))) {
                if (!preg_match('/\.|index|css|js|v[0-9]+/', $text) && is_dir($this->dir.$text)) {
                    if (!in_array($text, $this->modules) && !isset(self::$cfg->modules[$text])) {
                        $this->modules[] = $text;
                    }
                }
            }
            closedir($f);
        }
    }

    /**
     * โหลดโมดูลที่ติดตั้งแล้วทั้งหมด
     *
     * @return static
     */
    public static function create()
    {
        if (null === self::$instance) {
            self::$instance = new static;
        }
        return self::$instance;
    }

    /**
     * คืนค่าชื่อโมดูลทั้งหมดที่ติดตั้งแล้ว
     *
     * @return array
     */
    public function get()
    {
        return $this->modules;
    }

    /**
     * คืนค่า $className Controller ทั้งหมดของโมดูลที่ติดตั้งแล้ว
     *
     * @param string $className ชื่อคลาสที่ต้องการ เช่น Init
     *
     * @return array
     */
    public function getControllers($className)
    {
        return $this->listClassNames($className, 'controller');
    }
    /**
     * คืนค่า $className Model ทั้งหมดของโมดูลที่ติดตั้งแล้ว
     *
     * @param string $className ชื่อคลาสที่ต้องการ เช่น Init
     *
     * @return array
     */

    public function getModels($className)
    {
        return $this->listClassNames($className, 'model');
    }

    /**
     * คืนค่า $className View ทั้งหมดของโมดูลที่ติดตั้งแล้ว
     *
     * @param string $className ชื่อคลาสที่ต้องการ เช่น Init
     *
     * @return array
     */
    public function getViews($className)
    {
        return $this->listClassNames($className, 'view');
    }

    /**
     * คืนค่า $className ทั้งหมดของโมดูลที่ติดตั้งแล้ว
     *
     * @param string $className ชื่อคลาสที่ต้องการ เช่น Init
     *
     * @return array
     */

    public function listClassNames($className, $type)
    {
        $classList = [];

        $file = strtolower($className);

        foreach ($this->modules as $module) {
            $filePath = $this->dir.$module.'/'.$type.'s/'.$file.'.php';

            if (file_exists($filePath)) {
                require_once $filePath;
                $classList[] = '\\'.ucfirst($module).'\\'.$className.'\\'.ucfirst($type);
            }
        }

        return $classList;
    }

    /**
     * คืนค่าไดเร็คทอรี่โมดูล
     *
     * @return string
     */
    public function getDir()
    {
        return $this->dir;
    }
}
