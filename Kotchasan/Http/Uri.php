<?php
/**
 * @filesource Kotchasan/Http/Uri.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Http;

use \Psr\Http\Message\UriInterface;

/**
 * Class สำหรับจัดการ Uri (PSR-7)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Uri extends \Kotchasan\KBase implements UriInterface
{
  /**
   * Uri scheme
   *
   * @var string
   */
  protected $scheme = '';
  /**
   * Uri user info
   *
   * @var string
   */
  protected $userInfo = '';
  /**
   * Uri host
   *
   * @var string
   */
  protected $host = '';
  /**
   * Uri port
   *
   * @var int
   */
  protected $port;
  /**
   * Uri path
   *
   * @var string
   */
  protected $path = '';
  /**
   * Uri query string หลัง ?
   *
   * @var string
   */
  protected $query = '';
  /**
   * Uri fragment หลัง  #
   *
   * @var string
   */
  protected $fragment = '';

  /**
   * Create a new Uri.
   *
   * @param string $uri
   * @throws \InvalidArgumentException ถ้า Uri ไม่ถูกต้อง
   */
  public function __construct($scheme, $host, $path = '/', $query = '', $port = null, $user = '', $pass = '', $fragment = '')
  {
    $this->scheme = $this->filterScheme($scheme);
    $this->host = $host;
    $this->path = $path;
    $this->query = $this->filterQueryFragment($query);
    $this->port = $this->filterPort($this->scheme, $this->host, $port) ? $port : null;
    $this->userInfo = $user.($pass === '' ? '' : ':'.$pass);
    $this->fragment = $this->filterQueryFragment($fragment);
  }

  /**
   * magic function ส่งออกคลาสเป็น String
   *
   * @return string
   */
  public function __toString()
  {
    return self::createUriString(
        $this->scheme, $this->getAuthority(), $this->path, $this->query, $this->fragment
    );
  }

  /**
   * สร้างคลาสจากลิงค์
   *
   * @param string $uri
   * @return Uri
   * @throws \InvalidArgumentException ถ้า $uri ไม่ถูกต้อง
   */
  public static function createFromUri($uri)
  {
    $parts = parse_url($uri);
    if (false === $parts) {
      throw new \InvalidArgumentException('Invalid Uri');
    } else {
      $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
      $host = isset($parts['host']) ? $parts['host'] : '';
      $port = isset($parts['port']) ? $parts['port'] : null;
      $user = isset($parts['user']) ? $parts['user'] : '';
      $pass = isset($parts['pass']) ? $parts['pass'] : '';
      $path = isset($parts['path']) ? $parts['path'] : '';
      $query = isset($parts['query']) ? $parts['query'] : '';
      $fragment = isset($parts['fragment']) ? $parts['fragment'] : '';
      return new static($scheme, $host, $path, $query, $port, $user, $pass, $fragment);
    }
  }

  /**
   * สร้าง Uri จากตัวแปร $_SERVER
   *
   * @return Uri
   * @throws \InvalidArgumentException ถ้า Uri ไม่ถูกต้อง
   */
  public static function createFromGlobals()
  {
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'].'://';
    } elseif ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
      $scheme = 'https://';
    } else {
      $scheme = 'http://';
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
      $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
    } elseif (empty($_SERVER['HTTP_HOST'])) {
      $host = $_SERVER['SERVER_NAME'];
    } else {
      $host = $_SERVER['HTTP_HOST'];
    }
    $pos = strpos($host, ':');
    if ($pos !== false) {
      $port = (int)substr($host, $pos + 1);
      $host = strstr($host, ':', true);
    } else {
      $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80;
    }
    $path = empty($_SERVER['REQUEST_URI']) ? '/' : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    $user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
    $pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
    return new static($scheme, $host, $path, $query, $port, $user, $pass);
  }

  /**
   * คืนค่า scheme ของ Uri ไม่รวม :// เช่น http, https
   *
   * @return string
   */
  public function getScheme()
  {
    return $this->scheme;
  }

  /**
   * ตืนค่า authority ของ Uri [user-info@]host[:port]
   *
   * @return string
   */
  public function getAuthority()
  {
    return ($this->userInfo ? $this->userInfo.'@' : '').$this->host.($this->port !== null ? ':'.$this->port : '');
  }

  /**
   * คืนค่าข้อมูล user ของ Uri user[:password]
   *
   * @return string
   */
  public function getUserInfo()
  {
    return $this->userInfo;
  }

  /**
   * คืนค่า Hostname ของ Uri เช่น domain.tld
   *
   * @return string
   */
  public function getHost()
  {
    return $this->host;
  }

  /**
   * คืนค่าหมายเลข Port ของ Uri
   * ไม่ระบุหรือเป็น default port (80,433) คืนค่า null
   *
   * @return null|int
   */
  public function getPort()
  {
    return $this->port;
  }

  /**
   * คืนค่า path ของ Uri เช่น /kotchasan
   *
   * @return string
   */
  public function getPath()
  {
    return $this->path;
  }

  /**
   * คืนค่า query string (ข้อมูลหลัง ? ใน Uri) ของ Uri
   *
   * @return string
   */
  public function getQuery()
  {
    return $this->query;
  }

  /**
   * คืนค่า fragment (ข้อมูลหลัง # ใน Uri) ของ Uri
   *
   * @return string
   */
  public function getFragment()
  {
    return $this->fragment;
  }

  /**
   * กำหนดค่า scheme ของ Uri
   *
   * @param string $scheme http หรือ https หรือค่าว่าง
   * @return Uri คืนค่า Object ใหม่
   * @throws \InvalidArgumentException ถ้าไม่ใช่ ค่าว่าง http หรือ https
   */
  public function withScheme($scheme)
  {
    $clone = clone $this;
    $clone->scheme = $this->filterScheme($scheme);
    return $clone;
  }

  /**
   * กำหนดข้อมูล user ของ Uri
   *
   * @param string $user
   * @param string $password
   * @return Uri คืนค่า Object ใหม่
   */
  public function withUserInfo($user, $password = null)
  {
    $clone = clone $this;
    $clone->userInfo = $user.($password ? ':'.$password : '');
    return $clone;
  }

  /**
   * กำหนดชื่อ host
   *
   * @param string $host ชื่อ host
   * @return Uri คืนค่า Object ใหม่
   */
  public function withHost($host)
  {
    $clone = clone $this;
    $clone->host = $host;
    return $clone;
  }

  /**
   * กำหนดค่า port
   *
   * @param null|int $port หมายเลข port 1- 65535 หรือ null
   * @return Uri คืนค่า Object ใหม่
   * @throws \InvalidArgumentException ถ้า port ไม่ถูกต้อง
   */
  public function withPort($port)
  {
    $clone = clone $this;
    $clone->port = $this->filterPort($this->scheme, $this->host, $port);
    return $clone;
  }

  /**
   * กำหนดชื่อ path
   * path ต้องเริ่มต้นด้วย / เช่น /kotchasan
   * หรือเป็นค่าว่าง ถ้าเป็นรากของโดเมน
   *
   * @param string $path ชื่อ path
   * @return Uri คืนค่า Object ใหม่
   */
  public function withPath($path)
  {
    $clone = clone $this;
    $clone->path = $this->filterPath($path);
    return $clone;
  }

  /**
   * กำหนดค่า query string
   *
   * @param string $query
   * @return Uri คืนค่า Object ใหม่
   * @throws \InvalidArgumentException ถ้า query string ไม่ถูกต้อง
   */
  public function withQuery($query)
  {
    if (!is_string($query) && !method_exists($query, '__toString')) {
      throw new \InvalidArgumentException('Uri query must be a string');
    }
    $query = ltrim((string)$query, '?');
    $clone = clone $this;
    $clone->query = $this->filterQueryFragment($query);
    return $clone;
  }

  /**
   * กำหนดค่า fragment
   *
   * @param string $fragment
   * @return Uri คืนค่า Object ใหม่
   * @throws \InvalidArgumentException ถ้า fragment ไม่ถูกต้อง
   */
  public function withFragment($fragment)
  {
    if (!is_string($fragment) && !method_exists($fragment, '__toString')) {
      throw new \InvalidArgumentException('Uri fragment must be a string');
    }
    $fragment = ltrim((string)$fragment, '#');
    $clone = clone $this;
    $clone->fragment = $this->filterQueryFragment($fragment);
    return $clone;
  }

  /**
   * ตรวจสอบ port
   *
   * @param string $scheme
   * @param string $host
   * @param int $port
   * @return int|null
   * @throws \InvalidArgumentException ถ้า port ไม่ถูกต้อง
   */
  private function filterPort($scheme, $host, $port)
  {
    if (null !== $port) {
      $port = (int)$port;
      if (1 > $port || 0xffff < $port) {
        throw new \InvalidArgumentException('Port number must be between 1 and 65535');
      }
    }
    return $this->isNonStandardPort($scheme, $host, $port) ? $port : null;
  }

  /**
   * สร้าง Uri
   *
   * @param string $scheme
   * @param string $authority
   * @param string $path
   * @param string $query
   * @param string $fragment
   * @return string เช่น http://domain.tld/
   */
  private static function createUriString($scheme, $authority, $path, $query, $fragment)
  {
    $uri = '';
    if (!empty($scheme)) {
      $uri .= $scheme.'://';
    }
    if (!empty($authority)) {
      $uri .= $authority;
    }
    if ($path != null) {
      if ($uri && substr($path, 0, 1) !== '/') {
        $uri .= '/';
      }
      $uri .= $path;
    }
    if ($query != '') {
      $uri .= '?'.$query;
    }
    if ($fragment != '') {
      $uri .= '#'.$fragment;
    }
    return $uri;
  }

  /**
   * ตรวจสอบว่าเป็น port มาตรฐานหรือไม่
   * เช่น http เป็น 80 หรือ https เป็น 433
   *
   * @param string $scheme
   * @param string $host
   * @param int $port
   * @return bool
   */
  private function isNonStandardPort($scheme, $host, $port)
  {
    if (!$scheme && $port) {
      return true;
    }
    if (!$host || !$port) {
      return false;
    }
    return ($scheme != 'http' && $scheme != 'https') || ($port != 80 && $port != 443);
  }

  /**
   * ตรวจสอบ scheme
   *
   * @param string $scheme
   * @return string
   * @throws \InvalidArgumentException ถ้าไม่ใช่ ค่าว่าง http หรือ https
   */
  private function filterScheme($scheme)
  {
    $schemes = array('' => '', 'http' => 'http', 'https' => 'https');
    $scheme = rtrim(strtolower($scheme), ':/');
    if (isset($schemes[$scheme])) {
      return $scheme;
    } else {
      throw new \InvalidArgumentException('Uri scheme must be http, https or empty string');
    }
  }

  /**
   * ตรวจสอบ query และ fragment
   *
   * @param $str
   * @return string
   */
  private function filterQueryFragment($str)
  {
    return preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/', function ($match) {
      return rawurlencode($match[0]);
    }, $str);
  }

  /**
   * ตรวจสอบ path
   *
   * @param $path
   * @return string
   */
  private function filterPath($path)
  {
    return preg_replace_callback('/(?:[^a-zA-Z0-9_\-\.~:@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/', function ($match) {
      return rawurlencode($match[0]);
    }, $path);
  }

  /**
   * ฟังก์ชั่นสร้าง URL สำหรับส่งต่อ Query string จากหน้าหนึ่งไปยังอีกหน้าหนึ่ง
   * เพื่อให้สามารถสร้าง URL ที่สามารถส่งกลับไปยังหน้าเดิมได้โดย ฟังก์ชั่น back()
   * ลบรายการที่ เป็น null ออก
   *
   * @param array $query_string
   * @return string
   */
  public function createBackUri($query_string)
  {
    $qs = array();
    foreach ($this->parseQueryParams($this->query) as $key => $value) {
      $key = ltrim($key, '_');
      if ($key != 'token' && key_exists($key, $query_string) && $query_string[$key] === null) {
        continue;
      } elseif (preg_match('/^[0-9]+$/', $key)) {
        continue;
      }
      if ($value !== null) {
        $qs['_'.$key] = rawurlencode($value);
      }
    }
    foreach ($query_string as $key => $value) {
      if ($value !== null) {
        $qs[$key] = $value;
      }
    }
    return $this->withQuery($this->paramsToQuery($qs, true));
  }

  /**
   * ฟังก์ชั่น แยก Querystring ออกเป็น array
   *
   * @param string $query
   * @return array
   */
  public function parseQueryParams($query)
  {
    $result = array();
    if (!empty($query)) {
      foreach (explode('&', str_replace('&amp;', '&', $query)) as $item) {
        if (preg_match('/^(.*)=(.*)?$/', $item, $match)) {
          $result[$match[1]] = $match[2];
        } else {
          $result[$item] = null;
        }
      }
    }
    return $result;
  }

  /**
   * ฟังก์ชั่นแปลง Queryparams เป็น Querystring
   *
   * @param array $params
   * @param boolean $encode false เชื่อม Querystring ด้วย &, true  เชื่อม Querystring ด้วย &amp;
   * @return string
   */
  public function paramsToQuery($params, $encode)
  {
    $qs = array();
    foreach ($params as $key => $value) {
      if ($value === null) {
        $qs[$key] = $key;
      } else {
        $qs[$key] = $key.'='.$value;
      }
    }
    return implode($encode ? '&amp;' : '&', $qs);
  }

  /**
   * ฟังก์ชั่นแทนที่ Query params ลงใน URL
   *
   * @param array $params
   * @param boolean $encode false (default) เชื่อม Querystring ด้วย &, true  เชื่อม Querystring ด้วย &amp;
   * @return Uri
   */
  public function withParams($params, $encode = false)
  {
    $qs = array();
    foreach ($this->parseQueryParams($this->query) as $key => $value) {
      $qs[$key] = $value;
    }
    foreach ($params as $key => $value) {
      $qs[$key] = $value;
    }
    return $this->withQuery($this->paramsToQuery($qs, $encode));
  }

  /**
   * ฟังก์ชั่นแสดงผลตัวแบ่งหน้า
   *
   * @param int $totalpage จำนวนหน้าทั้งหมด
   * @param int $page หน้าปัจจุบัน
   * @param int $maxlink (optional) จำนวนตัวเลือกแบ่งหน้าสูงสุด ค่าปกติ 9
   * @return string
   */
  public function pagination($totalpage, $page, $maxlink = 9)
  {
    if ($totalpage > $maxlink) {
      $start = $page - floor($maxlink / 2);
      if ($start < 1) {
        $start = 1;
      } elseif ($start + $maxlink > $totalpage) {
        $start = $totalpage - $maxlink + 1;
      }
    } else {
      $start = 1;
    }
    $url = '<a href="'.$this->withParams(array('page' => ':page'), true).'" title="{LNG_go to page} :page">:page</a>';
    $splitpage = ($start > 2) ? str_replace(':page', 1, $url) : '';
    for ($i = $start; $i <= $totalpage && $maxlink > 0; $i++) {
      $splitpage .= ($i == $page) ? '<strong title="{LNG_Showing page} '.$i.'">'.$i.'</strong>' : str_replace(':page', $i, $url);
      $maxlink--;
    }
    $splitpage .= ($i < $totalpage) ? str_replace(':page', $totalpage, $url) : '';
    return empty($splitpage) ? '<strong>1</strong>' : $splitpage;
  }

  /**
   * แปลง POST เป็น query string สำหรับการส่งกลับไปหน้าเดิม ที่มาจากการโพสต์ด้วยฟอร์ม
   *
   * @param string $url URL ที่ต้องการส่งกลับ เช่น index.php
   * @param array $query_string (option) query string ที่ต้องการส่งกลับไปด้วย array('key' => 'value', ...)
   * @return string URL+query string
   */
  public function postBack($url, $query_string = array())
  {
    return $this->createBack($url, $_POST, $query_string);
  }

  /**
   * แปลง GET เป็น query string สำหรับการส่งกลับไปหน้าเดิม ที่มาจากการโพสต์ด้วยฟอร์ม
   *
   * @param string $url URL ที่ต้องการส่งกลับ เช่น index.php
   * @param array $query_string (option) query string ที่ต้องการส่งกลับไปด้วย array('key' => 'value', ...)
   * @return string URL+query string
   */
  public function getBack($url, $query_string = array())
  {
    return $this->createBack($url, $_GET, $query_string);
  }

  /**
   * แปลง POST เป็น query string สำหรับการส่งกลับไปหน้าเดิม ที่มาจากการโพสต์ด้วยฟอร์ม
   *
   * @param string $url URL ที่ต้องการส่งกลับ เช่น index.php
   * @param array $source query string จาก $_POST หรือ $_GET
   * @param array $query_string query string ที่ต้องการส่งกลับไปด้วย array('key' => 'value', ...)
   * @return string URL+query string
   */
  private function createBack($url, $source, $query_string)
  {
    foreach ($source as $key => $value) {
      if ($value !== '' && preg_match('/^_{1,}(.*)$/', $key, $match)) {
        if (!isset($query_string[$match[1]])) {
          $query_string[$match[1]] = $value;
        }
      }
    }
    if (isset($query_string['time'])) {
      $query_string['time'] = time();
    }
    $qs = array();
    foreach ($query_string as $key => $value) {
      if ($value !== null) {
        $qs[$key] = $key.'='.$value;
      }
    }
    return $url.(strpos($url, '?') === false ? '?' : '&').implode('&', $qs);
  }
}