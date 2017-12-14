<?php
/**
 * @filesource Kotchasan/Accordion.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Accordion
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Accordion
{
  private $id;
  private $datas;
  private $type;

  /**
   * Construct
   *
   * @param string $id ID ของ Accordian ห้ามซ้ำกับอันอื่น
   * @param array $items รายการเริ่มต้น array(array('title1' => 'detail1'), array('title2' => 'detail2'))
   * @param boolean $onetab true สามารถเปิดได้ทีละเท็บ, false (ค่าเริ่มต้น) สามารถเปิด-ปิดแท็บได้อิสระ
   */
  public function __construct($id, $items = array(), $onetab = false)
  {
    $this->id = $id;
    $this->datas = empty($items) ? array() : $items;
    $this->type = $onetab ? 'radio' : 'checkbox';
  }

  /**
   * เพิ่มรายการ Accordion
   *
   * @param string $title
   * @param string $detail
   * @param boolean $select true แสดงรายการนี้, ค่าเริ่มต้นคือไม่ (false)
   */
  public function add($title, $detail, $select = false)
  {
    $this->datas[$title] = array(
      'detail' => $detail,
      'select' => $select
    );
  }

  /**
   * สร้างโค้ด HTML
   *
   * @return string
   */
  public function render()
  {
    $html = '<div class="accordion">';
    $n = 1;
    foreach ($this->datas as $title => $item) {
      $html .= '<div class="item">';
      $html .= '<input id="'.$this->id.$n.'" name="'.$this->id.'" type="'.$this->type.'"'.($item['select'] ? ' checked' : '').'>';
      $html .= '<label for="'.$this->id.$n.'">'.$title.'</label>';
      $html .= '<div class="body"><div class="article">'.$item['detail'].'</div></div>';
      $html .= '</div>';
      $n++;
    }
    return $html.'</div>';
  }
}