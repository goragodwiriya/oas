<?php
namespace Kotchasan\Http;

use Kotchasan\Psr\Http\Message\UriInterface;

/**
 * HTTP Uri Class
 * Implements PSR-7 UriInterface
 *
 * @package Kotchasan\Http
 *
 * @method string postBack(string $path = '', array $params = [], bool $encode = false) Build a URL by merging current query with provided params
 */
class Uri implements UriInterface
{
    /**
     * @var string Uri scheme (without ":" suffix)
     */
    protected $scheme = '';

    /**
     * @var string Uri user
     */
    protected $user = '';

    /**
     * @var string Uri password
     */
    protected $password = '';

    /**
     * @var string Uri host
     */
    protected $host = '';

    /**
     * @var int|null Uri port
     */
    protected $port;

    /**
     * @var string Uri path
     */
    protected $path = '';

    /**
     * @var string Uri query string (without "?" prefix)
     */
    protected $query = '';

    /**
     * @var string Uri fragment (without "#" prefix)
     */
    protected $fragment = '';

    /**
     * Constructor
     *
     * @param string $scheme
     * @param string $host
     * @param string $path
     * @param string $query
     * @param int|null $port
     * @param string $user
     * @param string $password
     * @param string $fragment
     */
    public function __construct($scheme = '', $host = '', $path = '', $query = '', $port = null, $user = '', $password = '', $fragment = '')
    {
        $this->scheme = $this->filterScheme($scheme);
        $this->host = $host;
        $this->path = $this->filterPath($path);
        $this->query = $this->filterQuery($query);
        $this->port = $this->filterPort($port);
        $this->user = $user;
        $this->password = $password;
        $this->fragment = $this->filterFragment($fragment);
    }

    /**
     * Build a URL for post-back by merging current query parameters with provided params.
     * If a param value is null it will be removed from the resulting query.
     *
     * @param string $path Target path (e.g. 'index.php')
     * @param array $params Parameters to merge
     * @param bool $encode If true, use '&amp;' as separator for HTML contexts
     * @return string The generated URL
     */
    public function postBack(string $path = '', array $params = [], bool $encode = false): string
    {
        // parse existing query string
        $current = [];
        if ($this->query !== '') {
            parse_str($this->query, $current);
        }

        // merge params; null means remove
        foreach ($params as $k => $v) {
            if ($v === null) {
                unset($current[$k]);
            } else {
                $current[$k] = $v;
            }
        }

        // build query string
        if (!empty($current)) {
            // http_build_query supports encoding separator, but for PHP portability we'll replace when needed
            $query = http_build_query($current, '', '&');
            if ($encode) {
                $query = str_replace('&', '&amp;', $query);
            }
            return $path.'?'.$query;
        }

        return $path;
    }

