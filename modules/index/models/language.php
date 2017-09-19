<?php
/**
 * @filesource modules/index/models/language.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Language;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * โมเดลสำหรับภาษา (language.php)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Orm\Field
{
  /**
   * ชื่อตาราง
   *
   * @var string
   */
  protected $table = 'language';

  /**
   * รับค่าจาก action
   *
   * @param Request $request
   */
  public function action(Request $request)
  {
    $ret = array();
    // session, referer, member, can_config, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
      if (Login::checkPermission($login, 'can_config') && Login::notDemoMode($login)) {
        // ค่าที่ส่งมา
        $id = $request->post('id')->filter('0-9,');
        $action = $request->post('action')->toString();
        if ($action == 'delete') {
          $model = new \Kotchasan\Model;
          $model->db()->delete($model->getTableName('language'), array('id', explode(',', $id)), 0);
          // อัปเดทไฟล์ ภาษา
          $error = self::updateLanguageFile();
          if (empty($error)) {
            $ret['location'] = 'reload';
          } else {
            $ret['alert'] = $error;
          }
        } elseif ($action == 'import') {
          // import language
          self::import();
          // reload
          $ret['location'] = 'reload';
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่า JSON
    echo json_encode($ret);
  }

  /**
   * อัปเดทไฟล์ ภาษา
   */
  public static function updateLanguageFile()
  {
    // ภาษาที่ติดตั้ง
    $languages = Language::installedLanguage();
    // query ข้อมูลภาษา
    $model = new \Kotchasan\Model;
    $query = $model->db()->createQuery()->select()->from('language')->order('key');
    // เตรียมข้อมูล
    $datas = array();
    foreach ($query->toArray()->execute() as $item) {
      $save = array('key' => $item['key']);
      foreach ($languages as $lng) {
        if (isset($item[$lng]) && $item[$lng] != '') {
          if ($item['type'] == 'array') {
            $data = @unserialize($item[$lng]);
            if (is_array($data)) {
              $save[$lng] = $data;
            }
          } elseif ($item['type'] == 'int') {
            $save[$lng] = (int)$item[$lng];
          } else {
            $save[$lng] = $item[$lng];
          }
        }
      }
      $datas[$item['js'] == 1 ? 'js' : 'php'][] = $save;
    }
    // บันทึกไฟล์ภาษา
    $error = '';
    foreach ($datas as $type => $items) {
      $error .= Language::save($items, $type);
    }
    return $error;
  }

  /**
   * นำเข้าข้อมูลไฟล์ภาษา
   */
  public static function import()
  {
    $dir = ROOT_PATH.'language/';
    if (is_dir($dir)) {
      // Model
      $model = new \Kotchasan\Model;
      // ตาราง language
      $language_table = $model->getTableName('language');
      // โหลดไฟล์ภาษาที่ติดตั้งไว้
      $f = opendir($dir);
      while (false !== ($text = readdir($f))) {
        if (preg_match('/([a-z]{2,2})\.(php|js)/', $text, $match)) {
          if ($model->db()->fieldExists($language_table, $match[1]) == false) {
            // เพิ่มคอลัมน์ภาษา ถ้ายังไม่มีภาษาที่ต้องการ
            $model->db()->query("ALTER TABLE `$language_table` ADD `$match[1]` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci AFTER `key`");
          }
          if ($match[2] == 'php') {
            self::importPHP($model->db(), $language_table, $match[1], $dir.$text);
          } else {
            self::importJS($model->db(), $language_table, $match[1], $dir.$text);
          }
        }
      }
      closedir($f);
    }
  }

  /**
   * นำเข้าข้อมูลไฟล์ภาษา PHP
   *
   * @param Database $db Database Object
   * @param string $language_table ชื่อตาราง language
   * @param string $lang ชื่อภาษา
   * @param string $file_name ไฟล์ภาษา
   */
  public static function importPHP($db, $language_table, $lang, $file_name)
  {
    foreach (include ($file_name) AS $key => $value) {
      if (is_array($value)) {
        $type = 'array';
      } elseif (is_int($value)) {
        $type = 'int';
      } else {
        $type = 'text';
      }
      $search = $db->first($language_table, array(
        array('key', $key),
        array('js', 0)
      ));
      if ($type == 'array') {
        $value = serialize($value);
      }
      if ($search) {
        $db->update($language_table, $search->id, array(
          $lang => $value,
        ));
      } else {
        $db->insert($language_table, array(
          'key' => $key,
          'js' => 0,
          'type' => $type,
          'owner' => 'index',
          $lang => $value,
        ));
      }
    }
  }

  /**
   * นำเข้าข้อมูลไฟล์ภาษา Javascript
   *
   * @param Database $db Database Object
   * @param string $language_table ชื่อตาราง language
   * @param string $lang ชื่อภาษา
   * @param string $file_name ไฟล์ภาษา
   */
  public static function importJS($db, $language_table, $lang, $file_name)
  {
    $patt = '/^var[\s]+([A-Z0-9_]+)[\s]{0,}=[\s]{0,}[\'"](.*)[\'"];$/';
    foreach (file($file_name) AS $item) {
      $item = trim($item);
      if ($item != '') {
        if (preg_match($patt, $item, $match)) {
          $search = $db->first($language_table, array(
            array('key', $match[1]),
            array('js', 1)
          ));
          if ($search) {
            $db->update($language_table, $search->id, array(
              $lang => $match[2],
            ));
          } else {
            $db->insert($language_table, array(
              'key' => $match[1],
              'js' => 1,
              'type' => 'text',
              'owner' => 'index',
              $lang => $match[2],
            ));
          }
        }
      }
    }
  }
}
