<?php
/**
 * @filesource modules/index/views/language.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Language;

use \Kotchasan\Http\Request;
use \Kotchasan\DataTable;
use \Kotchasan\Text;
use \Kotchasan\Language;

/**
 * module=language
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
  /**
   * ข้อมูลโมดูล
   */
  private $languages;

  /**
   * ตารางภาษา
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // ชนิดของภาษาที่เลือก php,js
    $js = $request->request('js')->toBoolean();
    $this->languages = Language::installedLanguage();
    // URL สำหรับส่งให้ตาราง
    $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
    // ตารางภาษา
    $table = new DataTable(array(
      'id' => 'language_table',
      /* Uri */
      'uri' => $uri,
      /* Model */
      'model' => 'Index\Language\Model',
      /* แบ่งหน้า */
      'perPage' => max(10, $request->cookie('language_perPage', 30)->toInt()),
      /* เรียงลำดับ */
      'sort' => $request->cookie('language_sort', 'id DESC')->toString(),
      /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
      'onRow' => array($this, 'onRow'),
      /* คอลัมน์ที่ไม่ต้องแสดงผล */
      'hideColumns' => array('type', 'js'),
      /* คอลัมน์ที่สามารถค้นหาได้ */
      'searchColumns' => array_merge(array('key'), $this->languages),
      /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
      'action' => 'index.php/index/model/language/action?js='.$js,
      'actionCallback' => 'dataTableActionCallback',
      'actions' => array(
        array(
          'id' => 'action',
          'class' => 'ok',
          'text' => '{LNG_With selected}',
          'options' => array(
            'delete' => '{LNG_Delete}'
          )
        ),
        array(
          'class' => 'button add icon-plus',
          'href' => $uri->createBackUri(array('module' => 'languageedit', 'id' => null, 'js' => $js)),
          'text' => '{LNG_Add New}'
        ),
        array(
          'class' => 'button add icon-import',
          'id' => 'import',
          'text' => '{LNG_Import} {LNG_Language}'
        ),
      ),
      /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
      'filters' => array(
        'js' => array(
          'name' => 'js',
          'text' => '{LNG_Type}',
          'options' => array(0 => 'php', 1 => 'js'),
          'value' => $js
        )
      ),
      /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
      'headers' => array(
        'id' => array(
          'text' => '{LNG_ID}',
          'sort' => 'id'
        ),
        'key' => array(
          'text' => '{LNG_Key}',
          'sort' => 'key'
        ),
        'owner' => array(
          'text' => '{LNG_Module}',
          'class' => 'center',
          'sort' => 'owner'
        )
      ),
      /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
      'cols' => array(
        'owner' => array(
          'class' => 'center'
        ),
      ),
      /* ปุ่มแสดงในแต่ละแถว */
      'buttons' => array(
        array(
          'class' => 'icon-edit button green',
          'href' => $uri->createBackUri(array('module' => 'languageedit', 'id' => ':id', 'js' => $js)),
          'text' => '{LNG_Edit}'
        )
      )
    ));
    foreach ($this->languages as $lng) {
      $table->headers[$lng] ['sort'] = $lng;
    }
    // save cookie
    setcookie('language_perPage', $table->perPage, time() + 3600 * 24 * 365, '/');
    setcookie('language_sort', $table->sort, time() + 3600 * 24 * 365, '/');
    // Javascript
    $table->script('initLanguageTable("language_table");');
    // คืนค่า HTML
    return $table->render();
  }

  /**
   * จัดรูปแบบการแสดงผลในแต่ละแถว
   *
   * @param array $item ข้อมูลแถว
   * @param int $o ID ของข้อมูล
   * @param object $prop กำหนด properties ของ TR
   * @return array คืนค่า $item กลับไป
   */
  public function onRow($item, $o, $prop)
  {
    foreach ($this->languages as $lng) {
      if ($item['type'] == 'array') {
        if (!empty($item[$lng])) {
          $data = @unserialize($item[$lng]);
          if (is_array($data)) {
            $item[$lng] = implode(', ', $data);
          }
        }
      }
      $item[$lng] = empty($item[$lng]) ? '' : '<span title="'.htmlspecialchars($item[$lng]).'">'.self::toText($item[$lng]).'</span>';
    }
    $item['key'] = '<a class="icon-copy" title="'.htmlspecialchars($item['key']).'">'.self::toText($item['key']).'</a>';
    return $item;
  }

  /**
   * แปลงข้อความ สำหรับแสดงตัวอย่าง
   *
   * @param string $text
   * @return string
   */
  private static function toText($text)
  {
    return Text::cut(str_replace(array("\r", "\n", '&'), array('', ' ', '&amp;'), strip_tags($text)), 50);
  }
}