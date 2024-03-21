<?php
/**
 * @filesource modules/index/controllers/editprofile.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Editprofile;

use Gcms\Login;
use Kotchasan\Html;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=editprofile
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * แก้ไขข้อมูลส่วนตัวสมาชิก
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // ข้อความ title bar
        $this->title = Language::get('Editing your account');
        // เลือกเมนู
        $this->menu = 'member';
        // สมาชิก, ไม่ใช่สมาชิกตัวอย่าง
        if ($login = Login::notDemoMode(Login::isMember())) {
            // อ่านข้อมูลสมาชิก
            $user = \Index\Editprofile\Model::get($request->request('id', $login['id'])->toInt());
            // ตัวเอง, แอดมินแก้ไขได้ทุกคน ยกเว้น ID 1
            if ($user && $user['id'] > 0 && ($login['id'] == $user['id'] || Login::isAdmin())) {
                // แสดงผล
                $section = Html::create('section');
                // breadcrumbs
                $breadcrumbs = $section->add('nav', array(
                    'class' => 'breadcrumbs'
                ));
                $ul = $breadcrumbs->add('ul');
                $ul->appendChild('<li><span class="icon-user">{LNG_Users}</span></li>');
                $ul->appendChild('<li><a href="{BACKURL?module=member&id=0}">{LNG_Member list}</a></li>');
                $ul->appendChild('<li><span>{LNG_Edit}</span></li>');
                $section->add('header', array(
                    'innerHTML' => '<h2 class="icon-profile">'.$this->title.'</h2>'
                ));
                $div = $section->add('div', array(
                    'class' => 'content_bg'
                ));
                // แสดงฟอร์ม
                $div->appendChild(\Index\Editprofile\View::create()->render($request, $user, $login));
                // คืนค่า HTML
                return $section->render();
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
