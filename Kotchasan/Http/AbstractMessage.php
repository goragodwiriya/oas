<?php

namespace Kotchasan\Http;

use Kotchasan\Psr\Http\Message\MessageInterface;
use Kotchasan\Psr\Http\Message\StreamInterface;

/**
 * Abstract HTTP Message Class
 * Implements common functionality for HTTP messages
 *
 * @package Kotchasan\Http
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array HTTP headers
     */
    protected $headers = [];

    /**
     * @var string HTTP protocol version
     */
    protected $protocol = '1.1';

    /**
     * @var StreamInterface HTTP message body
     */
    protected $stream;

    /**
     * Create a new HTTP message.
     *
     * @param StreamInterface $stream HTTP message body
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /**
     * {@inheritDoc}
     */
    public function getProtocolVersion()
    {
        return $this->protocol;
    }

    /**
     * {@inheritDoc}
     */
    public function withProtocolVersion($version)
    {
        $clone = clone $this;
        $clone->protocol = $version;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * {@inheritDoc}
     */
    public function hasHeader($name)
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * {@inheritDoc}
     */
    public function getHeader($name)
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : [];
    }

    /**
     * {@inheritDoc}
     */
    public function getHeaderLine($name)
    {
        $header = $this->getHeader($name);
        return is_array($header) ? implode(',', $header) : (string) $header;
    }

    /**
     * {@inheritDoc}
     */
    public function withHeader($name, $value)
    {
        $clone = clone $this;
        $name = strtolower($name);
        $clone->headers[$name] = is_array($value) ? $value : [$value];
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withAddedHeader($name, $value)
    {
        if (!$this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }
        $clone = clone $this;
        $name = strtolower($name);
        $clone->headers[$name] = array_merge(
            $clone->headers[$name],
            is_array($value) ? $value : [$value]
        );
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withoutHeader($name)
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getBody()
    {
        return $this->stream;
    }

    /**
     * {@inheritDoc}
     */
    public function withBody(StreamInterface $body)
    {
        $clone = clone $this;
        $clone->stream = $body;
        return $clone;
    }
}
