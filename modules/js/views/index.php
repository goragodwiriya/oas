<?php
/**
 * @filesource modules/js/views/index.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Js\Index;

use \Kotchasan\Language;

/**
 * Generate JS file
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Kotchasan\KBase
{

  /**
   * สร้างไฟล์ js
   */
  public function index()
  {
    // default js
    $js = array();
    $js[] = 'var WEB_URL = "'.WEB_URL.'",';
    $js[] = 'FIRST_MODULE = "home";';
    $js[] = file_get_contents(ROOT_PATH.'js/gajax.js');
    $js[] = file_get_contents(ROOT_PATH.'js/autocomplete.js');
    $js[] = file_get_contents(ROOT_PATH.'js/clock.js');
    $js[] = file_get_contents(ROOT_PATH.'js/ddmenu.js');
    $js[] = file_get_contents(ROOT_PATH.'js/ddpanel.js');
    $js[] = file_get_contents(ROOT_PATH.'js/editinplace.js');
    $js[] = file_get_contents(ROOT_PATH.'js/graphs.js');
    $js[] = file_get_contents(ROOT_PATH.'js/loader.js');
    $js[] = file_get_contents(ROOT_PATH.'js/sorttable.js');
    $js[] = file_get_contents(ROOT_PATH.'js/table.js');
    $js[] = file_get_contents(ROOT_PATH.'js/tooltip.js');
    $js[] = file_get_contents(ROOT_PATH.'js/common.js');
    // js ของโมดูล
    $dir = ROOT_PATH.'modules/';
    $f = @opendir($dir);
    if ($f) {
      while (false !== ($text = readdir($f))) {
        if ($text != "." && $text != "..") {
          if (is_dir($dir.$text)) {
            if (is_file($dir.$text.'/script.js')) {
              $js[] = file_get_contents($dir.$text.'/script.js');
            }
          }
        }
      }
      closedir($f);
    }
    $lng = Language::name();
    $data_folder = Language::languageFolder();
    if (is_file($data_folder.$lng.'.js')) {
      $js[] = file_get_contents($data_folder.$lng.'.js');
    }
    $languages = Language::getItems(array(
        'MONTH_SHORT',
        'MONTH_LONG',
        'DATE_LONG',
        'DATE_SHORT',
        'YEAR_OFFSET'
    ));
    $js[] = 'Date.monthNames = ["'.implode('", "', $languages['MONTH_SHORT']).'"];';
    $js[] = 'Date.longMonthNames = ["'.implode('", "', $languages['MONTH_LONG']).'"];';
    $js[] = 'Date.longDayNames = ["'.implode('", "', $languages['DATE_LONG']).'"];';
    $js[] = 'Date.dayNames = ["'.implode('", "', $languages['DATE_SHORT']).'"];';
    $js[] = 'Date.yearOffset = '.(int)$languages['YEAR_OFFSET'].';';
    if (!empty(self::$cfg->facebook_appId)) {
      $js[] = file_get_contents(ROOT_PATH.'js/facebook.js');
      $js[] = 'initFacebook("'.self::$cfg->facebook_appId.'", "'.Language::name().'");';
    }
    // compress javascript
    $patt = array('#/\*(?:[^*]*(?:\*(?!/))*)*\*/#u', '#[\r\t]#', '#\n//.*\n#', '#;//.*\n#', '#[\n]#', '#[\s]{2,}#');
    $replace = array('', '', '', ";\n", '', ' ');
    // Response
    $response = new \Kotchasan\Http\Response;
    $response->withHeaders(array(
        'Content-type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'public',
        // cache 1 month
        'Expires' => gmdate('D, d M Y H:i:s', strtotime('+1 month')).' GMT',
      ))
      ->withContent(preg_replace($patt, $replace, implode("\n", $js)))
      ->send();
  }
}
