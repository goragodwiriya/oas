<?php
/**
 * @filesource modules/inventory/views/export.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Export;

use \Kotchasan\Template;

/**
 * ส่งออกข้อมูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View
{

  /**
   * ส่งออกข้อมูลเป็น HTML หรือ หน้าสำหรับพิมพ์
   *
   * @param array $content
   */
  public static function toPrint($content)
  {
    $template = Template::createFromFile(ROOT_PATH.'modules/inventory/template/print.html');
    $template->add($content);
    echo $template->render();
  }
}