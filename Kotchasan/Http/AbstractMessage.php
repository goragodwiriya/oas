<?php
/**
 * @filesource Kotchasan/Http/AbstractMessage.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Http;

use \Psr\Http\Message\MessageInterface;
use \Psr\Http\Message\StreamInterface;

/**
 * HTTP messages base class (PSR-7)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
abstract class AbstractMessage implements MessageInterface
{
  /**
   * @var string
   */
  protected $protocol = '1.1';
  /**
   * @var StreamInterface
   */
  protected $stream;
  /**
   * @var array
   */
  protected $headers = array();

  /**
   * อ่านเวอร์ชั่นของโปรโตคอล
   *
   * @return string เช่น 1.1, 1.0
   */
  public function getProtocolVersion()
  {
    return $this->protocol;
  }

  /**
   * กำหนดเวอร์ชั่นของโปรโตคอล
   *
   * @param string $version เช่น 1.1, 1.0
   * @return \static
   */
  public function withProtocolVersion($version)
  {
    $clone = clone $this;
    $clone->protocol = $version;
    return $clone;
  }

  /**
   * คืนค่า header ทั้งหมด ผลลัพท์เป็น array
   *
   * @return array
   */
  public function getHeaders()
  {
    return $this->headers;
  }

  /**
   * ตรวจสอบว่ามี header หรือไม่
   *
   * @param string $name
   * @return boolean คืนค่า true ถ้ามี
   */
  public function hasHeader($name)
  {
    return isset($this->headers[$name]);
  }

  /**
   * อ่าน header ที่ต้องการ ผลลัพท์เป็น array
   *
   * @param string $name
   * @return string[] คืนค่าแอเรย์ของ header ถ้าไม่พบคืนค่าแอเรย์ว่าง
   */
  public function getHeader($name)
  {
    return isset($this->headers[$name]) ? $this->headers[$name] : array();
  }

  /**
   * อ่าน header ที่ต้องการ ผลลัพท์เป็น string
   *
   * @param string $name
   * @return string คืนค่ารายการ header ทั้งหมดที่พบเชื่อมต่อด้วย ลูกน้ำ (,) หรือคืนค่าข้อความว่าง หากไม่พบ
   */
  public function getHeaderLine($name)
  {
    $values = $this->getHeader($name);
    return empty($values) ? '' : implode('', $values);
  }

  /**
   * กำหนด header แทนที่รายการเดิม
   *
   * @param string $name ชื่อของ Header
   * @param string|string[] $value ค่าของ Header เป็น string หรือ แอเรย์ของ string
   * @return \static
   * @throws \InvalidArgumentException for invalid header names or values.
   */
  public function withHeader($name, $value)
  {
    $this->filterHeader($name);
    $clone = clone $this;
    $clone->headers[$name] = is_array($value) ? $value : (array)$value;
    return $clone;
  }

  /**
   * กำหนด header พร้อมกันหลายรายการ แทนที่รายการเดิม
   *
   * @param array $headers array($key => $value, $key => $value...)
   * @return \static
   * @throws \InvalidArgumentException for invalid header names or values.
   */
  public function withHeaders($headers)
  {
    $clone = clone $this;
    foreach ($headers as $name => $value) {
      $this->filterHeader($name);
      $clone->headers[$name] = is_array($value) ? $value : (array)$value;
    }
    return $clone;
  }

  /**
   * เพิ่ม header ใหม่
   *
   * @param string $name ชื่อของ Header
   * @param string|string[] $value ค่าของ Header เป็น string หรือ แอเรย์ของ string
   * @return \static
   * @throws \InvalidArgumentException ถ้าชื่อ header ไม่ถูกต้อง
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
   * ลบ header
   *
   * @param string $name ชื่อ header ที่ต้องการลบ
   * @return \static
   */
  public function withoutHeader($name)
  {
    $clone = clone $this;
    unset($clone->headers[$name]);
    return $clone;
  }

  /**
   * อ่าน stream
   *
   * @return StreamInterface
   */
  public function getBody()
  {
    return $this->stream;
  }

  /**
   * กำหนด stream
   *
   * @param StreamInterface $body.
   * @return \static
   */
  public function withBody(StreamInterface $body)
  {
    $clone = clone $this;
    $clone->stream = $body;
    return $clone;
  }

  /**
   * ตรวจสอบความถูกต้องของ header
   *
   * @param string $name
   * @throws \InvalidArgumentException ถ้า header ไม่ถูกต้อง
   */
  protected function filterHeader($name)
  {
    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $name)) {
      throw new \InvalidArgumentException('Invalid header name');
    }
  }
}
