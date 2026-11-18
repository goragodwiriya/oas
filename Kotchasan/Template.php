<?php
namespace Kotchasan;

/**
 * Kotchasan Template Class
 *
 * This class provides methods to create and manage templates,
 * allowing for dynamic content rendering and variable replacement.
 *
 * @package Kotchasan
 */
class Template
{
    /**
     * Number of columns for grid display
     *
     * @var int
     */
    protected $cols = 0;

    /**
     * Array of data
     *
     * @var array
     */
    protected $items;

    /**
     * Variable for line break (Grid)
     *
     * @var int
     */
    protected $num;

    /**
     * Name of the currently used template including the folder where the template is stored
     * Starting from DOCUMENT_ROOT, e.g., skin/default/
     *
     * @var string
     */
    protected static $src;

    /**
     * URL base for the currently selected template source.
     *
     * @var string
     */
    protected static $url = '';

    /**
     * Template data
     *
     * @var string
     */
    private $skin;

    /**
     * Sets the template variables
     * This function replaces all variables with the provided values in the template immediately
     *
     * @param array $array An array of variable names and their corresponding values to be replaced in the template
     *                     Format: array(key1=>val1,key2=>val2)
     * @return static
     */
    public function add($array)
    {
        if ($this->cols > 0 && $this->num == 0) {
            $this->items[] = "</div>\n<div class=row>";
            $this->num = $this->cols;
        }
        $this->items[] = self::pregReplace(array_keys($array), array_values($array), $this->skin);
        --$this->num;
        return $this;
    }

    /**
     * Loads a template
     * It checks the module's file first, if not found, it uses the owner's file
     *
     * @param string $owner  The name of the installed module
     * @param string $module The name of the module
     * @param string $name   The name of the template without the file extension
     *
     * @return static
     */
    public static function create($owner, $module, $name)
    {
        return self::createFromHTML(self::load($owner, $module, $name));
    }

    /**
     * Loads a template from a file
     *
     * @param string $filename The filename
     *
     * @throws \InvalidArgumentException If the file is not found
     *
     * @return static
     */
    public static function createFromFile($filename)
    {
        if (is_file($filename)) {
            return self::createFromHTML(file_get_contents($filename));
        } else {
            throw new \InvalidArgumentException('File not found '.basename((string) $filename));
        }
    }

    /**
     * Creates a template from HTML
     *
     * @param string $html The HTML code
     *
     * @return static
     */
    public static function createFromHTML($html)
    {
        $obj = new static();
        $obj->skin = $html;
        $obj->items = [];
        $obj->num = -1;
        $obj->cols = 0;
        return $obj;
    }

    /**
     * Returns the directory of the template starting from DOCUMENT_ROOT
     *
     * @return string
     */
    public static function get()
    {
        return self::$src;
    }

    /**
     * Returns the URL base for the current template source.
     *
     * @return string
     */
    public static function getUrl()
    {
        return self::$url;
    }

    /**
     * Checks if data has been added to the template
     *
     * @return bool Returns true if the add function has been called before, false otherwise
     */
    public function hasItem()
    {
        return !empty($this->items);
    }

    /**
     * Sets the template to be used
     *
     * @param string $theme Theme slug. A personal theme in the site's own data
     *                      folder (DATA_FOLDER.themes/{slug}/ — DATA_FOLDER is
     *                      already per-user, e.g. datas/users/{username}/) wins
     *                      over a shared theme of the same slug in themes/.
     */
    public static function init($theme)
    {
        if ($theme == '') {
            self::$src = '';
            self::$url = '';
            return;
        }

        $privateSrc = defined('DATA_FOLDER') ? DATA_FOLDER.'themes/'.$theme.'/' : '';
        if ($privateSrc !== '' && is_dir(ROOT_PATH.$privateSrc)) {
            self::$src = $privateSrc;
            self::$url = WEB_URL.$privateSrc;
            return;
        }

        self::$src = 'themes/'.$theme.'/';
        self::$url = WEB_URL.self::$src;
    }

