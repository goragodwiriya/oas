<?php
/**
 * @filesource modules/index/views/usage.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Usage;

use Kotchasan\DataTable;
use Kotchasan\Date;
use Kotchasan\Http\Request;

/**
 * module=usage
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
    private $actions;

    /**
     * ตาราง Log
     *
     * @param Request $request
     * @param array $login
     *
     * @return string
     */
    public function render(Request $request, $login)
    {
        // ค่าที่ส่งมา
        $params = array(
            'from' => $request->request('from')->date(),
            'to' => $request->request('to')->date(),
            'act' => $request->request('act')->filter('a-zA-Z'),
            'mod' => $request->request('mod')->filter('a-z')
        );
        $this->actions = \Index\Usage\Model::actions();
        // URL สำหรับส่งให้ตาราง
        $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
        // ตาราง
        $table = new DataTable(array(
            /* Uri */
            'uri' => $uri,
            /* Model */
            'model' => \Index\Usage\Model::toDataTable($params),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('usage_perPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => $request->cookie('usage_sort', 'create_date desc')->toString(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id'),
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('topic', 'name'),
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/index/model/usage/action',
            'actionCallback' => 'dataTableActionCallback',
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => array(
                array(
                    'name' => 'from',
                    'type' => 'date',
                    'text' => '{LNG_from}',
                    'value' => $params['from']
                ),
                array(
                    'name' => 'to',
                    'type' => 'date',
                    'text' => '{LNG_to}',
                    'value' => $params['to']
                ),
                array(
                    'name' => 'mod',
                    'text' => '{LNG_Module}',
                    'options' => array(0 => '{LNG_all items}')+\Index\Usage\Model::modules(),
                    'value' => $params['mod']
                ),
                array(
                    'name' => 'act',
                    'text' => '{LNG_Action}',
                    'options' => array(0 => '{LNG_all items}') + $this->actions,
                    'value' => $params['act']
                )
            ),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'create_date' => array(
                    'text' => '{LNG_Date}',
                    'sort' => 'create_date'
                ),
                'topic' => array(
                    'text' => '{LNG_Detail}'
                ),
                'name' => array(
                    'text' => '{LNG_Name}',
                    'class' => 'center',
                    'sort' => 'name'
                ),
                'module' => array(
                    'text' => '{LNG_Module}',
                    'class' => 'center',
                    'sort' => 'module'
                ),
                'action' => array(
                    'text' => '{LNG_Action}',
                    'class' => 'center',
                    'sort' => 'action'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'create_date' => array(
                    'class' => 'nowrap'
                ),
                'name' => array(
                    'class' => 'center nowrap'
                ),
                'module' => array(
                    'class' => 'center'
                ),
                'action' => array(
                    'class' => 'center'
                )
            )
        ));
        if ($login['id'] == 1) {
            // Super Admin
            $table->actions = array(
                array(
                    'id' => 'action',
                    'class' => 'ok',
                    'text' => '{LNG_With selected}',
                    'options' => array(
                        'delete' => '{LNG_Delete}'
                    )
                )
            );
        }
        // save cookie
        setcookie('usage_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('usage_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
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
        $topic = strip_tags($item['topic']);
        $item['topic'] = '<span class=one_line title="'.$topic.'">'.$topic.'</span>';
        $item['create_date'] = Date::format($item['create_date'], 'd M Y H:i');
        $item['action'] = isset($this->actions[$item['action']]) ? $this->actions[$item['action']] : $item['action'];
        return $item;
    }
}
