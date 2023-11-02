<?php
/**
 * @filesource modules/index/views/login.php
 */

namespace Index\Login;

use Kotchasan\Http\Request;
use Kotchasan\Template;

/**
 * กรอบสมาชิก
 */
class View extends \Gcms\View
{
    /**
     * กรอบสมาชิก
     *
     * @param Request $request
     * @param array $login
     *
     * @return string
     */
    public static function member(Request $request, $login)
    {
        // member.html
        $template = Template::create('', '', 'member');
        $template->add(array(
            '/{DISPLAYNAME}/' => empty($login['name']) ? $login['username'] : $login['name'],
            '/{USERICON}/' => is_file(ROOT_PATH.DATA_FOLDER.'avatar/'.$login['id'].'.jpg') ? WEB_URL.DATA_FOLDER.'avatar/'.$login['id'].'.jpg' : WEB_URL.'skin/img/noicon.png'
        ));
        return $template->render();
    }

    /**
     * กรอบ login
     *
     * @param Request $request
     *
     * @return string
     */
    public static function login(Request $request)
    {
        // login.html
        return Template::load('', '', 'login');
    }
}
