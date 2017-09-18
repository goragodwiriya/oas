<?php
/**
 * @filesource Kotchasan/Grid.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Grid System
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Grid extends \Kotchasan\Template
{

  public function __construct()
  {
    $this->cols = 1;
  }

  /**
   * กำหนดจำนวนกอลัมน์ของกริด
   *
   * @param int $cols จำนวนคอลัมน์ มากกว่า 0
   * @return \static
   */
  public function setCols($cols)
  {
    $this->cols = max(1, (int)$cols);
    $this->num = $this->cols;
    return $this;
  }

  /**
   * คืนค่าจำนวนคอลัมน์ของกริด
   *
   * @return int
   */
  public function getCols()
  {
    return $this->cols;
  }
}
