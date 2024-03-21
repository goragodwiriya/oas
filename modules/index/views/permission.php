<?php
/**
 * @filesource modules/index/views/permission.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Permission;

use Kotchasan\DataTable;
use Kotchasan\Http\Request;

/**
 * module=permission
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * @var array
     */
    protected $permission;

    /**
     * ตารางสิทธิ์สมาชิก
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // สิทธิสมาชิก
        $this->permission = \Gcms\Controller::getPermissions();
        // ค่าที่ส่งมา
        $params = array(
            'status' => $request->request('status', -1)->toInt(),
            'permission' => $this->permission
        );
        // สถานะสมาชิก
        $member_status = array(-1 => '{LNG_all items}');
        foreach (self::$cfg->member_status as $key => $value) {
            $member_status[$key] = '{LNG_'.$value.'}';
        }
        $filters = [];
        // หมวดหมู่
        $this->category = \Index\Category\Model::init(false);
        foreach ($this->category->items() as $k => $label) {
            if (!$this->category->isEmpty($k)) {
                $params[$k] = $request->request($k)->topic();
                $filters[] = array(
                    'name' => $k,
                    'text' => $label,
                    'options' => array('' => '{LNG_all items}') + $this->category->toSelect($k),
                    'value' => $params[$k]
                );
            }
        }
        $filters[] = array(
            'name' => 'status',
            'text' => '{LNG_Member status}',
            'options' => $member_status,
            'value' => $params['status']
        );
        $headers = array(
            'name' => array(
                'text' => '{LNG_Name}'
            )
        );
        $cols = array(
            'name' => array(
                'class' => 'nowrap'
            )
        );
        foreach ($this->permission as $k => $v) {
            $cols[$k] = array(
                'class' => 'center'
            );
            $headers[$k] = array(
                'text' => '<span class=two_lines title="'.$v.'">'.$v.'</span>',
                'class' => 'center wrap'
            );
        }
        // URL สำหรับส่งให้ตาราง
        $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
        // ตาราง
        $table = new DataTable(array(
            /* Uri */
            'uri' => $uri,
            /* Model */
            'model' => \Index\Permission\Model::toDataTable($params),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('permission_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'defaultSort' => 'id',
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id', 'status', 'permission', 'username'),
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('name', 'username'),
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/index/model/permission/action',
            'actionCallback' => 'dataTableActionCallback',
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => $filters,
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => $headers,
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => $cols
        ));
        // save cookie
        setcookie('permission_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        // คืนค่า HTML
        return $table->render();
    }

    /**
     * จัดรูปแบบการแสดงผลในแต่ละแถว
     *
     * @param array  $item ข้อมูลแถว
     * @param int    $o    ID ของข้อมูล
     * @param object $prop กำหนด properties ของ TR
     *
     * @return array คืนค่า $item กลับไป
     */
    public function onRow($item, $o, $prop)
    {
        $item['name'] = empty($item['name']) ? '' : '<a id=login_'.$item['id'].' class="icon-signin status'.$item['status'].'" title="{LNG_Login as} '.$item['username'].'">'.$item['name'].'</a>';
        $permission = empty($item['permission']) ? [] : explode(',', trim($item['permission'], " \t\n\r\0\x0B,"));
        foreach ($this->permission as $k => $v) {
            $item[$k] = '<a id="'.$k.'_'.$item['id'].'" class="icon-valid '.(in_array($k, $permission) ? 'access' : 'disabled').'" title="'.$v.'"></a>';
        }
        return $item;
    }
}
