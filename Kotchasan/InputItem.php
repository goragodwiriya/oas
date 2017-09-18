<?php
/**
 * @filesource Kotchasan/InputItem.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Text;

/**
 * Input Object
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class InputItem
{
  /**
   * ตัวแปรเก็บค่าของ Object
   *
   * @var mixed
   */
  protected $value;
  /**
   * ตัวแปรบอกประเภท Input เช่น GET POST SESSION COOKIE
   *
   * @var string|null
   */
  protected $type;

  /**
   * Class Constructer
   *
   * @param mixed $value null (default)
   * @param string|null $type ประเภท Input เช่น GET POST SESSION COOKIE หรือ null ถ้าไม่ได้มาจากรายการข้างต้น
   */
  public function __construct($value = null, $type = null)
  {
    $this->value = $value;
    $this->type = $type;
  }

  /**
   * สร้าง Object
   *
   * @param mixed $value
   * @param string|null $type ประเภท Input เช่น GET POST SESSION COOKIE หรือ null ถ้าไม่ได้มาจากรายการข้างต้น
   * @return \static
   */
  public static function create($value, $type = null)
  {
    return new static($value, $type);
  }

  /**
   * คืนค่าตามข้อมูลที่ส่งมา
   *
   * @return mixed
   */
  public function all()
  {
    return $this->value;
  }

  /**
   * ตรวจสอบว่ามาจาก $_GET หรือไม่
   *
   * @return boolean คืนค่า true ถ้ามาจาก $_GET
   */
  public function isGet()
  {
    return $this->type === 'GET';
  }

  /**
   * ตรวจสอบว่ามาจาก $_POST หรือไม่
   *
   * @return boolean คืนค่า true ถ้ามาจาก $_POST
   */
  public function isPost()
  {
    return $this->type === 'POST';
  }

  /**
   * ตรวจสอบว่ามาจาก $_SESSION หรือไม่
   *
   * @return boolean คืนค่า true ถ้ามาจาก $_SESSION
   */
  public function isSession()
  {
    return $this->type === 'SESSION';
  }

  /**
   * ตรวจสอบว่ามาจาก $_COOKIE หรือไม่
   *
   * @return boolean คืนค่า true ถ้ามาจาก $_COOKIE
   */
  public function isCookie()
  {
    return $this->type === 'COOKIE';
  }

  /**
   * ตรวจสอบว่ามีตัวแปรส่งมาหรือไม่
   *
   * @return boolean คืนค่า true ถ้ามีตัวแปรส่งมา
   */
  public function exists()
  {
    return $this->type !== null;
  }

  /**
   * คืนค่าเป็น boolean
   *
   * @return bool
   *
   * @assert create(true)->toBoolean() [==] 1
   * @assert create(false)->toBoolean() [==] 0
   * @assert create(1)->toBoolean() [==] 1
   * @assert create(0)->toBoolean() [==] 0
   * @assert create(null)->toBoolean() [==] 0
   */
  public function toBoolean()
  {
    return empty($this->value) ? 0 : 1;
  }

  /**
   * คืนค่าเป็น double
   *
   * @return double
   *
   * @assert create(0.454)->toDouble() [==] 0.454
   * @assert create(0.545)->toDouble() [==] 0.545
   */
  public function toDouble()
  {
    return (double)$this->value;
  }

  /**
   * คืนค่าเป็น float
   *
   * @return float
   *
   * @assert create(0.454)->toFloat() [==] 0.454
   * @assert create(0.545)->toFloat() [==] 0.545
   */
  public function toFloat()
  {
    return (float)$this->value;
  }

  /**
   * คืนค่าเป็น integer
   *
   * @return int
   *
   * @assert create(0.454)->toInt() [==] 0
   * @assert create(2.945)->toInt() [==] 2
   */
  public function toInt()
  {
    return (int)$this->value;
  }

  /**
   * คืนค่าเป็น Object
   *
   * @return object
   *
   * @assert create('test')->toObject() [==] (object)'test'
   */
  public function toObject()
  {
    return (object)$this->value;
  }

  /**
   * คืนค่าเป็น String
   *
   * @return string|null คืนค่าเป็น string หรือ null
   *
   * @assert create('ทดสอบ')->toString() [==] 'ทดสอบ'
   * @assert create('1')->toString() [==] '1'
   * @assert create(1)->toString() [==] '1'
   * @assert create(null)->toString() [==] null
   */
  public function toString()
  {
    return $this->value === null ? null : (string)$this->value;
  }

  /**
   * แปลง tag และ ลบช่องว่างไม่เกิน 1 ช่อง ไม่ขึ้นบรรทัดใหม่
   * เช่นหัวข้อของบทความ
   *
   * @return string
   *
   * @assert create(' ทด\/สอบ'."\r\n\t".'<?php echo \'555\'?> ')->topic() [==] 'ทด&#92;/สอบ &lt;?php echo &#039;555&#039;?&gt;'
   */
  public function topic()
  {
    return Text::topic($this->value);
  }

  /**
   * แปลง tag ไม่แปลง &amp;
   * และลบช่องว่างหัวท้าย
   * สำหรับ URL หรือ email
   *
   * @return string
   *
   * @assert create(" http://www.kotchasan.com?a=1&b=2&amp;c=3 ")->url() [==] 'http://www.kotchasan.com?a=1&amp;b=2&amp;c=3'
   * @assert create("javascript:alert('xxx')")->url() [==] 'alertxxx'
   * @assert create("http://www.xxx.com/javascript/")->url() [==] 'http://www.xxx.com/javascript/'
   */
  public function url()
  {
    return Text::url($this->value);
  }

  /**
   * รับค่าอีเมล์และหมายเลขโทรศัพท์เท่านั้น
   *
   * @return string
   *
   * @assert create(' admin@demo.com')->username() [==] 'admin@demo.com'
   * @assert create('012 3465')->username() [==] '0123465'
   */
  public function username()
  {
    return Text::username($this->value);
  }

  /**
   * รับค่าสำหรับ password อักขระทุกตัวไม่มีช่องว่าง
   *
   * @return string
   *
   * @assert create(" 0\n12   34\r\r6\t5 ")->password() [==] '0123465'
   */
  public function password()
  {
    return Text::password($this->value);
  }

  /**
   * ฟังก์ชั่น แปลง & " ' < > \ เป็น HTML entities
   * และลบช่องว่างหัวท้าย
   * ใช้แปลงค่าที่รับจาก input ที่ไม่ยอมรับ tag
   *
   * @return string
   *
   * @assert create(" ทด\/สอบ<?php echo '555'?> ")->text() [==] 'ทด&#92;/สอบ&lt;?php echo &#039;555&#039;?&gt;'
   */
  public function text()
  {
    return trim(Text::htmlspecialchars($this->value));
  }

  /**
   * แปลง < > \ { } เป็น HTML entities และแปลง \n เป็น <br>
   * และลบช่องว่างหัวท้าย
   * ใช้รับข้อมูลที่มาจาก textarea
   *
   * @return string
   *
   * @assert create("ทด\/สอบ\n<?php echo '555'?>")->textarea() [==] "ทด&#92;/สอบ\n&lt;?php echo '555'?&gt;"
   */
  public function textarea()
  {
    return trim(preg_replace(array('/</s', '/>/s', '/\\\/s', '/\{/', '/\}/'), array('&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;'), $this->value));
  }

  /**
   * ลบ tag, BBCode ออก ให้เหลือแต่ข้อความล้วน
   * ลบช่องว่างไม่เกิน 1 ช่อง ไม่ขึ้นบรรทัดใหม่
   * และลบช่องว่างหัวท้าย
   * ใช้เป็น description
   *
   *
   * @param int $len ความยาวของ description 0 หมายถึงคืนค่าทั้งหมด
   * @return string
   *
   * @assert create("ท.ด(ส     )อ\"บ'\r\n\t<?php echo '555'?>")->description() [==] 'ท.ด(ส )อ บ'
   * @assert create('ทดสอบ<style>body {color: red}</style>')->description() [==] 'ทดสอบ'
   * @assert create('ทดสอบ<b>ตัวหนา</b>')->description() [==] 'ทดสอบตัวหนา'
   * @assert create('ทดสอบ{LNG_Language name}')->description() [==] 'ทดสอบ'
   * @assert create('ทดสอบ[code]ตัวหนา[/code]')->description() [==] 'ทดสอบ'
   * @assert create('ทดสอบ[b]ตัวหนา[/b]')->description() [==] 'ทดสอบตัวหนา'
   * @assert create('2 > 1 < 3 > 2{WIDGET_XXX}')->description() [==] '2 > 1 < 3 > 2'
   * @assert create('ทดสอบ<!--WIDGET_XXX-->')->description() [==] 'ทดสอบ'
   * @assert create('ท&amp;ด&quot;\&nbsp;/__ส-อ+บ')->description() [==] 'ท ด \ /__ส-อ+บ'
   * @assert create('ภาคภูมิ')->description(2) [==] 'ภา'
   */
  public function description($len = 0)
  {
    $patt = array(
      /* style */
      '@<(script|style)[^>]*?>.*?</\\1>@isu' => '',
      /* tag */
      '@<[a-z\/\!\?][^>]{0,}>@isu' => '',
      /* keywords */
      '/{(WIDGET|LNG)_[\w\s\.\-\'\(\),%\/:&\#;]+}/su' => '',
      /* BBCode (code) */
      '/(\[code(.+)?\]|\[\/code\]|\[ex(.+)?\])/ui' => '',
      /* BBCode ทั่วไป [b],[i] */
      '/\[([a-z]+)([\s=].*)?\](.*?)\[\/\\1\]/ui' => '\\3',
      /* ตัวอักษรที่ไม่ต้องการ */
      '/(&rdquo;|&quot;|&nbsp;|&amp;|[\r\n\s\t\"\']){1,}/isu' => ' '
    );
    $text = trim(preg_replace(array_keys($patt), array_values($patt), $this->value));
    return $this->cut($text, $len);
  }

  /**
   * ลบ PHP tag และแปลง \ เป็น $#92; ใช้รับข้อมูลจาก editor
   * เช่นเนื้อหาของบทความ
   *
   * @return string
   *
   * @assert create('ทด\/สอบ<?php echo "555"?>')->detail() [==] 'ทด&#92;/สอบ'
   */
  public function detail()
  {
    return preg_replace(array('/<\?(.*?)\?>/su', '/\\\/'), array('', '&#92;'), $this->value);
  }

  /**
   * ลบ tags และ ลบช่องว่างไม่เกิน 1 ช่อง ไม่ขึ้นบรรทัดใหม่
   * และลบช่องว่างหัวท้าย
   * ใช้เป็น tags หรือ keywords
   *
   * @param int $len ความยาวของ keywords 0 หมายถึงคืนค่าทั้งหมด
   * @return string
   *
   * @assert create("<b>ทด</b>   \r\nสอบ")->keywords() [==] 'ทด สอบ'
   */
  public function keywords($len = 0)
  {
    $text = trim(preg_replace('/[\r\n\s\t\"\'<>]{1,}/isu', ' ', strip_tags($this->value)));
    return $this->cut($text, $len);
  }

  /**
   * ฟังก์ชั่นรับข้อความ ยอมรับอักขระทั้งหมด
   * และแปลง ' เป็น &#39;
   * และลบช่องว่างหัวท้าย
   *
   * @return string
   *
   * @assert create("ทด'สอบ")->quote() [==] "ทด&#39;สอบ"
   */
  public function quote()
  {
    return str_replace("'", '&#39;', trim($this->value));
  }

  /**
   * ฟังก์ชั่นแทนที่อักขระที่ไม่ต้องการ
   *
   * @param string $format Regular Expression อักขระที่ยอมรับ เช่น \d\s\-:
   * @param string $replace ข้อความแทนที่
   * @return string
   *
   * @assert create('admin,1234')->filter('0-9a-zA-Z,') [==] 'admin,1234'
   * @assert create('adminกข,12ฟ34')->filter('0-9a-zA-Z,') [==] 'admin,1234'
   */
  public function filter($format, $replace = '')
  {
    return trim(preg_replace('/[^'.$format.']/', $replace, $this->value));
  }

  /**
   * วันที่และเวลา
   * คืนค่า null ถ้าข้อมูลวันที่ว่างเปล่า
   *
   * @return string
   *
   * @assert create('2016-01-01 20:20:20')->date() [==] '2016-01-01 20:20:20'
   * @assert create('')->date() [==] null
   * @assert create(null)->date() [==] null
   */
  public function date()
  {
    $ret = $this->filter('\d\s\-:');
    return $ret == '' ? null : $ret;
  }

  /**
   * ค่าสี
   *
   * @return string
   *
   * @assert create('#000')->color() [==] '#000'
   * @assert create('red')->color() [==] 'red'
   */
  public function color()
  {
    return $this->filter('\#a-zA-Z0-9');
  }

  /**
   * ตัวเลข
   *
   * @return string
   *
   * @assert create(12345)->number() [==] '12345'
   * @assert create(0.12345)->number() [==] '012345'
   * @assert create('ทด0123สอ4บ5')->number() [==] '012345'
   */
  public function number()
  {
    return $this->filter('\d');
  }

  /**
   * ตัดสตริงค์
   *
   * @param string $str
   * @param int $len ความยาวที่ต้องการ
   * @return string
   */
  private function cut($str, $len)
  {
    if (!empty($len) && !empty($str)) {
      $str = mb_substr($str, 0, (int)$len);
    }
    return $str;
  }
}
