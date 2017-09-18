<?php
/**
 * @filesource Kotchasan/Image.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * คลาสสำหรับจัดการรูปภาพ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Image
{
  /**
   * คุณภาพของรูปภาพ
   *
   * @var int
   */
  static private $quality = 75;

  /**
   * ฟังก์ชั่น ตัดรูปภาพ ตามขนาดที่กำหนด
   * รูปภาพปลายทางจะมีขนาดเท่าที่กำหนด หากรูปภาพต้นฉบับมีขนาดหรืออัตราส่วนไม่พอดีกับขนาดของภาพปลายทาง
   * รูปภาพจะถูกตัดขอบออกหรือจะถูกขยาย เพื่อให้พอดีกับรูปภาพปลายทางที่ต้องการ
   *
   * @param string $source path และชื่อไฟล์ของไฟล์รูปภาพต้นฉบับ
   * @param string $target path และชื่อไฟล์ของไฟล์รูปภาพปลายทาง
   * @param int $thumbwidth ความกว้างของรูปภาพที่ต้องการ
   * @param int $thumbheight ความสูงของรูปภาพที่ต้องการ
   * @param string $watermark (optional) ข้อความลายน้ำ
   * @return boolean สำเร็จคืนค่า true
   */
  public static function crop($source, $target, $thumbwidth, $thumbheight, $watermark = '')
  {
    $info = getImageSize($source);
    switch ($info['mime']) {
      case 'image/gif':
        $o_im = imageCreateFromGIF($source);
        break;
      case 'image/jpg':
      case 'image/jpeg':
      case 'image/pjpeg':
        $o_im = self::orient($source);
        break;
      case 'image/png':
      case 'image/x-png':
        $o_im = imageCreateFromPNG($source);
        break;
      default:
        return false;
    }
    $o_wd = @imagesx($o_im);
    $o_ht = @imagesy($o_im);
    $wm = $o_wd / $thumbwidth;
    $hm = $o_ht / $thumbheight;
    $h_height = $thumbheight / 2;
    $w_height = $thumbwidth / 2;
    $t_im = ImageCreateTrueColor($thumbwidth, $thumbheight);
    $int_width = 0;
    $int_height = 0;
    $adjusted_width = $thumbwidth;
    $adjusted_height = $thumbheight;
    if ($o_wd > $o_ht) {
      $adjusted_width = ceil($o_wd / $hm);
      $half_width = $adjusted_width / 2;
      $int_width = $half_width - $w_height;
      if ($adjusted_width < $thumbwidth) {
        $adjusted_height = ceil($o_ht / $wm);
        $half_height = $adjusted_height / 2;
        $int_height = $half_height - $h_height;
        $adjusted_width = $thumbwidth;
        $int_width = 0;
      }
    } elseif (($o_wd < $o_ht) || ($o_wd == $o_ht)) {
      $adjusted_height = ceil($o_ht / $wm);
      $half_height = $adjusted_height / 2;
      $int_height = $half_height - $h_height;
      if ($adjusted_height < $thumbheight) {
        $adjusted_width = ceil($o_wd / $hm);
        $half_width = $adjusted_width / 2;
        $int_width = $half_width - $w_height;
        $adjusted_height = $thumbheight;
        $int_height = 0;
      }
    }
    ImageCopyResampled($t_im, $o_im, -$int_width, -$int_height, 0, 0, $adjusted_width, $adjusted_height, $o_wd, $o_ht);
    if ($watermark != '') {
      $t_im = self::watermarkText($t_im, $watermark);
    }
    $ret = @ImageJPEG($t_im, $target, self::$quality);
    imageDestroy($o_im);
    imageDestroy($t_im);
    return $ret;
  }

  /**
   * ฟังก์ชั่นปรับขนาดของภาพ โดยรักษาอัตราส่วนของภาพตามความกว้างที่ต้องการ
   * หากรูปภาพมีขนาดเล็กกว่าที่กำหนด จะเป็นการ copy file
   * หากรูปภาพมาความสูง หรือความกว้างมากกว่า $width
   * จะถูกปรับขนาดให้มีขนาดไม่เกิน $width (ทั้งความสูงและความกว้าง)
   * และเปลี่ยนชนิดของภาพเป็น jpg
   *
   * @param string $source path และชื่อไฟล์ของไฟล์รูปภาพต้นฉบับ
   * @param string $target path ของไฟล์รูปภาพปลายทาง
   * @param string $name ชื่อไฟล์ของรูปภาพปลายทาง
   * @param int $width ขนาดสูงสุดของรูปภาพที่ต้องการ
   * @param string $watermark (optional) ข้อความลายน้ำ
   * @return array|bool คืนค่าแอเรย์ [name, width, height, mime] ของรูปภาพปลายทาง หรือ false ถ้าไม่สามารถดำเนินการได้
   */
  public static function resize($source, $target, $name, $width, $watermark = '')
  {
    $info = @getImageSize($source);
    if ($info[0] > $width || $info[1] > $width) {
      switch ($info['mime']) {
        case 'image/gif':
          $o_im = imageCreateFromGIF($source);
          break;
        case 'image/jpg':
        case 'image/jpeg':
        case 'image/pjpeg':
          $o_im = self::orient($source);
          break;
        case 'image/png':
        case 'image/x-png':
          $o_im = imageCreateFromPNG($source);
          break;
      }
      $o_wd = @imagesx($o_im);
      $o_ht = @imagesy($o_im);
      if ($o_wd <= $o_ht) {
        $h = $width;
        $w = round($h * $o_wd / $o_ht);
      } else {
        $w = $width;
        $h = round($w * $o_ht / $o_wd);
      }
      $t_im = @ImageCreateTrueColor($w, $h);
      @ImageCopyResampled($t_im, $o_im, 0, 0, 0, 0, $w + 1, $h + 1, $o_wd, $o_ht);
      if ($watermark != '') {
        $t_im = self::watermarkText($t_im, $watermark);
      }
      $newname = substr($name, 0, strrpos($name, '.')).'.jpg';
      if (!@ImageJPEG($t_im, $target.$newname, self::$quality)) {
        $ret = false;
      } else {
        $ret['name'] = $newname;
        $ret['width'] = $w;
        $ret['height'] = $h;
        $ret['mime'] = 'image/jpeg';
      }
      @imageDestroy($o_im);
      @imageDestroy($t_im);
      return $ret;
    } elseif (@copy($source, $target.$name)) {
      $ret['name'] = $name;
      $ret['width'] = $info[0];
      $ret['height'] = $info[1];
      $ret['mime'] = $info['mime'];
      return $ret;
    }
    return false;
  }

  /**
   * ฟังก์ชั่น โหลดภาพ jpg และหมุนภาพอัตโนมัติจากข้อมูลของ Exif
   *
   * @param resource $source resource ของรูปภาพต้นฉบับ
   * @return resource คืนค่า resource ของรูปภาพหลังจากหมุนแล้ว ถ้าไม่สนับสนุนคืนค่า resource เดิม
   */
  public static function orient($source)
  {
    $imgsrc = imageCreateFromJPEG($source);
    if (function_exists('exif_read_data')) {
      // read image exif and rotate
      $exif = @exif_read_data($source);
      if (!isset($exif['Orientation'])) {
        return $imgsrc;
      } elseif ($exif['Orientation'] == 2) {
        // horizontal flip
        $imgsrc = self::flip($imgsrc);
      } elseif ($exif['Orientation'] == 3) {
        // 180 rotate left
        $imgsrc = imagerotate($imgsrc, 180, 0);
      } elseif ($exif['Orientation'] == 4) {
        // vertical flip
        $imgsrc = self::flip($imgsrc);
      } elseif ($exif['Orientation'] == 5) {
        // vertical flip + 90 rotate right
        $imgsrc = imagerotate($imgsrc, 270, 0);
        $imgsrc = self::flip($imgsrc);
      } elseif ($exif['Orientation'] == 6) {
        // 90 rotate right
        $imgsrc = imagerotate($imgsrc, 270, 0);
      } elseif ($exif['Orientation'] == 7) {
        // horizontal flip + 90 rotate right
        $imgsrc = imagerotate($imgsrc, 90, 0);
        $imgsrc = self::flip($imgsrc);
      } elseif ($exif['Orientation'] == 8) {
        // 90 rotate left
        $imgsrc = imagerotate($imgsrc, 90, 0);
      }
    }
    return $imgsrc;
  }

  /**
   * ฟังก์ชั่น พลิกรูปภาพ (ซ้าย-ขวา คล้ายกระจกเงา)
   *
   * @param resource $imgsrc resource ของรูปภาพต้นฉบับ
   * @return resource คืนค่า resource ของรูปภาพหลังจากพลิกรูปภาพแล้ว ไม่สำเร็จคืนค่า resource ของรูปภาพต้นฉบับ
   */
  public static function flip($imgsrc)
  {
    $width = imagesx($imgsrc);
    $height = imagesy($imgsrc);
    $src_x = $width - 1;
    $src_y = 0;
    $src_width = -$width;
    $src_height = $height;
    $imgdest = imagecreatetruecolor($width, $height);
    if (imagecopyresampled($imgdest, $imgsrc, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height)) {
      return $imgdest;
    }
    return $imgsrc;
  }

  /**
   * ฟังก์ชั่น วาดลายน้ำที่เป็นตัวอักษรลงบนรูปภาพ
   *
   * @param resource $imgsrc resource ของรูปภาพต้นฉบับ
   * @param string $text ข้อความที่จะใช้เป็นลายน้ำ
   * @param string $pos (optional) ตำแหน่งของลายน้ำเช่น center top bottom right left (default 'top left')
   * @param string $color (optional) สีของตัวอักษร เป็น hex เท่านั้น ไม่ต้องมี # (default CCCCCC)
   * @param int $font_size (optional) ขนาดตัวอักษรของลายน้ำเป็นพิกเซล (default 20px)
   * @param int $opacity (optional) กำหนดค่าตัวอักษรโปร่งใส 0-50 (default 50)
   * @return resource ของรูปภาพต้นฉบับ
   */
  public static function watermarkText($imgsrc, $text, $pos = '', $color = 'CCCCCC', $font_size = 20, $opacity = 50)
  {
    $font = ROOT_PATH.'skin/fonts/leelawad.ttf';
    $offset = 5;
    $alpha_color = imagecolorallocatealpha($imgsrc, hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2)), 127 * (100 - $opacity) / 100);
    $box = imagettfbbox($font_size, 0, $font, $text);
    if (preg_match('/center/i', $pos)) {
      $y = $box[1] + (imagesy($imgsrc) / 2) - ($box[5] / 2);
    } elseif (preg_match('/bottom/i', $pos)) {
      $y = imagesy($imgsrc) - $offset;
    } else {
      $y = $box[1] - $box[5] + $offset;
    }
    if (preg_match('/center/i', $pos)) {
      $x = $box[0] + (imagesx($imgsrc) / 2) - ($box[4] / 2);
    } elseif (preg_match('/right/i', $pos)) {
      $x = $box[0] - $box[4] + imagesx($imgsrc) - $offset;
    } else {
      $x = $offset;
    }
    imagettftext($imgsrc, $font_size, 0, $x, $y, $alpha_color, $font, $text);
    return $imgsrc;
  }

  /**
   * อ่านข้อมูล Exif ของรูปภาพ
   *
   * @param string $src
   * @return array|boolean array(width, height, mime) ของรูปภาพ, false ถ้าไม่สามารถอ่านได้
   */
  public static function info($src)
  {
    // Exif
    $info = getImageSize($src);
    if ($info && $info[0] > 0 && $info[1] > 0) {
      return array(
        'width' => $info[0],
        'height' => $info[1],
        'mime' => $info['mime']
      );
    }
    return false;
  }
}
