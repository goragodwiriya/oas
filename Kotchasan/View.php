<?php
/**
 * @filesource Kotchasan/View.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Template;

/**
 * View base class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Kotchasan\KBase
{
  /**
   * ตัวแปรเก็บเนื่อหาของเว็บไซต์
   *
   * @var array
   */
  protected $contents = array();
  /**
   * meta tag
   *
   * @var array
   */
  protected $metas = array();
  /**
   * รายการ header
   *
   * @var array
   */
  protected $headers = array();
  /**
   * ตัวแปรเก็บเนื่อหาของเว็บไซต์ ที่จะแทนที่หลังจาก render แล้ว
   *
   * @var array
   */
  protected $after_contents = array();

  /**
   * ใส่เนื้อหาลงใน $contens
   *
   * @param array $array ชื่อที่ปรากฏใน template รูปแบบ array(key1 => val1, key2 => val2)
   */
  public function setContents($array)
  {
    foreach ($array as $key => $value) {
      $this->contents[$key] = $value;
    }
  }

  /**
   * ใส่เนื้อหาลงใน $contens หลัง render แล้ว
   *
   * @param array $array ชื่อที่ปรากฏใน template รูปแบบ array(key1 => val1, key2 => val2)
   */
  public function setContentsAfter($array)
  {
    foreach ($array as $key => $value) {
      $this->after_contents[$key] = $value;
    }
  }

  /**
   * ใส่ Tag ลงใน Head ของ HTML
   *
   * @param array $array
   */
  public function setMetas($array)
  {
    foreach ($array as $key => $value) {
      $this->metas[$key] = $value;
    }
  }

  /**
   * ใส่ไฟล์ Javascript ลงใน header
   *
   * @param string $url
   */
  public function addJavascript($url)
  {
    $this->metas[$url] = '<script src="'.$url.'"></script>';
  }

  /**
   * ใส่ไฟล์ CSS ลงใน header
   *
   * @param string $url
   */
  public function addCSS($url)
  {
    $this->metas[$url] = '<link rel=stylesheet href="'.$url.'">';
  }

  /**
   * กำหนด header ให้กับเอกสาร
   *
   * @param array $array
   */
  public function setHeaders($array)
  {
    foreach ($array as $key => $value) {
      $this->headers[$key] = $value;
    }
  }

  /**
   * ส่งออกเป็น HTML
   *
   * @param string|null $template HTML Template ถ้าไม่กำหนด (null) จะใช้ index.html
   */
  public function renderHTML($template = null)
  {
    // default for template
    $this->contents['/{WEBTITLE}/'] = self::$cfg->web_title;
    $this->contents['/{WEBDESCRIPTION}/'] = self::$cfg->web_description;
    $this->contents['/{WEBURL}/'] = WEB_URL;
    $this->contents['/{SKIN}/'] = Template::get();
    $this->contents['/^[\s\t]+/m'] = '';
    foreach ($this->after_contents as $key => $value) {
      $this->contents[$key] = $value;
    }
    if (!empty($this->metas)) {
      $this->contents['/(<head.*)(<\/head>)/isu'] = '$1'.implode("\n", $this->metas).'$2';
    }
    // แทนที่ลงใน Template
    if ($template === null) {
      // ถ้าไม่ได้กำหนดมาใช้ index.html
      $template = Template::load('', '', 'index');
    }
    return Template::pregReplace(array_keys($this->contents), array_values($this->contents), $template);
  }

  /**
   * ส่งออกเนื้อหา และ header ตามที่กำหนด
   *
   * @param string $content เนื้อหา
   */
  public function output($content)
  {
    // send header
    foreach ($this->headers as $key => $value) {
      header("$key: $value");
    }
    // output content
    echo $content;
  }

  /**
   * ฟังก์ชั่น แทนที่ query string ด้วยข้อมูลจาก GET สำหรับส่งต่อไปยัง URL ถัดไป
   *
   * @param array|string $f รับค่าจากตัวแปร $f มาสร้าง query string
   * array ส่งมาจาก preg_replace
   * string กำหนดเอง
   * @return string คืนค่า query string ใหม่ ลบ id=0
   * @assert (array(2 => 'module=retmodule&id=0')) [==] "http://localhost/?module=retmodule&amp;page=1&amp;sort=id"  [[$_SERVER['QUERY_STRING'] = '_module=test&1234&_page=1&_sort=id&action=login&id=1']]
   * @assert ('module=retmodule&5678') [==] "http://localhost/?module=retmodule&amp;page=1&amp;sort=id&amp;id=1&amp;5678"
   */
  public static function back($f)
  {
    $uri = self::$request->getUri();
    $query_url = array();
    foreach (explode('&', $uri->getQuery()) as $item) {
      if (preg_match('/^(_)?(.*)=([^$]{1,})$/', $item, $match)) {
        if ($match[2] == 'action' && ($match[3] == 'login' || $match[3] == 'logout')) {
          // ไม่ใช้รายการ action=login, action=logout
        } else {
          $query_url[$match[2]] = $match[3];
        }
      }
    }
    if (is_array($f)) {
      $f = isset($f[2]) ? $f[2] : null;
    }
    if (!empty($f)) {
      foreach (explode('&', $f) as $item) {
        if (preg_match('/^([a-zA-Z0-9_\-]+)(=(.*))?$/', $item, $match)) {
          if (!isset($match[3])) {
            // ไม่มี value
            $query_url[$match[1]] = null;
          } elseif ($match[3] === '0') {
            // ไม่ใช้รายการที่หลังเครื่องหมาย = เท่ากับ 0
            unset($query_url[$match[1]]);
          } else {
            $query_url[$match[1]] = $match[3];
          }
        }
      }
    }
    return (string)$uri->withQuery($uri->paramsToQuery($query_url, true));
  }
}