<?php
/**
 * @filesource modules/index/views/language.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Language;

use Kotchasan\DataTable;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Text;

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
     * @var array
     */
    private $languages;

    /**
     * ตารางภาษา
     *
     * @param Request $request
     *
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
            'model' => \Index\Language\Model::toDataTable(),
            /* แบ่งหน้า */
            'perPage' => max(10, $request->cookie('language_perPage', 30)->toInt()),
            /* เรียงลำดับ */
            'sort' => $request->cookie('language_sort', 'id DESC')->toString(),
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('type', 'js', 'owner'),
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
                    'class' => 'float_button icon-new',
                    'href' => $uri->createBackUri(array('module' => 'languageedit', 'id' => null, 'js' => $js)),
                    'title' => '{LNG_Add}'
                ),
                'import' => array(
                    'class' => 'button pink icon-import border',
                    'id' => 'import_0',
                    'text' => '{LNG_Import} {LNG_Language}',
                    'data-confirm' => Language::trans('{LNG_You want to} {LNG_Import} {LNG_Language}?')
                )
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
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'owner' => array(
                    'class' => 'center'
                )
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
            $table->headers[$lng]['sort'] = $lng;
        }
        // save cookie
        setcookie('language_perPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        setcookie('language_sort', $table->sort, time() + 2592000, '/', HOST, HTTPS, true);
        // Javascript
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
        foreach ($this->languages as $lng) {
            if ($item['type'] == 'array') {
                if (!empty($item[$lng])) {
                    $data = @unserialize($item[$lng]);
                    if (is_array($data)) {
                        $item[$lng] = implode(', ', $data);
                    }
                }
            }
            $item[$lng] = empty($item[$lng]) ? '' : '<span class="icon-copy two_lines" title="'.htmlspecialchars($item[$lng]).'">'.self::showText($item[$lng]).'</span>';
        }
        $item['key'] = '<span class="icon-copy two_lines" title="'.htmlspecialchars($item['key']).'">'.self::showText($item['key']).'</span>';
        // คืนค่า HTML
        return $item;
    }

    /**
     * แปลงข้อความ สำหรับแสดงตัวอย่าง
     *
     * @param string $text
     *
     * @return string
     */
    private static function showText($text)
    {
        return preg_replace('/[\r\n\t\s]+/', ' ', strip_tags($text));
    }
}
