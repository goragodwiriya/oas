<?php

namespace Kotchasan\Http;

use Kotchasan\Http\Traits\RequestCookieTrait;
use Kotchasan\Http\Traits\RequestInfoTrait;
use Kotchasan\Http\Traits\RequestMethodTrait;
use Kotchasan\Http\Traits\RequestParametersTrait;
use Kotchasan\Http\Traits\RequestSecurityTrait;
use Kotchasan\Psr\Http\Message\ServerRequestInterface;
use Kotchasan\Psr\Http\Message\UploadedFileInterface;

/**
 * HTTP Request Class
 * Implements PSR-7 ServerRequestInterface with enhanced functionality
 *
 * @package Kotchasan\Http
 */
class Request extends AbstractRequest implements ServerRequestInterface
{
    use RequestParametersTrait;
    use RequestSecurityTrait;
    use RequestMethodTrait;
    use RequestInfoTrait;
    use RequestCookieTrait;

    /**
     * @var array Cookie parameters
     */
    protected $cookieParams;

    /**
     * @var array|object|null Parsed body parameters
     */
    protected $parsedBody;

    /**
     * @var array Query string arguments
     */
    protected $queryParams = [];

    /**
     * @var array Server parameters
     */
    protected $serverParams;

    /**
     * @var array Upload file parameters
     */
    protected $uploadedFiles = [];

    /**
     * @var array Request attributes
     */
    protected $attributes = [];

