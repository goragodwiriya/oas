<?php
/**
 * @filesource Kotchasan/Config.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Configuration class for loading and managing config settings.
 * This class is responsible for loading and managing configuration settings.
 *
 * @see https://www.kotchasan.com/
 */
#[\AllowDynamicProperties]
class Config
{
    /**
     * Cache expiration time in seconds.
     * 0 means no caching.
     *
     * @var int
     */
    public $cache_expire = 0;

    /**
     * Default character set.
     *
     * @var string
     */
    public $char_set = 'UTF-8';

    /**
     * The mail server hostname, e.g., localhost or smtp.gmail.com.
     *
     * @var string
     */
    public $email_Host = 'localhost';

    /**
     * The password for the mail server.
     *
     * @var string
     */
    public $email_Password = '';

    /**
     * The mail server port number (default is 25, use 465 or 587 for Gmail, 587 for DirectAdmin).
     *
     * @var int
     */
    public $email_Port = 25;

    /**
     * Specifies whether SMTP authentication is required for the mail server.
     * If set to true, username and password for the mail server must be provided.
     *
     * @var bool
     */
    public $email_SMTPAuth = false;

    /**
     * The SSL encryption protocol for sending emails, e.g., ssl.
     *
     * @var string
     */
    public $email_SMTPSecure = '';

    /**
     * The username for the mail server.
     *
     * @var string
     */
    public $email_Username = '';

    /**
     * The character set for outgoing emails, e.g., tis-620.
     *
     * @var string
     */
    public $email_charset = 'utf-8';

    /**
     * Selects the program used for sending emails (1 for PHPMailer).
     *
     * @var int
     */
    public $email_use_phpMailer = 1;

    /**
     * The list of supported languages (found in the language/ directory). The default language is 'en' (English).
     *
     * @var array
     */
    public $languages = ['th'];

    /**
     * The list of fields from the member table used for login.
     *
     * @var array
     */
    public $login_fields = ['username'];

    /**
     * The email address used as the sender for non-reply emails, e.g., no-reply@domain.tld.
     *
     * @var string
     */
    public $noreply_email = '';

    /**
     * The encryption key for password encryption.
     *
     * @var string
     */
    public $password_key = '1234567890';

    /**
     * The currently used template (folder name).
     *
     * @var string
     */
    public $skin = 'default';

    /**
     * The server timezone, e.g., Asia/Bangkok (use Asia/Bangkok for servers located in Thailand).
     *
     * @var string
     */
    public $timezone = 'Asia/Bangkok';

    /**
     * The description of the website.
     *
     * @var string
     */
    public $web_description = 'PHP Framework developed by Thai people';

    /**
     * The title of the website.
     *
     * @var string
     */
    public $web_title = 'Kotchasan PHP Framework';
    /**
     * @var Singleton used to invoke this class only once
     */
    private static $instance = null;

    /**
     * Creates an instance of the class, which can be called only once.
     *
     * @return static
     */
    public static function create()
    {
        if (null === self::$instance) {
            self::$instance = new static;
        }
        return self::$instance;
    }

    /**
     * Retrieves the value of a variable and converts the result based on the specified data type in $default.
     *
     * @param string $key     Variable name
     * @param mixed  $default (optional) Default value if the variable is not found
     *
     * @return mixed
     */
    public function get($key, $default = '')
    {
        if (isset($this->{$key})) {
            $result = $this->$key;
            if (is_float($default)) {
                // Floating-point number, e.g., 0.0
                $result = (float) $result;
            } elseif (is_int($default)) {
                // Integer, e.g., 0
                $result = (int) $result;
            } elseif (is_bool($default)) {
                // Boolean, true or false
                $result = (bool) $result;
            }
        } else {
            $result = $default;
        }
        return $result;
    }

    /**
     * Loads the config file.
     *
     * @param string $file Config file (fullpath)
     *
     * @return object
     */
    public static function load($file)
    {
        $config = [];
        if (is_file($file)) {
            $config = include $file;
        }
        return (object) $config;
    }

    /**
     * Saves the project's config file.
     *
     * @param array  $config
     * @param string $file   Config file (fullpath)
     *
     * @return bool
     */
    public static function save($config, $file)
    {
        $f = @fopen($file, 'wb');
        if ($f !== false) {
            if (!preg_match('/^.*\/([^\/]+)\.php?/', $file, $match)) {
                $match[1] = 'config';
            }
            fwrite($f, '<'."?php\n/* $match[1].php */\nreturn ".var_export((array) $config, true).';');
            fclose($f);
            if (function_exists('opcache_invalidate')) {
                // Reset file cache
                opcache_invalidate($file);
            } else {
                // Small delay
                usleep(1000000);
            }
            // Success
            return true;
        } else {
            return false;
        }
    }

    /**
     * Creates an instance of the class, which can be called only once.
     *
     * @return static
     */
    protected function __construct()
    {
        if (is_file(ROOT_PATH.'settings/config.php')) {
            $config = include ROOT_PATH.'settings/config.php';
            if (is_array($config)) {
                foreach ($config as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }
        if (ROOT_PATH != APP_PATH && is_file(APP_PATH.'settings/config.php')) {
            $config = include APP_PATH.'settings/config.php';
            if (is_array($config)) {
                foreach ($config as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}
