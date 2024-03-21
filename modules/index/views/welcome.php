<?php
/**
 * @filesource modules/index/views/welcome.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Welcome;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Http\Uri;
use Kotchasan\Language;
use Kotchasan\Template;

/**
 * Login, Forgot, Register
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Kotchasan\View
{
    /**
     * ฟอร์มเข้าระบบ
     *
     * @param Request $request
     *
     * @return object
     */
    public static function login(Request $request)
    {
        $login_action = $request->request('ret')->url();
        if (preg_match('/[a-z0-9]{32,32}/', $request->get('id')->filter('a-z0-9'), $match)) {
            // activate email
            \Index\Activate\Model::execute($match[0]);
            // activate message
            Login::$login_message = Language::get('Congratulations, your email address has been verified. please login');
        }
        if ($login_action === 'reload') {
            $ret_uri = (string) $request->getUri()->withoutParams('action')->withoutQuery(array('module' => 'welcome'));
        } elseif ($login_action !== '') {
            $ret_uri = (string) Uri::createFromUri($login_action)->withoutParams('action')->withoutQuery(array('module' => 'welcome'));
        }
        if (!isset($ret_uri) || preg_match('/loader\.php/', $ret_uri)) {
            $ret_uri = WEB_URL.'index.php';
        }
        $fields = [];
        foreach (Language::get('LOGIN_FIELDS') as $k => $label) {
            if (in_array($k, self::$cfg->login_fields)) {
                $fields[] = $label;
            }
        }
        if (empty(self::$cfg->line_channel_id)) {
            $line_button = '';
        } else {
            // Line Login
            $line_button = '<a class="button line wide margin-top" href="'.\Index\Linelogin\Model::url($ret_uri).'"><span class=icon-line>Log in</span></a>';
        }
        if (empty(Login::$login_message)) {
            Login::$login_message = strip_tags($request->get('msg', '')->topic());
            if (Login::$login_message != '') {
                Login::$login_input = 'username';
            }
        }
        // loginfrm.html
        $template = Template::create('', '', 'loginfrm');
        $template->add(array(
            '/{LOGO}/' => self::logo(),
            '/{LOGO_CLASS}/' => \Index\Index\Controller::logoClass(),
            '/<FACEBOOK>(.*)<\/FACEBOOK>/s' => empty(self::$cfg->facebook_appId) ? '' : '\\1',
            '/{LINELOGIN}/' => $line_button,
            '/{PLACEHOLDER}/' => implode(',', $fields),
            '/{TOKEN}/' => $request->createToken(),
            '/{EMAIL}/' => isset(Login::$login_params['username']) ? Login::$login_params['username'] : '',
            '/{PASSWORD}/' => isset(Login::$login_params['password']) ? Login::$login_params['password'] : '',
            '/{MESSAGE}/' => Login::$login_message,
            '/{CLASS}/' => empty(Login::$login_message) ? 'hidden' : (empty(Login::$login_input) ? 'message' : 'error'),
            '/{URL}/' => $ret_uri,
            '/{LOGINMENU}/' => self::menus('login'),
            '/{REMEMBER}/' => $request->cookie('login_remember')->toBoolean() ? 'checked' : '',
            '/{LOGIN_MESSAGE}/' => empty(self::$cfg->login_message) || !in_array(self::$cfg->login_message_style, array('tip', 'message', 'warning')) ? '' : '<aside class="'.self::$cfg->login_message_style.'">'.nl2br(self::$cfg->login_message).'</aside>'
        ));
        return (object) array(
            'detail' => $template->render(),
            'title' => self::$cfg->web_title.' - '.Language::get('Login with an existing account'),
            'bodyClass' => 'welcomepage'
        );
    }

    /**
     * ฟอร์มขอรหัสผ่านใหม่
     *
     * @param Request $request
     *
     * @return object
     */
    public static function forgot(Request $request)
    {
        // forgotfrm.html
        $template = Template::create('', '', 'forgotfrm');
        $template->add(array(
            '/{LOGO}/' => self::logo(),
            '/{LOGO_CLASS}/' => \Index\Index\Controller::logoClass(),
            '/{TOKEN}/' => $request->createToken(),
            '/{EMAIL}/' => Login::$login_params['username'],
            '/{MESSAGE}/' => Login::$login_message,
            '/{CLASS}/' => empty(Login::$login_message) ? 'hidden' : (empty(Login::$login_input) ? 'message' : 'error'),
            '/{LOGINMENU}/' => self::menus('forgot'),
            '/{LOGIN_MESSAGE}/' => empty(self::$cfg->login_message) || !in_array(self::$cfg->login_message_style, array('tip', 'message', 'warning')) ? '' : '<aside class="'.self::$cfg->login_message_style.'">'.nl2br(self::$cfg->login_message).'</aside>'
        ));
        return (object) array(
            'detail' => $template->render(),
            'title' => self::$cfg->web_title.' - '.Language::get('Get new password'),
            'bodyClass' => 'welcomepage'
        );
    }

    /**
     * ฟอร์มสมัครสมาชิก
     *
     * @param Request $request
     *
     * @return object
     */
    public static function register(Request $request)
    {
        $fields = [];
        foreach (Language::get('LOGIN_FIELDS') as $k => $label) {
            if (($k == 'email' || $k == 'username') && in_array($k, self::$cfg->login_fields)) {
                $fields[] = $label;
            }
        }
        $selects = [];
        $category = \Index\Category\Model::init(false);
        foreach ($category->items() as $item => $label) {
            if (
                // จำเป็นต้องระบุ
                in_array($item, self::$cfg->categories_required) &&
                // ไม่ใช่แอดมินเท่านั้น (สมาชิกทั่วไป)
                !in_array($item, self::$cfg->categories_disabled) &&
                // มีรายการ
                !$category->isEmpty($item)
            ) {
                foreach ($category->toSelect($item) as $key => $value) {
                    $selects[$item][$key] = '<option value="'.$key.'">'.$value.'</option>';
                }
            }
        }
        $categories = '';
        foreach ($selects as $key => $items) {
            $title = '{LNG_Please select} '.$category->name($key);
            $categories .= '<label class="g-input icon-menus">';
            $categories .= '<select id="register_'.$key.'" name="register_'.$key.'" title="'.$title.'">';
            $categories .= '<option value="">'.$title.'</option>'.implode('', $items);
            $categories .= '</select></label>';
        }
        // registerfrm.html
        $template = Template::create('', '', 'registerfrm');
        $template->add(array(
            '/{LOGO}/' => self::logo(),
            '/{LOGO_CLASS}/' => \Index\Index\Controller::logoClass(),
            '/{PLACEHOLDER}/' => implode(',', $fields),
            '/{CATEGORIES}/' => $categories,
            '/{TOKEN}/' => $request->createToken(),
            '/{LOGINMENU}/' => self::menus('register'),
            '/{LOGIN_MESSAGE}/' => empty(self::$cfg->login_message) || !in_array(self::$cfg->login_message_style, array('tip', 'message', 'warning')) ? '' : '<aside class="'.self::$cfg->login_message_style.'">'.nl2br(self::$cfg->login_message).'</aside>'
        ));
        return (object) array(
            'detail' => $template->render(),
            'title' => self::$cfg->web_title.' - '.Language::get('Register'),
            'bodyClass' => 'welcomepage'
        );
    }

    /**
     * เมนูหน้าเข้าระบบ
     *
     * @param  $from
     *
     * @return string
     */
    public static function menus($from)
    {
        $menus = [];
        if (in_array($from, array('register', 'forgot'))) {
            $menus[] = '<a href="index.php?module=welcome&amp;action=login" target=_self>{LNG_Login}</a>';
        }
        if (in_array($from, array('forgot', 'login')) && !empty(self::$cfg->user_register)) {
            $menus[] = '<a href="index.php?module=welcome&amp;action=register" target=_self>{LNG_Register}</a>';
        }
        if (in_array($from, array('register', 'login')) && !empty(self::$cfg->user_forgot)) {
            $menus[] = '<a href="index.php?module=welcome&amp;action=forgot" target=_self>{LNG_Forgot}</a>';
        }
        return empty($menus) ? '' : implode('&nbsp;/&nbsp;', $menus);
    }

    /**
     * คืนค่า logo ของเว็บไซต์
     *
     * @return string
     */
    private static function logo()
    {
        if (is_file(ROOT_PATH.DATA_FOLDER.'images/logo.png')) {
            $logo = '<img src="'.WEB_URL.DATA_FOLDER.'images/logo.png" alt="{WEBTITLE}">';
            if (!empty(self::$cfg->show_title_logo)) {
                $logo .= '{WEBTITLE}';
            }
        } else {
            $logo = '<span class="'.self::$cfg->default_icon.'">{WEBTITLE}</span>';
        }
        return $logo;
    }
}
