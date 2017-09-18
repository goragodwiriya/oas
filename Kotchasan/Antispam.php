<?php
/**
 * @filesource Kotchasan/Antispam.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Text;

/**
 * คลาสสำหรับจัดการ Antispam
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Antispam extends \Kotchasan\KBase
{
  /**
   * current Antispam ID
   *
   * @var string
   */
  protected $antispamchar;

  /**
   * create class
   *
   * @param string $id รหัส Antispam ถ้าเป็น null หมายถึงการสร้าง Antispam ใหม่,
   * ถ้ามีการกำหนดค่ามาจะใช้ในการตวจสอบ Antispam
   * @param int $len ความยาวของอักขระ Antispam (ค่าเริ่มต้น 4)
   */
  public function __construct($id = null, $len = 4)
  {
    if ($id) {
      $this->antispamchar = (string)$id;
    } else {
      $this->antispamchar = Text::rndname(32);
      $_SESSION[$this->antispamchar] = Text::rndname($len);
    }
  }

  /**
   * อ่านค่าค่าคีย์ของ Antispam
   *
   * @return string|null คืนค่าคีย์ของ Antispam
   */
  public function getId()
  {
    return $this->antispamchar;
  }

  /**
   * อ่านค่า Antispam
   *
   * @return string|null คืนค่า Antispam
   */
  public function getValue()
  {
    return empty($_SESSION[$this->antispamchar]) ? null : $_SESSION[$this->antispamchar];
  }

  /**
   * ฟังก์ชั่นตรวจสอบ Antispam กับ $value
   *
   * @param string $value
   * @return boolean true ถ้าตรงกัน, false ไม่พบ Antispam หรือไม่ถูกต้อง
   */
  public function valid($value)
  {
    return !empty($this->antispamchar) && !empty($_SESSION[$this->antispamchar]) && $_SESSION[$this->antispamchar] === $value;
  }

  /**
   * ลบ Antispan
   */
  public function delete()
  {
    unset($_SESSION[$this->antispamchar]);
  }

  /**
   * สร้าง Image สำหรับ Antispam
   *
   * @param string $id คีย์ของ Antispam
   * @param boolean $toHTML true คืนค่าเป็น tag IMG, false สร้างรูปภาพ
   * @return string คืนค่าเป็น tag IMG, $toHTML เป็น false คืนค่าข้อความว่าง
   */
  public static function createImage($id, $toHTML = false)
  {
    if (!empty($_SESSION[$id]) && preg_match('/[a-z0-9]{32,32}/', $id)) {
      $antispamchar = $_SESSION[$id];
      $im = imagecreate(80, 20);
      // transparent
      $trans_colour = imagecolorallocatealpha($im, 0, 0, 0, 127);
      imagefill($im, 0, 0, $trans_colour);
      // random points
      for ($i = 0; $i <= 128; $i++) {
        $point_color = imagecolorallocate($im, rand(0, 255), rand(0, 255), rand(0, 255));
        imagesetpixel($im, rand(2, 128), rand(2, 38), $point_color);
      }
      // output characters
      for ($i = 0; $i < strlen($antispamchar); $i++) {
        $text_color = imagecolorallocate($im, rand(0, 255), rand(0, 128), rand(0, 255));
        $x = 5 + $i * 20;
        $y = rand(1, 4);
        imagechar($im, 5, $x, $y, $antispamchar{$i}, $text_color);
      }
      // png image
      if ($toHTML) {
        if (ob_get_length() > 0) {
          ob_end_flush();
        }
        ob_start();
        imagepng($im);
        $image_string = ob_get_contents();
        ob_end_clean();
      } else {
        header("Content-type: image/png");
        imagepng($im);
      }
      // clear
      imagedestroy($im);
      // return
      return $toHTML ? '<span><img src="data:image/png;base64,'.base64_encode($image_string).'" alt=Antispam></span>' : '';
    }
  }
}
