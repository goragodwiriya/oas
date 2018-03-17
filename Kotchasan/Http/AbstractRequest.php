<?php
/**
 * @filesource Kotchasan/Http/AbstractRequest.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Http;

use \Psr\Http\Message\RequestInterface;
use \Psr\Http\Message\UriInterface;
use \Kotchasan\Http\AbstractMessage;
use \Kotchasan\Http\Uri;

/**
 * Class สำหรับจัดการ URL
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class AbstractRequest extends AbstractMessage implements RequestInterface
{
  /**
   * @var Uri
   */
  protected $uri;
  /**
   * @var string
   */
  protected $method = null;
  /**
   * @var string
   */
  protected $requestTarget;

  /**
   * อ่านค่า request target.
   *
   * @return string
   */
  public function getRequestTarget()
  {
    if ($this->requestTarget === null) {
      $this->requestTarget = $this->uri;
    }
    return $this->requestTarget;
  }

  /**
   * กำหนดค่า request target.
   *
   * @param mixed $requestTarget
   * @return \static
   */
  public function withRequestTarget($requestTarget)
  {
    $clone = clone $this;
    $clone->requestTarget = $requestTarget;
    return $clone;
  }

  /**
   * อ่านค่า HTTP method
   *
   * @return string Returns the request method.
   */
  public function getMethod()
  {
    if ($this->method === null) {
      $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
      if ($this->method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
        $this->method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
      }
    }
    return $this->method;
  }

  /**
   * กำหนดค่า HTTP method
   *
   * @param string $method
   * @return \static
   */
  public function withMethod($method)
  {
    $clone = clone $this;
    $clone->method = $method;
    return $clone;
  }

  /**
   * อ่าน Uri
   *
   * @return Uri
   */
  public function getUri()
  {
    if ($this->uri === null) {
      $this->uri = Uri::createFromGlobals();
    }
    return $this->uri;
  }

  /**
   * กำหนดค่า Uri
   *
   * @param Uri $uri
   * @param boolean $preserveHost
   * @return \static
   */
  public function withUri(UriInterface $uri, $preserveHost = false)
  {
    $clone = clone $this;
    $clone->uri = $uri;
    if (!$preserveHost) {
      if ($uri->getHost() !== '') {
        $clone->headers['Host'] = $uri->getHost();
      }
    } else {
      if ($this->uri->getHost() !== '' && (!$this->hasHeader('Host') || $this->getHeader('Host') === null)) {
        $clone->headers['Host'] = $uri->getHost();
      }
    }
    return $clone;
  }

  /**
   * สร้างคลาสจากลิงค์ และ รวมค่าที่มาจาก $_GET ด้วย
   *
   * @param string $uri ค่าเริ่มต้นคือ index.php
   * @param array $exclude รายการแอเรย์ของ $_GET ที่ไม่ต้องการให้รวมอยู่ใน URL
   * @return \static
   */
  public static function createUriWithGet($uri = 'index.php', $exclude = array())
  {
    $query = array();
    foreach ($_GET as $key => $value) {
      if ($value != '' && !in_array($key, $exclude)) {
        $query[$key] = $key.'='.$value;
      }
    }
    if (!empty($query)) {
      $uri .= (strpos($uri, '?') === false ? '?' : '&').implode('&', $query);
    }
    return Uri::createFromUri($uri);
  }

  /**
   * สร้างคลาสจากลิงค์ และ รวมค่าที่มาจาก $_POST ด้วย
   *
   * @param string $uri ค่าเริ่มต้นคือ index.php
   * @param array $exclude รายการแอเรย์ของ $_POST ที่ไม่ต้องการให้รวมอยู่ใน URL
   * @return \static
   */
  public static function createUriWithPost($uri = 'index.php', $exclude = array())
  {
    $query = array();
    foreach ($_POST as $key => $value) {
      if ($value != '' && !in_array($key, $exclude)) {
        $query[$key] = $key.'='.$value;
      }
    }
    if (!empty($query)) {
      $uri .= (strpos($uri, '?') === false ? '?' : '&').implode('&', $query);
    }
    return Uri::createFromUri($uri);
  }

  /**
   * สร้างคลาสจากลิงค์ และ รวมค่าที่มาจาก $_GET และ $_POST ด้วย
   *
   * @param string $uri ค่าเริ่มต้นคือ index.php
   * @param array $exclude รายการแอเรย์ของ $_GET และ $_POST ที่ไม่ต้องการให้รวมอยู่ใน URL
   * @return \static
   */
  public function createUriWithGlobals($uri = 'index.php', $exclude = array())
  {
    $query = array();
    foreach ($_GET as $key => $value) {
      if ($value != '' && !in_array($key, $exclude)) {
        $query[$key] = $key.'='.$value;
      }
    }
    foreach ($_POST as $key => $value) {
      if ($value != '' && !in_array($key, $exclude)) {
        $query[$key] = $key.'='.$value;
      }
    }
    if (!empty($query)) {
      $uri .= (strpos($uri, '?') === false ? '?' : '&').implode('&', $query);
    }
    return Uri::createFromUri($uri);
  }
}