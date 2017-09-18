<?php
/**
 * @filesource Kotchasan/Pdf.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\DOMParser;
use \Kotchasan\DOMNode;

/**
 * Pdf Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Pdf extends \PDF\FPDF
{
  protected $fontSize;
  protected $lineHeight = 5;
  protected $B;
  protected $I;
  protected $U;
  protected $unit;
  protected $link = null;
  protected $lastBlock = true;
  protected $css;
  protected $cssClass;

  /**
   * Create FPDF ภาษาไทย
   *
   * @param string $orientation
   * @param string $unit
   * @param string $size
   * @param int $fontSize
   */
  public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4', $fontSize = 10)
  {
    // create FPDF
    parent::__construct($orientation, $unit, $size);
    // ค่าเริ่มต้นตัวแปรต่างๆ
    $this->B = 0;
    $this->I = 0;
    $this->U = 0;
    $this->unit = $unit;
    $this->fontSize = $fontSize;
    // ฟ้อนต์ภาษาไทย
    $this->AddFont('loma', '', 'Loma.php');
    $this->AddFont('loma', 'B', 'Loma-Bold.php');
    $this->AddFont('loma', 'I', 'Loma-Oblique.php');
    $this->AddFont('loma', 'BI', 'Loma-BoldOblique.php');
    $this->AddFont('angsana', '', 'angsa.php');
    $this->AddFont('angsana', 'B', 'angsab.php');
    $this->AddFont('angsana', 'I', 'angsai.php');
    $this->AddFont('angsana', 'BI', 'angsaz.php');
    // ฟอ้นต์เริ่มต้น
    $this->SetFont('loma', '', $this->fontSize);
    // default styles
    $this->css = array(
      'H1' => array(
        'SIZE' => $this->fontSize + 10,
        'LINE-HEIGHT' => $this->lineHeight + 2.5
      ),
      'H2' => array(
        'SIZE' => $this->fontSize + 8,
        'LINE-HEIGHT' => $this->lineHeight + 2
      ),
      'H3' => array(
        'SIZE' => $this->fontSize + 6,
        'LINE-HEIGHT' => $this->lineHeight + 1.5
      ),
      'H4' => array(
        'SIZE' => $this->fontSize + 4,
        'LINE-HEIGHT' => $this->lineHeight + 1
      ),
      'H5' => array(
        'SIZE' => $this->fontSize + 2,
        'LINE-HEIGHT' => $this->lineHeight + 0.5
      ),
      'EM' => array(
        'COLOR' => '#FF5722'
      ),
      'I' => array(
        'FONT-STYLE' => 'ITALIC'
      ),
      'B' => array(
        'FONT-WEIGHT' => 'BOLD'
      ),
      'STRONG' => array(
        'FONT-WEIGHT' => 'BOLD'
      ),
      'U' => array(
        'TEXT-DECORATION' => 'UNDERLINE'
      ),
      'A' => array(
        'TEXT-DECORATION' => 'UNDERLINE'
      ),
      'BLOCKQUOTE' => array(
        'BORDER-COLOR' => '#DDDDDD',
        'BACKGROUND-COLOR' => '#F9F9F9',
        'COLOR' => '#666666',
        'SIZE' => $this->fontSize - 1,
        'PADDING' => 2
      ),
      'CODE' => array(
        'BORDER-COLOR' => '#DDDDDD',
        'BACKGROUND-COLOR' => '#F9F9F9',
        'COLOR' => '#666666',
        'SIZE' => $this->fontSize + 4,
        'PADDING' => 2,
        'DISPLAY' => 'BLOCK',
        'FONT-FAMILY' => 'angsana',
        'FONT-STYLE' => 'ITALIC'
      ),
      'TABLE' => array(
        'BORDER-COLOR' => '#DDDDDD'
      ),
      'TH' => array(
        'TEXT-ALIGN' => 'CENTER',
        'BACKGROUND-COLOR' => '#EEEEEE'
      ),
      'TD' => array(
        'COLOR' => '#333333'
      )
    );
    // class style
    $this->cssClass = array(
      'COMMENT' => array(
        'SIZE' => $this->fontSize - 1,
        'COLOR' => '#259B24'
      ),
      'CENTER' => array(
        'TEXT-ALIGN' => 'CENTER'
      ),
      'LEFT' => array(
        'TEXT-ALIGN' => 'LEFT'
      ),
      'RIGHT' => array(
        'TEXT-ALIGN' => 'RIGHT'
      ),
      'BG2' => array(
        'BACKGROUND-COLOR' => '#F9F9F9'
      ),
      'FULLWIDTH' => array(
        'WIDTH' => '100%'
      )
    );
  }

  /**
   * กำหนดรูปแบบของ tag
   *
   * @param string $tag
   * @param array $attributes
   */
  public function SetStyles($tag, $attributes)
  {
    foreach ($attributes as $key => $value) {
      $this->css[strtoupper($tag)][strtoupper($key)] = $value;
    }
  }

  /**
   * กำหนดรูปแบบของ class
   *
   * @param string $className
   * @param array $attributes
   */
  public function SetCssClass($className, $attributes)
  {
    foreach ($attributes as $key => $value) {
      $this->cssClass[strtoupper($className)][strtoupper($key)] = $value;
    }
  }

  /**
   * สร้าง PDF จาก HTML โค้ด
   * แสดงผลตามรูปแบบที่กำหนดโดย คชสาร
   *
   * @param string $html โค้ด HTML4
   * @param string $charset default cp874 (ภาษาไทย)
   */
  public function WriteHTML($html, $charset = 'cp874')
  {
    // parse HTML
    $dom = new DOMParser($html, $charset);
    // render
    foreach ($dom->nodes() as $node) {
      $this->render($node);
    }
  }

  /**
   *
   * @param DOMNode $node
   * @return string
   */
  protected function render($node)
  {
    if ($node->nodeName == '') {
      // โหนดข้อความ
      $node->attributes['DISPLAY'] = 'INLINE';
      $lineHeight = empty($node->parentNode->attributes['LINE-HEIGHT']) ? $this->lineHeight : $node->parentNode->attributes['LINE-HEIGHT'];
      if ($node->parentNode && $node->parentNode->attributes['DISPLAY'] !== 'INLINE' && sizeof($node->parentNode->childNodes) == 1) {
        // block node
        $align = empty($node->parentNode->attributes['TEXT-ALIGN']) ? '' : $node->parentNode->attributes['TEXT-ALIGN'][0];
        $border = empty($node->parentNode->attributes['BORDER-COLOR']) ? 0 : 1;
        $fill = empty($node->parentNode->attributes['BACKGROUND-COLOR']) ? false : true;
        $tPadding = empty($node->parentNode->attributes['PADDING-TOP']) ? 0 : $node->parentNode->attributes['PADDING-TOP'];
        $rPadding = empty($node->parentNode->attributes['PADDING-RIGHT']) ? 0 : $node->parentNode->attributes['PADDING-RIGHT'];
        $bPadding = empty($node->parentNode->attributes['PADDING-BOTTOM']) ? 0 : $node->parentNode->attributes['PADDING-BOTTOM'];
        $lPadding = empty($node->parentNode->attributes['PADDING-LEFT']) ? 0 : $node->parentNode->attributes['PADDING-LEFT'];
        $this->MultiCell(0, $lineHeight, $node->unentities($node->nodeValue), $border, $align, $fill, $tPadding, $rPadding, $bPadding, $lPadding);
        $this->lastBlock = true;
      } else {
        // inline node
        if ($this->link) {
          // link
          $this->Write($lineHeight, $node->unentities($node->nodeValue), $this->link);
        } else {
          // text
          $this->Write($lineHeight, $node->unentities($node->nodeValue));
        }
        $this->lastBlock = false;
      }
    } else {
      // อ่าน CSS ของโหนด
      $this->loadStyle($node);
      // open tag
      if ($node->nodeName == 'BR') {
        // ขึ้นบรรทัดใหม่
        $this->Ln();
      } elseif ($node->nodeName == 'IMG') {
        // รูปภาพ
        $this->drawImg($node);
      } elseif ($node->nodeName == 'HR') {
        // เส้นคั่น
        $this->drawHr($node);
      } elseif ($node->nodeName == 'TABLE') {
        // ตาราง
        $this->drawTable($node);
      } else {
        // ขึ้นบรรทัดใหม่
        if (!$this->lastBlock) {
          if ($node->attributes['DISPLAY'] !== 'INLINE') {
            $this->Ln();
          } elseif ($node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
            $this->Ln();
          }
        }
        // link
        if ($node->nodeName == 'A' && !empty($node->attributes['HREF'])) {
          $this->link = $node->attributes['HREF'];
        }
        // กำหนด CSS
        $this->applyCSS($node);
        // render โหนดลูก
        foreach ($node->childNodes as $child) {
          $this->render($child);
        }
        // คืนค่า CSS
        $this->restoredCSS($node);
      }
    }
  }

  /**
   * อ่าน CSS ของโหนด
   *
   * @param DOMNode $node
   */
  protected function loadStyle($node)
  {
    // display
    $node->attributes['DISPLAY'] = $node->isInlineElement() ? 'INLINE' : 'BLOCK';
    // style เริ่มต้น
    if (isset($this->css[$node->nodeName])) {
      foreach ($this->css[$node->nodeName] as $key => $value) {
        $node->attributes[$key] = $value;
      }
    }
    // style จาก property style
    if (!empty($node->attributes['STYLE'])) {
      foreach (explode(';', strtoupper($node->attributes['STYLE'])) as $style) {
        if (preg_match('/^([A-Z\-]+)[\s]{0,}\:[\s]{0,}([A-Z0-9\-]+).*?/', trim($style), $match)) {
          $node->attributes[$match[1]] = $match[2];
        }
      }
      unset($node->attributes['STYLE']);
    }
    // style จาก class
    if (isset($node->attributes['CLASS'])) {
      foreach (explode(' ', $node->attributes['CLASS']) as $class) {
        $class = strtoupper($class);
        if (isset($this->cssClass[$class])) {
          foreach ($this->cssClass[$class] as $key => $value) {
            if (!isset($node->attributes[$key])) {
              $node->attributes[$key] = $value;
            }
          }
        }
      }
      unset($node->attributes['CLASS']);
    }
    // padding
    if (!empty($node->attributes['PADDING'])) {
      $value = (int)$node->attributes['PADDING'];
      if (!isset($node->attributes['PADDING-LEFT'])) {
        $node->attributes['PADDING-LEFT'] = $value;
      }
      if (!isset($node->attributes['PADDING-TOP'])) {
        $node->attributes['PADDING-TOP'] = $value;
      }
      if (!isset($node->attributes['PADDING-RIGHT'])) {
        $node->attributes['PADDING-RIGHT'] = $value;
      }
      if (!isset($node->attributes['PADDING-BOTTOM'])) {
        $node->attributes['PADDING-BOTTOM'] = $value;
      }
      unset($node->attributes['PADDING']);
    }
  }

  /**
   * กำหนด CSS
   *
   * @param DOMNode $node
   */
  protected function applyCSS($node)
  {
    // แบบตัวอักษร
    if (!empty($node->attributes['FONT-FAMILY'])) {
      $node->FontFamily = $this->FontFamily;
      $this->SetFont($node->attributes['FONT-FAMILY']);
    }
    // สีตัวอักษร
    if (!empty($node->attributes['COLOR'])) {
      if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sr)?g/', $this->TextColor, $match)) {
        $node->TextColor = array(
          'r' => $match[1],
          'g' => isset($match[3]) ? $match[3] : null,
          'b' => isset($match[4]) ? $match[4] : null
        );
      }
      list($r, $g, $b) = $this->colorToRGb($node->attributes['COLOR']);
      $this->SetTextColor($r, $g, $b);
    }
    // สีพื้น
    if (!empty($node->attributes['BACKGROUND-COLOR'])) {
      if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sr)?g/', $this->FillColor, $match)) {
        $node->FillColor = array(
          'r' => $match[1],
          'g' => isset($match[3]) ? $match[3] : null,
          'b' => isset($match[4]) ? $match[4] : null
        );
      }
      list($r, $g, $b) = $this->colorToRGb($node->attributes['BACKGROUND-COLOR']);
      $this->SetFillColor($r, $g, $b);
    }
    // สีกรอบ
    if (!empty($node->attributes['BORDER-COLOR'])) {
      if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sR)?G/', $this->DrawColor, $match)) {
        $node->DrawColor = array(
          'r' => $match[1],
          'g' => isset($match[3]) ? $match[3] : null,
          'b' => isset($match[4]) ? $match[4] : null
        );
      }
      list($r, $g, $b) = $this->colorToRGb($node->attributes['BORDER-COLOR']);
      $this->SetDrawColor($r, $g, $b);
    }
    // ตัวหนา
    if (!empty($node->attributes['FONT-WEIGHT'])) {
      $this->SetStyle('B', $node->attributes['FONT-WEIGHT'] == 'BOLD');
    }
    // ตัวเอียง
    if (!empty($node->attributes['FONT-STYLE'])) {
      $this->SetStyle('I', $node->attributes['FONT-STYLE'] == 'ITALIC');
    }
    // ขีดเส้นใต้
    if (!empty($node->attributes['TEXT-DECORATION'])) {
      $this->SetStyle('U', $node->attributes['TEXT-DECORATION'] == 'UNDERLINE');
    }
    // ขนาดตัวอักษร
    if (!empty($node->attributes['SIZE'])) {
      $node->FontSizePt = $this->FontSizePt;
      $this->SetFontSize($node->attributes['SIZE']);
    }
  }

  /**
   * คืนค่า CSS
   *
   * @param DOMNode $node
   */
  protected function restoredCSS($node)
  {
    // แบบตัวอักษร
    if (!empty($node->attributes['FONT-FAMILY'])) {
      $this->SetFont($node->FontFamily);
    }
    // สีกรอบ
    if (!empty($node->attributes['BORDER-COLOR']) && isset($node->DrawColor)) {
      $this->SetDrawColor($node->DrawColor['r'], $node->DrawColor['g'], $node->DrawColor['b']);
    }
    // สีพื้น
    if (!empty($node->attributes['BACKGROUND-COLOR']) && isset($node->FillColor)) {
      $this->SetFillColor($node->FillColor['r'], $node->FillColor['g'], $node->FillColor['b']);
    }
    // สีตัวอักษร
    if (!empty($node->attributes['COLOR']) && isset($node->TextColor)) {
      $this->SetTextColor($node->TextColor['r'], $node->TextColor['g'], $node->TextColor['b']);
    }
    // ตัวหนา
    if (!empty($node->attributes['FONT-WEIGHT'])) {
      $this->SetStyle('B', $node->attributes['FONT-WEIGHT'] != 'BOLD');
    }
    // ตัวเอียง
    if (!empty($node->attributes['FONT-STYLE'])) {
      $this->SetStyle('I', $node->attributes['FONT-STYLE'] != 'ITALIC');
    }
    // ขีดเส้นใต้
    if (!empty($node->attributes['TEXT-DECORATION'])) {
      $this->SetStyle('U', $node->attributes['TEXT-DECORATION'] != 'UNDERLINE');
    }
    // ขนาดตัวอักษร
    if (!empty($node->attributes['SIZE'])) {
      $this->SetFontSize($node->FontSizePt);
    }
  }

  /**
   * แสดงผลตัวหนา ตัวเอียง ขีดเส้นใต้
   *
   * @param string $style B I หรือ U
   * @param boolean $enable true เปิดใช้งาน, false ปิดใช้งาน
   */
  protected function SetStyle($style, $enable)
  {
    $this->$style += ($enable ? 1 : -1);
    $font_style = '';
    foreach (array('B', 'I', 'U') as $s) {
      if ($this->$s > 0) {
        $font_style .= $s;
      }
    }
    $this->SetFont('', $font_style);
  }

  /**
   * คำนวนขนาด
   *
   * @param int|string $size ขนาด เช่น 100%, 20px
   * @param int $max_size ขนาดที่ 100%
   * @return int
   */
  protected function calculateSize($size, $max_size)
  {
    if (preg_match('/^([0-9]+)(px|pt|mm|cm|in|\%)?$/', strtolower($size), $match)) {
      if ($match[2] == '%') {
        return ($max_size * (int)$match[1]) / 100;
      } else {
        return (int)$match[1];
      }
    }
    return (int)$size;
  }

  /**
   * เส้นคั่น
   *
   * @param DOMNode $node
   */
  protected function drawHr($node)
  {
    // ขึ้นบรรทัดใหม่
    $ln = 2;
    if (!$this->lastBlock) {
      if ($node->attributes['DISPLAY'] !== 'INLINE') {
        $ln = 7;
      } elseif ($node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
        $ln = 7;
      }
    }
    $this->Ln($ln);
    // current position
    $x = $this->GetX();
    $y = $this->GetY();
    // client width
    $cw = $this->w - $this->lMargin - $this->rMargin;
    if (empty($node->attributes['WIDTH'])) {
      // width 100%
      $w = $cw;
    } else {
      // width จากที่กำหนดมา
      $w = $this->calculateSize($node->attributes['WIDTH'], $cw);
      if (!empty($node->attributes['ALIGN']) && $cw > $w) {
        switch (strtoupper($node->attributes['ALIGN'])) {
          case 'CENTER':
            $x = ($cw - $w) / 2;
            break;
          case 'RIGHT':
            $x = $cw - $w;
            break;
        }
      }
    }
    if (!empty($node->attributes['COLOR'])) {
      $node->DrawColor = $this->DrawColor;
      list($r, $g, $b) = $this->colorToRGb($node->attributes['COLOR']);
      $this->SetDrawColor($r, $g, $b);
    }
    $lineWidth = $this->LineWidth;
    $this->SetLineWidth(0.4);
    $this->Line($x, $y, $x + $w, $y);
    $this->SetLineWidth($lineWidth);
    if (!empty($node->attributes['COLOR'])) {
      $this->DrawColor = $node->DrawColor;
    }
    // ขึ้นบรรทัดใหม่
    $this->Ln(2);
  }

  /**
   * แสดงรูปภาพ
   *
   * @param DOMNode $node
   */
  protected function drawImg($node)
  {
    if (isset($node->attributes['SRC']) && file_exists($node->attributes['SRC'])) {
      list($left, $top, $width, $height) = $this->resizeImage($node);
      if ($node->parentNode->nodeName == 'FIGURE') {
        $this->Image($node->attributes['SRC'], $left, $top, $width, $height);
        $this->lastBlock = true;
      } else {
        if ($node->attributes['DISPLAY'] == 'INLINE' && $node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
          // ขึ้นบรรทัดใหม่
          $x = $this->lMargin;
          $y = $this->y + $this->lineHeight;
        } else {
          // get current X and Y
          $x = $this->GetX();
          $y = $this->GetY();
        }
        $this->Image($node->attributes['SRC'], $x, $y);
        $this->x = $x + $width;
        $this->y = $y;
        $this->lastBlock = false;
      }
    }
  }

  /**
   * คำนวนตำแหน่งและปรับขนาดของรูปภาพ คืนค่าขนาดและตำปหน่งของรูปภาพ
   * ถ้ารูปภาพมีขนาดใหญ่กว่าพิ้นที่แสดงผลจะปรับขนาด
   * ถ้ารูปภาพมีขนาดเล็กกว่าพิ้นที่แสดงผล จะแสดงขนาดเดิม ตามตำแหน่งที่กำหนด
   *
   * @param DOMNode $node tag IMG
   * @return array array(left, top, width, height) top เป็น null เสมอ
   */
  protected function resizeImage($node)
  {
    list($width, $height) = getimagesize($node->attributes['SRC']);
    if ($width < $this->wPt && $height < $this->hPt) {
      $k = 72 / 96 / $this->k;
      $l = null;
      if (isset($node->parentNode->attributes['TEXT-ALIGN'])) {
        switch ($node->parentNode->attributes['TEXT-ALIGN']) {
          case 'CENTER':
            $l = ($this->w - ($width * $k)) / 2;
            break;
          case 'RIGHT':
            $l = ($this->w - ($width * $k));
            break;
        }
      }
      return array($l, null, $width * $k, $height * $k);
    } else {
      $ws = $this->wPt / $width;
      $hs = $this->hPt / $height;
      $scale = min($ws, $hs);
      if ($this->unit == 'pt') {
        $k = 1;
      } elseif ($this->unit == 'mm') {
        $k = 25.4 / 72;
      } elseif ($this->unit == 'cm') {
        $k = 2.54 / 72;
      } elseif ($this->unit == 'in') {
        $k = 1 / 72;
      }
      return array(null, null, ((($scale * $width) - 56.7) * $k), ((($scale * $height) - 56.7) * $k));
    }
  }

  /**
   * แปลงค่าสี HTML hex เช่น #FF0000 เป็นค่าสี RGB
   *
   * @param string $color ค่าสี HTML hex เช่น #FF0000
   * @return array คืนค่า array($r, $g, $b) เช่น #FF0000 = array(255, 0, 0)
   */
  protected function colorToRGb($color)
  {
    return array(
      hexdec(substr($color, 1, 2)),
      hexdec(substr($color, 3, 2)),
      hexdec(substr($color, 5, 2))
    );
  }

  /**
   * แสดงตาราง
   *
   * @param DOMNode $table
   */
  protected function drawTable($table)
  {
    if (!$this->lastBlock) {
      $this->Ln();
    }
    // คำนวณความกว้างของ Cell
    $columnSizes = $this->calculateColumnsWidth($table);
    // กำหนด CSS
    $this->applyCSS($table);
    // line-height
    $lineHeight = $this->lineHeight + 2;
    // thead, tbody, tfoot
    foreach ($table->childNodes as $table_group) {
      foreach ($table_group->childNodes as $tr) {
        // อ่าน CSS ของโหนด
        $this->loadStyle($tr);
        // คำนวณความสูงของแถว
        $h = 0;
        foreach ($tr->childNodes as $col => $td) {
          // apply css จาก tr
          foreach ($tr->attributes as $key => $value) {
            $td->attributes[$key] = $value;
          }
          // อ่าน CSS ของโหนด
          $this->loadStyle($td);
          // คำนวณจำนวนแถวของข้อความ
          $h = max($h, $this->NbLines($columnSizes[$col], $td->nodeValue));
        }
        $h = $h * $lineHeight;
        // ตรวจสอบการแบ่งหน้า
        $this->CheckPageBreak($h);
        // แสดงผล
        $y = $this->y;
        foreach ($tr->childNodes as $col => $td) {
          // กำหนด CSS
          $this->applyCSS($td);
          $align = '';
          if (!empty($td->attributes['TEXT-ALIGN'])) {
            $align = $td->attributes['TEXT-ALIGN'][0];
          }
          // current x
          $x = $this->x;
          // bg & border
          $this->Cell($columnSizes[$col], $h, '', 1, 0, '', !empty($td->attributes['BACKGROUND-COLOR']));
          // restore position
          $this->x = $x;
          $this->y = $y;
          // draw text
          $this->MultiCell($columnSizes[$col], $lineHeight, $td->nodeValue, 0, $align);
          // next cell
          $this->x = $x + $columnSizes[$col];
          $this->y = $y;
          // คืนค่า CSS
          $this->restoredCSS($td);
        }
        $this->SetXY($this->lMargin, $y + $h);
      }
    }
    // คืนค่า CSS
    $this->restoredCSS($table);
    // ขึ้นบรรทัดใหม่
    $this->lastBlock = true;
  }

  /**
   * คำนวนขนาดของคอลัมน์เป็น %
   *
   * @param DOMNode $table
   * @return array
   */
  protected function calculateColumnsWidth($table)
  {
    // page width
    $cw = $this->w - $this->lMargin - $this->rMargin;
    if (!empty($table->attributes['WIDTH'])) {
      // ความกว้างของตาราง width=xxx
      $table_width = $this->calculateSize($table->attributes['WIDTH'], $cw);
    }
    $columnSizes = array();
    foreach ($table->childNodes as $child) {
      foreach ($child->childNodes as $tr) {
        foreach ($tr->childNodes as $col => $td) {
          // อ่านข้อความใส่ลงในโหนด
          $td->nodeValue = $td->nodeText();
          // คำนวณความกว้างของข้อความ
          $td->textWidth = $this->GetStringWidth($td->nodeValue);
          // ลบโหนดลูกออก
          unset($td->childNodes);
          // ความกว้างของ cell
          $length = isset($table_width) && !empty($td->attributes['WIDTH']) ? $this->calculateSize($td->attributes['WIDTH'], $table_width) : $td->textWidth;
          $columnSizes[$col]['max'] = !isset($columnSizes[$col]['max']) ? $length : ($columnSizes[$col]['max'] < $length ? $length : $columnSizes[$col]['max']);
          $columnSizes[$col]['avg'] = !isset($columnSizes[$col]['avg']) ? $length : $columnSizes[$col]['avg'] + $length;
          $columnSizes[$col]['raw'][] = $length;
        }
      }
    }
    $columnSizes = array_map(function ($columnSize) {
      $columnSize['avg'] = $columnSize['avg'] / sizeof($columnSize['raw']);
      return $columnSize;
    }, $columnSizes);
    foreach ($columnSizes as $key => $columnSize) {
      $colMaxSize = $columnSize['max'];
      $colAvgSize = $columnSize['avg'];
      $stdDeviation = $this->sd($columnSize['raw']);
      $coefficientVariation = $stdDeviation / $colAvgSize;
      $columnSizes[$key]['cv'] = $coefficientVariation;
      $columnSizes[$key]['stdd'] = $stdDeviation;
      $columnSizes[$key]['stdd/max'] = $stdDeviation / $colMaxSize;
      if (($columnSizes[$key]['stdd/max'] < 0.3 || $coefficientVariation == 1) && ($coefficientVariation == 0 || ($coefficientVariation > 0.6 && $coefficientVariation < 1.5))) {
        $columnSizes[$key]['calc'] = $colAvgSize;
      } else {
        if ($coefficientVariation > 1 && $columnSizes[$key]['stdd'] > 4.5 && $columnSizes[$key]['stdd/max'] > 0.2) {
          $tmp = ($colMaxSize - $colAvgSize) / 2;
        } else {
          $tmp = 0;
        }
        $columnSizes[$key]['calc'] = $colAvgSize + ($colMaxSize / $colAvgSize) * 2 / abs(1 - $coefficientVariation);
        $columnSizes[$key]['calc'] = $columnSizes[$key]['calc'] > $colMaxSize ? $colMaxSize - $tmp : $columnSizes[$key]['calc'];
      }
    }
    $totalCalculatedSize = 0;
    foreach ($columnSizes as $columnSize) {
      $totalCalculatedSize += $columnSize['calc'];
    }
    $result = array();
    foreach ($columnSizes as $key => $columnSize) {
      if (empty($table_width)) {
        $result[$key] = 100 / ($totalCalculatedSize / $columnSize['calc']);
      } else {
        $result[$key] = ($columnSize['calc'] * $table_width) / $totalCalculatedSize;
      }
    }
    return $result;
  }

  /**
   * calculate standard deviation.
   *
   * @param $array
   * @return float
   */
  protected function sd($array)
  {
    if (sizeof($array) == 1) {
      return 1.0;
    }
    $sd_square = function ($x, $mean) {
      return pow($x - $mean, 2);
    };
    return sqrt(array_sum(array_map($sd_square, $array, array_fill(0, sizeof($array), (array_sum($array) / sizeof($array))))) / (sizeof($array) - 1));
  }

  /**
   * Output a cell
   *
   * @param int $w ความกว้าง, 0 คำนวณอัตโนมัติ
   * @param int $h line-height
   * @param string $txt ข้อความที่แสดง
   * @param int|string $border 0 ไม่แสดง, 1 แสดงทั้ง 4 ด้าน, LTRB กำหนดเอง
   * @param int $ln ตำแหน่งหลังจากวาดแล้ว 0 (default) ไปทางขวา, 1 กลับไปจุดเริ่มต้น, 2 บรรทัดถัดไป
   * @param string $align L หรือค่าว่าง (default) ชิดซ้าย, R ชิดขวา, C ตรงกลาง, J justify (default)
   * @param boolean $fill true แสดงพื้นหลัง, false โปร่งใส
   * @param string $link URL
   * @param int $tPadding padding-top
   * @param int $rPadding padding-right
   * @param int $bPadding padding-bottom
   * @param int $lPadding padding-right
   */
  public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $tPadding = 0, $rPadding = 0, $bPadding = 0, $lPadding = 0)
  {
    $k = $this->k;
    if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
      // Automatic page break
      $x = $this->x;
      $ws = $this->ws;
      if ($ws > 0) {
        $this->ws = 0;
        $this->_out('0 Tw');
      }
      $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
      $this->x = $x;
      if ($ws > 0) {
        $this->ws = $ws;
        $this->_out(sprintf('%.3F Tw', $ws * $k));
      }
    }
    if ($w == 0) {
      $w = $this->w - $this->rMargin - $this->x;
    }
    $s = '';
    if ($fill || $border == 1) {
      if ($fill) {
        $op = ($border == 1) ? 'B' : 'f';
      } else {
        $op = 'S';
      }
      $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x * $k, ($this->h - $this->y + $tPadding) * $k, $w * $k, (-$h - $bPadding - $tPadding) * $k, $op);
    }
    if (is_string($border)) {
      $x = $this->x;
      $y = $this->y;
      if (strpos($border, 'L') !== false) {
        $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y + $tPadding) * $k, $x * $k, ($this->h - ($y + $h) - $bPadding) * $k);
      }
      if (strpos($border, 'T') !== false) {
        $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y + $tPadding) * $k, ($x + $w) * $k, ($this->h - $y + $tPadding) * $k);
      }
      if (strpos($border, 'R') !== false) {
        $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->h - $y + $tPadding) * $k, ($x + $w) * $k, ($this->h - ($y + $h) - $bPadding) * $k);
      }
      if (strpos($border, 'B') !== false) {
        $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - ($y + $h) - $bPadding) * $k, ($x + $w) * $k, ($this->h - ($y + $h) - $bPadding) * $k);
      }
    }
    if ($txt !== '') {
      if (!isset($this->CurrentFont)) {
        $this->Error('No font has been set');
      }
      if ($align == 'R') {
        $dx = $w - $this->cMargin - $this->GetStringWidth($txt) - $rPadding;
      } elseif ($align == 'C') {
        $dx = ($w - $this->GetStringWidth($txt)) / 2;
      } else {
        $dx = $this->cMargin + $lPadding;
      }
      if ($this->ColorFlag) {
        $s .= 'q '.$this->TextColor.' ';
      }
      $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x + $dx) * $k, ($this->h - ($this->y + .5 * $h + .3 * $this->FontSize)) * $k, $this->_escape($txt));
      if ($this->underline) {
        $s .= ' '.$this->_dounderline($this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $txt);
      }
      if ($this->ColorFlag) {
        $s .= ' Q';
      }
      if ($link) {
        $this->Link($this->x + $dx, $this->y + .5 * $h - .5 * $this->FontSize, $this->GetStringWidth($txt), $this->FontSize, $link);
      }
    }
    if ($s) {
      $this->_out($s);
    }
    $this->lasth = $h;
    if ($ln > 0) {
      // Go to next line
      $this->y += $h;
      if ($ln == 1) {
        $this->x = $this->lMargin;
      }
    } else {
      $this->x += $w;
    }
  }

  /**
   * Output text with automatic or explicit line breaks
   *
   * @param int $w ความกว้าง, 0 คำนวณอัตโนมัติ
   * @param int $h line-height
   * @param string $s ข้อความที่แสดง
   * @param int|string $border 0 ไม่แสดง, 1 แสดงทั้ง 4 ด้าน, LTRB กำหนดเอง
   * @param string $align L หรือค่าว่างชิดซ้าย, R ชิดขวา, C ตรงกลาง, J justify (default)
   * @param boolean $fill true แสดงพื้นหลัง, false โปร่งใส
   * @param int $tPadding padding-top
   * @param int $rPadding padding-right
   * @param int $bPadding padding-bottom
   * @param int $lPadding padding-right
   */
  public function MultiCell($w, $h, $s, $border = 0, $align = 'J', $fill = false, $tPadding = 0, $rPadding = 0, $bPadding = 0, $lPadding = 0)
  {
    if (!isset($this->CurrentFont)) {
      $this->Error('No font has been set');
    }
    if ($border == 1) {
      $border = 'RLTB';
    }
    $startY = $this->y;
    $this->y += $tPadding;
    $cw = &$this->CurrentFont['cw'];
    if ($w == 0) {
      $cell_width = $this->w - $this->rMargin - $this->x;
      $w = $cell_width - $lPadding - $rPadding;
    } else {
      $cell_width = $w;
    }
    $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
    $nb = strlen($s);
    if ($nb > 0 && $s[$nb - 1] == "\n") {
      $nb--;
    }
    $sep = -1;
    $i = 0;
    $j = 0;
    $l = 0;
    $ns = 0;
    $nl = 1;
    while ($i < $nb) {
      // ตัวอักษรถัดไป
      $c = $s[$i];
      if ($c == "\n") {
        // ขึ้นบรรทัดใหม่
        if ($this->ws > 0) {
          $this->ws = 0;
          $this->_out('0 Tw');
        }
        if ($nl == 1) {
          // บรรทัดแรก
          $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
        } else {
          $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
        }
        $i++;
        $sep = -1;
        $j = $i;
        $l = 0;
        $ns = 0;
        $nl++;
        continue;
      } elseif ($c == ' ') {
        $sep = $i;
        $ls = $l;
        $ns++;
      }
      $l += $cw[$c];
      if ($l > $wmax) {
        // ขึ้นบรรทัดใหม่เมื่อข้อความเกินกว่าความกว้างของเอกสาร
        if ($sep == -1) {
          if ($i == $j) {
            $i++;
          }
          if ($this->ws > 0) {
            $this->ws = 0;
            $this->_out('0 Tw');
          }
          if ($nl == 1) {
            // บรรทัดแรก
            $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
          } else {
            $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
          }
        } else {
          if ($align == 'J') {
            $this->ws = ($ns > 1) ? ($wmax - $ls) / 1000 * $this->FontSize / ($ns - 1) : 0;
            $this->_out(sprintf('%.3F Tw', $this->ws * $this->k));
          }
          if ($nl == 1) {
            // บรรทัดแรก
            $this->Cell($cell_width, $h, substr($s, $j, $sep - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
          } else {
            $this->Cell($cell_width, $h, substr($s, $j, $sep - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
          }
          $i = $sep + 1;
        }
        $sep = -1;
        $j = $i;
        $l = 0;
        $ns = 0;
        $nl++;
      } else {
        $i++;
      }
    }
    // Last chunk
    if ($this->ws > 0) {
      $this->ws = 0;
      $this->_out('0 Tw');
    }
    if ($nl == 1) {
      // บรรทัดเดียว
      $this->Cell($cell_width, $h, substr($s, $j, $i - $j), $border, 2, $align, $fill, '', $tPadding, $rPadding, $bPadding, $lPadding);
    } else {
      // บรรทัดสุดท้าย
      $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('T', '', $border), 2, $align, $fill, '', 0, $rPadding, $bPadding, $lPadding);
    }
    $this->y += $bPadding;
    $this->x = $this->lMargin;
  }

  /**
   * คำนวณความสูงของเซล
   *
   * @param int $w
   * @param string $s
   * @return int
   */
  protected function NbLines($w, $s)
  {
    $cw = &$this->CurrentFont['cw'];
    if ($w == 0) {
      $w = $this->w - $this->rMargin - $this->x;
    }
    $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
    $nb = strlen($s);
    if ($nb > 0 && $s[$nb - 1] == "\n") {
      $nb--;
    }
    $sep = -1;
    $i = 0;
    $j = 0;
    $l = 0;
    $nl = 1;
    while ($i < $nb) {
      $c = $s[$i];
      if ($c == "\n") {
        $i++;
        $sep = -1;
        $j = $i;
        $l = 0;
        $nl++;
        continue;
      }
      if ($c == ' ') {
        $sep = $i;
      }
      $l+=$cw[$c];
      if ($l > $wmax) {
        if ($sep == -1) {
          if ($i == $j) {
            $i++;
          }
        } else {
          $i = $sep + 1;
        }
        $sep = -1;
        $j = $i;
        $l = 0;
        $nl++;
      } else {
        $i++;
      }
    }
    return $nl;
  }

  /**
   * ตรวจสอบความสูงของตาราง ถ้าความสูงของตารางเกินหน้า
   * จะขึ้นหน้าใหม่
   *
   * @param int $h ความสูงของตาราง
   */
  protected function CheckPageBreak($h)
  {
    if ($this->GetY() + $h > $this->PageBreakTrigger) {
      $this->AddPage($this->CurOrientation);
    }
  }

  /**
   * ขึ้นบรรทัดใหม่
   *
   * @param int $h line-height ถ้าไม่กำหนดจะใช้ค่าล่าสุด
   */
  public function Ln($h = null)
  {
    // ขึ้นบรรทัดใหม่
    $this->x = $this->lMargin;
    $this->y += ($h ? $h : $this->lineHeight);
    // บอกว่าขึ้นบรรทัดใหม้แล้ว
    $this->lastBlock = true;
  }
}
