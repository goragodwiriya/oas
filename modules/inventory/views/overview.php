<?php
/**
 * @filesource modules/inventory/views/overview.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Overview;

use Kotchasan\Http\Request;

/**
 * module=inventory-write&tab=overview
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * รายงานสินค้าเป็นกราฟ
     *
     * @param Request $request
     * @param object $product
     *
     * @return string
     */
    public function render(Request $request, $product)
    {
        // ปีที่เลือก
        $year = $request->request('y', date('Y'))->toInt();
        // แสดงผล
        $content = '<section id=report class=setup_frm>';
        // รายงานสินค้าคงคลัง
        $thead = '';
        foreach (\Kotchasan\Language::get('MONTH_SHORT') as $v) {
            $thead .= '<th class=center>'.$v.'</th>';
        }
        $rows = '';
        // สรุปรายงานสินค้าคงคลัง รายเดือน
        foreach (\Inventory\Stock\Model::monthlyReport($product->id, $year) as $k => $item) {
            $rows .= '<tr><th>{LNG_'.$k.'}</th>';
            for ($i = 1; $i < 13; ++$i) {
                $rows .= '<td class=center>'.(empty($item[$i]) ? 0 : $item[$i]).'</td>';
            }
            $rows .= '</tr>';
        }
        $option = '';
        // query ปีที่มีการทำรายการเพื่อเป็นตัวเลือกปี
        foreach (\Inventory\Stock\Model::listYears($product->id) as $k => $v) {
            $sel = $year == $k ? ' selected' : '';
            $option .= '<option value='.$k.$sel.'>'.$v.'</option>';
        }
        $content .= '<article>';
        $content .= '<header><h3>{LNG_Product activity report} '.$product->topic.'<label>&nbsp;{LNG_year} <select id=year>'.$option.'</select></label></h3></header>';
        $content .= '<div id=year_graph class="margin-left-right">';
        $content .= '<table class=hidden>';
        $content .= '<thead><tr><th>{LNG_monthly}</th>'.$thead.'</tr></thead>';
        $content .= '<tbody>'.$rows.'</tbody>';
        $content .= '</table>';
        $content .= '</div>';
        $content .= '</article>';
        $content .= '</section>';
        $content .= '<script>initInventoryOverview('.$product->id.');</script>';
        // คืนค่า HTML
        return $content;
    }
}
