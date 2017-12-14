<?php
/**
 * @filesource Kotchasan/Http/Request.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Http;

use \Psr\Http\Message\RequestInterface;
use \Kotchasan\InputItem;
use \Kotchasan\Inputs;
use \Kotchasan\Files;

/**
 * คลาสสำหรับจัดการตัวแปรต่างๆจาก Server
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Request extends AbstractRequest implements RequestInterface
{
  /**
   * @var array
   */
  private $serverParams;
  /**
   * @var array
   */
  private $cookieParams;
  /**
   * @var array
   */
  private $queryParams;
  /**
   * @var array
   */
  private $parsedBody;
  /**
   * @var Files
   */
  private $uploadedFiles;
  /**
   * @var array
   */
  private $attributes = array();

  /**
   * คืนค่าจากตัวแปร SERVER
   *
   * @return array
   */
  public function getServerParams()
  {
    if ($this->serverParams === null) {
      $this->serverParams = $_SERVER;
    }
    return $this->serverParams;
  }

  /**
   * คืนค่าจากตัวแปร COOKIE
   *
   * @return array
   */
  public function getCookieParams()
  {
    if ($this->cookieParams === null) {
      $this->cookieParams = $_COOKIE;
    }
    return $this->cookieParams;
  }

  /**
   * กำหนดค่า cookieParams
   *
   * @param array $cookies
   */
  public function withCookieParams(array $cookies)
  {
    $clone = clone $this;
    $clone->cookieParams = $cookies;
    return $clone;
  }

  /**
   * คืนค่าจากตัวแปร GET
   *
   * @return null|array|object
   */
  public function getQueryParams()
  {
    if ($this->queryParams === null) {
      $this->queryParams = $this->normalize($_GET);
    }
    return $this->queryParams;
  }

  /**
   * กำหนดค่า queryParams
   *
   * @param array $query
   * @return \static
   */
  public function withQueryParams(array $query)
  {
    $clone = clone $this;
    $clone->queryParams = $query;
    return $clone;
  }

  /**
   * คืนค่าจากตัวแปร POST
   *
   * @return null|array|object
   */
  public function getParsedBody()
  {
    if ($this->parsedBody === null) {
      $this->parsedBody = $this->normalize($_POST);
    }
    return $this->parsedBody;
  }

  /**
   * กำหนดค่า parsedBody
   *
   * @param null|array|object $data
   */
  public function withParsedBody($data)
  {
    $clone = clone $this;
    $clone->parsedBody = $data;
    return $clone;
  }

  /**
   * คืนค่าไฟล์อัปโหลด FILES
   *
   * @return Files
   */
  public function getUploadedFiles()
  {
    if ($this->uploadedFiles === null) {
      $this->uploadedFiles = new Files;
      if (isset($_FILES)) {
        foreach ($_FILES as $name => $file) {
          if (is_array($file['name'])) {
            foreach ($file['name'] as $key => $value) {
              $this->uploadedFiles->add($key, $file['tmp_name'][$key], $value, $file['type'][$key], $file['size'][$key], $file['error'][$key]);
            }
          } else {
            $this->uploadedFiles->add($name, $file['tmp_name'], $file['name'], $file['type'], $file['size'], $file['error']);
          }
        }
      }
    }
    return $this->uploadedFiles;
  }

  /**
   * กำหนดค่า uploadedFiles
   *
   * @param array $uploadedFiles
   * @return \static
   */
  public function withUploadedFiles(array $uploadedFiles)
  {
    $clone = clone $this;
    $clone->uploadedFiles = $uploadedFiles;
    return $clone;
  }

  /**
   * คืนค่า attributes ทั้งหมด
   *
   * @return array
   */
  public function getAttributes()
  {
    return $this->attributes;
  }

  /**
   * อ่านค่า attributes ที่ต้องการ
   *
   * @param string $name ชื่อของ attributes
   * @param mixed $default คืนค่า $default ถ้าไม่พบ
   * @return mixed
   */
  public function getAttribute($name, $default = null)
  {
    return isset($this->attributes[$name]) ? $this->attributes[$name] : $default;
  }

  /**
   * กำหนดค่า attributes
   *
   * @param string $name ชื่อของ attributes
   * @param mixed $value ค่าของ attribute
   * @return \static
   */
  public function withAttribute($name, $value)
  {
    $clone = clone $this;
    $clone->attributes[$name] = $value;
    return $clone;
  }

  /**
   * ลบ attributes
   *
   * @param string $name ชื่อของ attributes
   * @return \static
   */
  public function withoutAttribute($name)
  {
    $clone = clone $this;
    unset($clone->attributes[$name]);
    return $clone;
  }

  /**
   * อ่านค่าจากตัวแปร GET
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  public function get($name, $default = null)
  {
    return $this->createInputItem($this->getQueryParams(), $name, $default, 'GET');
  }

  /**
   * อ่านค่าจากตัวแปร POST
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|array InputItem หรือ แอเรย์ของ InputItem
   */
  public function post($name, $default = null)
  {
    return $this->createInputItem($this->getParsedBody(), $name, $default, 'POST');
  }

  /**
   * อ่านค่าจากตัวแปร POST GET COOKIE ตามลำดับ
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  public function request($name, $default = null)
  {
    return $this->globals(array('POST', 'GET', 'COOKIE'), $name, $default);
  }

  /**
   * อ่านค่าจากตัวแปร SESSION
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  public function session($name, $default = null)
  {
    return $this->createInputItem($_SESSION, $name, $default, 'SESSION');
  }

  /**
   * อ่านค่าจากตัวแปร GLOBALS $_POST $_GET $_SESSION $_COOKIE ตามลำดับ
   *
   * @param array $keys ชื่อตัวแปรที่ต้องการอ่าน ตัวพิมพ์ใหญ่ เช่น array('POST', 'GET') หมายถึงอ่านค่าจาก $_POST ก่อน
   * ถ้าไม่พบจะอ่านจาก $_GET และถ้าไม่พบอีกจะใช้ค่า $default
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  public function globals($keys, $name, $default = null)
  {
    foreach ($keys as $key) {
      if ($key == 'POST') {
        $datas = $this->getParsedBody();
      } elseif ($key == 'GET') {
        $datas = $this->getQueryParams();
      } elseif ($key == 'SESSION') {
        $datas = $_SESSION;
      } elseif ($key == 'COOKIE') {
        $datas = $this->getCookieParams();
      }
      if (isset($datas[$name])) {
        return is_array($datas[$name]) ? new Inputs($datas[$name], $key) : new InputItem($datas[$name], $key);
      }
    }
    return is_array($default) ? new Inputs($default) : new InputItem($default);
  }

  /**
   * กำหนดค่าตัวแปร SESSION
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $value ค่าของตัวแปร
   * @return \static
   */
  public function setSession($name, $value)
  {
    $_SESSION[$name] = $value;
    return $this;
  }

  /**
   * อ่านค่าจากตัวแปร COOKIE
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  public function cookie($name, $default = '')
  {
    return $this->createInputItem($this->getCookieParams(), $name, $default, 'COOKIE');
  }

  /**
   * อ่านค่าจากตัวแปร SERVER
   *
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return \static
   */
  public function server($name, $default = null)
  {
    return isset($_SERVER[$name]) ? $_SERVER[$name] : $default;
  }

  /**
   * ฟังก์ชั่น อ่าน ip ของ client
   *
   * @return string|null IP ที่อ่านได้
   */
  public function getClientIp()
  {
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
      return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
      return $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $IParray = array_filter(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
      return $IParray[0];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
      return $_SERVER['REMOTE_ADDR'];
    }
    return null;
  }

  /**
   * ฟังก์ชั่นเริ่มต้นใช้งาน session
   */
  public function initSession()
  {
    $sessid = $this->get('sessid')->toString();
    if (!empty($sessid) && preg_match('/[a-zA-Z0-9]{20,}/', $sessid)) {
      session_id($sessid);
    }
    session_start();
    if (!ob_get_status()) {
      if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        // เปิดใช้งานการบีบอัดหน้าเว็บไซต์
        ob_start('ob_gzhandler');
      } else {
        ob_start();
      }
    }
    return true;
  }

  /**
   * ฟังก์ชั่นสร้าง token
   *
   * @return string
   */
  public function createToken()
  {
    $token = md5(uniqid(rand(), true));
    $_SESSION[$token] = 0;
    return $token;
  }

  /**
   * ลบ token
   */
  public function removeToken()
  {
    $token = $this->globals(array('POST', 'GET'), 'token', null)->toString();
    if ($token !== null) {
      unset($_SESSION[$token]);
    }
  }

  /**
   * ฟังก์ชั่น ตรวจสอบ token ที่มาจากฟอร์ม และ ตรวจสอบ Referer ด้วย
   * รับค่าที่มาจาก $_POST เท่านั้น
   * ฟังก์ชั่นนี้ต้องเรียกต่อจาก initSession() เสมอ
   * อายุของ token กำหนดที่ TOKEN_LIMIT
   *
   * @return boolean คืนค่า true ถ้า token ถูกต้องและไม่หมดอายุ
   */
  public function isSafe()
  {
    $token = $this->post('token')->toString();
    if ($token !== null) {
      if (isset($_SESSION[$token]) && $_SESSION[$token] < TOKEN_LIMIT && $this->isReferer()) {
        $_SESSION[$token] ++;
        return true;
      } else {
        unset($_SESSION[$token]);
      }
    }
    return false;
  }

  /**
   * ฟังก์ชั่น ตรวจสอบ referer
   *
   * @return boolean คืนค่า true ถ้า referer มาจากเว็บไซต์นี้
   */
  public function isReferer()
  {
    $host = empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] : $_SERVER['HTTP_HOST'];
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if (preg_match("/$host/ui", $referer)) {
      return true;
    } elseif (preg_match('/^(http(s)?:\/\/)(.*)(\/.*){0,}$/U', WEB_URL, $match)) {
      return preg_match("/$match[3]/ui", $referer);
    } else {
      return false;
    }
  }

  /**
   * ตรวจสอบว่าเรียกมาโดย Ajax หรือไม่
   *
   * @return boolean true ถ้าเรียกมาจาก Ajax (XMLHttpRequest)
   */
  public function isAjax()
  {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
  }

  /**
   * remove slashes (/)
   *
   * @param array $vars ตัวแปร Global เช่น POST GET
   */
  private function normalize($vars)
  {
    if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
      return $this->stripSlashes($vars);
    }
    return $vars;
  }

  /**
   * ฟังก์ชั่น remove slashes (/)
   *
   * @param array $datas
   * @return array
   */
  private function stripSlashes($datas)
  {
    if (is_array($datas)) {
      foreach ($datas as $key => $value) {
        $datas[$key] = $this->stripSlashes($value);
      }
      return $datas;
    }
    return stripslashes($datas);
  }

  /**
   * อ่านค่าจาก $source
   *
   * @param array $source ตัวแปร GET POST
   * @param string $name ชื่อตัวแปร
   * @param mixed $default ค่าเริ่มต้นหากไม่พบตัวแปร
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   * @param string|null $type ประเภท Input เช่น GET POST SESSION COOKIE หรือ null ถ้าไม่ได้มาจากรายการข้างต้น
   * @return InputItem|Inputs InputItem หรือ Collection ของ InputItem
   */
  private function createInputItem($source, $name, $default, $type)
  {
    if (isset($source[$name])) {
      return is_array($source[$name]) ? new Inputs($source[$name], $type) : new InputItem($source[$name], $type);
    } elseif (preg_match('/(.*)\[(.*)\]/', $name, $match) && isset($source[$match[1]][$match[2]])) {
      return new InputItem($source[$match[1]][$match[2]], $type);
    } else {
      return is_array($default) ? new Inputs($default) : new InputItem($default);
    }
  }
}