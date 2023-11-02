<?php
/**
 * @filesource modules/index/controllers/welcome.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Welcome;

use Kotchasan\Http\Request;
use Kotchasan\Template;

/**
 * module=welcome
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * forgot, login register
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        $page = $this->execute($request);
        return $page->detail();
    }

    /**
     * ประมวลผลหน้าที่เลือกจาก action ที่ส่งมา
     * forgot, login register
     *
     * @param Request $request
     *
     * @return static
     */
    public function execute(Request $request)
    {
        // action ที่เลือก
        $action = $request->request('action')->toString();
        // ตรวจสอบ method ที่กำหนดไว้เท่านั้น
        if ($action == 'register' && !empty(self::$cfg->user_register)) {
            $action = 'register';
        } elseif ($action == 'forgot' && !empty(self::$cfg->user_forgot)) {
            $action = 'forgot';
        } else {
            $action = 'login';
        }
        // ประมวลผลหน้าที่เรียก
        $page = \Index\Welcome\View::$action($request);
        // welcome.html
        $template = Template::create('', '', 'welcome');
        $template->add(array(
            '/{CONTENT}/' => $page->detail
        ));
        $this->detail = $template->render();
        $this->title = $page->title;
        $this->bodyClass = $page->bodyClass;
        // คืนค่า Controller
        return $this;
    }
}
