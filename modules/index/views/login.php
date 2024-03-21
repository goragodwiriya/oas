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
        if (is_file(ROOT_PATH.DATA_FOLDER.'avatar/'.$login['id'].'.jpg')) {
            $usericon = '<img src="'.WEB_URL.DATA_FOLDER.'avatar/'.$login['id'].'.jpg" class=user_icon alt="{DISPLAYNAME}">{DISPLAYNAME}';
        } else {
            $username = empty($login['username']) ? $login['name'] : $login['username'];
            if ($username == '') {
                $usericon = '<img src="'.WEB_URL.'skin/img/noicon.png" class=user_icon alt="{DISPLAYNAME}">{DISPLAYNAME}';
            } else {
                $usericon = '<span class="user_icon" data-letters="'.mb_substr($username, 0, 2).'" title="{DISPLAYNAME}">{DISPLAYNAME}</span>';
            }
        }
        // member.html
        $template = Template::create('', '', 'member');
        $template->add(array(
            '/{USERICON}/' => $usericon,
            '/{DISPLAYNAME}/' => empty($login['name']) ? $login['username'] : $login['name']
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
