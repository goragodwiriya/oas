<?php
/**
 * @filesource Kotchasan/Http/Uri.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

/**
 * Class for managing Uri (PSR-7)
 *
 * @see https://www.kotchasan.com/
 */
class Uri extends \Kotchasan\KBase implements \Psr\Http\Message\UriInterface
{
    /**
     * Uri fragment after #
     *
     * @var string
     */
    protected $fragment = '';
    /**
     * Uri host
     *
     * @var string
     */
    protected $host = '';
    /**
     * Uri path
     *
     * @var string
     */
    protected $path = '';
    /**
     * Uri port
     *
     * @var int
     */
    protected $port;
    /**
     * Uri query string after ?
     *
     * @var string
     */
    protected $query = '';
    /**
     * Uri scheme
     *
     * @var string
     */
    protected $scheme = '';
    /**
     * Uri user info
     *
     * @var string
     */
    protected $userInfo = '';

    /**
     * Constructs a new Uri object.
     *
     * @param string $scheme The URI scheme (e.g., "http", "https").
     * @param string $host The URI host.
     * @param string $path The URI path (default is "/").
     * @param string $query The URI query string (default is an empty string).
     * @param int|null $port The URI port (optional, default is null).
     * @param string $user The username for URI authentication (optional, default is an empty string).
     * @param string $pass The password for URI authentication (optional, default is an empty string).
     * @param string $fragment The URI fragment (part after the # character, optional, default is an empty string).
     */
    public function __construct($scheme, $host, $path = '/', $query = '', $port = null, $user = '', $pass = '', $fragment = '')
    {
        $this->scheme = $this->filterScheme($scheme);
        $this->host = $host;
        $this->path = $path;
        $this->query = $this->filterQueryFragment($query);
        $this->port = $this->filterPort($this->scheme, $this->host, $port) ? $port : null;
        $this->userInfo = $user.($pass === '' ? '' : ':'.$pass);
        $this->fragment = $this->filterQueryFragment($fragment);
    }

    /**
     * Magic function to output the class as a String
     *
     * @return string
     */
    public function __toString()
    {
        return self::createUriString(
            $this->scheme,
            $this->getAuthority(),
            $this->path,
            $this->query,
            $this->fragment
        );
    }

    /**
     * Create a URL for redirecting query strings from one page to another
     * to allow creating URLs that can be sent back to the original page with the `back()` function
     * Remove null items
     *
     * @param array $query_string
     *
     * @return string
     */
    public function createBackUri($query_string)
    {
        $query_str = [];
        foreach ($this->parseQueryParams($this->query) as $key => $value) {
            $key = ltrim($key, '_');
            if (key_exists($key, $query_string) && $query_string[$key] === null) {
                continue;
            } elseif (preg_match('/((^[0-9]+$)|(.*?(username|password|token|time).*?))/', $key)) {
                continue;
            }
            if ($value !== null) {
                $query_str['_'.$key] = $value;
            }
        }
        foreach ($query_string as $key => $value) {
            if ($value !== null) {
                $query_str[$key] = $value;
            }
        }
        return $this->withQuery($this->paramsToQuery($query_str, true));
    }

