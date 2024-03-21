<?php
/**
 * @filesource modules/index/controllers/languageedit.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Languageedit;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=languageedit
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * ฟอร์มเขียน/แก้ไข ภาษา
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::get('Manage languages');
        // เลือกเมนู
        $this->menu = 'settings';
        // ปุ่มในเมนู settings
        $this->button = 'language';
        // ข้อมูลที่ต้องการ
        $language = \Index\Languageedit\Model::get($request, $this);
        // สามารถตั้งค่าระบบได้
        if ($language && Login::checkPermission(Login::isMember(), 'can_config')) {
            // ข้อความ title bar
            $title = Language::get($language->id == 0 ? 'Add' : 'Edit');
            $this->title = $title.' '.htmlspecialchars($language->key);
            // แสดงผล
            $section = Html::create('section');
            // breadcrumbs
            $breadcrumbs = $section->add('nav', array(
                'class' => 'breadcrumbs'
            ));
            $ul = $breadcrumbs->add('ul');
            $ul->appendChild('<li><span class="icon-settings">{LNG_Settings}</span></li>');
            $ul->appendChild('<li><a href="{BACKURL?module=language&id=0}">{LNG_Language}</a></li>');
            $ul->appendChild('<li><span>'.$title.'</span></li>');
            $section->add('header', array(
                'innerHTML' => '<h2 class="icon-language">'.$this->title.'</h2>'
            ));
            // menu
            $section->appendChild(\Index\Tabmenus\View::render($request, 'settings', $this->button));
            $div = $section->add('div', array(
                'class' => 'content_bg'
            ));
            // แสดงฟอร์ม
            $div->appendChild(\Index\Languageedit\View::create()->render($request, $language, $this->button !== 'language'));
            // คืนค่า HTML
            return $section->render();
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
