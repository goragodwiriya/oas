<?php
/**
 * @filesource Kotchasan/Menu.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * คลาสสำหรับแสดงผลเมนูมาตรฐานของ Kotchasan
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Menu
{

  /**
   * แสดงผลเมนู
   *
   * @param array $items รายการเมนู
   * @return string
   */
  public static function render($items, $select)
  {
    $menus = array();
    foreach ($items as $alias => $values) {
      if (isset($values['submenus'])) {
        $menus[] = self::getItem($alias, $values, true, $select).'<ul>';
        $menus[] = self::render($values['submenus'], $select);
        $menus[] = '</ul>';
      } else {
        $menus[] = self::getItem($alias, $values, false, $select).'</li>';
      }
    }
    return implode('', $menus);
  }

  /**
   * ฟังก์ชั่น แปลงเป็นรายการเมนู
   *
   * @param string|int $name ชื่อเมนู
   * @param array $item แอเรย์ข้อมูลเมนู
   * @param boolean $arrow true แสดงลูกศรสำหรับเมนูที่มีเมนูย่อย
   * @param string $select ชื่อเมนูที่ถูกเลือก
   * @return string คืนค่า HTML ของเมนู
   */
  protected static function getItem($name, $item, $arrow, $select)
  {
    if (empty($name) && !is_int($name)) {
      $c = '';
    } else {
      $c = array($name);
      if ($name == $select) {
        $c[] = 'select';
      }
      $c = ' class="'.implode(' ', $c).'"';
    }
    if (!empty($item['url'])) {
      $a = array('href="'.$item['url'].'"');
      if (!empty($item['target'])) {
        $a[] = 'target="'.$item['target'].'"';
      }
    }
    if (!empty($item['text'])) {
      $a[] = 'title="'.$item['text'].'"';
    }
    $a = isset($a) ? ' '.implode(' ', $a) : '';
    if ($arrow) {
      return '<li'.$c.'><a class=menu-arrow'.$a.'><span>'.(empty($item['text']) ? '&nbsp;' : htmlspecialchars_decode($item['text'])).'</span></a>';
    } else {
      return '<li'.$c.'><a'.$a.'><span>'.(empty($item['text']) ? '&nbsp;' : htmlspecialchars_decode($item['text'])).'</span></a>';
    }
  }
}
