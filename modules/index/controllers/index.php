<?php
/**
 * @filesource modules/index/controllers/index.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Index;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;
use Kotchasan\Template;

/**
 * Controller สำหรับแสดงหน้าเว็บ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * หน้าหลักเว็บไซต์ (index.html)
     * ให้ผลลัพท์เป็น HTML
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        // ตัวแปรป้องกันการเรียกหน้าเพจโดยตรง
        define('MAIN_INIT', 'indexhtml');
        // session cookie
        $request->initSession();
        // ตรวจสอบการ login
        Login::create($request);
        // กำหนด skin ให้กับ template
        Template::init(self::$cfg->skin);
        // View
        self::$view = new \Gcms\View();
        // เข้าระบบ
        $login = Login::isMember();
        // โหลดเมนู
        self::$menus = \Index\Menu\Controller::init($login);
        // Javascript
        self::$view->addScript('var FIRST_MODULE="'.self::$menus->home().'";');
        // โหลดโมดูลที่ติดตั้งแล้ว
        self::$modules = \Gcms\Modules::create();
        foreach (self::$modules->getControllers('Init') as $className) {
            if (method_exists($className, 'execute')) {
                // โหลดค่าติดตั้งโมดูล
                $className::execute($request, $login);
            }
        }
        foreach (self::$modules->getControllers('Initmenu') as $className) {
            if (method_exists($className, 'execute')) {
                // โหลดค่าติดตั้งโมดูล
                $className::execute($request, self::$menus, $login);
            }
        }
        // Controller หลัก
        $page = \Index\Main\Controller::create()->execute($request);
        // ตัวเลือกภาษา
        $languages = '';
        foreach (Language::installedLanguage() as $item) {
            $t = '{LNG_Language} '.strtoupper($item);
            $languages .= '<li><a id=lang_'.$item.' href="'.$page->canonical()->withParams(array('lang' => $item), true).'" aria-label="'.$t.'"  style="background-image:url('.WEB_URL.'language/'.$item.'.gif)" tabindex=1>&nbsp;</a></li>';
        }
        if (is_file(ROOT_PATH.DATA_FOLDER.'images/logo.png')) {
            $logo = '<img src="'.WEB_URL.DATA_FOLDER.'images/logo.png" alt="{WEBTITLE}">';
            if (!empty(self::$cfg->show_title_logo)) {
                $logo .= '{WEBTITLE}';
            }
            self::$view->setMetas(array(
                '<link rel="icon" type="image/x-icon" href="'.WEB_URL.DATA_FOLDER.'images/logo.png">'
            ));
        } else {
            $logo = '<span class="'.self::$cfg->default_icon.'">{WEBTITLE}</span>';
        }
        // LINE Add friend
        if ($login && !empty(self::$cfg->line_official_account) && !empty(self::$cfg->line_channel_access_token)) {
            $line_add_friend = '<a href="https://line.me/R/ti/p/'.self::$cfg->line_official_account.'" class=icon-line target=_blank>{LNG_Add friend}</a>';
        } else {
            $line_add_friend = '';
        }
        // เนื้อหา
        self::$view->setContents(array(
            // main template
            '/{MAIN}/' => $page->detail(),
            // กรอบ login
            '/{LOGIN}/' => \Index\Login\Controller::init($request, $login),
            // เมนู
            '/{MENUS}/' => self::$menus->render($page->menu(), $login),
            // โลโก
            '/{LOGO}/' => $logo,
            '/{LOGO_CLASS}/' => self::logoClass(),
            // language menu
            '/{LANGUAGES}/' => $languages,
            // title
            '/{TITLE}/' => $page->title(),
            // class สำหรับ body
            '/{BODYCLASS}/' => $page->bodyClass().' '.self::$cfg->theme_width,
            // LINE Add friend
            '/{LINE}/' => $line_add_friend,
            // เวอร์ชั่น
            '/{VERSION}/' => self::$cfg->version,
            // เลขเวอร์ชั่นของไฟล์
            '/{REV}/' => isset(self::$cfg->reversion) ? self::$cfg->reversion : ''
        ));
        // ส่งออก เป็น HTML
        $response = new Response();
        if ($page->status() === 404) {
            $response = $response->withStatus(404)->withAddedHeader('Status', '404 Not Found');
        }
        $response->withContent(self::$view->renderHTML())->send();
    }

    /**
     * คืนค่า class ของ logo
     *
     * @param string
     */
    public static function logoClass()
    {
        if (empty(self::$cfg->show_title_logo)) {
            $logo_class = 'hide_title';
        } else {
            $logo_class = 'show_title_logo';
        }
        if (!empty(self::$cfg->new_line_title)) {
            $logo_class .= ' new_line_title';
        }
        return $logo_class;
    }
}
