<?php
/**
 * @filesource modules/index/views/modules.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Modules;

use Kotchasan\DataTable;
use Kotchasan\Http\Request;

/**
 * module=modules
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * โมดูลที่ติดตั้งแล้ว
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ตาราง
        $table = new DataTable(array(
            /* Model */
            'datas' => \Index\Modules\Model::toDataTable(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* ไม่ต้องแสดง caption */
            'showCaption' => false,
            /* enable drag row */
            'dragColumn' => 1,
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/index/model/modules/action',
            'actionCallback' => 'dataTableActionCallback',
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'id' => array(
                    'text' => '{LNG_Module}'
                ),
                'published' => array(
                    'text' => '{LNG_Status}',
                    'class' => 'center'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'published' => array(
                    'class' => 'center'
                )
            )
        ));
        // คืนค่า HTML
        $content = '<div class=setup_frm>';
        $content .= '<fieldset><legend><span class=icon-modules>{LNG_Installed modules}</span></legend></fieldset>';
        $content .= '<div class=tbl_modules>'.$table->render().'</div>';
        $content .= '</div>';
        return $content;
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
        $item['published'] = '<a id="published_'.$item['id'].'" class="icon-valid '.($item['published'] ? 'access' : 'disabled').'"></a>';
        return $item;
    }
}
