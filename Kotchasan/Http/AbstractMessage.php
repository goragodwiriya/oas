<?php
/**
 * @filesource Kotchasan/Http/AbstractMessage.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * AbstractMessage Class
 *
 * This class represents an abstract HTTP message base class (PSR-7).
 * It implements the MessageInterface.
 *
 * @package Kotchasan\Http
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array The headers of the message.
     */
    protected $headers = [];

    /**
     * @var string The protocol version of the message.
     */
    protected $protocol = '1.1';

    /**
     * @var StreamInterface The body of the message.
     */
    protected $stream;

    /**
     * Initializes the class.
     *
     * @param bool $withHeader Whether to include the HTTP header or not. Default is false.
     */
    public function __construct($withHeader = false)
    {
        if ($withHeader) {
            $this->headers = $this->getRequestHeaders();
        }
    }

    /**
     * Get the body of the message.
     *
     * @return StreamInterface The message body.
     */
    public function getBody()
    {
        return $this->stream;
    }

    /**
     * Get the specified header as an array.
     *
     * @param string $name The name of the header.
     *
     * @return string[] The values of the header as an array, or an empty array if not found.
     */
    public function getHeader($name)
    {
        return isset($this->headers[$name]) ? $this->headers[$name] : [];
    }

    /**
     * Get the specified header as a string.
     *
     * @param string $name The name of the header.
     *
     * @return string The concatenated values of the header, separated by commas, or an empty string if not found.
     */
    public function getHeaderLine($name)
    {
        $values = $this->getHeader($name);
        return empty($values) ? '' : implode(',', $values);
    }

    /**
     * Get all the headers.
     *
     * @return array The headers of the message.
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Get the protocol version of the message.
     *
     * @return string The protocol version.
     */
    public function getProtocolVersion()
    {
        return $this->protocol;
    }

    /**
     * Check if a header exists.
     *
     * @param string $name The name of the header.
     *
     * @return bool True if the header exists, false otherwise.
     */
    public function hasHeader($name)
    {
        return isset($this->headers[$name]);
    }

    /**
     * Add a new header.
     *
     * @param string          $name  The name of the header.
     * @param string|string[] $value The value(s) of the header as a string or an array of strings.
     *
     * @throws \InvalidArgumentException If the header name is invalid.
     *
     * @return static
     */
    public function withAddedHeader($name, $value)
    {
        $this->filterHeader($name);
        $clone = clone $this;
        if (is_array($value)) {
            foreach ($value as $item) {
                $clone->headers[$name][] = $item;
            }
        } else {
            $clone->headers[$name][] = $value;
        }
        return $clone;
    }

    /**
     * Set the message body.
     *
     * @param StreamInterface $body The message body.
     *
     * @return static
     */
    public function withBody(StreamInterface $body)
    {
        $clone = clone $this;
        $clone->stream = $body;
        return $clone;
    }

    /**
     * Set a header, replacing any existing values.
     *
     * @param string          $name  The name of the header.
     * @param string|string[] $value The value(s) of the header as a string or an array of strings.
     *
     * @throws \InvalidArgumentException for invalid header names or values.
     *
     * @return static
     */
    public function withHeader($name, $value)
    {
        $this->filterHeader($name);
        $clone = clone $this;
        $clone->headers[$name] = is_array($value) ? $value : (array) $value;
        return $clone;
    }

    /**
     * Set multiple headers, replacing any existing values.
     *
     * @param array $headers An array of headers in the format: array($key => $value, $key => $value...)
     *
     * @throws \InvalidArgumentException for invalid header names or values.
     *
     * @return static
     */
    public function withHeaders($headers)
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $this->filterHeader($name);
            $clone->headers[$name] = is_array($value) ? $value : (array) $value;
        }
        return $clone;
    }

    /**
     * Set the protocol version of the message.
     *
     * @param string $version The protocol version (e.g., 1.1, 1.0).
     *
     * @return static
     */
    public function withProtocolVersion($version)
    {
        $clone = clone $this;
        $clone->protocol = $version;
        return $clone;
    }

    /**
     * Remove a header.
     *
     * @param string $name The name of the header to remove.
     *
     * @return static
     */
    public function withoutHeader($name)
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }

    /**
     * Check the validity of a header.
     *
     * @param string $name The name of the header.
     *
     * @throws \InvalidArgumentException If the header is invalid.
     */
    protected function filterHeader($name)
    {
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid header name');
        }
    }

    /**
     * Function to retrieve HTTP headers.
     *
     * @return array An array of HTTP headers.
     */
    protected function getRequestHeaders()
    {
        $headers = [];
        if (function_exists("apache_request_headers")) {
            foreach (apache_request_headers() as $key => $value) {
                if (preg_match('/^[a-zA-Z0-9\-]+$/', $key)) {
                    $headers[$key] = array($value);
                }
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (preg_match('/^HTTP_([a-zA-Z0-9_]+)$/', $key, $match)) {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace(array('_', '-'), ' ', $match[1]))))] = array($value);
                }
            }
        }
        return $headers;
    }
}
