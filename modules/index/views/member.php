<?php
/**
 * @filesource modules/index/views/member.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Index\Member;

use \Kotchasan\Http\Request;
use \Kotchasan\DataTable;
use \Kotchasan\Date;

/**
 * module=member
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{

  /**
   * ตารางรายชื่อสมาชิก
   *
   * @param Request $request
   * @return string
   */
  public function render(Request $request)
  {
    // สถานะสมาชิก
    $member_status = array(-1 => '{LNG_all items}');
    foreach (self::$cfg->member_status as $key => $value) {
      $member_status[$key] = '{LNG_'.$value.'}';
    }
    // URL สำหรับส่งให้ตาราง
    $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
    // ตาราง
    $table = new DataTable(array(
      /* Uri */
      'uri' => $uri,
      /* Model */
      'model' => \Index\Member\Model::toDataTable(),
      /* รายการต่อหน้า */
      'perPage' => $request->cookie('member_perPage', 30)->toInt(),
      /* เรียงลำดับ */
      'sort' => $request->cookie('member_sort', 'id desc')->toString(),
      /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
      'onRow' => array($this, 'onRow'),
      /* คอลัมน์ที่ไม่ต้องแสดงผล */
      'hideColumns' => array('id', 'visited', 'website', 'username'),
      /* คอลัมน์ที่สามารถค้นหาได้ */
      'searchColumns' => array('name', 'username', 'phone'),
      /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
      'action' => 'index.php/index/model/member/action',
      'actionCallback' => 'dataTableActionCallback',
      'actions' => array(
        array(
          'id' => 'action',
          'class' => 'ok',
          'text' => '{LNG_With selected}',
          'options' => array(
            'sendpassword' => '{LNG_Get new password}',
            'active_1' => '{LNG_Can login}',
            'active_0' => '{LNG_Unable to login}',
            'delete' => '{LNG_Delete}'
          )
        ),
        array(
          'class' => 'button green icon-plus',
          'href' => $uri->createBackUri(array('module' => 'register', 'id' => 0)),
          'text' => '{LNG_Register}'
        )
      ),
      /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
      'filters' => array(
        'status' => array(
          'name' => 'status',
          'default' => -1,
          'text' => '{LNG_Member status}',
          'options' => $member_status,
          'value' => $request->request('status', -1)->toInt()
        )
      ),
      /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
      'headers' => array(
        'name' => array(
          'text' => '{LNG_Name}',
          'sort' => 'name'
        ),
        'active' => array(
          'text' => '',
          'colspan' => 2
        ),
        'phone' => array(
          'text' => '{LNG_Phone}',
          'class' => 'center'
        ),
        'status' => array(
          'text' => '{LNG_Member status}',
          'class' => 'center'
        ),
        'create_date' => array(
          'text' => '{LNG_Created}',
          'class' => 'center'
        ),
        'lastvisited' => array(
          'text' => '{LNG_Last login} ({LNG_times})',
          'class' => 'center',
          'sort' => 'lastvisited'
        )
      ),
      /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
      'cols' => array(
        'active' => array(
          'class' => 'center'
        ),
        'fb' => array(
          'class' => 'center'
        ),
        'phone' => array(
          'class' => 'center'
        ),
        'status' => array(
          'class' => 'center'
        ),
        'create_date' => array(
          'class' => 'center'
        ),
        'lastvisited' => array(
          'class' => 'center'
        )
      ),
      /* ปุ่มแสดงในแต่ละแถว */
      'buttons' => array(
        array(
          'class' => 'icon-edit button green',
          'href' => $uri->createBackUri(array('module' => 'editprofile', 'id' => ':id')),
          'text' => '{LNG_Edit}'
        )
      )
    ));
    // save cookie
    setcookie('member_perPage', $table->perPage, time() + 3600 * 24 * 365, '/');
    setcookie('member_sort', $table->sort, time() + 3600 * 24 * 365, '/');
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
    $item['create_date'] = Date::format($item['create_date'], 'd M Y');
    if ($item['active'] == 1) {
      $item['active'] = '<span class="icon-valid access" title="{LNG_Can login}"></span>';
      $item['lastvisited'] = empty($item['lastvisited']) ? '-' : Date::format($item['lastvisited'], 'd M Y H:i').' ('.number_format($item['visited']).')';
    } else {
      $item['active'] = '<span class="icon-valid disabled" title="{LNG_Unable to login}"></span>';
      $item['lastvisited'] = '-';
    }
    $item['fb'] = $item['fb'] == 1 ? '<a href="//'.$item['website'].'" target=_blank class="icon-facebook notext"></a>' : '';
    $item['status'] = isset(self::$cfg->member_status[$item['status']]) ? '<span class=status'.$item['status'].'>{LNG_'.self::$cfg->member_status[$item['status']].'}</span>' : '';
    $item['phone'] = self::showPhone($item['phone']);
    return $item;
  }
}