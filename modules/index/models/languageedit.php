<?php
/**
 * @filesource modules/index/models/languageedit.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Languageedit;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;

/**
 * บันทึกการเขียน/แก้ไข ภาษา
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านรายการ owner จากฐานข้อมูลภาษา
   *
   * @return array
   */
  public static function getOwners()
  {
    $model = new static;
    $query = $model->db()->createQuery()
      ->select('owner')
      ->from('language')
      ->groupBy('owner')
      ->toArray();
    $result = array();
    foreach ($query->execute() as $item) {
      $result[$item['owner']] = $item['owner'];
    }
    return $result;
  }

  /**
   * ยอมรับ tag บางตัว ในภาษา a em b strong ul ol li dd dt dl
   *
   * @param string $string
   * @return string
   */
  public function allowTags($string)
  {
    return preg_replace_callback('/(&lt;(\/?(a|em|b|strong|ul|ol|li|dd|dt|dl)).*?&gt;)/is', function($matches) {
      return html_entity_decode($matches[1]);
    }, $string);
  }

  /**
   * form submit
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, member, can_config, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
      if (Login::checkPermission($login, 'can_config') && Login::notDemoMode($login)) {
        // ค่าที่ส่งมา
        $save = array(
          'js' => $request->post('write_js')->toBoolean(),
          'type' => $request->post('write_type')->topic(),
          'owner' => $request->post('write_owner')->topic(),
          'key' => $this->allowTags($request->post('write_key')->topic())
        );
        // ภาษาที่ติดตั้ง
        $languages = Language::installedLanguage();
        $values = array();
        foreach ($request->post('datas')->topic() as $items) {
          foreach ($languages as $lng) {
            if ($items[$lng] != '') {
              $values[$lng][$items['key']] = $items[$lng];
              if (sizeof($values[$lng]) > 1) {
                $save['type'] = 'array';
              }
            }
          }
        }
        if (empty($values)) {
          // ไม่ได้กรอกภาษา
          $ret['alert'] = Language::trans('{LNG_Please fill in} {LNG_Language} th');
        } else {
          foreach ($languages as $lng) {
            if (isset($values[$lng])) {
              if ($save['type'] == 'array') {
                $save[$lng] = $this->allowTags(serialize($values[$lng]));
              } else {
                $save[$lng] = $this->allowTags(reset($values[$lng]));
              }
            } else {
              $save[$lng] = '';
            }
          }
          if ($save['js']) {
            if (isset($save['en']) && $save['en'] == '') {
              $save['en'] = $save['key'];
            }
            $save['key'] = strtoupper(preg_replace(array('/[\s]+/', '/([^A-Z_]+)/i'), array('_', ''), $save['key']));
          }
          $id = $request->post('write_id')->toInt();
          // Model
          $model = new static;
          // ตาราง
          $table_language = $model->getTableName('language');
          // ตรวจสอบรายการที่แก้ไข
          if ($id > 0) {
            $language = $model->db()->first($table_language, $id);
          }
          if ($id > 0 && !$language) {
            $ret['alert'] = Language('Sorry, Item not found It&#39;s may be deleted');
          } elseif ($save['key'] == '') {
            $ret['ret_write_key'] = Language::get('Please fill in');
          } else {
            // ตรวจสอบข้อมูลซ้ำ
            $search = $model->db()->first($table_language, array(
              array('key', $save['key']),
              array('js', $save['js'])
            ));
            if ($search && ($id == 0 || $id != $search->id)) {
              $ret['ret_write_key'] = Language::replace('This :name already exist', array(':name' => Language::get('Key')));
            } else {
              // บันทึก
              if ($id == 0) {
                // ใหม่
                $id = $model->db()->insert($table_language, $save);
                // redirect
                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'language', 'js' => $save['js'], 'sort' => 'id DESC')).'#datatable_'.$id;
              } else {
                // แก้ไข
                $model->db()->update($table_language, $id, $save);
                // redirect
                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'language', 'js' => $save['js'])).'#datatable_'.$id;
              }
              // อัปเดทไฟล์ ภาษา
              $error = \Index\Language\Model::updateLanguageFile();
              if (empty($error)) {
                $ret['alert'] = Language::get('Saved successfully');
              } else {
                unset($ret['location']);
                $ret['alert'] = $error;
              }
              // clear
              $request->removeToken();
            }
          }
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }
}
