<?php
/**
 * @filesource modules/index/views/memberstatus.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Memberstatus;

use Kotchasan\Html;

/**
 * module=memberstatus
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * รายการสถานะสมาชิก
     *
     * @return string
     */
    public function render()
    {
        $form = Html::create('form', array(
            'id' => 'setup_frm',
            'class' => 'setup_frm'
        ));
        $fieldset = $form->add('fieldset', array(
            'titleClass' => 'icon-star0',
            'title' => '{LNG_List of} {LNG_Member status}'
        ));
        $list = $fieldset->add('ul', array(
            'class' => 'editinplace_list',
            'id' => 'list'
        ));
        foreach (self::$cfg->member_status as $s => $item) {
            $list->appendChild(self::createRow($s, $item, (isset(self::$cfg->color_status[$s]) ? self::$cfg->color_status[$s] : '#000000')));
        }
        $fieldset = $form->add('fieldset', array(
            'class' => 'submit'
        ));
        $a = $fieldset->add('a', array(
            'class' => 'button add large',
            'id' => 'list_add_0'
        ));
        $a->add('span', array(
            'class' => 'icon-plus',
            'innerHTML' => '{LNG_Add} {LNG_Member status}'
        ));
        // Javascript
        $form->script('initEditInplace("list", "index/model/memberstatus/action", "list_add_0");');
        // คืนค่า HTML
        return $form->render();
    }

    /**
     * ฟังก์ชั่นสร้างแถวของรายการหมวดหมู่
     *
     * @param array $item
     *
     * @return string
     */
    public static function createRow($id, $item, $color)
    {
        $row = '<li class="row" id="list_'.$id.'">';
        if ($id > 1) {
            $row .= '<div><span id="list_delete_'.$id.'" class="icon-delete" title="{LNG_Delete}"></span></div>';
        } else {
            $row .= '<div>&nbsp;</div>';
        }
        $row .= '<div><span id="list_color_'.$id.'" class="icon-color" title="'.$color.'"></span></div>';
        $row .= '<div><span id="list_name_'.$id.'" title="{LNG_Click to edit}" class="editinplace">'.$item.'</span></div>';
        if ($id == 1) {
            $row .= '<div class="right comment">{LNG_Administrator status It is of utmost importance to do everything}</div>';
        } elseif ($id == 0) {
            $row .= '<div class="right comment">{LNG_Status for general members}</div>';
        } else {
            $row .= '<div>&nbsp;</div>';
        }
        $row .= '</li>';
        return $row;
    }
}
