<?php

namespace Kotchasan\Http;

use Kotchasan\Psr\Http\Message\RequestInterface;
use Kotchasan\Psr\Http\Message\UriInterface;

/**
 * Abstract HTTP Request Class
 * Implements common functionality for HTTP requests
 *
 * @package Kotchasan\Http
 */
abstract class AbstractRequest extends AbstractMessage implements RequestInterface
{
    /**
     * @var string HTTP method
     */
    protected $method = 'GET';

    /**
     * @var string Request target
     */
    protected $requestTarget;

    /**
     * @var UriInterface Uri object
     */
    protected $uri;

    /**
     * {@inheritDoc}
     */
    public function getRequestTarget()
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath();
        if ($target == '') {
            $target = '/';
        }
        if ($this->uri->getQuery() != '') {
            $target .= '?'.$this->uri->getQuery();
        }
        return $target;
    }

    /**
     * {@inheritDoc}
     */
    public function withRequestTarget($requestTarget)
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * {@inheritDoc}
     */
    public function withMethod($method)
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * {@inheritDoc}
     */
    public function withUri(UriInterface $uri, $preserveHost = false)
    {
        $clone = clone $this;
        $clone->uri = $uri;
        if (!$preserveHost) {
            if ($uri->getHost() !== '') {
                $clone->headers['host'] = [$uri->getHost()];
            }
        } elseif (!$this->hasHeader('Host') && $uri->getHost() !== '') {
            $clone->headers['host'] = [$uri->getHost()];
        }
        return $clone;
    }
}
