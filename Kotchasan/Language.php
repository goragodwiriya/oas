<?php
/**
 * @filesource Kotchasan/Language.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class for language loading.
 *
 * @see https://www.kotchasan.com/
 */
final class Language extends \Kotchasan\KBase
{
    /**
     * @var array Installed languages.
     */
    private static $installed_languages;

    /**
     * @var string Name of the currently used language.
     */
    private static $language_name;

    /**
     * @var object Language data.
     */
    private static $languages = null;

    /**
     * Retrieves the language variable with the specified key.
     *
     * @assert ('YEAR_OFFSET') [==] 543
     * @assert ('XYZ', []) [==] []
     * @assert ('DATE_LONG', null, 0) [==] 'อาทิตย์'
     * @assert ('DATE_LONG', null, 12) [==] 'DATE_LONG'
     * @assert ('not found', 'default') [==] 'default'
     *
     * @param string $key     The language variable key or English text.
     * @param mixed  $default The default value to return if the key is not found.
     * @param mixed  $value   If an array variable is specified and $value is set, returns the language value at $key[$value].
     *
     * @return mixed The language value or the default value if the key is not found.
     */
    public static function get($key, $default = null, $value = null)
    {
        if (null === self::$languages) {
            new static;
        }
        if (isset(self::$languages->{$key})) {
            $item = self::$languages->{$key};
            if ($value !== null && is_array($item)) {
                return isset($item[$value]) ? $item[$value] : ($default === null ? $key : $default);
            } else {
                return $item;
            }
        } else {
            return $default === null ? $key : $default;
        }
    }

    /**
     * Retrieves multiple language items based on the specified keys.
     *
     * @param array $keys An array of language variable keys or English texts.
     *
     * @return array An array of language values.
     */
    public static function getItems(array $keys = [])
    {
        if (null === self::$languages) {
            new static;
        }
        $result = [];
        foreach ($keys as $i => $key) {
            $result[is_int($i) ? $key : $i] = isset(self::$languages->{$key}) ? self::$languages->{$key} : $key;
        }
        return $result;
    }

    /**
     * Loads all installed language files.
     *
     * @param string $type The file type (php or js).
     *
     * @return array An array of language data.
     */
    public static function installed($type)
    {
        $language_folder = self::languageFolder();
        $datas = [];
        foreach (self::installedLanguage() as $lng) {
            if ($type == 'php') {
                if (is_file($language_folder.$lng.'.php')) {
                    // php
                    $datas[$lng] = include $language_folder.$lng.'.php';
                }
            } elseif (is_file($language_folder.$lng.'.js')) {
                // js
                $list = file($language_folder.$lng.'.js');
                foreach ($list as $item) {
                    if (preg_match('/var\s+(.*)\s+=\s+[\'"](.*)[\'"];/', $item, $values)) {
                        $datas[$lng][$values[1]] = $values[2];
                    }
                }
            }
        }
        // จัดกลุ่มภาษาตาม key
        $languages = [];
        foreach ($datas as $language => $values) {
            foreach ($values as $key => $value) {
                $languages[$key][$language] = $value;
                if (is_array($value)) {
                    $languages[$key]['array'] = true;
                }
            }
        }
        // จัดกลุ่มภาษาตาม id
        $datas = [];
        $i = 0;
        foreach ($languages as $key => $row) {
            $datas[$i] = ArrayTool::replace(array('id' => $i, 'key' => $key), $row);
            ++$i;
        }
        return $datas;
    }

    /**
     * Retrieves an array of installed languages.
     *
     * @return array An array of installed languages.
     */
    public static function installedLanguage()
    {
        if (!isset(self::$installed_languages)) {
            $language_folder = self::languageFolder();
            $files = [];
            File::listFiles($language_folder, $files);
            foreach ($files as $file) {
                if (preg_match('/(.*\/([a-z]{2,2}))\.(php|js)$/', $file, $match)) {
                    self::$installed_languages[$match[2]] = $match[2];
                }
            }
        }
        return self::$installed_languages;
    }

    /**
     * Checks if a language key exists in the given array of languages.
     *
     * @assert (array(array('id' => 0, 'key' => 'One'), array('id' => 100, 'key' => 'Two')), 'One') [==] 0
     * @assert (array(array('id' => 0, 'key' => 'One'), array('id' => 100, 'key' => 'Two')), 'two') [==] 100
     * @assert (array(array('id' => 0, 'key' => 'One'), array('id' => 100, 'key' => 'Two')), 'O') [==] -1
     *
     * @param array  $languages An array of language data.
     * @param string $key       The language key to check.
     *
     * @return int The index of the found key, or -1 if not found.
     */
    public static function keyExists($languages, $key)
    {
        foreach ($languages as $item) {
            if (strcasecmp($item['key'], $key) == 0) {
                return $item['id'];
            }
        }
        return -1;
    }

