<?php

namespace Kotchasan\Http;

use Kotchasan\Psr\Http\Message\ResponseInterface;
use Kotchasan\Psr\Http\Message\StreamInterface;

/**
 * PSR-7 HTTP Response implementation
 *
 * @author Goragod Wiriya <admin@goragod.com>
 */
class Response implements ResponseInterface
{
    /**
     * @var int HTTP status code
     */
    private $statusCode = 200;

    /**
     * @var string HTTP reason phrase
     */
    private $reasonPhrase = '';

    /**
     * @var array HTTP headers
     */
    private $headers = [];

    /**
     * @var StreamInterface Response body
     */
    private $body;

    /**
     * @var string HTTP protocol version
     */
    private $protocolVersion = '1.1';

    /**
     * HTTP status codes และ reason phrases
     *
     * @var array
     */
    private static $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->body = new Stream('php://temp', 'w+');
        $this->reasonPhrase = self::$statusTexts[$this->statusCode] ?? '';
    }

    // ===== PSR-7 ResponseInterface Implementation =====

    /**
     * Gets the response status code.
     *
     * @return int Status code.
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Return an instance with the specified status code and, optionally, reason phrase.
     *
     * @param int $code The 3-digit integer result code to set.
     * @param string $reasonPhrase The reason phrase to use with the provided status code.
     * @return static
     */
    public function withStatus($code, $reasonPhrase = '')
    {
        $new = clone $this;
        $new->statusCode = (int) $code;
        $new->reasonPhrase = $reasonPhrase ?: (self::$statusTexts[$code] ?? '');
        return $new;
    }

    /**
     * Gets the response reason phrase associated with the status code.
     *
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }

    /**
     * Retrieves all message header values.
     *
     * @return string[][] Returns an associative array of the message's headers.
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name Case-insensitive header field name.
     * @return bool Returns true if any header names match the given header name.
     */
    public function hasHeader($name)
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * @param string $name Case-insensitive header field name.
     * @return string[] An array of string values as provided for the given header.
     */
    public function getHeader($name)
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * @param string $name Case-insensitive header field name.
     * @return string A string of values as provided for the given header concatenated together.
     */
    public function getHeaderLine($name)
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * @param string $name Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     * @return static
     */
    public function withHeader($name, $value)
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        return $new;
    }

    /**
     * Return an instance with the specified header appended with the given value.
     *
     * @param string $name Case-insensitive header field name to add.
     * @param string|string[] $value Header value(s).
     * @return static
     */
    public function withAddedHeader($name, $value)
    {
        $new = clone $this;
        $key = strtolower($name);
        $values = is_array($value) ? $value : [$value];

        if (isset($new->headers[$key])) {
            $new->headers[$key] = array_merge($new->headers[$key], $values);
        } else {
            $new->headers[$key] = $values;
        }

        return $new;
    }

    /**
     * Return an instance without the specified header.
     *
     * @param string $name Case-insensitive header field name to remove.
     * @return static
     */
    public function withoutHeader($name)
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface Returns the body as a stream.
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Return an instance with the specified message body.
     *
     * @param StreamInterface $body Body.
     * @return static
     */
    public function withBody(StreamInterface $body)
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * @return string HTTP protocol version.
     */
    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     *
     * @param string $version HTTP protocol version
     * @return static
     */
    public function withProtocolVersion($version)
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    // ===== Convenience Methods =====

    /**
     * Set multiple headers at once
     *
     * @param array $headers
     * @return self
     */
    public function withHeaders(array $headers)
    {
        $response = $this;
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    /**
     * Set content as string
     *
     * @param string $content
     * @return self
     */
    public function withContent($content)
    {
        $stream = new Stream('php://temp', 'w+');
        $stream->write($content);
        $stream->rewind();
        return $this->withBody($stream);
    }

    /**
     * Get content as string
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->body->getContents();
    }

    /**
     * Send JSON response
     *
     * @param mixed $data
     * @param int $status
     * @return self
     */
    public function json($data, $status = 200)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withContent($json);
    }

    /**
     * Send HTML response
     *
     * @param string $html
     * @param int $status
     * @return self
     */
    public function html($html, $status = 200)
    {
        return $this->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withContent($html);
    }

    /**
     * Send redirect response
     *
     * @param string $url
     * @param int $status
     * @return self
     */
    public function redirect($url, $status = 302)
    {
        return $this->withStatus($status)
            ->withHeader('Location', $url);
    }

    /**
     * Send the response to the browser
     */
    public function send()
    {
        // Capture buffered output (errors/warnings) in debug mode
        $bufferedOutput = '';
        if (defined('DEBUG') && DEBUG >= 1 && ob_get_level() > 0) {
            $bufferedOutput = ob_get_contents();
        }

        // Clean buffer if exists
        if (ob_get_level() > 0) {
            ob_clean();
        }

        // Determine if this is a JSON response
        $contentType = $this->getHeaderLine('content-type');
        $isJson = stripos($contentType, 'application/json') !== false;

        // Get body content once and rewind for potential re-read
        $this->body->rewind();
        $bodyContent = $this->body->getContents();

        // Handle debug output for JSON responses
        if ($isJson && defined('DEBUG') && DEBUG >= 1) {
            $debugInfo = $this->collectDebugInfo($bufferedOutput);
            if (!empty($debugInfo)) {
                // Decode current JSON body, add _debug, re-encode
                $data = json_decode($bodyContent, true);
                if (is_array($data)) {
                    $data['_debug'] = $debugInfo;
                    $bodyContent = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                }
            }
        }

        // Send status line
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                header(ucwords($name, '-').': '.$value, false);
            }
        }

        // Send body
        echo $bodyContent;

        // For HTML responses, append debug output after body
        if (!$isJson && defined('DEBUG') && DEBUG >= 1) {
            $this->outputHtmlDebug($bufferedOutput);
        }
    }

    /**
     * Collect debug information from various sources
     *
     * @param string $bufferedOutput
     * @return array
     */
    private function collectDebugInfo($bufferedOutput)
    {
        $debug = [];

        // Add buffered output (PHP errors/warnings)
        if (!empty($bufferedOutput)) {
            $debug['php_output'] = trim($bufferedOutput);
        }

        // Add debug() function calls
        if (class_exists('Kotchasan', false) && !empty(\Kotchasan::$debugger)) {
            $debug['console'] = \Kotchasan::$debugger;
            // Clear debugger to prevent doShutdown from outputting script tags
            \Kotchasan::$debugger = null;
        }

        return $debug;
    }

    /**
     * Output debug information for HTML responses
     *
     * @param string $bufferedOutput
     */
    private function outputHtmlDebug($bufferedOutput)
    {
        // Check if there's anything to output
        $hasBufferedOutput = !empty($bufferedOutput);
        $hasDebugger = class_exists('Kotchasan', false) && !empty(\Kotchasan::$debugger);

        if (!$hasBufferedOutput && !$hasDebugger) {
            return;
        }

        // Output PHP errors/warnings in a visible div
        if ($hasBufferedOutput) {
            // DEBUG >= 2: Show raw output, DEBUG == 1: Show escaped output
            if (defined('DEBUG') && DEBUG >= 2) {
                echo $bufferedOutput;
            } else {
                echo '<!-- PHP Debug Output: '.htmlspecialchars($bufferedOutput).' -->';
            }
        }

        // Output debug() calls to console
        if ($hasDebugger) {
            echo '<script>';
            foreach (\Kotchasan::$debugger as $item) {
                echo 'console.log('.$item.');';
            }
            echo '</script>';
            // Clear debugger to prevent doShutdown from outputting again
            \Kotchasan::$debugger = null;
        }
    }

    // ===== Static Factory Methods =====

    /**
     * สร้าง Response instance ใหม่
     *
     * @param string|null $content
     * @param int $status
     * @param array $headers
     * @return self
     */
    public static function create($content = null, $status = 200, array $headers = [])
    {
        $response = new self();

        if ($content !== null) {
            $response = $response->withContent($content);
        }

        if ($status !== 200) {
            $response = $response->withStatus($status);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * สร้าง JSON response
     *
     * @param mixed $data
     * @param int $status
     * @param array $headers
     * @return self
     */
    public static function makeJson($data, $status = 200, array $headers = [])
    {
        $response = self::create(null, $status, $headers);
        return $response->json($data, $status);
    }

    /**
     * สร้าง Bad Request response (400)
     *
     * @param mixed $data
     * @param array $headers
     * @return self
     */
    public static function makeBadRequest($data = null, array $headers = [])
    {
        return self::makeJson($data ?: ['message' => 'Bad Request'], 400, $headers);
    }

    /**
     * สร้าง Unauthorized response (401)
     *
     * @param mixed $data
     * @param array $headers
     * @return self
     */
    public static function makeUnauthorized($data = null, array $headers = [])
    {
        return self::makeJson($data ?: ['message' => 'Unauthorized'], 401, $headers);
    }

    /**
     * สร้าง Forbidden response (403)
     *
     * @param mixed $data
     * @param array $headers
     * @return self
     */
    public static function makeForbidden($data = null, array $headers = [])
    {
        return self::makeJson($data ?: ['message' => 'Forbidden'], 403, $headers);
    }

    /**
     * สร้าง Not Found response (404)
     *
     * @param mixed $data
     * @param array $headers
     * @return self
     */
    public static function makeNotFound($data = null, array $headers = [])
    {
        return self::makeJson($data ?: ['message' => 'Not Found'], 404, $headers);
    }

    /**
     * สร้าง Server Error response (500)
     *
     * @param mixed $data
     * @param array $headers
     * @return self
     */
    public static function makeServerError($data = null, array $headers = [])
    {
        return self::makeJson($data ?: ['message' => 'Internal Server Error'], 500, $headers);
    }

    // ===== Security and Additional Methods =====

    /**
     * Set security headers
     *
     * @param array $options Security options
     * @return self
     */
    public function setSecurityHeaders(array $options = []): self
    {
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'"
        ];

        $headers = array_merge($defaults, $options);
        $response = $this;

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Set CORS headers.
     *
     * This method is a pure utility that applies the given CORS header values.
     * Origin resolution (deciding whether to echo the request Origin or use a
     * configured allowlist) must be done by the caller before invoking this
     * method — pass the resolved origin via $options['Access-Control-Allow-Origin'].
     *
     * Example (controller layer):
     *   $origin = \Kotchasan\Cors::resolveOrigin($request, $cfg->api_cors ?? '*');
     *   $response->setCorsHeaders(['Access-Control-Allow-Origin' => $origin]);
     *
     * @param array $options Override any default CORS header value.
     * @return self
     */
    public function setCorsHeaders(array $options = []): self
    {
        $defaults = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Max-Age' => '86400'
        ];

        $headers = array_merge($defaults, $options);
        $response = $this;

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Set no-cache headers
     *
     * @return self
     */
    public function setNoCacheHeaders(): self
    {
        return $this
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * Send file response
     *
     * @param string $filePath Path to file
     * @param string|null $filename Download filename
     * @param array $headers Additional headers
     * @return self
     */
    public function file(string $filePath, ?string $filename = null, array $headers = []): self
    {
        if (!file_exists($filePath)) {
            return $this->withStatus(404)->withContent('File not found');
        }

        $filename = $filename ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);

        $response = $this
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) $fileSize)
            ->withHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // Set file content
        $content = file_get_contents($filePath);
        return $response->withContent($content);
    }

    /**
     * Create download response
     *
     * @param string $content File content
     * @param string $filename Download filename
     * @param string $mimeType MIME type
     * @return self
     */
    public function download(string $content, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        return $this
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->withContent($content);
    }

    /**
     * Create XML response
     *
     * @param string $xml XML content
     * @param int $status HTTP status code
     * @return self
     */
    public function xml(string $xml, int $status = 200): self
    {
        return $this
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withContent($xml);
    }

    /**
     * Create plain text response
     *
     * @param string $text Text content
     * @param int $status HTTP status code
     * @return self
     */
    public function text(string $text, int $status = 200): self
    {
        return $this
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withContent($text);
    }
}
