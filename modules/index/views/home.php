<?php
/**
 * @filesource modules/index/views/home.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Home;

use \Kotchasan\Http\Request;

/**
 * module=dashboard
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * หน้า Home
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    $content = '<div style="position: absolute;top: 50%;margin-top: -80px;left: 50%;margin-left: -112px;text-align: center;z-index: -1;">';
    $content .= '<div class=warper style="display:block">';
    $content .= '<div class="warper">';
    $content .= '<div>';
    $content .= '<img src="./skin/'.self::$cfg->skin.'/img/title.png" style="width:100px" alt="Kotchasan PHP Framework">';
    $content .= '<h1 style="line-height:1.8;margin:0;text-shadow:3px 3px 0 rgba(0,0,0,0.1);font-weight:normal;">คชสาร (Kotchasan)</h1>';
    $content .= 'Siam PHP Framework';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    return $content;
  }
}