    /**
     * Creates a new Uri object based on the current server environment.
     *
     * @return Uri A new Uri object based on the server environment.
     * @throws \InvalidArgumentException If the provided URI is invalid.
     */
    public static function createFromGlobals()
    {
        // Determine the URI scheme
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'].'://';
        } elseif ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
            $scheme = 'https://';
        } else {
            $scheme = 'http://';
        }

        // Determine the URI host
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
        } elseif (empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['SERVER_NAME'];
        } else {
            $host = $_SERVER['HTTP_HOST'];
        }

        // Determine the URI port
        $pos = strpos($host, ':');
        if ($pos !== false) {
            $port = (int) substr($host, $pos + 1);
            $host = strstr($host, ':', true);
        } elseif (isset($_SERVER['SERVER_PORT'])) {
            $port = (int) $_SERVER['SERVER_PORT'];
        } else {
            $port = $scheme === 'https://' ? 443 : 80;
        }

        // Determine the URI path
        $path = empty($_SERVER['REQUEST_URI']) ? '/' : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Determine the URI query string
        $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

        // Determine the URI user authentication
        $user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
        $pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

        // Create and return a new Uri object
        return new static($scheme, $host, $path, $query, $port, $user, $pass);
    }

    /**
     * Creates a new Uri object based on the provided URI string.
     *
     * @param string $uri The URI string to create the Uri object from.
     *
     * @return Uri A new Uri object based on the provided URI.
     * @throws \InvalidArgumentException If the provided URI is invalid.
     */
    public static function createFromUri($uri)
    {
        $parts = parse_url($uri);
        if (false === $parts) {
            throw new \InvalidArgumentException('Invalid Uri');
        } else {
            $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
            $host = isset($parts['host']) ? $parts['host'] : '';
            $port = isset($parts['port']) ? $parts['port'] : null;
            $user = isset($parts['user']) ? $parts['user'] : '';
            $pass = isset($parts['pass']) ? $parts['pass'] : '';
            $path = isset($parts['path']) ? $parts['path'] : '';
            $query = isset($parts['query']) ? $parts['query'] : '';
            $fragment = isset($parts['fragment']) ? $parts['fragment'] : '';

            // Create and return a new Uri object
            return new static($scheme, $host, $path, $query, $port, $user, $pass, $fragment);
        }
    }

    /**
     * Retrieves the authority component of the URI.
     *
     * The authority component consists of the user information, host, and optional port.
     * If the user information is present, it is followed by "@" symbol.
     * If the port is present, it is preceded by ":" symbol.
     *
     * @return string The authority component of the URI.
     */
    public function getAuthority()
    {
        return ($this->userInfo ? $this->userInfo.'@' : '').$this->host.($this->port !== null ? ':'.$this->port : '');
    }

    /**
     * Convert GET parameters to a query string for returning to the original page after form submission.
     *
     * Generate a URL for returning to the original page using the specified base URL and query string.
     * The query string can be provided as an array in the form of key-value pairs.
     * The method automatically includes the query string from the current request by default.
     *
     * @param string $url The URL to return to, e.g., index.php.
     * @param array $query_string (optional) The query string to include as an array of key-value pairs. Default is an empty array.
     *
     * @return string The generated URL with the query string.
     */
    public function getBack($url, $query_string = [])
    {
        return $this->createBack($url, $_GET, $query_string);
    }

    /**
     * Get the fragment (data after #) of the Uri.
     *
     * @return string The fragment of the Uri.
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * Get the hostname of the Uri, e.g., domain.tld.
     *
     * @return string The hostname of the Uri.
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Get the path of the Uri, e.g., /kotchasan.
     *
     * @return string The path of the Uri.
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Get the port number of the Uri.
     * If not specified or default port (80, 443), return null.
     *
     * @return null|int The port number of the Uri.
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Get the query string (data after ?) of the Uri.
     *
     * @return string The query string of the Uri.
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Get the scheme of the Uri without ://, e.g., http, https.
     *
     * @return string The scheme of the Uri.
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * Get the user information of the Uri user[:password].
     *
     * @return string The user information of the Uri.
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * Generate a pagination HTML string.
     *
     * @param int $totalpage The total number of pages.
     * @param int $page      The current page.
     * @param int $maxlink   The maximum number of links to display.
     *
     * @return string The pagination HTML string.
     */
    public function pagination($totalpage, $page, $maxlink = 9)
    {
        if ($totalpage > $maxlink) {
            $start = $page - floor($maxlink / 2);
            if ($start < 1) {
                $start = 1;
            } elseif ($start + $maxlink > $totalpage) {
                $start = $totalpage - $maxlink + 1;
            }
        } else {
            $start = 1;
        }
        $url = '<a href="'.$this->withParams(array('page' => ':page'), true).'" title="{LNG_go to page} :page">:page</a>';
        $splitpage = ($start > 2) ? str_replace(':page', 1, $url) : '';
        for ($i = $start; $i <= $totalpage && $maxlink > 0; ++$i) {
            $splitpage .= ($i == $page) ? '<strong title="{LNG_Showing page} '.$i.'">'.$i.'</strong>' : str_replace(':page', $i, $url);
            --$maxlink;
        }
        $splitpage .= ($i < $totalpage) ? str_replace(':page', $totalpage, $url) : '';
        return empty($splitpage) ? '<strong>1</strong>' : $splitpage;
    }

    /**
     * Convert an array of query parameters to a query string.
     *
     * @param array $params An array of query parameters.
     * @param bool  $encode Whether to encode the query string using '&amp;' (true) or '&' (false).
     *
     * @return string The generated query string.
     */
    public function paramsToQuery($params, $encode)
    {
        $query_str = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^[a-zA-Z0-9_\-\[\]]+$/', $key)) {
                if ($value === null) {
                    $query_str[$key] = $key;
                } else {
                    $query_str[$key] = $key.'='.$this->filterQueryFragment($value);
                }
            }
        }
        return implode($encode ? '&amp;' : '&', $query_str);
    }

    /**
     * Parse the query string and return an array of query parameters.
     *
     * @param string|null $query The query string to parse (optional). If not provided, the object's query string will be used.
     *
     * @return array An array of query parameters.
     */
    public function parseQueryParams($query = null)
    {
        $query = $query === null ? $this->query : $query;
        $result = [];
        if (!empty($query)) {
            foreach (explode('&', str_replace('&amp;', '&', $query)) as $item) {
                if (preg_match('/^([a-zA-Z0-9_\-\[\]]+)(=(.*))?$/', $item, $match)) {
                    if (isset($match[3])) {
                        if (!(preg_match('/^[0-9]+$/', $match[1]) && $match[3] === '')) {
                            $result[$match[1]] = $match[3];
                        }
                    } else {
                        $result[$match[1]] = null;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Generate a URL with query string for posting back to the original page, typically used with form submissions.
     *
     * @param string $url          The URL to which the form should be submitted.
     * @param array  $query_string Additional query string parameters to be included in the URL.
     * @param bool   $encode       Determines whether the query string should be encoded using &amp; or & (default is false).
     *
     * @return string The generated URL with query string.
     */
    public function postBack($url, $query_string = [], $encode = false)
    {
        return $this->createBack($url, $_POST, $query_string, $encode);
    }

    /**
     * Set the fragment of the URL.
     *
     * @param string $fragment The fragment to be set.
     *
     * @throws \InvalidArgumentException If the fragment is not a string.
     *
     * @return static A new instance of the class with the updated fragment.
     */
    public function withFragment($fragment)
    {
        if (!is_string($fragment) && !method_exists($fragment, '__toString')) {
            throw new \InvalidArgumentException('Uri fragment must be a string');
        }
        $fragment = ltrim((string) $fragment, '#');
        $clone = clone $this;
        $clone->fragment = $this->filterQueryFragment($fragment);
        return $clone;
    }

    /**
     * Set the host of the URL.
     *
     * @param string $host The host to be set.
     *
     * @return static A new instance of the class with the updated host.
     */
    public function withHost($host)
    {
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    /**
     * Set or update query parameters in the URL.
     *
     * @param array $params An array of query parameters to be set or updated.
     * @param bool  $encode False (default) to connect query string with "&", true to connect with "&amp;".
     *
     * @return static A new instance of the class with the updated query parameters.
     */
    public function withParams($params, $encode = false)
    {
        $query_str = [];
        foreach ($this->parseQueryParams($this->query) as $key => $value) {
            $query_str[$key] = $value;
        }
        foreach ($params as $key => $value) {
            $query_str[$key] = $value;
        }
        return $this->withQuery($this->paramsToQuery($query_str, $encode));
    }

    /**
     * Remove specified query parameters from the URL.
     *
     * @param string|array $names  The names of attributes to be removed.
     * @param bool         $encode False (default) to connect query string with "&", true to connect with "&amp;".
     *
     * @return static A new instance of the class without the specified query parameters.
     */
    public function withoutParams($names, $encode = false)
    {
        $attributes = $this->parseQueryParams($this->query);
        if (is_array($names)) {
            foreach ($names as $name) {
                unset($attributes[$name]);
            }
        } else {
            unset($attributes[$names]);
        }
        return $this->withQuery($this->paramsToQuery($attributes, $encode));
    }

    /**
     * Set the path of the URI.
     * The path must start with '/' (e.g., '/kotchasan'), or it can be empty if it is the root of the domain.
     *
     * @param string $path The path name.
     *
     * @return static A new instance of the class with the updated path.
     */
    public function withPath($path)
    {
        $clone = clone $this;
        $clone->path = $this->filterPath($path);
        return $clone;
    }

    /**
     * Set the port number of the URI.
     *
     * @param null|int $port The port number (1-65535) or null.
     *
     * @throws \InvalidArgumentException If the port number is invalid.
     *
     * @return static A new instance of the class with the updated port number.
     */
    public function withPort($port)
    {
        $clone = clone $this;
        $clone->port = $this->filterPort($this->scheme, $this->host, $port);
        return $clone;
    }

    /**
     * Set the query string of the URI.
     *
     * @param string $query The query string.
     *
     * @return static A new instance of the class with the updated query string.
     * @throws \InvalidArgumentException If the query string is invalid.
     */
    public function withQuery($query)
    {
        if (!is_string($query) && !method_exists($query, '__toString')) {
            throw new \InvalidArgumentException('Uri query must be a string');
        }
        $query = ltrim((string) $query, '?');
        $clone = clone $this;
        $clone->query = $this->filterQueryFragment($query);
        return $clone;
    }

    /**
     * Remove the specified query parameters from the URL.
     *
     * @param array $query An array of query parameters to be removed (e.g., ['q1' => 'value1', 'q2' => 'value2']).
     *
     * @return static A new instance of the class without the specified query parameters.
     */
    public function withoutQuery($query)
    {
        $clone = clone $this;
        $queries = [];
        foreach (explode('&', $clone->query) as $item) {
            $queries[$item] = $item;
        }
        foreach ($query as $k => $v) {
            unset($queries[$k.'='.$v]);
        }
        $clone->query = implode('&', $queries);
        return $clone;
    }

    /**
     * Set the scheme (http or https) of the URI.
     *
     * @param string $scheme The scheme (http, https, or empty string).
     *
     * @return static A new instance of the class with the updated scheme.
     * @throws \InvalidArgumentException If the scheme is not empty, http, or https.
     */
    public function withScheme($scheme)
    {
        $clone = clone $this;
        $clone->scheme = $this->filterScheme($scheme);
        return $clone;
    }

    /**
     * Return a new instance of the class with the specified user information (username and password).
     *
     * @param string $user     The username.
     * @param string $password (optional) The password.
     *
     * @return self A new instance of the class with the updated user information.
     */
    public function withUserInfo($user, $password = null)
    {
        $clone = clone $this;
        $clone->userInfo = $user.($password ? ':'.$password : '');
        return $clone;
    }

    /**
     * Convert POST data to a query string for redirecting back to the previous page after form submission.
     * Returns the URL with the query string.
     *
     * @param string $url The URL to redirect back to, e.g., index.php.
     * @param array $source The query string from $_POST or $_GET.
     * @param array $query_string Additional query string parameters in the format array('key' => 'value', ...).
     * @param bool $encode False to concatenate query string with '&', true to use '&amp;'.
     *
     * @return string The URL with the query string.
     */
    private function createBack($url, $source, $query_string, $encode = false)
    {
        // Process source array and update query string array
        foreach ($source as $key => $value) {
            if ($value !== '' && !preg_match('/.*?(username|password|token|time).*?/', $key) && preg_match('/^_{1,}(.*)$/', $key, $match)) {
                if (!isset($query_string[$match[1]])) {
                    $query_string[$match[1]] = $value;
                }
            }
        }

        // Update 'time' query string parameter with current time
        if (isset($query_string['time'])) {
            $query_string['time'] = time();
        }

        $query_str = [];
        foreach ($query_string as $key => $value) {
            if ($value !== null) {
                $query_str[$key] = $value;
            }
        }

        return $url.(strpos($url, '?') === false ? '?' : '&').$this->paramsToQuery($query_str, $encode);
    }

    /**
     * Create a URI string.
     * Example: http://domain.tld/
     *
     * @param string $scheme
     * @param string $authority
     * @param string $path
     * @param string $query
     * @param string $fragment
     *
     * @return string The URI string.
     */
    private static function createUriString($scheme, $authority, $path, $query, $fragment)
    {
        $uri = '';
        if (!empty($scheme)) {
            $uri .= $scheme.'://';
        }
        if (!empty($authority)) {
            $uri .= $authority;
        }
        if ($path != null) {
            if ($uri && substr($path, 0, 1) !== '/') {
                $uri .= '/';
            }
            $uri .= $path;
        }
        if ($query != '') {
            $uri .= '?'.$query;
        }
        if ($fragment != '') {
            $uri .= '#'.$fragment;
        }
        return $uri;
    }

    /**
     * Validate the path.
     *
     * @param string $path
     *
     * @return string The filtered path.
     */
    private function filterPath($path)
    {
        return preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~:@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/', function ($match) {
            return rawurlencode($match[0]);
        }, $path);
    }

    /**
     * Filter and validate the port number for a URI.
     *
     * @param string      $scheme The URI scheme (e.g., http, https).
     * @param string      $host   The URI host.
     * @param int|null    $port   The port number to be filtered and validated.
     *
     * @return int|null The filtered port number, or null if the default port for the given scheme and host is used.
     * @throws \InvalidArgumentException If the port number is invalid (not within the range of 1 to 65535).
     */
    private function filterPort($scheme, $host, $port)
    {
        if (null !== $port) {
            $port = (int) $port;
            if (1 > $port || 0xffff < $port) {
                throw new \InvalidArgumentException('Port number must be between 1 and 65535');
            }
        }
        return $this->isNonStandardPort($scheme, $host, $port) ? $port : null;
    }

    /**
     * Check and filter the query and fragment components of a URL.
     *
     * @param string $str The string to filter.
     *
     * @return string The filtered string.
     */
    private function filterQueryFragment($str)
    {
        return preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/', function ($match) {
            return rawurlencode($match[0]);
        }, $str);
    }

    /**
     * Check the URL scheme.
     *
     * @param string $scheme The scheme to check.
     *
     * @return string The filtered scheme.
     * @throws \InvalidArgumentException If the scheme is not empty, "http", or "https".
     */
    private function filterScheme($scheme)
    {
        $schemes = array('' => '', 'http' => 'http', 'https' => 'https');
        $scheme = rtrim(strtolower($scheme), ':/');
        if (isset($schemes[$scheme])) {
            return $scheme;
        } else {
            throw new \InvalidArgumentException('Uri scheme must be http, https, or an empty string');
        }
    }

    /**
     * Check if the port is a non-standard port for the given scheme and host.
     *
     * This method determines if the specified scheme and host combination has a non-standard port.
     * It returns true if either the scheme is not 'http' or 'https', or the port is not 80 or 443.
     * Otherwise, it returns false.
     *
     * @param string $scheme The scheme (e.g., http, https).
     * @param string $host The host.
     * @param int|null $port The port number.
     *
     * @return bool True if the port is non-standard, false otherwise.
     */
    private function isNonStandardPort($scheme, $host, $port)
    {
        if (!$scheme && $port) {
            return true;
        }
        if (!$host || !$port) {
            return false;
        }
        return ($scheme != 'http' && $scheme != 'https') || ($port != 80 && $port != 443);
    }
}
