<?php
/**
 * @filesource modules/index/controllers/menu.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Menu;

use \Gcms\Login;

/**
 * รายการเมนู
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
   * @return \static
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
   * @param array $login
   * @return string
   */
  public function render($select, $login)
  {
    // สามารถตั้งค่าระบบได้
    if (!Login::checkPermission($login, 'can_config')) {
      unset($this->menus['settings']);
    }
    // ไม่ใช่แอดมิน
    if (!Login::isAdmin()) {
      unset($this->menus['member']);
    }
    // ไม่มีโมดูลติดตั้ง
    if (empty($this->menus['module']['submenus'])) {
      unset($this->menus['module']);
    }
    return \Kotchasan\Menu::render($this->menus, $select);
  }

  /**
   * เพิ่มเมนูระดับบนสุด
   *
   * @param string $toplvl ชื่อเมนูระดับบนสุด
   * @param string $text ข้อความแสดงบนเมนู
   * @param string|null $url ถ้าไมได้ระบุ (null) เป็นเมนุเปล่าหรือเมนูที่มีเมนูย่อย
   * @param array|null $submenus ถ้าไมได้ระบุ (null) จะไม่มีเมนูย่อย
   * @param string|null $before เพิ่มเมนูลงในตำแหน่งก่อนหน้าเมนูที่เลือก ถ้าไม่พบหรือไม่ได้ระบุ (null) จะเพิ่มไปรายการสุดท้าย
   */
  public function addTopLvlMenu($toplvl, $text, $url = null, $submenus = null, $before = null)
  {
    $menu = array('text' => $text);
    if (!empty($url)) {
      $menu['url'] = $url;
    }
    if (!empty($submenus)) {
      $menu['submenus'] = $submenus;
    }
    $menus = array();
    foreach ($this->menus as $_module => $_menus) {
      if ($_module === $before) {
        $menus[$toplvl] = $menu;
        $menu = null;
      }
      $menus[$_module] = $_menus;
    }
    if (!empty($menu)) {
      $menus[$toplvl] = $menu;
    }
    $this->menus = $menus;
  }

  /**
   * ฟังก์ชั่นเพิ่มเมนูของโมดูลที่ติดตั้ง
   *
   * @param string $toplvl ชื่อเมนูระดับบนสุด
   * @param string $text ข้อความแสดงบนเมนู
   * @param string|null $url ถ้าไมได้ระบุ (null) เป็นเมนุเปล่าหรือเมนูที่มีเมนูย่อย
   * @param array|null $submenus ถ้าไมได้ระบุ (null) จะไม่มีเมนูย่อย
   */
  public function add($toplvl, $text, $url = null, $submenus = null)
  {
    if (isset($this->menus[$toplvl])) {
      $menu = array('text' => $text);
      if (!empty($url)) {
        $menu['url'] = $url;
      }
      if (!empty($submenus)) {
        $menu['submenus'] = $submenus;
      }
      $this->menus[$toplvl]['submenus'][] = $menu;
    }
  }
}