    /**
     * Inserts HTML into the template directly
     * Used to insert HTML between items
     *
     * @param string $html The HTML code
     *
     * @return static
     */
    public function insertHTML($html)
    {
        $this->items[] = $html;
        return $this;
    }

    /**
     * Checks if the Template file is empty
     *
     * @return bool Returns true if the Template file is empty or not found, false otherwise
     */
    public function isEmpty()
    {
        return empty($this->skin);
    }

    /**
     * Loads a template
     * It checks the module's file first, if not found, it uses the owner's file
     * If not found, uses Hybrid approach (exception in dev, graceful error in production)
     *
     * @param string $owner  The name of the installed module
     * @param string $module The name of the registered module
     * @param string $name   The name of the template without the file extension
     *
     * @throws \Kotchasan\Exception\TemplateNotFoundException in development mode
     *
     * @return string
     */
    public static function load($owner, $module, $name)
    {
        $src = APP_PATH.self::$src;
        $paths = [
            $module != '' ? $src.$module.'/'.$name.'.html' : null,
            $owner != '' ? $src.$owner.'/'.$name.'.html' : null,
            $src.$name.'.html'
        ];

        // Try to load template from possible paths
        foreach ($paths as $path) {
            if ($path && is_file($path)) {
                return file_get_contents($path);
            }
        }

        // Template not found
        $attemptedPaths = array_filter($paths);

        throw new \Kotchasan\Exception\TemplateNotFoundException($name, $attemptedPaths);
    }

    /**
     * Executes the preg_replace function
     *
     * @param array  $patt    The keys in the template
     * @param array  $replace The text to replace the keys
     * @param string $skin    The template
     *
     * @return string
     */
    public static function pregReplace($patt, $replace, $skin)
    {
        $skin = (string) $skin;
        if (!is_array($patt)) {
            $patt = [$patt];
        }
        if (!is_array($replace)) {
            $replace = [$replace];
        }
        foreach ($patt as $i => $item) {
            $text = ($replace[$i] === null) ? '' : $replace[$i];
            // '/{LNG_([^}]+)}/e' => 'Class::method' calls the method for every match.
            // The e must be one of the modifiers after the last /: an e inside the
            // pattern ('/{name}/', '/(<head.*)(<\/head>)/isu') is not one, and a value
            // that happened to read like Class::method used to be called.
            if (preg_match('/^(.*\/)([a-zA-Z]*)e([a-zA-Z]*)$/', $item, $parts) && preg_match('/^([\\\\a-z0-9]+)::([a-z0-9_\\\\]+).*/i', $text, $func)) {
                $skin = preg_replace_callback($parts[1].$parts[2].$parts[3], [$func[1], $func[2]], $skin);
            } else {
                $skin = self::replace($item, $text, $skin);
            }
        }
        return $skin;
    }

    /**
     * One pattern of pregReplace().
     *
     * A pattern without groups ('/{DETAIL}/') puts the value in as text. Through
     * preg_replace(), "$1", "\1" or "${1}" in it were read as references to groups
     * the pattern does not have: a post saying "Price $100" showed "Price 0".
     * A pattern with groups keeps the references ('/(<head.*)(<\/head>)/' => '$1...$2').
     *
     * @param string $pattern
     * @param mixed  $text
     * @param string $skin
     *
     * @return string
     */
    private static function replace($pattern, $text, $skin)
    {
        $match = [];
        $found = @preg_match($pattern, $skin, $match, PREG_UNMATCHED_AS_NULL);
        if ($found === 0) {
            return $skin;
        }
        if ($found === 1 && count($match) === 1) {
            $text = (string) $text;
            $result = preg_replace_callback($pattern, function () use ($text) {
                return $text;
            }, $skin);

            return $result === null ? $skin : $result;
        }

        return preg_replace($pattern, $text, $skin);
    }

    /**
     * Returns the rendered HTML
     *
     * @return string
     */
    public function render()
    {
        if ($this->cols === 0) {
            // Template
            return empty($this->items) ? $this->skin : implode("\n", $this->items);
        } elseif (!empty($this->items)) {
            // Grid
            return "<div class=row>\n".implode("\n", $this->items)."\n</div>";
        }
        return '';
    }
}
