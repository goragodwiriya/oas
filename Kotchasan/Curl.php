<?php
/**
 * @filesource Kotchasan/Curl.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Curl Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.1
 */
class Curl
{
  /**
   * พารามิเตอร์ CURLOPT
   *
   * @var array
   */
  protected $options = array();
  /**
   * HTTP headers
   *
   * @var array
   */
  protected $headers = array();
  /**
   * ตัวแปรสำหรับเก็บ Error ที่มาจาก cURL
   * 0 ไม่มี error (ค่าเริ่มต้น)
   * มากกว่า 0 Error No. ของ cURL
   *
   * @var int
   */
  protected $error = 0;
  /**
   * ข้อความ Error จาก cURL หากมีข้อผิดพลาดในการส่ง
   *
   * @var string
   */
  protected $errorMessage = '';

  /**
   * Construct
   *
   * @throws \ErrorException ถ้าไม่รองรับ cURL
   */
  public function __construct()
  {
    if (!extension_loaded('curl')) {
      throw new \ErrorException('cURL library is not loaded');
    }
    // default parameter
    $this->headers = array(
      'Connection' => 'keep-alive',
      'Keep-Alive' => '300',
      'Accept-Charset' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7',
      'Accept-Language' => 'en-us,en;q=0.5'
    );
    $this->options = array(
      CURLOPT_TIMEOUT => 30,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERAGENT => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_SSL_VERIFYPEER => false
    );
  }

  /**
   * คืนค่า error no จากการ cURL
   * 0 หมายถึงไม่มี error
   *
   * @return int
   * */
  function error()
  {
    return $this->error;
  }

  /**
   * คืนค่าข้อความ Error จาก cURL หากมีข้อผิดพลาดในการส่ง
   *
   * @return string
   * */
  function errorMessage()
  {
    return $this->errorMessage;
  }

  /**
   * DELETE
   *
   * @param string $url
   * @param mix $params
   * @return string
   */
  public function delete($url, $params)
  {
    $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    if (is_array($params)) {
      $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, NULL, '&');
    } else {
      $this->options[CURLOPT_POSTFIELDS] = $params;
    }
    return $this->execute($url);
  }

  /**
   * GET
   *
   * @param string $url
   * @param mix $params
   * @return string
   */
  public function get($url, $params = array())
  {
    $this->options[CURLOPT_CUSTOMREQUEST] = 'GET';
    $this->options[CURLOPT_HTTPGET] = true;
    if (is_array($params)) {
      $url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($params, NULL, '&');
    } else {
      $this->options[CURLOPT_POSTFIELDS] = $params;
    }
    return $this->execute($url);
  }

  /**
   * HEAD
   *
   * @param string $url
   * @param mix $params
   * @return string
   */
  public function head($url, $params = array())
  {
    $this->options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
    $this->options[CURLOPT_NOBODY] = true;
    if (is_array($params)) {
      $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, NULL, '&');
    } else {
      $this->options[CURLOPT_POSTFIELDS] = $params;
    }
    return $this->execute($url);
  }

  /**
   * POST
   *
   * @param string $url
   * @param mix $params
   * @return string
   */
  public function post($url, $params = array())
  {
    $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
    $this->options[CURLOPT_POST] = true;
    if (is_array($params)) {
      $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, NULL, '&');
    } else {
      $this->options[CURLOPT_POSTFIELDS] = $params;
    }
    return $this->execute($url);
  }

  /**
   * PUT
   *
   * @param string $url
   * @param mix $params
   * @return string
   */
  public function put($url, $params = array())
  {
    $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
    if (is_array($params)) {
      $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, NULL, '&');
    } else {
      $this->options[CURLOPT_POSTFIELDS] = $params;
    }
    return $this->execute($url);
  }

  /**
   * กำหนด referer
   *
   * @param string $referrer
   * @return $this
   */
  public function referer($referrer)
  {
    $this->options[CURLOPT_REFERER] = $referrer;
    return $this;
  }

  /**
   * ใช้งาน PROXY
   *
   * @param string $url
   * @param int $port
   * @param string $username
   * @param string $password
   * @return $this
   */
  public function httpproxy($url = '', $port = 80, $username = null, $password = null)
  {
    $this->options[CURLOPT_HTTPPROXYTUNNEL] = true;
    $this->options[CURLOPT_PROXY] = $url.':'.$port;
    if ($username !== null && $password !== null) {
      $this->options[CURLOPT_PROXYUSERPWD] = $username.':'.$password;
    }
    return $this;
  }

  /**
   * Login สำหรับการส่งแบบ HTTP
   *
   * @param string $username
   * @param string $password
   * @param string $type any (default), digest, basic, digest_ie, negotiate, ntlm, ntlm_wb, anysafe, only
   * @return $this
   */
  public function httpauth($username = '', $password = '', $type = 'any')
  {
    $this->options[CURLOPT_HTTPAUTH] = constant('CURLAUTH_'.strtoupper($type));
    $this->options[CURLOPT_USERPWD] = $username.':'.$password;
    return $this;
  }

  /**
   * กำหนดค่า cookie file
   *
   * @param string $cookiePath
   * @return $this
   */
  public function setCookie($cookiePath)
  {
    $this->options[CURLOPT_COOKIEFILE] = $cookiePath;
    $this->options[CURLOPT_COOKIEJAR] = $cookiePath;
    return $this;
  }

  /**
   * กำหนด Header
   *
   * @param array $headers
   * @return $this
   */
  public function setHeaders($headers)
  {
    foreach ($headers as $key => $value) {
      $this->headers[$key] = $value;
    }
    return $this;
  }

  /**
   * กำหนด Options
   *
   * @param array $options
   * @return $this
   */
  public function setOptions($options)
  {
    foreach ($options as $key => $value) {
      $this->options[$key] = $value;
    }
    return $this;
  }

  /**
   * ประมวลผล cURL
   *
   * @param string $url
   * @return string
   */
  protected function execute($url)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($this->headers)) {
      $headers = array();
      foreach ($this->headers as $key => $value) {
        $headers[] = $key.': '.$value;
      }
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    foreach ($this->options as $key => $value) {
      curl_setopt($ch, $key, $value);
    }
    $response = curl_exec($ch);
    if (curl_error($ch)) {
      $this->error = curl_errno($ch);
      $this->errorMessage = curl_error($ch);
    }
    curl_close($ch);
    return $response;
  }
}