    /**
     * Checks if a language variable specified by name exists and is an array with a given key.
     *
     * @assert ('DATE_LONG', 1) [==] true
     * @assert ('DATE_LONG', 7) [==] false
     *
     * @param array  $datas The language data.
     * @param string $name  The name of the language variable.
     * @param string $key   The key to check.
     *
     * @return bool True if the language variable exists and is an array with the given key, false otherwise.
     */
    public static function arrayKeyExists($name, $key)
    {
        if (null === self::$languages) {
            new static;
        }
        return is_array(self::$languages->{$name}) && isset(self::$languages->{$name}[$key]);
    }

    /**
     * Retrieves the folder path where language files are stored.
     *
     * @return string The folder path where language files are stored.
     */
    public static function languageFolder()
    {
        return ROOT_PATH.'language/';
    }

    /**
     * Retrieves the name of the currently active language.
     *
     * @assert () [==] 'th'
     *
     * @return string The name of the currently active language.
     */
    public static function name()
    {
        if (null === self::$languages) {
            new static;
        }
        return self::$language_name;
    }

    /**
     * Sets the language to be used.
     *
     * @param string $language The language to be set.
     *
     * @return string The name of the language.
     */
    public static function setName($language)
    {
        if (null === self::$languages || $language !== self::$languages) {
            new static($language);
        }
        return self::$language_name;
    }

    /**
     * Function that translates the language received from Theme parsing.
     *
     * @assert (array(1 => 'not found')) [==] 'not found'
     *
     * @param array $match The variable received from Theme parsing.
     *
     * @return string
     */
    public static function parse($match)
    {
        return self::get($match[1]);
    }

    /**
     * Retrieves the language value based on the given key and replaces placeholders with values from the $replace array.
     *
     * @assert ('You want to :action', array(':action' => 'delete')) [==] 'You want to delete'
     * @assert ('You want to %s', 'delete') [==] 'You want to delete'
     * @assert ('You want to %s', 1) [==] 'You want to 1'
     *
     * @param string $key     The language key.
     * @param mixed  $replace The values to replace placeholders.
     *
     * @return mixed
     */
    public static function replace($key, $replace)
    {
        if (null === self::$languages) {
            new static;
        }
        $value = isset(self::$languages->$key) ? self::$languages->$key : $key;
        if (is_array($replace) || is_object($replace)) {
            foreach ($replace as $k => $v) {
                $v = isset(self::$languages->$v) ? self::$languages->$v : $v;
                $value = str_replace($k, $v, $value);
            }
        } else {
            $value = str_replace('%s', $replace, $value);
        }
        return $value;
    }

    /**
     * Saves the language file.
     *
     * @param array  $languages The language data to be saved.
     * @param string $type      The type of file to save ('php' or 'js').
     *
     * @return string
     */
    public static function save($languages, $type)
    {
        $datas = [];
        foreach ($languages as $items) {
            foreach ($items as $key => $value) {
                if (!in_array($key, array('id', 'key', 'array', 'owner', 'type', 'js'))) {
                    $datas[$key][$items['key']] = $value;
                }
            }
        }
        $language_folder = self::languageFolder();
        foreach ($datas as $lang => $items) {
            $list = [];
            foreach ($items as $key => $value) {
                if ($type == 'js') {
                    if (is_string($value)) {
                        $list[] = "var $key = '$value';";
                    } else {
                        $list[] = "var $key = $value;";
                    }
                } elseif (is_array($value)) {
                    $save = [];
                    foreach ($value as $k => $v) {
                        $data = '';
                        if (preg_match('/^[0-9]+$/', $k)) {
                            $data = $k.' => ';
                        } else {
                            $data = '\''.$k.'\' => ';
                        }
                        if (is_string($v)) {
                            $data .= '\''.$v.'\'';
                        } else {
                            $data .= $v;
                        }
                        $save[] = $data;
                    }
                    $list[] = '\''.$key."' => array(\n    ".implode(",\n    ", $save)."\n  )";
                } elseif (is_string($value)) {
                    $list[] = '\''.$key.'\' => \''.($value).'\'';
                } else {
                    $list[] = '\''.$key.'\' => '.$value;
                }
            }
            $file = $language_folder.$lang.'.'.$type;
            // save
            $f = @fopen($file, 'wb');
            if ($f !== false) {
                if ($type == 'php') {
                    $content = '<'."?php\n/* language/$lang.php */\nreturn array(\n  ".implode(",\n  ", $list)."\n);";
                } else {
                    $content = implode("\n", $list);
                }
                fwrite($f, $content);
                fclose($f);
                if (function_exists('opcache_invalidate')) {
                    // reset file cache
                    opcache_invalidate($file);
                }
            } else {
                return sprintf(self::get('File %s cannot be created or is read-only.'), $lang.'.'.$type);
            }
        }
        return '';
    }

