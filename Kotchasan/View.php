<?php
/**
 * @filesource Kotchasan/View.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides basic functionalities for views.
 * It is responsible for rendering HTML templates, managing CSS and JavaScript files, and handling headers.
 *
 * @see https://www.kotchasan.com/
 */
class View extends \Kotchasan\KBase
{
    /**
     * Array to store the contents of the website to be replaced after rendering.
     *
     * @var array
     */
    protected $afterContents = [];

    /**
     * Array to store the contents of the website.
     *
     * @var array
     */
    protected $contents = [];

    /**
     * Array to store the headers.
     *
     * @var array
     */
    protected $headers = [];

    /**
     * Array to store meta tags.
     *
     * @var array
     */
    protected $metas = [];

    /**
     * Array to store JavaScript code to be inserted in the head section.
     *
     * @var array
     */
    protected $scripts = [];

    /**
     * Creates an instance of the View class.
     *
     * @return static
     */
    public static function create()
    {
        return new static;
    }

    /**
     * Adds a CSS file to the header.
     *
     * @param string $url The URL of the CSS file.
     */
    public function addCSS($url)
    {
        $this->metas[$url] = '<link rel="stylesheet" href="'.$url.'">';
    }

    /**
     * Adds a JavaScript file to the header.
     *
     * @param string $url The URL of the JavaScript file.
     */
    public function addJavascript($url)
    {
        $this->metas[$url] = '<script src="'.$url.'"></script>';
    }

    /**
     * Adds JavaScript code to be inserted in the head section before closing the head tag.
     *
     * @param string $script The JavaScript code.
     */
    public function addScript($script)
    {
        $this->scripts[] = $script;
    }

    /**
     * Replaces the query string with data from the GET request for forwarding to the next URL.
     * The function takes either an array (returned from preg_replace) or a string.
     * Returns the new query string with the 'id=0' removed.
     *
     * @assert (array(2 => 'module=retmodule&id=0')) [==] "http://localhost/?module=retmodule&amp;page=1&amp;sort=id"  [[$_SERVER['QUERY_STRING'] = '_module=test&1234&_page=1&_sort=id&action=login&id=1']]
     * @assert ('module=retmodule&5678') [==] "http://localhost/?module=retmodule&amp;page=1&amp;sort=id&amp;id=1&amp;5678"
     *
     * @param array|string $f The value from the variable $f used to create the query string.
     *
     * @return string The new query string.
     */
    public static function back($f)
    {
        $uri = self::$request->getUri();
        $queryUrl = [];
        foreach (explode('&', $uri->getQuery()) as $item) {
            if (preg_match('/^(_)?(.*)=([^$]{1,})$/', $item, $match)) {
                if ($match[2] == 'action' && ($match[3] == 'login' || $match[3] == 'logout')) {
                    // Exclude action=login and action=logout from the query_url
                } else {
                    $queryUrl[$match[2]] = $match[3];
                }
            }
        }
        if (is_array($f)) {
            $f = isset($f[2]) ? $f[2] : null;
        }
        if (!empty($f)) {
            foreach (explode('&', $f) as $item) {
                if (preg_match('/^([a-zA-Z0-9_\-]+)(=(.*))?$/', $item, $match)) {
                    if (!isset($match[3])) {
                        // No value
                        $queryUrl[$match[1]] = null;
                    } elseif ($match[3] === '0') {
                        // Exclude items with no value after the equal sign
                        unset($queryUrl[$match[1]]);
                    } else {
                        $queryUrl[$match[1]] = $match[3];
                    }
                }
            }
        }
        return (string) $uri->withQuery($uri->paramsToQuery($queryUrl, true));
    }

    /**
     * Outputs the content and headers as specified.
     *
     * @param string $content The content to be output.
     */
    public function output($content)
    {
        // Send headers
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }
        // Output content
        echo $content;
    }

    /**
     * Renders the content as HTML.
     *
     * @param string|null $template The HTML template to be used. If not specified (null), 'index.html' will be used.
     *
     * @return string The rendered HTML content.
     */
    public function renderHTML($template = null)
    {
        // Default template values
        $this->contents['/{WEBTITLE}/'] = self::$cfg->web_title;
        $this->contents['/{WEBDESCRIPTION}/'] = self::$cfg->web_description;
        $this->contents['/{WEBURL}/'] = WEB_URL;
        $this->contents['/{SKIN}/'] = Template::get();
        foreach ($this->afterContents as $key => $value) {
            $this->contents[$key] = $value;
        }
        $head = '';
        if (!empty($this->metas)) {
            $head .= implode("\n", $this->metas);
        }
        if (!empty($this->scripts)) {
            $head .= "\n<script>\n".implode("\n", $this->scripts)."\n</script>";
        }
        if ($head != '') {
            $this->contents['/(<head.*)(<\/head>)/isu'] = '$1'.$head.'$2';
        }
        // Replace in the template
        if ($template === null) {
            // If no template is specified, use 'index.html'
            $template = Template::load('', '', 'index');
        }
        return Template::pregReplace(array_keys($this->contents), array_values($this->contents), $template);
    }

    /**
     * Sets the content in $contents.
     *
     * @param array $array An array of names to be used in the template (e.g., array(key1 => val1, key2 => val2)).
     */
    public function setContents($array)
    {
        foreach ($array as $key => $value) {
            $this->contents[$key] = $value;
        }
    }

    /**
     * Sets the content in $contents after rendering.
     *
     * @param array $array An array of names to be used in the template (e.g., array(key1 => val1, key2 => val2)).
     */
    public function setContentsAfter($array)
    {
        foreach ($array as $key => $value) {
            $this->afterContents[$key] = $value;
        }
    }

    /**
     * Sets headers for the document.
     *
     * @param array $array An array of headers (e.g., array(key1 => val1, key2 => val2)).
     */
    public function setHeaders($array)
    {
        foreach ($array as $key => $value) {
            $this->headers[$key] = $value;
        }
    }

    /**
     * Adds meta tags to the HTML head.
     *
     * @param array $array An array of meta tags (e.g., array(key1 => val1, key2 => val2)).
     */
    public function setMetas($array)
    {
        foreach ($array as $key => $value) {
            $this->metas[$key] = $value;
        }
    }

    /**
     * Get the value from an array based on a given key.
     * If the key doesn't exist, return the default value.
     *
     * @param array  $array    The array to search in.
     * @param string $key      The key to retrieve the value from.
     * @param mixed  $default  The default value to return if the key doesn't exist.
     *
     * @return mixed  The value from the array or the default value.
     */
    public static function array_value($array, $key, $default = '')
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    /**
     * Get the value from an object based on a given key.
     * If the key doesn't exist, return the default value.
     *
     * @param object $source   The object to retrieve the value from.
     * @param string $key      The key to retrieve the value from.
     * @param mixed  $default  The default value to return if the key doesn't exist.
     *
     * @return mixed  The value from the object or the default value.
     */
    public static function object_value($source, $key, $default = '')
    {
        return isset($source->{$key}) ? $source->{$key} : $default;
    }
}