    /**
     * Create a new Uri from server variables.
     *
     * @return static
     */
    public static function createFromGlobals()
    {
        // Determine the URI scheme
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } elseif ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        // Determine the URI host
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
        } elseif (empty($_SERVER['HTTP_HOST'])) {
            if (!empty($_SERVER['SERVER_NAME'])) {
                $host = $_SERVER['SERVER_NAME'];
            } elseif (defined('HOST') && HOST !== '') {
                $host = HOST;
            } else {
                $host = 'localhost';
            }
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
            $port = $scheme === 'https' ? 443 : 80;
        }

        // Determine the URI path
        $path = empty($_SERVER['REQUEST_URI']) ? '/' : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Determine the URI query string
        $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

        // Determine the URI user authentication
        $user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
        $password = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

        // Create and return a new Uri object
        $uri = new static();
        $uri->scheme = $scheme;
        $uri->host = $host;
        $uri->port = ($port !== 80 && $port !== 443) ? $port : null;
        $uri->path = $path ?: '/';
        $uri->query = $query;
        $uri->user = $user;
        $uri->password = $password;

        return $uri;
    }

    /**
     * Creates a new Uri object based on the provided URI string.
     *
     * @param string $uri The URI string to create the Uri object from.
     *
     * @return static A new Uri object based on the provided URI.
     * @throws \InvalidArgumentException If the provided URI is invalid.
     */
    public static function createFromUri($uri)
    {
        $parts = parse_url($uri);
        if (false === $parts) {
            throw new \InvalidArgumentException('Invalid Uri');
        }

        $instance = new static();
        $instance->scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
        $instance->host = isset($parts['host']) ? $parts['host'] : '';
        $instance->port = isset($parts['port']) ? $parts['port'] : null;
        $instance->user = isset($parts['user']) ? $parts['user'] : '';
        $instance->password = isset($parts['pass']) ? $parts['pass'] : '';
        $instance->path = isset($parts['path']) ? $parts['path'] : '/';
        $instance->query = isset($parts['query']) ? $parts['query'] : '';
        $instance->fragment = isset($parts['fragment']) ? $parts['fragment'] : '';

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthority()
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        if ($this->getUserInfo() !== '') {
            $authority = $this->getUserInfo().'@'.$authority;
        }

        if ($this->port !== null) {
            $defaultPort = $this->getDefaultPort();
            if ($defaultPort === null || $this->port !== $defaultPort) {
                $authority .= ':'.$this->port;
            }
        }

        return $authority;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo()
    {
        if ($this->user === '') {
            return '';
        }

        $userInfo = $this->user;
        if ($this->password !== '') {
            $userInfo .= ':'.$this->password;
        }

        return $userInfo;
    }

    /**
     * {@inheritDoc}
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * {@inheritDoc}
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * {@inheritDoc}
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * {@inheritDoc}
     */
    public function withScheme($scheme)
    {
        $scheme = $this->filterScheme($scheme);
        $clone = clone $this;
        $clone->scheme = $scheme;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withUserInfo($user, $password = null)
    {
        $clone = clone $this;
        $clone->user = $user;
        $clone->password = $password !== null ? $password : '';
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withHost($host)
    {
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withPort($port)
    {
        $port = $this->filterPort($port);
        $clone = clone $this;
        $clone->port = $port;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withPath($path)
    {
        $path = $this->filterPath($path);
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withQuery($query)
    {
        $query = $this->filterQuery($query);
        $clone = clone $this;
        $clone->query = $query;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withFragment($fragment)
    {
        $fragment = $this->filterFragment($fragment);
        $clone = clone $this;
        $clone->fragment = $fragment;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme.':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//'.$authority;
        }

        $path = $this->path;
        if ($path !== '') {
            if ($path[0] !== '/') {
                if ($authority !== '') {
                    $path = '/'.$path;
                }
            } elseif (isset($path[1]) && $path[1] === '/') {
                if ($authority === '') {
                    $path = '/'.ltrim($path, '/');
                }
            }
            $uri .= $path;
        }

        if ($this->query !== '') {
            $uri .= '?'.$this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#'.$this->fragment;
        }

        return $uri;
    }

    /**
     * Get the default port for a scheme.
     *
     * @return int|null
     */
    protected function getDefaultPort()
    {
        switch ($this->scheme) {
        case 'http':
            return 80;
        case 'https':
            return 443;
        default:
            return null;
        }
    }

    /**
     * Filter and normalize the scheme.
     *
     * @param string $scheme
     * @return string
     */
    protected function filterScheme($scheme)
    {
        $scheme = strtolower($scheme);
        $scheme = rtrim($scheme, ':/');
        return $scheme;
    }

    /**
     * Filter and validate the port.
     *
     * @param int|null $port
     * @return int|null
     * @throws \InvalidArgumentException
     */
    protected function filterPort($port)
    {
        if ($port === null) {
            return null;
        }

        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port: '.$port);
        }

        return $port;
    }

    /**
     * Filter and normalize the path.
     *
     * @param string $path
     * @return string
     */
    protected function filterPath($path)
    {
        if ($path === '') {
            return '';
        }

        $path = preg_replace('#/+#', '/', $path);
        return $path;
    }

    /**
     * Filter and normalize the query string.
     *
     * @param string $query
     * @return string
     */
    protected function filterQuery($query)
    {
        if ($query === '' || $query === null) {
            return '';
        }

        if ($query[0] === '?') {
            $query = substr($query, 1);
        }

        return $query;
    }

    /**
     * Filter and normalize the fragment.
     *
     * @param string $fragment
     * @return string
     */
    protected function filterFragment($fragment)
    {
        if ($fragment === '' || $fragment === null) {
            return '';
        }

        if ($fragment[0] === '#') {
            $fragment = substr($fragment, 1);
        }

        return $fragment;
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
            if ($item !== '') {
                $queries[$item] = $item;
            }
        }
        foreach ($query as $k => $v) {
            unset($queries[$k.'='.$v]);
        }
        $clone->query = implode('&', $queries);
        return $clone;
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
            parse_str($query, $result);
        }
        return $result;
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
            if ($value !== null) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $query_str[] = urlencode($key).'[]='.urlencode($v);
                    }
                } else {
                    $query_str[] = urlencode($key).'='.urlencode($value);
                }
            }
        }
        return implode($encode ? '&amp;' : '&', $query_str);
    }

    /**
     * Create a URL for redirecting query strings from one page to another
     * to allow creating URLs that can be sent back to the original page with the `back()` function
     * Remove null items
     *
     * @param array $query_string
     *
     * @return static
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
     * Generate pagination HTML
     *
     * @param int $totalPages Total number of pages
     * @param int $currentPage Current page number
     * @param int $maxLinks Maximum number of page links to show
     *
     * @return string Pagination HTML
     */
    public function pagination($totalPages, $currentPage = 1, $maxLinks = 9)
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = [];
        $html[] = '<nav class="pagination">';

        // Previous button
        if ($currentPage > 1) {
            $prevUri = $this->withParams(['page' => $currentPage - 1]);
            $html[] = '<a href="'.(string) $prevUri.'" class="prev">&laquo;</a>';
        }

        // Calculate start and end page numbers
        $start = max(1, $currentPage - floor($maxLinks / 2));
        $end = min($totalPages, $start + $maxLinks - 1);

        // Adjust start if we're near the end
        if ($end - $start + 1 < $maxLinks) {
            $start = max(1, $end - $maxLinks + 1);
        }

        // First page link
        if ($start > 1) {
            $firstUri = $this->withParams(['page' => 1]);
            $html[] = '<a href="'.(string) $firstUri.'">1</a>';
            if ($start > 2) {
                $html[] = '<span class="dots">...</span>';
            }
        }

        // Page number links
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                $html[] = '<span class="current">'.$i.'</span>';
            } else {
                $pageUri = $this->withParams(['page' => $i]);
                $html[] = '<a href="'.(string) $pageUri.'">'.$i.'</a>';
            }
        }

        // Last page link
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html[] = '<span class="dots">...</span>';
            }
            $lastUri = $this->withParams(['page' => $totalPages]);
            $html[] = '<a href="'.(string) $lastUri.'">'.$totalPages.'</a>';
        }

        // Next button
        if ($currentPage < $totalPages) {
            $nextUri = $this->withParams(['page' => $currentPage + 1]);
            $html[] = '<a href="'.(string) $nextUri.'" class="next">&raquo;</a>';
        }

        $html[] = '</nav>';

        return implode('', $html);
    }
}
