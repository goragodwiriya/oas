<?php

namespace Kotchasan\Http;

use Kotchasan\Psr\Http\Message\StreamInterface;

/**
 * HTTP Stream Class
 * Implements PSR-7 StreamInterface
 *
 * @package Kotchasan\Http
 */
class Stream implements StreamInterface
{
    /**
     * @var resource|null Stream resource
     */
    protected $resource;

    /**
     * @var bool Whether the stream is seekable
     */
    protected $seekable;

    /**
     * @var bool Whether the stream is readable
     */
    protected $readable;

    /**
     * @var bool Whether the stream is writable
     */
    protected $writable;

    /**
     * @var array|mixed|null Stream metadata
     */
    protected $metadata;

    /**
     * @var int|null Stream size
     */
    protected $size;

    /**
     * Create a new Stream.
     *
     * @param string|resource $stream Stream resource or string
     * @param string $mode Mode for opening the stream
     * @throws \InvalidArgumentException
     */
    public function __construct($stream, $mode = 'r')
    {
        if (is_string($stream)) {
            $resource = @fopen($stream, $mode);
            if ($resource === false) {
                throw new \InvalidArgumentException('Could not open stream: '.$stream);
            }
            $this->resource = $resource;
        } elseif (is_resource($stream)) {
            $this->resource = $stream;
        } else {
            throw new \InvalidArgumentException('Invalid stream provided');
        }

        $this->metadata = stream_get_meta_data($this->resource);
        $this->seekable = $this->metadata['seekable'];
        $this->readable = strpos($mode, 'r') !== false || strpos($mode, '+') !== false;
        $this->writable = strpos($mode, 'w') !== false || strpos($mode, 'a') !== false || strpos($mode, '+') !== false;
        $this->size = null;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        if (!$this->isReadable()) {
            return '';
        }

        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        if ($this->resource) {
            $resource = $this->detach();
            fclose($resource);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->metadata = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $resource;
    }

    /**
     * {@inheritDoc}
     */
    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);
        $this->size = $stats['size'];

        return $this->size;
    }

    /**
     * {@inheritDoc}
     */
    public function tell()
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        $position = ftell($this->resource);
        if ($position === false) {
            throw new \RuntimeException('Could not get the position of the pointer in stream');
        }

        return $position;
    }

    /**
     * {@inheritDoc}
     */
    public function eof()
    {
        return $this->resource === null || feof($this->resource);
    }

    /**
     * {@inheritDoc}
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * {@inheritDoc}
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new \RuntimeException('Could not seek in stream');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * {@inheritDoc}
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * {@inheritDoc}
     */
    public function write($string)
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }

        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) {
            throw new \RuntimeException('Could not write to stream');
        }

        $this->size = null;

        return $bytes;
    }

    /**
     * {@inheritDoc}
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * {@inheritDoc}
     */
    public function read($length)
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        $string = fread($this->resource, $length);
        if ($string === false) {
            throw new \RuntimeException('Could not read from stream');
        }

        return $string;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents()
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new \RuntimeException('Could not get contents of stream');
        }

        return $contents;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata($key = null)
    {
        if ($this->resource === null) {
            return $key ? null : [];
        }

        if ($key === null) {
            return $this->metadata;
        }

        return isset($this->metadata[$key]) ? $this->metadata[$key] : null;
    }
}
