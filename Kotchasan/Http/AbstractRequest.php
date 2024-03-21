<?php
/**
 * @filesource Kotchasan/Http/AbstractRequest.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

/**
 * Class for managing URLs
 *
 * @see https://www.kotchasan.com/
 */
class AbstractRequest extends AbstractMessage implements \Psr\Http\Message\RequestInterface
{
    /**
     * @var string|null The HTTP method
     */
    protected $method = null;

    /**
     * @var string|null The request target
     */
    protected $requestTarget;

    /**
     * @var \Kotchasan\Http\Uri|null The URI
     */
    protected $uri;

    /**
     * Create a class from a link and merge values from $_GET
     *
     * @param string $uri The URI, default is 'index.php'
     * @param array $exclude An array of keys from $_GET to exclude from the URL
     *
     * @return Uri
     */
    public static function createUriWithGet($uri = 'index.php', $exclude = [])
    {
        $query = [];
        self::map($query, $_GET, $exclude);
        if (!empty($query)) {
            $uri .= (strpos($uri, '?') === false ? '?' : '&').http_build_query($query);
        }
        return Uri::createFromUri($uri);
    }

    /**
     * Create a class from a link and merge values from $_GET and $_POST
     *
     * @param string $uri The URI, default is 'index.php'
     * @param array $exclude An array of keys from $_GET and $_POST to exclude from the URL
     *
     * @return Uri
     */
    public function createUriWithGlobals($uri = 'index.php', $exclude = [])
    {
        $query_str = [];
        self::map($query_str, $_GET, $exclude);
        self::map($query_str, $_POST, $exclude);
        if (!empty($query_str)) {
            $uri .= (strpos($uri, '?') === false ? '?' : '&').http_build_query($query_str);
        }
        return Uri::createFromUri($uri);
    }

    /**
     * Create a class from a link and merge values from $_POST
     *
     * @param string $uri The URI, default is 'index.php'
     * @param array $exclude An array of keys from $_POST to exclude from the URL
     *
     * @return Uri
     */
    public static function createUriWithPost($uri = 'index.php', $exclude = [])
    {
        $query = [];
        self::map($query, $_POST, $exclude);
        if (!empty($query)) {
            $uri .= (strpos($uri, '?') === false ? '?' : '&').http_build_query($query);
        }
        return Uri::createFromUri($uri);
    }

    /**
     * Get the HTTP method
     *
     * @return string The request method
     */
    public function getMethod()
    {
        if ($this->method === null) {
            $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
            if ($this->method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $this->method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
            }
        }
        return $this->method;
    }

    /**
     * Get the request target
     *
     * @return string The request target
     */
    public function getRequestTarget()
    {
        if ($this->requestTarget === null) {
            $this->requestTarget = $this->uri;
        }
        return $this->requestTarget;
    }

    /**
     * Get the URI
     *
     * @return Uri
     */
    public function getUri()
    {
        if ($this->uri === null) {
            $this->uri = Uri::createFromGlobals();
        }
        return $this->uri;
    }

    /**
     * Merge arrays $_GET and $_POST into a single data array
     *
     * @param array &$result A variable to store the result for further use
     * @param array $array The array to merge, e.g., $_GET or $_POST
     * @param array $exclude A list of keys from the array that should not be included in the result
     */
    public static function map(&$result, $array, $exclude = [])
    {
        foreach ($array as $key => $value) {
            if (!in_array($key, $exclude)) {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $result[$key.'['.$k.']'] = $v;
                    }
                } else {
                    $result[$key] = $value;
                }
            }
        }
    }

    /**
     * Set the HTTP method
     *
     * @param string $method The HTTP method
     *
     * @return static
     */
    public function withMethod($method)
    {
        $clone = clone $this;
        $clone->method = $method;
        return $clone;
    }

    /**
     * Set the request target
     *
     * @param mixed $requestTarget The request target
     *
     * @return static
     */
    public function withRequestTarget($requestTarget)
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    /**
     * Set the URI
     *
     * @param \Kotchasan\Http\UriInterface $uri The URI
     * @param bool $preserveHost Whether to preserve the host header
     *
     * @return static
     */
    public function withUri(\Psr\Http\Message\UriInterface $uri, $preserveHost = false)
    {
        $clone = clone $this;
        $clone->uri = $uri;
        if (!$preserveHost) {
            if ($uri->getHost() !== '') {
                $clone->headers['Host'] = $uri->getHost();
            }
        } else {
            if ($this->uri->getHost() !== '' && (!$this->hasHeader('Host') || $this->getHeader('Host') === null)) {
                $clone->headers['Host'] = $uri->getHost();
            }
        }
        return $clone;
    }
}