    /**
     * Create a new HTTP request from server variables.
     *
     * @param bool $withRequest Include $_POST, $_GET, $_COOKIE data
     */
    public function __construct($withRequest = true)
    {
        $this->serverParams = $_SERVER;
        $stream = new Stream('php://input', 'r');
        $uri = Uri::createFromGlobals();
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                $headers[strtolower($key)] = [$value];
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $key = substr($key, 5);
                    $key = str_replace('_', '-', strtolower($key));
                    $headers[$key] = [$value];
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['content-type'] = [$_SERVER['CONTENT_TYPE']];
            }
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $headers['content-length'] = [$_SERVER['CONTENT_LENGTH']];
            }
        }

        // Determine HTTP method with override support
        $originalMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $method = $originalMethod;
        // 1. X-HTTP-Method-Override header (highest priority)
        if ($originalMethod === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $override = strtoupper(trim($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
            if ($override !== '') {
                $method = $override;
            }
        }
        // 2. _method parameter (POST only, lower priority than header)
        elseif ($originalMethod === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper(trim($_POST['_method']));
            if ($override !== '') {
                $method = $override;
            }
        }
        $this->method = $method;
        $this->uri = $uri;
        $this->headers = $headers;
        $this->stream = $stream;

        if ($withRequest) {
            $this->queryParams = $_GET;
            $this->cookieParams = $_COOKIE;
            $this->uploadedFiles = $this->normalizeFiles($_FILES);

            // Handle different content types for parsed body
            $this->parsedBody = $this->parseRequestBody();
        }
    }

    /**
     * Parse request body based on Content-Type
     *
     * @return array|object|null
     */
    protected function parseRequestBody()
    {
        $contentType = $this->getHeaderLine('content-type');

        // Handle JSON content type
        if (strpos($contentType, 'application/json') !== false) {
            try {
                $rawBody = file_get_contents('php://input');
                if (!empty($rawBody)) {
                    $decoded = json_decode($rawBody, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
            } catch (\Exception $e) {
                // Log error if needed, fallback to empty array
            }
            return [];
        }

        // Handle form data (multipart/form-data or application/x-www-form-urlencoded)
        if (strpos($contentType, 'multipart/form-data') !== false ||
            strpos($contentType, 'application/x-www-form-urlencoded') !== false ||
            empty($contentType) ||
            $_SERVER['REQUEST_METHOD'] === 'POST') {
            return $_POST;
        }

        // Default fallback to $_POST
        return $_POST;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerParams()
    {
        return $this->serverParams;
    }

    /**
     * {@inheritDoc}
     */
    public function getCookieParams()
    {
        return $this->cookieParams;
    }

    /**
     * {@inheritDoc}
     */
    public function withCookieParams(array $cookies)
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryParams()
    {
        return $this->queryParams;
    }

    /**
     * {@inheritDoc}
     */
    public function withQueryParams(array $query)
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getUploadedFiles()
    {
        return $this->uploadedFiles;
    }

    /**
     * {@inheritDoc}
     */
    public function withUploadedFiles(array $uploadedFiles)
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * {@inheritDoc}
     */
    public function withParsedBody($data)
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * {@inheritDoc}
     */
    public function getAttribute($name, $default = null)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function withAttribute($name, $value)
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withoutAttribute($name)
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    /**
     * Normalize uploaded files array.
     *
     * @param array $files
     * @return array
     */
    protected function normalizeFiles(array $files)
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $result = $this->createUploadedFile($value);
                // createUploadedFile may return array for multiple files (name[])
                if (is_array($result)) {
                    // Flatten with explicit index: images[0], images[1], etc.
                    foreach ($result as $fileKey => $file) {
                        $normalized[$key.'['.$fileKey.']'] = $file;
                    }
                } else {
                    $normalized[$key] = $result;
                }
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            } else {
                throw new \InvalidArgumentException('Invalid value in files specification');
            }
        }
        return $normalized;
    }

    /**
     * Create an UploadedFile object from an array.
     *
     * @param array $value
     * @return UploadedFile
     */
    protected function createUploadedFile(array $value)
    {
        if (is_array($value['tmp_name'])) {
            $files = [];
            foreach (array_keys($value['tmp_name']) as $key) {
                $files[$key] = $this->createUploadedFile([
                    'tmp_name' => $value['tmp_name'][$key],
                    'name' => $value['name'][$key],
                    'type' => $value['type'][$key],
                    'size' => $value['size'][$key],
                    'error' => $value['error'][$key]
                ]);
            }
            return $files;
        }
        return new UploadedFile(
            $value['tmp_name'],
            $value['size'],
            $value['error'],
            $value['name'],
            $value['type']
        );
    }

    /**
     * Initialize the session and start output buffering
     *
     * @return bool Always returns true
     */
    public function initSession()
    {
        // If USE_SESSION_DATABASE is defined and true, set the custom session handler
        if (defined('USE_SESSION_DATABASE') && USE_SESSION_DATABASE === true) {
            // Check if the Session class exists in the new framework
            // If not available, fall back to regular session handling
            if (class_exists('\\Kotchasan\\Session')) {
                $sess = new \Kotchasan\Session();
                session_set_save_handler(
                    [$sess, '_open'],
                    [$sess, '_close'],
                    [$sess, '_read'],
                    [$sess, '_write'],
                    [$sess, '_destroy'],
                    [$sess, '_gc']
                );
                // Register a shutdown function to write the session data
                register_shutdown_function('session_write_close');
            }
        }

        // Start the session if it's not already active and headers haven't been sent
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Start output buffering if it's not already started
        if (!ob_get_status()) {
            if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
                // Enable gzip compression
                ob_start('ob_gzhandler');
            } else {
                ob_start();
            }
        }

        return true;
    }

    /**
     * Set a request attribute (convenience method).
     *
     * @param string $name Attribute name
     * @param mixed $value Attribute value
     * @return self
     */
    public function setAttribute(string $name, $value): self
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    /**
     * Create a request instance from global variables.
     *
     * @return static
     */
    public static function createFromGlobals(): self
    {
        return new static(true);
    }

    /**
     * Backward-compatible helper to get a sanitized string parameter from POST or GET.
     *
     * @param string $name
     * @param string|null $default
     * @return string|null
     */
    public function string(string $name, $default = null)
    {
        $body = $this->getParsedBody();
        if (is_array($body) && array_key_exists($name, $body)) {
            return $this->sanitizeValue($body[$name]);
        }
        if (isset($this->queryParams[$name])) {
            return $this->sanitizeValue($this->queryParams[$name]);
        }
        return $default;
    }

    /**
     * Get a parameter from request (backward compatibility)
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getParameter(string $key, $default = null)
    {
        $args = func_get_args();
        $sanitize = true;
        if (isset($args[2])) {
            $sanitize = (bool) $args[2];
        }
        // Prefer POST, fallback to GET
        $body = $this->getParsedBody();
        $value = is_array($body) && array_key_exists($key, $body) ? $body[$key] : ($this->queryParams[$key] ?? $default);
        if ($sanitize) {
            return $this->sanitizeValue($value);
        }
        return $value;
    }

    /**
     * Sanitize request data (GET/POST only)
     *
     * @return array
     */
    public function sanitize(): array
    {
        $args = func_get_args();
        if (isset($args[0]) && is_array($args[0])) {
            $data = $args[0];
        } else {
            $data = array_merge($this->queryParams, (array) $this->parsedBody);
        }
        foreach ($data as $key => $value) {
            $data[$key] = $this->sanitizeValue($value);
        }
        return $data;
    }

    /**
     * Check if request has valid referer (backward compatibility)
     *
     * @return bool
     */
    public function isReferer(): bool
    {
        return $this->isValidReferer();
    }

    /**
     * Set CSRF token (backward compatibility)
     *
     * @param string $token CSRF token
     * @return self
     */
    public function setCsrfToken(string $token): self
    {
        return $this->setAttribute('csrf_token', $token);
    }

    // ===== Static Helper Methods =====

    /**
     * Check if current request is AJAX
     *
     * @return bool
     */
    public static function isCurrentAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    /**
     * Check if current request is secure
     *
     * @return bool
     */
    public static function isCurrentSecure(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
    }

    /**
     * Get current client IP
     *
     * @return string
     */
    public static function getCurrentClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get current user agent
     *
     * @return string
     */
    public static function getCurrentUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Sanitize input data statically
     *
     * @param mixed $data Data to sanitize
     * @return mixed
     */
    public static function sanitizeInput($data)
    {
        $request = new static(false);
        return $request->sanitizeValue($data);
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
     * Map values from source array to destination array, excluding specified keys
     *
     * @param array $dest Destination array
     * @param array $source Source array
     * @param array $exclude Keys to exclude
     */
    public static function map(&$dest, $source, $exclude = [])
    {
        foreach ($source as $key => $value) {
            if (!in_array($key, $exclude) && $value !== null && $value !== '') {
                $dest[$key] = $value;
            }
        }
    }
}
