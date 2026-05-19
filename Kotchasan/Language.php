<?php
namespace Kotchasan;

/**
 * Kotchasan Language Class
 *
 * This class provides methods for managing and retrieving language translations.
 * It supports loading language files, retrieving language variables, and formatting strings.
 *
 * @package Kotchasan
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
     * @param string $key     The language variable key or English text.
     * @param mixed  $default The default value to return if the key is not found.
     * @param mixed  $value   If an array variable is specified and $value is set, returns the language value at $key[$value].
     *
     * @return mixed The language value or the default value if the key is not found.
     */
    public static function get($key, $default = null, $value = null)
    {
        if (null === self::$languages) {
            new static();
        }
        $key = preg_replace('/\s+/', ' ', trim($key));
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
            new static();
        }
        $result = [];
        foreach ($keys as $i => $key) {
            $key = preg_replace('/\s+/', ' ', trim($key));
            $result[is_int($i) ? $key : $i] = isset(self::$languages->{$key}) ? self::$languages->{$key} : $key;
        }
        return $result;
    }

    /**
     * Loads all installed language files.
     *
     * @param string $type The file type (php).
     *
     * @return array An array of language data.
     */
    public static function installed($type)
    {
        if ($type !== 'php') {
            return [];
        }

        $language_folder = self::languageFolder();
        $datas = [];
        foreach (self::installedLanguage() as $lng) {
            $language = self::loadPhpLanguage($language_folder, $lng);
            if ($language !== null) {
                $datas[$lng] = $language;
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
            $datas[$i] = ArrayTool::replace(['id' => $i, 'key' => $key], $row);
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
            self::$installed_languages = [];
            $language_folder = self::languageFolder();
            $files = [];
            File::listFiles($language_folder, $files);
            foreach ($files as $file) {
                if (preg_match('/(.*\/([a-z]{2,2}))\.(php)$/', $file, $match)) {
                    self::$installed_languages[$match[2]] = $match[2];
                }
            }
            ksort(self::$installed_languages);
        }
        return self::$installed_languages;
    }

    /**
     * Checks if a language key exists in the given array of languages.
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
     * @param array  $datas The language data.
     * @param string $name  The name of the language variable.
     * @param string $key   The key to check.
     *
     * @return bool True if the language variable exists and is an array with the given key, false otherwise.
     */
    public static function arrayKeyExists($name, $key)
    {
        if (null === self::$languages) {
            new static();
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
     * @return string The name of the currently active language.
     */
    public static function name()
    {
        if (null === self::$languages) {
            new static();
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
        if (null === self::$languages || $language !== self::$language_name) {
            new static($language);
        }
        return self::$language_name;
    }

    /**
     * Function that translates the language received from Theme parsing.
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
     * @param string $key     The language key.
     * @param mixed  $replace The values to replace placeholders.
     *
     * @return string
     */
    public static function replace($key, $replace)
    {
        if (null === self::$languages) {
            new static();
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
     * Format a string based on a key using sprintf formatting.
     *
     * @param string $key The key to lookup in the language translations.
     * @param mixed ...$values Optional values to substitute into the formatted string.
     *
     * @return string The formatted string.
     */
    public static function sprintf($key, ...$values)
    {
        if (null === self::$languages) {
            new static();
        }
        $values = func_get_args();
        $key = array_shift($values);
        $value = isset(self::$languages->$key) ? self::$languages->$key : $key;
        return \sprintf($value, ...$values);
    }

    /**
     * Saves the language file.
     *
     * @param array  $languages The language data to be saved.
     * @param string $type      The type of file to save ('php' or 'json').
     *
     * @return string
     */
    public static function save($languages, $type)
    {
        $datas = [];
        foreach ($languages as $items) {
            foreach ($items as $key => $value) {
                if (!in_array($key, ['id', 'key', 'array', 'owner', 'type'])) {
                    $datas[$key][$items['key']] = $value;
                }
            }
        }
        $language_folder = self::languageFolder();
        foreach ($datas as $lang => $items) {
            $list = [];
            foreach ($items as $key => $value) {
                if ($type == 'json') {
                    $list[$key] = $value;
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
                } elseif ($type == 'json') {
                    $content = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $language = self::loadPhpLanguage($language_folder, $lang);
        if ($language !== null) {
            self::$languages = (object) $language;
            self::$language_name = $lang;
        }
    }

    /**
     * Loads the language.
     *
     * @param string|null $lang The language to be used. If not specified, it will read from the 'my_lang' cookie.
     */
    private function __construct($lang = null)
    {
        $language_folder = self::languageFolder();
        $installedLanguages = self::installedLanguage();
        if (self::$cfg && isset(self::$cfg->languages) && is_array(self::$cfg->languages)) {
            $configLanguages = self::$cfg->languages;
        } elseif (!empty($installedLanguages)) {
            $configLanguages = $installedLanguages;
        } else {
            $configLanguages = ['th' => 'th'];
        }

        if ($lang === null) {
            if (self::$request !== null) {
                $queryParams = self::$request->getQueryParams();
                $cookieParams = self::$request->getCookieParams();
                $lang = isset($queryParams['lang']) ? $queryParams['lang'] : (isset($cookieParams['my_lang']) ? $cookieParams['my_lang'] : '');
                $lang = preg_match('/^[a-z]+$/', $lang) ? $lang : '';
            } else {
                $lang = '';
            }
        }
        if (empty($lang)) {
            if (defined('INIT_LANGUAGE')) {
                $init_language = constant('INIT_LANGUAGE');
                if ($init_language === 'auto') {
                    $lang = array_key_first($configLanguages) ?: 'th';
                } else {
                    $lang = $init_language;
                }
            } else {
                $lang = array_key_first($configLanguages) ?: 'th';
            }
        }

        foreach (ArrayTool::replace([$lang => $lang], $configLanguages) as $item) {
            if (!empty($item)) {
                $language = self::loadPhpLanguage($language_folder, $item);
                if ($language !== null) {
                    self::$languages = (object) $language;
                    self::$language_name = $item;
                    if (PHP_SAPI !== 'cli' && !headers_sent()) {
                        setcookie('my_lang', $item, time() + 2592000, '/');
                    }
                    break;
                }
            }
        }
        if (null === self::$languages) {
            self::$language_name = 'th';
            self::$languages = (object) [
                'DATE_FORMAT' => 'd M Y เวลา H:i น.',
                'DATE_LONG' => [
                    0 => 'อาทิตย์',
                    1 => 'จันทร์',
                    2 => 'อังคาร',
                    3 => 'พุธ',
                    4 => 'พฤหัสบดี',
                    5 => 'ศุกร์',
                    6 => 'เสาร์'
                ],
                'DATE_SHORT' => [
                    0 => 'อา.',
                    1 => 'จ.',
                    2 => 'อ.',
                    3 => 'พ.',
                    4 => 'พฤ.',
                    5 => 'ศ.',
                    6 => 'ส.'
                ],
                'YEAR_OFFSET' => 543,
                'MONTH_LONG' => [
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
                ],
                'MONTH_SHORT' => [
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
                ]
            ];
        }
        if (!defined('LANGUAGE')) {
            define('LANGUAGE', self::$language_name);
        }
    }

    /**
     * Load a PHP language dictionary.
     *
     * @param string $language_folder
     * @param string $lang
     *
     * @return array|null
     */
    private static function loadPhpLanguage($language_folder, $lang)
    {
        $file = $language_folder.$lang.'.php';
        if (!is_file($file)) {
            return null;
        }

        $language = include $file;
        return is_array($language) ? $language : null;
    }
}