    /**
     * Translates the given content by replacing language placeholders.
     *
     * @assert ('ภาษา {LNG_DATE_FORMAT} ไทย') [==] 'ภาษา d M Y เวลา H:i น. ไทย'
     *
     * @param string $content The content to be translated.
     *
     * @return string The translated content.
     */
    public static function trans($content)
    {
        return preg_replace_callback('/{LNG_([^}]+)}/', function ($match) {
            return Language::get($match[1]);
        }, $content);
    }

    /**
     * Loads the language file based on the selected language.
     *
     * @param string $lang The language to be loaded.
     */
    public static function load($lang)
    {
        // Language folder
        $language_folder = self::languageFolder();
        if (is_file($language_folder.$lang.'.php')) {
            $language = include $language_folder.$lang.'.php';
            if (isset($language)) {
                self::$languages = (object) $language;
                self::$language_name = $lang;
            }
        }
    }

    /**
     * Loads the language.
     *
     * @param string|null $lang The language to be used. If not specified, it will read from the 'my_lang' cookie.
     */
    private function __construct($lang = null)
    {
        // Language folder
        $language_folder = self::languageFolder();
        // Selected language
        if ($lang === null) {
            $lang = self::$request->get('lang', self::$request->cookie('my_lang', '')->toString())->filter('a-z');
        }
        if (empty($lang)) {
            if (defined('INIT_LANGUAGE')) {
                if (INIT_LANGUAGE === 'auto') {
                    // Language from browser
                    $languages = self::$request->getAcceptableLanguages();
                    if (!empty($languages) && preg_match('/^([a-z]{2,2}).*?$/', strtolower($languages[0]), $match)) {
                        $lang = $match[1];
                    } else {
                        $lang = 'th';
                    }
                } else {
                    // Use the specified initial language
                    $lang = INIT_LANGUAGE;
                }
            }
        }
        // Check language and use the first one found
        foreach (ArrayTool::replace(array($lang => $lang), self::$cfg->languages) as $item) {
            if (!empty($item)) {
                if (is_file($language_folder.$item.'.php')) {
                    $language = include $language_folder.$item.'.php';
                    if (isset($language)) {
                        self::$languages = (object) $language;
                        self::$language_name = $item;
                        // Save the currently used language in a cookie
                        setcookie('my_lang', $item, time() + 2592000, '/');
                        break;
                    }
                }
            }
        }
        if (null === self::$languages) {
            // Default language
            self::$language_name = 'th';
            self::$languages = (object) array(
                'DATE_FORMAT' => 'd M Y เวลา H:i น.',
                'DATE_LONG' => array(
                    0 => 'อาทิตย์',
                    1 => 'จันทร์',
                    2 => 'อังคาร',
                    3 => 'พุธ',
                    4 => 'พฤหัสบดี',
                    5 => 'ศุกร์',
                    6 => 'เสาร์'
                ),
                'DATE_SHORT' => array(
                    0 => 'อา.',
                    1 => 'จ.',
                    2 => 'อ.',
                    3 => 'พ.',
                    4 => 'พฤ.',
                    5 => 'ศ.',
                    6 => 'ส.'
                ),
                'YEAR_OFFSET' => 543,
                'MONTH_LONG' => array(
                    1 => 'มกราคม',
                    2 => 'กุมภาพันธ์',
                    3 => 'มีนาคม',
                    4 => 'เมษายน',
                    5 => 'พฤษภาคม',
                    6 => 'มิถุนายน',
                    7 => 'กรกฎาคม',
                    8 => 'สิงหาคม',
                    9 => 'กันยายน',
                    10 => 'ตุลาคม',
                    11 => 'พฤศจิกายน',
                    12 => 'ธันวาคม'
                ),
                'MONTH_SHORT' => array(
                    1 => 'ม.ค.',
                    2 => 'ก.พ.',
                    3 => 'มี.ค.',
                    4 => 'เม.ย.',
                    5 => 'พ.ค.',
                    6 => 'มิ.ย.',
                    7 => 'ก.ค.',
                    8 => 'ส.ค.',
                    9 => 'ก.ย.',
                    10 => 'ต.ค.',
                    11 => 'พ.ย.',
                    12 => 'ธ.ค.'
                )
            );
        }
        if (!defined('LANGUAGE')) {
            /* Register the currently used language */
            define('LANGUAGE', self::$language_name);
        }
    }
}
