<?php
/**
 * @filesource modules/index/controllers/linecallback.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Linecallback;

use Kotchasan\Curl;
use Kotchasan\Http\Request;

/**
 * linecallback.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * Controller รับค่าการ Login ด้วย LINE
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        if ($request->initSession()) {
            try {
                $code = $request->get('code', '')->toString();
                $ret_url = base64_decode($request->get('state', '')->toString());
                if ($code != '') {
                    // get refresh token
                    $url = "https://api.line.me/oauth2/v2.1/token";
                    $curl = new Curl();
                    $content = $curl->post($url, array(
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'redirect_uri' => str_replace('www.', '', WEB_URL.'line/callback.php'),
                        'client_id' => self::$cfg->line_channel_id,
                        'client_secret' => self::$cfg->line_channel_secret
                    ));
                    $result = json_decode($content, true);
                    // get user info
                    $url = 'https://api.line.me/oauth2/v2.1/verify';
                    $curl = new Curl();
                    $content = $curl->post($url, array(
                        'id_token' => $result['id_token'],
                        'client_id' => self::$cfg->line_channel_id
                    ));
                    $user = json_decode($content, true);
                    if (!empty($user['sub'])) {
                        // user
                        $user = \Index\Linelogin\Model::chklogin($request, $user);
                        if (is_array($user)) {
                            unset($user['password']);
                            // login
                            $_SESSION['login'] = $user;
                            // redirect
                            header('Location: '.$ret_url);
                        } else {
                            // ข้อผิดพลาด redirect กลับไปหน้า login
                            $params = array(
                                'module' => 'welcome',
                                'msg' => $user,
                                'ret' => $ret_url
                            );
                            header('Location: '.WEB_URL.'index.php?'.http_build_query($params));
                        }
                        exit;
                    }
                }
            } catch (\Kotchasan\InputItemException $e) {
            }
        }
        // redirect
        header('Location: '.WEB_URL);
    }
}
