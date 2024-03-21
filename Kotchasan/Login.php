<?php
/**
 * @filesource Kotchasan/Login.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

use Kotchasan\Http\Request;

/**
 * This class is responsible for handling user login functionality.
 *
 * @see https://www.kotchasan.com/
 */
class Login extends KBase
{
    /**
     * Indicates whether the login request is submitted.
     *
     * @var bool
     */
    public static $from_submit = false;

    /**
     * The name of the input field to be focused.
     * Can be 'login_username' or 'login_password'.
     *
     * @var string
     */
    public static $login_input;

    /**
     * The login message.
     *
     * @var string
     */
    public static $login_message;

    /**
     * The login parameters submitted by the user.
     *
     * @var array
     */
    public static $login_params = [];

    /**
     * Validates the login request and performs the login process.
     *
     * @param Request $request The HTTP request object.
     * @return static
     */
    public static function create(Request $request)
    {
        try {
            $obj = new static;
            self::$login_params['username'] = $request->post('login_username')->username();

            if (empty(self::$login_params['username'])) {
                if (isset($_SESSION['login'])) {
                    if (isset($_SESSION['login']['username'])) {
                        self::$login_params['username'] = Text::username($_SESSION['login']['username']);
                    }
                    if (isset($_SESSION['login']['password'])) {
                        self::$login_params['password'] = Text::password($_SESSION['login']['password']);
                    }
                }
                self::$from_submit = $request->post('login_username')->exists();
            } elseif ($request->post('login_password')->exists()) {
                self::$login_params['password'] = $request->post('login_password')->password();
                self::$from_submit = true;
            }

            $action = $request->request('action')->toString();
            if ($action === 'logout' && !self::$from_submit) {
                $obj->logout($request);
            } elseif ($action === 'forgot') {
                $obj->forgot($request);
            } else {
                if (empty(self::$login_params['username']) && self::$from_submit) {
                    self::$login_message = Language::get('Please fill up this form');
                    self::$login_input = 'login_username';
                } elseif (empty(self::$login_params['password']) && self::$from_submit) {
                    self::$login_message = Language::get('Please fill up this form');
                    self::$login_input = 'login_password';
                } elseif (!self::$from_submit || (self::$from_submit && $request->isReferer())) {
                    $obj->login($request, self::$login_params);
                }
            }
        } catch (InputItemException $e) {
            self::$login_message = $e->getMessage();
        }

        return $obj;
    }

    /**
     * Logs out the user by clearing the session and displaying a success message.
     *
     * @param Request $request The HTTP request object.
     * @return void
     */
    public function logout(Request $request)
    {
        unset($_SESSION['login']);
        self::$login_message = Language::get('Logout successful');
        self::$login_params = [];
    }

    /**
     * Initiates the password recovery process.
     *
     * @param Request $request The HTTP request object.
     * @return void
     */
    public function forgot(Request $request)
    {
        // Password recovery logic goes here
    }

    /**
     * Validates the login credentials and performs the login process.
     *
     * @param Request $request     The HTTP request object.
     * @param array   $loginParams The login parameters.
     *
     * @return void
     */
    public function login(Request $request, $loginParams)
    {
        // Check login against the database
        $login_result = $this->checkLogin($loginParams);
        if (is_array($login_result)) {
            // Save login session
            $_SESSION['login'] = $login_result;
        } else {
            if (is_string($login_result)) {
                // Error message
                self::$login_input = self::$login_input == 'password' ? 'login_password' : 'login_username';
                self::$login_message = $login_result;
            }
            // Logout: remove session and cookie
            unset($_SESSION['login']);
        }
    }

    /**
     * Validates the login credentials against the configured username and password.
     *
     * @param array $loginParams The login parameters. e.g., array('username' => '', 'password' => '');
     *
     * @return string|array Returns a string error message if login fails, or an array with login information.
     */
    public function checkLogin($loginParams)
    {
        if ($loginParams['username'] !== self::$cfg->get('username')) {
            self::$login_input = 'username';
            return 'not a registered user';
        } elseif ($loginParams['password'] !== self::$cfg->get('password')) {
            self::$login_input = 'password';
            return 'password incorrect';
        }
        // Return the logged-in user information
        return array(
            'username' => $loginParams['username'],
            'password' => $loginParams['password'],
            // Status: Admin
            'status' => 1
        );
    }

    /**
     * Checks if the user is an admin.
     *
     * @return array|null Returns the login information if the user is an admin, or null otherwise.
     */
    public static function isAdmin()
    {
        $login = self::isMember();
        return isset($login['status']) && $login['status'] == 1 ? $login : null;
    }

    /**
     * Checks if the user is a member.
     *
     * @return array|null Returns the login information if the user is a member, or null otherwise.
     */
    public static function isMember()
    {
        return empty($_SESSION['login']) ? null : $_SESSION['login'];
    }

    /**
     * Check the status of a login.
     *
     * If the login is empty or the status does not match the provided statuses,
     * null is returned. If the status matches, the login is returned.
     *
     * @param array $login    The login information.
     * @param mixed $statuses The allowed status(es) to check against.
     *
     * @return array|null The login information if the status matches, null otherwise.
     */
    public static function checkStatus($login, $statuses)
    {
        if (!empty($login)) {
            if ($login['status'] == 1) {
                // Admin
                return $login;
            } elseif (is_array($statuses)) {
                if (in_array($login['status'], $statuses)) {
                    return $login;
                }
            } elseif ($login['status'] == $statuses) {
                return $login;
            }
        }
        // No privileges
        return null;
    }
}
