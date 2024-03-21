<?php
/**
 * @filesource Kotchasan/Http/Stream.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 compliant data stream class.
 *
 * Represents a stream of data as defined by the PSR-7 StreamInterface.
 *
 * @see https://www.kotchasan.com/
 */
class Stream implements StreamInterface
{
    /**
     * Stream metadata.
     *
     * @var array
     */
    protected $meta;

    /**
     * Flag indicating if the stream is readable.
     *
     * @var bool
     */
    protected $readable;

    /**
     * Flag indicating if the stream is seekable.
     *
     * @var bool
     */
    protected $seekable;

    /**
     * Stream size.
     *
     * @var null|int
     */
    protected $size;

    /**
     * Stream resource.
     *
     * @var resource
     */
    protected $stream;

    /**
     * Flag indicating if the stream is writable.
     *
     * @var bool
     */
    protected $writable;

    /**
     * Creates a new Stream instance.
     *
     * @param resource|string $stream The stream resource or a string representing a file path or URL.
     * @param string $mode The mode in which to open the stream.
     *
     * @throws \InvalidArgumentException If the stream is not a valid resource.
     */
    public function __construct($stream, $mode = 'r')
    {
        if (is_string($stream)) {
            set_error_handler(function ($e) use (&$error) {
                $error = $e;
            }, E_WARNING);
            $stream = fopen($stream, $mode);
            restore_error_handler();
        }

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }

        $this->stream = $stream;
    }

    /**
     * Gets the contents of the stream as a string.
     *
     * @throws \RuntimeException If unable to read the stream contents.
     *
     * @return string The stream contents.
     */
    public function __toString()
    {
        if (is_resource($this->stream)) {
            try {
                $this->rewind();
                return $this->getContents();
            } catch (\RuntimeException $e) {
            }
        }

        return '';
    }

    /**
     * Closes the stream and releases its resources.
     *
     * @return void
     */
    public function close()
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
        }
    }

    /**
     * Detaches the stream resource and returns it.
     *
     * @return resource|null The detached stream resource.
     */
    public function detach()
    {
        $tmp = $this->stream;
        $this->meta = null;
        $this->readable = null;
        $this->seekable = null;
        $this->size = null;
        $this->stream = null;
        $this->writable = null;

        return $tmp;
    }

    /**
     * Checks if the end of the stream has been reached.
     *
     * @return bool True if the end of the stream has been reached, false otherwise.
     */
    public function eof()
    {
        return is_resource($this->stream) ? feof($this->stream) : true;
    }

    /**
     * Gets the contents of the stream as a string.
     *
     * @throws \RuntimeException If unable to read the stream contents.
     *
     * @return string The stream contents.
     */
    public function getContents()
    {
        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    /**
     * Gets the metadata of the stream or a specific key of the metadata.
     *
     * @param string|null $key The metadata key to retrieve.
     *
     * @return array|mixed|null The metadata as an array, a specific metadata value, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        if ($this->meta === null) {
            $this->meta = is_resource($this->stream) ? stream_get_meta_data($this->stream) : null;
        }

        if ($key === null) {
            return $this->meta;
        } else {
            return isset($this->meta[$key]) ? $this->meta[$key] : null;
        }
    }

    /**
     * Gets the size of the stream in bytes.
     *
     * @return int|null The size of the stream in bytes, or null if the size is unknown.
     */
    public function getSize()
    {
        if ($this->size === null) {
            if (is_resource($this->stream)) {
                $stats = fstat($this->stream);
                $this->size = isset($stats['size']) ? $stats['size'] : null;
            } else {
                $this->size = null;
            }
        }

        return $this->size;
    }

    /**
     * Checks if the stream is readable.
     *
     * @return bool True if the stream is readable, false otherwise.
     */
    public function isReadable()
    {
        if ($this->readable === null) {
            $mode = $this->getMetadata('mode');
            $this->readable = $mode === null ? false : (strstr($mode, 'r') || strstr($mode, '+'));
        }

        return $this->readable;
    }

    /**
     * Checks if the stream is seekable.
     *
     * @return bool True if the stream is seekable, false otherwise.
     */
    public function isSeekable()
    {
        if ($this->seekable === null) {
            $this->seekable = $this->getMetadata('seekable');
        }

        return $this->seekable;
    }

    /**
     * Checks if the stream is writable.
     *
     * @return bool True if the stream is writable, false otherwise.
     */
    public function isWritable()
    {
        if ($this->writable === null) {
            $mode = $this->getMetadata('mode');
            $this->writable = $mode === null ? false : (strstr($mode, 'x') || strstr($mode, 'w') || strstr($mode, 'c') || strstr($mode, 'a') || strstr($mode, '+'));
        }

        return $this->writable;
    }

    /**
     * Reads data from the stream.
     *
     * @param int $length The number of bytes to read.
     *
     * @throws \RuntimeException If unable to read the stream.
     *
     * @return string The data read from the stream.
     */
    public function read($length)
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        $data = fread($this->stream, $length);

        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $data;
    }

    /**
     * Seeks to a specific position in the stream.
     *
     * @throws \RuntimeException on failure
     */
    public function rewind()
    {
        return $this->seek(0);
    }

    /**
     * เลื่อน pointer ไปยังตำแหน่งที่กำหนด
     *
     * @param int $offset ตำแหน่งของ pointer
     * @param int $whence
     *
     * @throws \RuntimeException on failure
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException('Error seeking within stream');
        }
    }

    /**
     * คืนค่าตำแหน่งของ pointer ปัจจุบัน
     *
     * @throws \RuntimeException on error
     *
     * @return int
     */
    public function tell()
    {
        $position = is_resource($this->stream) ? ftell($this->stream) : false;
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }
        return $position;
    }

    /**
     * เขียนข้อมูลลงบน stream
     * คืนค่าจำนวน byte ที่เขียน
     *
     * @param string $string ข้อมูลที่เขียน
     *
     * @throws \RuntimeException on failure
     *
     * @return int
     */
    public function write($string)
    {
        $result = is_resource($this->stream) ? fwrite($this->stream, $string) : false;
        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        } else {
            $this->size = null;
        }
        return $result;
    }
}
