<?php
/**
 * @filesource Kotchasan/Files.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

use Kotchasan\Http\UploadedFile;

/**
 * Class Files
 * An array-based collection of files.
 *
 * @see https://www.kotchasan.com/
 */
class Files implements \Iterator
{
    /**
     * @var int
     */
    private $position = 0;

    /**
     * @var array
     */
    private $datas = [];

    /**
     * Initialize the class.
     */
    public function __construct()
    {
        $this->position = 0;
        $this->datas = [];
    }

    /**
     * Add a file to the collection.
     *
     * @param string $name         Input name
     * @param string $path         Uploaded file path
     * @param string $originalName Uploaded file name
     * @param string $mimeType     MIME Type
     * @param int    $size         Uploaded file size
     * @param int    $error        Upload error UPLOAD_ERR_XXX
     */
    public function add($name, $path, $originalName, $mimeType = null, $size = null, $error = null)
    {
        $this->datas[] = array(
            $name,
            new UploadedFile($path, $originalName, $mimeType, $size, $error)
        );
    }

    /**
     * Rewind the Iterator to the first element.
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->position = 0;
    }

    /**
     * Checks if the current position is valid.
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return isset($this->datas[$this->position]);
    }

    /**
     * Get the current UploadedFile.
     *
     * @return \Kotchasan\Http\UploadedFile
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->datas[$this->position][1];
    }

    /**
     * Get the key or index of the current UploadedFile in the list.
     *
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->datas[$this->position][0];
    }

    /**
     * Move to the next UploadedFile in the list.
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $this->position++;
    }

    /**
     * Get the specified file.
     *
     * @param string|int $key The requested item
     *
     * @return \Kotchasan\Http\UploadedFile|null
     */
    public function get($key)
    {
        $result = null;
        foreach ($this->datas as $values) {
            if ($values[0] === $key) {
                $result = $values[1];
                break;
            }
        }
        return $result;
    }
}
