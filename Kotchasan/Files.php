<?php
/**
 * @filesource Kotchasan/Files.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Http\UploadedFile;

/**
 * รายการ File รูปแบบ Array
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Files implements \Iterator
{
  /**
   * แอเรย์เก็บรายการ UploadedFile
   *
   * @var array
   */
  private $datas = array();

  /**
   * เพื่ม File ลงในคอลเล็คชั่น
   *
   * @param string $name ชื่อของ Input
   * @param string $path ไฟล์อัปโหลด รวมพาธ
   * @param string $originalName ชื่อไฟล์ที่อัปโหลด
   * @param string $mimeType MIME Type
   * @param int $size ขนาดไฟล์อัปโหลด
   * @param int $error ข้อผิดพลาดการอัปโหลด UPLOAD_ERR_XXX
   */
  public function add($name, $path, $originalName, $mimeType = null, $size = null, $error = null)
  {
    $this->datas[$name] = new UploadedFile($path, $originalName, $mimeType, $size, $error);
  }

  /**
   * อ่าน File ที่ต้องการ
   *
   * @param string|int $key รายการที่ต้องการ
   * @return UploadedFile
   */
  public function get($key)
  {
    return $this->datas[$key];
  }

  /**
   * inherited from Iterator
   */
  public function rewind()
  {
    reset($this->datas);
  }

  /**
   * @return UploadedFile
   */
  public function current()
  {
    $var = current($this->datas);
    return $var;
  }

  /**
   * @return string
   */
  public function key()
  {
    $var = key($this->datas);
    return $var;
  }

  /**
   * @return UploadedFile
   */
  public function next()
  {
    $var = next($this->datas);
    return $var;
  }

  /**
   * @return bool
   */
  public function valid()
  {
    $key = key($this->datas);
    return ($key !== NULL && $key !== FALSE);
  }
}
