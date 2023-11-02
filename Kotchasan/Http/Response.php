<?php
/**
 * @filesource Kotchasan/Http/Response.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Represents an HTTP response.
 *
 * This class extends the Message class and implements the ResponseInterface.
 * It provides methods to handle and manipulate HTTP responses.
 *
 * @see https://www.kotchasan.com/
 */
class Response extends Message implements ResponseInterface
{
    /**
     * Status codes and their corresponding text phrases.
     *
     * @var array
     */
    public static $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // RFC2518
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status', // RFC4918
        208 => 'Already Reported', // RFC5842
        226 => 'IM Used', // RFC3229
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Reserved',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect', // RFC7238
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot', // RFC2324
        422 => 'Unprocessable Entity', // RFC4918
        423 => 'Locked', // RFC4918
        424 => 'Failed Dependency', // RFC4918
        425 => 'Reserved for WebDAV advanced collections expired proposal', // RFC2817
        426 => 'Upgrade Required', // RFC2817
        428 => 'Precondition Required', // RFC6585
        429 => 'Too Many Requests', // RFC6585
        431 => 'Request Header Fields Too Large', // RFC6585
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates (Experimental)', // RFC2295
        507 => 'Insufficient Storage', // RFC4918
        508 => 'Loop Detected', // RFC5842
        510 => 'Not Extended', // RFC2774
        511 => 'Network Authentication Required' // RFC6585
    ];

    /**
     * The response content.
     *
     * @var string
     */
    protected $content;

    /**
     * The reason phrase associated with the status code.
     *
     * @var string
     */
    protected $reasonPhrase;

    /**
     * The status code of the response.
     *
     * @var int
     */
    protected $statusCode;

    /**
     * Create a new Response instance.
     *
     * @param int          $code          The status code.
     * @param string|null  $reasonPhrase  The reason phrase (optional).
     */
    public function __construct($code = 200, $reasonPhrase = null)
    {
        $this->statusCode = $code;
        $this->reasonPhrase = $reasonPhrase;
    }

    /**
     * Get the content of the response.
     *
     * @return string The response content.
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Get the reason phrase associated with the status code.
     *
     * @return string The reason phrase.
     *
     * @see http://tools.ietf.org/html/rfc7231#section-6
     * @see http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     */
    public function getReasonPhrase()
    {
        if ($this->reasonPhrase) {
            return $this->reasonPhrase;
        }
        if (isset(static::$statusTexts[$this->statusCode])) {
            return static::$statusTexts[$this->statusCode];
        }
        return '';
    }

    /**
     * Get the status code of the response.
     *
     * @return int The status code.
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Send the HTTP headers and content.
     *
     * @return Response The Response object.
     */
    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();
        return $this;
    }

    /**
     * Set the content of the response.
     *
     * @param mixed $content The response content.
     *
     * @throws \InvalidArgumentException if the content is not a string.
     *
     * @return Response The Response object.
     */
    public function withContent($content)
    {
        if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable([$content, '__toString'])) {
            throw new \InvalidArgumentException(sprintf('The Response content must be a string or object implementing __toString(), "%s" given.', gettype($content)));
        }
        $this->content = (string) $content;
        return $this;
    }

    /**
     * Set the status code and reason phrase of the response.
     *
     * @param int    $code          The status code.
     * @param string $reasonPhrase  The reason phrase (optional).
     *
     * @return Response The new Response object.
     */
    public function withStatus($code, $reasonPhrase = '')
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;
        return $clone;
    }

    /**
     * Send the response content.
     *
     * @return Response The Response object.
     */
    protected function sendContent()
    {
        if ($this->content) {
            echo $this->content;
        }
        return $this;
    }

    /**
     * Send the HTTP headers.
     *
     * @return Response The Response object.
     */
    protected function sendHeaders()
    {
        if (headers_sent()) {
            return $this;
        }
        header(sprintf('HTTP/%s %s %s', $this->protocol, $this->statusCode, $this->getReasonPhrase()), true, $this->statusCode);
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                header($name.': '.$value);
            }
        }
        return $this;
    }
}
