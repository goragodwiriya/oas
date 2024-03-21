<?php
/**
 * @filesource modules/index/controllers/menu.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Menu;

use Gcms\Login;

/**
 * คลาสสำหรับโหลดรายการเมนู
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller
{
    /**
     * รายการเมนู
     *
     * @var array
     */
    private $menus;

    /**
     * Controller สำหรับการโหลดเมนู
     *
     * @param array $login
     *
     * @return static
     */
    public static function init($login)
    {
        $obj = new static;
        // โหลดเมนู
        $obj->menus = \Index\Menu\Model::getMenus($login);
        return $obj;
    }

    /**
     * แสดงผลเมนู
     *
     * @param string $select
     * @param array  $login
     *
     * @return string
     */
    public function render($select, $login)
    {
        // ไม่มีเมนูตั้งค่า
        if (empty($this->menus['settings']['submenus'])) {
            unset($this->menus['settings']);
        }
        // แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
        if (!Login::notDemoMode(Login::isAdmin())) {
            unset($this->menus['member']);
        }
        // ไม่มีเมนู report
        if (empty($this->menus['report']['submenus'])) {
            unset($this->menus['report']);
        }
        if (count($this->menus) > 1) {
            return \Kotchasan\Menu::render($this->menus, $select);
        }
        return '';
    }

    /**
     * เมนูรายการแรก (หน้าหลัก)
     *
     * @return string
     */
    public function home()
    {
        $keys = array_keys($this->menus);
        return reset($keys);
    }

    /**
     * เพิ่มเมนูระดับบนสุด
     *
     * @param string      $toplvl   ชื่อเมนูระดับบนสุด
     * @param string|null $text     ข้อความแสดงบนเมนู, null ไม่แสดงเมนู (สำหรับเมนูระดับบนสุดเท่านั้น)
     * @param string|null $url      ถ้าไมได้ระบุ (null) เป็นเมนุเปล่าหรือเมนูที่มีเมนูย่อย
     * @param array|null  $submenus ถ้าไมได้ระบุ (null) จะไม่มีเมนูย่อย
     * @param string|null $before   เพิ่มเมนูลงในตำแหน่งก่อนหน้าเมนูที่เลือก ถ้าไม่พบหรือไม่ได้ระบุ (null) จะเพิ่มไปรายการสุดท้าย
     * @param string $target target ของลิงค์ (ถ้าระบุ $url)
     */
    public function addTopLvlMenu($toplvl, $text, $url = null, $submenus = null, $before = null, $target = null)
    {
        if (isset($this->menus[$toplvl])) {
            $this->menus[$toplvl]['text'] = $text;
            if (!empty($url)) {
                $this->menus[$toplvl]['url'] = $url;
            }
            if (!empty($target)) {
                $this->menus[$toplvl]['target'] = $target;
            }
            if (!empty($submenus)) {
                foreach ($submenus as $submenu) {
                    $this->menus[$toplvl]['submenus'][] = $submenu;
                }
            }
        } else {
            $menu = array('text' => $text);
            if (!empty($url)) {
                $menu['url'] = $url;
            }
            if (!empty($target)) {
                $menu['target'] = $target;
            }
            if (!empty($submenus)) {
                $menu['submenus'] = $submenus;
            }
            $menus = [];
            foreach ($this->menus as $_module => $_menus) {
                if ($_module === $before) {
                    if (isset($menus[$toplvl])) {
                        $menus[$toplvl] += $menu;
                    } else {
                        $menus[$toplvl] = $menu;
                    }
                    $menu = null;
                }
                if (isset($menus[$_module])) {
                    $menus[$_module] += $_menus;
                } else {
                    $menus[$_module] = $_menus;
                }
            }
            if (!empty($menu)) {
                $menus[$toplvl] = $menu;
            }
            $this->menus = $menus;
        }
    }

    /**
     * ฟังก์ชั่นเพิ่มเมนูของโมดูลที่ติดตั้ง
     *
     * @param string      $toplvl   ชื่อเมนูระดับบนสุด
     * @param string      $text     ข้อความแสดงบนเมนู
     * @param string|null $url      ถ้าไมได้ระบุ (null) เป็นเมนุเปล่าหรือเมนูที่มีเมนูย่อย
     * @param array|null  $submenus ถ้าไมได้ระบุ (null) จะไม่มีเมนูย่อย
     * @param string      $name     ชื่อเมนู
     */
    public function add($toplvl, $text, $url = null, $submenus = null, $name = null)
    {
        if (isset($this->menus[$toplvl])) {
            $menu = array('text' => $text);
            if (!empty($url)) {
                $menu['url'] = $url;
            }
            if (!empty($submenus)) {
                $menu['submenus'] = $submenus;
            }
            if ($name === null) {
                $this->menus[$toplvl]['submenus'][] = $menu;
            } else {
                $this->menus[$toplvl]['submenus'][$name] = $menu;
            }
        }
    }

    /**
     * คืนค่าเมนูระดับบนสุด
     * ไม่พบ คืนค่า null
     *
     * @param string $toplvl
     *
     * @return array|null
     */
    public function getTopLvlMenu($toplvl)
    {
        return isset($this->menus[$toplvl]) ? $this->menus[$toplvl] : null;
    }

    /**
     * ลบเมนูระดับบนสุด
     * ไม่พบ คืนค่า null
     *
     * @param string $toplvl
     */
    public function removeTopLvlMenu($toplvl)
    {
        unset($this->menus[$toplvl]);
    }
}
