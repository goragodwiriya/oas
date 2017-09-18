<?php
/**
 * @filesource Kotchasan/Text.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * String functions
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Text
{

  /**
   * แทนที่ข้อความด้วยข้อมูลจากแอเรย์ รองรับข้อมูลรูปแบบแอเรย์ย่อยๆ
   *
   * @param string $source ข้อความต้นฉบับ
   * @param array $replace ข้อความที่จะนำมาแทนที่ รูปแบบ array($key1 => $value1, $key2 => $value2) ข้อความใน $source ที่ตรงกับ $key จะถูกแทนที่ด้วย $value
   * @return string
   *
   * @assert ("SELECT * FROM table WHERE id=:id AND lang IN (:lang, '')", array(':id' => 1, array(':lang' => 'th'))) [==] "SELECT * FROM table WHERE id=1 AND lang IN (th, '')"
   */
  public static function replace($source, $replace)
  {
    if (!empty($replace)) {
      $keys = array();
      $values = array();
      ArrayTool::extract($replace, $keys, $values);
      $source = str_replace($keys, $values, $source);
    }
    return $source;
  }

  /**
   * แปลง tag และ ลบช่องว่างไม่เกิน 1 ช่อง ไม่ขึ้นบรรทัดใหม่
   * เช่นหัวข้อของบทความ
   *
   * @param string $text
   * @return string
   *
   * @assert (' ทด\/สอบ'."\r\n\t".'<?php echo \'555\'?> ') [==] 'ทด&#92;/สอบ &lt;?php echo &#039;555&#039;?&gt;'
   */
  public static function topic($text)
  {
    return trim(preg_replace('/[\r\n\s\t]+/', ' ', self::htmlspecialchars($text)));
  }

  /**
   * แปลง tag ไม่แปลง &amp;
   * และลบช่องว่างหัวท้าย
   * สำหรับ URL หรือ email
   *
   * @return string
   *
   * @assert (" http://www.kotchasan.com?a=1&b=2&amp;c=3 ") [==] 'http://www.kotchasan.com?a=1&amp;b=2&amp;c=3'
   * @assert ("javascript:alert('xxx')") [==] 'alertxxx'
   * @assert ("http://www.xxx.com/javascript/") [==] 'http://www.xxx.com/javascript/'
   */
  public static function url($text)
  {
    $text = preg_replace('/(^javascript:|[\(\)\'\"]+)/', '', trim($text));
    return self::htmlspecialchars($text, false);
  }

  /**
   * ฟังก์ชั่นรับค่าสำหรับใช้เป็น username
   * รองรับอีเมล์ ตัวเลข (หมายเลขโทรศัพท์) @ - _ . เท่านั้น
   *
   * @param string $text
   * @return string
   *
   * @assert (' ad_min@demo.com') [==] 'ad_min@demo.com'
   * @assert ('012 3465') [==] '0123465'
   */
  public static function username($text)
  {
    return preg_replace('/[^a-zA-Z0-9@\.\-_]+/', '', $text);
  }

  /**
   * รับค่าสำหรับ password อักขระทุกตัวไม่มีช่องว่าง
   *
   * @param string $text
   * @return string
   *
   * @assert (" 0\n12   34\r\r6\t5 ") [==] '0123465'
   */
  public static function password($text)
  {
    return preg_replace('/[^\w]+/', '', $text);
  }

  /**
   * ฟังก์ชั่น เข้ารหัส อักขระพิเศษ และ {} ก่อนจะส่งให้กับ textarea หรือ editor ตอนแก้ไข
   * & " ' < > { } ไม่แปลง รหัส HTML เช่น &amp; &#38;
   *
   * @param string $text ข้อความ
   * @return string
   *
   * @assert ('&"'."'<>{}&amp;&#38;") [==] "&amp;&quot;&#039;&lt;&gt;&#x007B;&#x007D;&amp;&#38;"
   */
  public static function toEditor($text)
  {
    return preg_replace(array('/&/', '/"/', "/'/", '/</', '/>/', '/{/', '/}/', '/&(amp;([\#a-z0-9]+));/'), array('&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#x007B;', '&#x007D;', '&\\2;'), $text);
  }

  /**
   * ฟังก์ชั่น ลบช่องว่าง และ ตัวอักษรขึ้นบรรทัดใหม่ ที่ติดกันเกินกว่า 1 ตัว
   *
   * @param string $text  ข้อความ
   * @param int $len จำนวนตัวอักษรสูงสุดที่ต้องการ, 0 (default) คืนค่าทั้งหมด
   * @return string คืนค่าข้อความที่ไม่มีตัวอักษรขึ้นบรรทัดใหม่
   *
   * @assert (" \tทดสอบ\r\nภาษาไทย") [==] 'ทดสอบ ภาษาไทย'
   */
  public static function oneLine($text, $len = 0)
  {
    return self::cut(trim(preg_replace('/[\r\n\t\s]+/', ' ', $text)), $len);
  }

  /**
   * ฟังก์ชั่น ตัดสตริงค์ตามความยาวที่กำหนด
   * หากข้อความที่นำมาตัดยาวกว่าที่กำหนด จะตัดข้อความที่เกินออก และเติม .. ข้างท้าย
   *
   * @param string $source ข้อความ
   * @param int $len ความยาวของข้อความที่ต้องการ  (จำนวนตัวอักษรรวมจุด)
   * @return string
   *
   * @assert ('สวัสดี ประเทศไทย', 8) [==] 'สวัสดี..'
   * @assert ('123456789', 8) [==] '123456..'
   */
  public static function cut($source, $len)
  {
    if (!empty($len)) {
      $len = (int)$len;
      $source = (mb_strlen($source) <= $len || $len < 3) ? $source : mb_substr($source, 0, $len - 2).'..';
    }
    return $source;
  }

  /**
   * ฟังก์ชั่น แปลงขนาดของไฟล์จาก byte เป็น kb mb
   *
   * @param int $bytes ขนาดของไฟล์ เป็น byte
   * @param int $precision จำนวนหลักหลังจุดทศนิยม (default 2)
   * @return string คืนค่าขนาดของไฟล์เป็น KB MB
   *
   * @assert (256) [==] '256 Bytes'
   * @assert (1024) [==] '1 KB'
   * @assert (1024 * 1024) [==] '1 MB'
   * @assert (1024 * 1024 * 1024) [==] '1 GB'
   * @assert (1024 * 1024 * 1024 * 1024) [==] '1 TB'
   */
  public static function formatFileSize($bytes, $precision = 2)
  {
    $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    if ($bytes <= 0) {
      return '0 Byte';
    } else {
      $bytes = max($bytes, 0);
      $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
      $pow = min($pow, count($units) - 1);
      $bytes /= pow(1024, $pow);
      return round($bytes, $precision).' '.$units[$pow];
    }
  }

  /**
   * ฟังก์ชั่น สุ่มตัวอักษร
   *
   * @param int $count จำนวนหลักที่ต้องการ
   * @param string $chars (optional) ตัวอักษรที่ใช้ในการสุ่ม default abcdefghjkmnpqrstuvwxyz
   * @return string
   */
  public static function rndname($count, $chars = 'abcdefghjkmnpqrstuvwxyz')
  {
    srand((double)microtime() * 10000000);
    $ret = "";
    $num = strlen($chars);
    for ($i = 0; $i < $count; $i++) {
      $ret .= $chars[rand() % $num];
    }
    return $ret;
  }

  /**
   * ฟังก์ชั่น HTML highlighter
   * แปลง BBCode
   * แปลงข้อความ http เป็นลิงค์
   *
   * @param string $detail ข้อความ
   * @return string คืนค่าข้อความ
   */
  public static function highlighter($detail)
  {
    $patt[] = '/\[(\/)?(i|dfn|b|strong|u|em|ins|del|sub|sup|small|big|ul|ol|li)\]/isu';
    $replace[] = '<\\1\\2>';
    $patt[] = '/\[color=([#a-z0-9]+)\]/isu';
    $replace[] = '<span style="color:\\1">';
    $patt[] = '/\[size=([0-9]+)(px|pt|em|\%)\]/isu';
    $replace[] = '<span style="font-size:\\1\\2">';
    $patt[] = '/\[\/(color|size)\]/isu';
    $replace[] = '</span>';
    $patt[] = '/\[url\](.*)\[\/url\]/U';
    $replace[] = '<a href="\\1" target="_blank" rel="nofollow">\\1</a>';
    $patt[] = '/\[url=(ftp|http)(s)?:\/\/(.*)\](.*)\[\/url\]/U';
    $replace[] = '<a href="\\1\\2://\\3" target="_blank" rel="nofollow">\\4</a>';
    $patt[] = '/\[url=(\/)?(.*)\](.*)\[\/url\]/U';
    $replace[] = '<a href="'.\WEB_URL.'\\2" target="_blank" rel="nofollow">\\3</a>';
    $patt[] = '/([^["]]|\r|\n|\s|\t|^)(https?:\/\/([^\s<>\"\']+))/';
    $replace[] = '\\1<a href="\\2" target="_blank" rel="nofollow">\\2</a>';
    $patt[] = '/\[youtube\]([a-z0-9-_]+)\[\/youtube\]/i';
    $replace[] = '<div class="youtube"><iframe src="//www.youtube.com/embed/\\1?wmode=transparent"></iframe></div>';
    return preg_replace($patt, $replace, $detail);
  }

  /**
   * ฟังก์ชั่นคืนค่าข้อความซ้ำๆตามจำนวนที่กำหนด
   *
   * @param string $text ข้อความหรือตัวอักษรที่ต้องการทำซ้ำ
   * @param int $count จำนวนที่ต้องการ
   * @return string
   *
   * @assert ('0', 10) [==] '0000000000'
   */
  public static function repeat($text, $count)
  {
    $result = '';
    for ($i = 0; $i < $count; $i++) {
      $result .= $text;
    }
    return $result;
  }

  /**
   * แปลง & " ' < > \ { } เป็น HTML entities ใช้แทน htmlspecialchars() ของ PHP
   *
   * @param string $text
   * @param boolean $double_encode true (default) แปลง รหัส HTML เช่น &amp; เป็น &amp;amp;, false ไม่แปลง
   * @return string
   */
  public static function htmlspecialchars($text, $double_encode = true)
  {
    $str = preg_replace(array('/&/', '/"/', "/'/", '/</', '/>/', '/\\\/', '/\{/', '/\}/'), array('&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;'), $text);
    if (!$double_encode) {
      $str = preg_replace('/&(amp;([#a-z0-9]+));/i', '&\\2;', $str);
    }
    return $str;
  }
}
