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
            throw new \InvalidArgumentException('File not found '.$filename);
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
     * @param string $theme The directory of the template starting from DOCUMENT_ROOT without a trailing slash, e.g., skin/default
     */
    public static function init($theme)
    {
        self::$src = ($theme == '') ? '' : 'themes/'.$theme.'/';
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
        if (!is_array($patt)) {
            $patt = [$patt];
        }
        if (!is_array($replace)) {
            $replace = [$replace];
        }
        foreach ($patt as $i => $item) {
            $text = ($replace[$i] === null) ? '' : $replace[$i];
            if (preg_match('/(.*\/(.*?))[e](.*?)$/', $item, $patt) && preg_match('/^([\\\\a-z0-9]+)::([a-z0-9_\\\\]+).*/i', $text, $func)) {
                $skin = preg_replace_callback($patt[1].$patt[3], [$func[1], $func[2]], $skin);
            } else {
                $skin = preg_replace($item, $text, $skin);
            }
        }
        return $skin;
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
