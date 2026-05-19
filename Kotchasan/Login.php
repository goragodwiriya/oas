<?php

namespace Kotchasan;

use Kotchasan\Http\Request;

/**
 * Kotchasan Login Class
 *
 * This class handles user login functionality, including login validation,
 * session management, and user authentication.
 *
 * @package Kotchasan
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
     * Can be 'username' or 'password'.
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
     * Creates a new instance of the Login class.
     *
     * @param Request $request The HTTP request object.
     *
     * @return static A new instance of the Login class.
     */
    public static function create(Request $request)
    {
        $key = static::sessionKey();

        $obj = new static();

        self::$login_params['username'] = $request->post('username')->username();

        if (empty(self::$login_params['username'])) {
            if (isset($_SESSION[$key])) {
                if (isset($_SESSION[$key]->username)) {
                    self::$login_params['username'] = Text::username($_SESSION[$key]->username);
                }
                if (isset($_SESSION[$key]->password)) {
                    self::$login_params['password'] = Text::password($_SESSION[$key]->password);
                }
            }
            self::$from_submit = $request->post('username')->exists();
        } elseif ($request->post('password')->exists()) {
            self::$login_params['password'] = $request->post('password')->password();
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
                self::$login_input = 'username';
            } elseif (empty(self::$login_params['password']) && self::$from_submit) {
                self::$login_message = Language::get('Please fill up this form');
                self::$login_input = 'password';
            } elseif (!self::$from_submit || (self::$from_submit && $request->isReferer())) {
                $obj->login($request, self::$login_params);
            }
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
        $key = static::sessionKey();
        unset($_SESSION[$key]);
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
        // Password recovery logic
        // Implementation depends on your application's requirements
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
        $key = static::sessionKey();

        // Check login against the database
        $login_result = $this->checkLogin($loginParams);

        if (is_array($login_result)) {
            // Save login session
            $_SESSION[$key] = $login_result;
        } else {
            // Login failed
            if (is_string($login_result)) {
                // Error message
                self::$login_input = self::$login_input === 'password' ? 'password' : 'username';
                self::$login_message = $login_result;
            }
            // Logout: remove session and cookie
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * Validates the login credentials against the configured username and password.
     * Override this method in your implementation to check against a database.
     *
     * @param array $loginParams The login parameters. e.g., array('username' => '', 'password' => '');
     *
     * @return string|object Returns a string error message if login fails, or an object with login information.
     */
    public function checkLogin($loginParams)
    {
        $username = $loginParams['username'] ?? '';
        $password = $loginParams['password'] ?? '';

        if ($username !== self::$cfg->get('username')) {
            self::$login_input = 'username';
            return 'not a registered user';
        } elseif ($password !== self::$cfg->get('password')) {
            self::$login_input = 'password';
            return 'password incorrect';
        }
        // Return the logged-in user information
        return (object) [
            'username' => $username,
            'password' => $password,
            // Status: Admin
            'status' => 1
        ];
    }

    /**
     * Check permission
     *
     * @param string|array $permission
     * @param object|null $login The login information.
     * @param bool $checkAdmin Check if you are an admin or not. If you're an admin, you don't need to check your permissions and restore them immediately.
     *
     * @return object|null Returns the login information if the user has permission, or null otherwise.
     */
    public static function hasPermission($permission, $login = null, $checkAdmin = true)
    {
        $login = $login ?? self::isMember();

        if (!$login) {
            return null;
        }

        if ($checkAdmin && $login->status === 1) {
            // Admin has all rights.
            return $login;
        } elseif (!empty($permission)) {
            if (is_array($permission)) {
                foreach ($permission as $item) {
                    if (in_array($item, $login->permission)) {
                        // Found rights
                        return $login;
                    }
                }
            } elseif (in_array($permission, $login->permission)) {
                // Found rights
                return $login;
            }
        }
        // Permission not found
        return null;
    }

    /**
     * Check if the user is a super admin
     *
     * @param object|null $login The login information.
     *
     * @return object|null Returns the login information if the user is a super admin, or null otherwise.
     */
    public static function isSuperAdmin($login = null)
    {
        $login = $login ?? self::isMember();
        return $login && $login->id === 1 ? $login : null;
    }

    /**
     * Checks if the user is an admin.
     *
     * @param object|null $login The login information.
     *
     * @return object|null Returns the login information if the user is an admin, or null otherwise.
     */
    public static function isAdmin($login = null)
    {
        $login = $login ?? self::isMember();
        return isset($login->status) && $login->status === 1 ? $login : null;
    }

    /**
     * Checks if the user is a member.
     *
     * @return object|null Returns the login information if the user is a member, or null otherwise.
     */
    public static function isMember()
    {
        $key = static::sessionKey();
        return empty($_SESSION[$key]) ? null : $_SESSION[$key];
    }

    /**
     * Check the status of a login.
     *
     * If the login is empty or the status does not match the provided statuses,
     * null is returned. If the status matches, the login is returned.
     *
     * @param object $login    The login information.
     * @param object $config    The module configuration.
     * @param string|array $statuses The allowed status(es) to check against.
     *
     * @return object|null The login information if the status matches, null otherwise.
     */
    public static function checkStatus($login, $config, $statuses)
    {
        // Return null when there is no login information
        if (empty($login)) {
            return null;
        }
        if ($login->status === 1) {
            // Admin
            return $login;
        } elseif (is_array($config->$statuses)) {
            if (in_array($login->status, $config->$statuses)) {
                return $login;
            }
        } elseif ($login->status === $config->$statuses) {
            return $login;
        }
        // No privileges
        return null;
    }

    /**
     * Session key used to store login information.
     * Can be overridden by configuration (e.g. self::$cfg->session_key) or
     * by subclasses overriding this method.
     *
     * @return string
     */
    public static function sessionKey()
    {
        if (isset(self::$cfg)) {
            if (!empty(self::$cfg->session_key)) {
                return (string) self::$cfg->session_key;
            }
            if (!empty(self::$cfg->session_prefix)) {
                return (string) self::$cfg->session_prefix.'login';
            }
        }
        return 'login';
    }
}
