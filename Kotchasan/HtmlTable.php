<?php
/**
 * @filesource Kotchasan/HtmlTable.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * HTML table
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class HtmlTable
{
  /**
   * แอเรย์เก็บข้อมูลส่วน thead
   *
   * @var array
   */
  private $thead;
  /**
   * แอเรย์ของ TableRow เก็บแถวของตาราง (tbody)
   *
   * @var array
   */
  private $tbody;
  /**
   * แอเรย์ของ TableRow เก็บแถวของตาราง (tfoot)
   *
   * @var array
   */
  private $tfoot;
  /**
   * caption ของ ตาราง
   *
   * @var string
   */
  private $caption;
  /**
   * แอเรย์เก็บ property ของตาราง
   *
   * @var array
   */
  private $properties;

  /**
   * class constructure
   *
   * @param array $properties
   */
  public function __construct($properties = array())
  {
    $this->tbody = array();
    $this->tfoot = array();
    $this->thead = array();
    $this->properties = $properties;
  }

  /**
   * สร้างตาราง
   *
   * @param array $properties
   * @return \static
   */
  public static function create($properties = array())
  {
    $obj = new static($properties);
    return $obj;
  }

  /**
   * กำหนด caption ของตาราง
   *
   * @param string $text
   */
  public function addCaption($text)
  {
    $this->caption = $text;
  }

  /**
   * แทรกแถวของ thead
   *
   * @param array $headers
   */
  public function addHeader($headers)
  {
    $this->thead[] = $headers;
  }

  /**
   * แทรกแถวของ tbody
   *
   * @param array $rows
   * @param array $attributes
   */
  public function addRow($rows, $attributes = array())
  {
    $tr = TableRow::create($attributes);
    foreach ($rows as $td) {
      $tr->addCell($td);
    }
    $this->tbody[] = $tr;
  }

  /**
   * แทรกแถวของ tfoot
   *
   * @param TableRow $row
   */
  public function addFooter(TableRow $row)
  {
    $this->tfoot[] = $row;
  }

  /**
   * แสดงผลตาราง
   *
   * @return string
   */
  public function render()
  {
    $prop = array();
    foreach ($this->properties as $k => $v) {
      $prop[] = $k.'="'.$v.'"';
    }
    $table = array("\n<table".(empty($prop) ? '' : ' '.implode(' ', $prop)).'>');
    if (!empty($this->caption)) {
      $table[] = '<caption>'.$this->caption.'</caption>';
    }
    // thead
    if (!empty($this->thead)) {
      $thead = array();
      foreach ($this->thead as $r => $rows) {
        $tr = array();
        foreach ($rows as $c => $th) {
          $prop = array('id' => 'id="c'.$c.'"', 'scope' => 'scope="col"');
          foreach ($th as $key => $value) {
            if ($key != 'text') {
              $prop[$key] = $key.'="'.$value.'"';
            }
          }
          $tr[] = '<th '.implode(' ', $prop).'>'.(isset($th['text']) ? $th['text'] : '').'</th>';
        }
        if (!empty($tr)) {
          $thead[] = "<tr>\n".implode("\n", $tr)."\n</tr>";
        }
      }
      if (!empty($thead)) {
        $table[] = "<thead>\n".implode("\n", $thead)."\n</thead>";
      }
    }
    // tfoot
    if (!empty($this->tfoot)) {
      $rows = array();
      foreach ($this->tfoot as $tr) {
        $rows[] = $tr->render();
      }
      if (!empty($rows)) {
        $table[] = "<tfoot>\n".implode("\n", $rows)."\n</tfoot>";
      }
    }
    // tbody
    if (!empty($this->tbody)) {
      $rows = array();
      foreach ($this->tbody as $tr) {
        $rows[] = $tr->render();
      }
      if (!empty($rows)) {
        $table[] = "<tbody>\n".implode("\n", $rows)."\n</tbody>";
      }
    }
    $table[] = "</table>\n";
    return implode("\n", $table);
  }
}

/**
 * HTML table row
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class TableRow
{
  /**
   * property ของแถว
   *
   * @var array
   */
  private $properties;
  /**
   * แอเรย์เก็บรายการ cell ในแถว
   *
   * @var array
   */
  private $tds;

  /**
   * class constructure
   *
   * @param array $properties
   */
  public function __construct($properties = array())
  {
    $this->properties = $properties;
    $this->tds = array();
  }

  /**
   * สร้างแถวสำหรับ tbody
   *
   * @param array $properties
   * @return \static
   */
  public static function create($properties = array())
  {
    $obj = new static($properties);
    return $obj;
  }

  /**
   * เพิ่ม cell ลงในแถว
   *
   * @param array $td
   */
  public function addCell($td)
  {
    $this->tds[] = $td;
  }

  /**
   * แสดงผลแถว
   *
   * @return string
   */
  public function render()
  {
    $prop = array();
    foreach ($this->properties as $key => $value) {
      $prop[$key] = $key.'="'.$value.'"';
    }
    $row = array('<tr '.implode(' ', $prop).'>');
    foreach ($this->tds as $c => $td) {
      $prop = array();
      $tag = 'td';
      foreach ($td as $key => $value) {
        if ($key == 'scope') {
          $tag = 'th';
          $prop['scope'] = 'scope="'.$value.'"';
          if (isset($this->properties['id'])) {
            $prop['id'] = 'id="r'.$this->properties['id'].'"';
          }
        } elseif ($key != 'text') {
          $prop[$key] = $key.'="'.$value.'"';
        }
      }
      if (isset($this->properties['id'])) {
        $prop['headers'] = $tag == 'th' ? 'headers="c'.$c.'"' : 'headers="r'.$this->properties['id'].' c'.$c.'"';
      }
      $tr[] = '<'.$tag.' '.implode(' ', $prop).'>'.(isset($th['text']) ? $th['text'] : '').'</'.$tag.'>';
      $row[] = '<'.$tag.' '.implode(' ', $prop).'>'.(empty($td['text']) ? '' : $td['text']).'</'.$tag.'>';
    }
    $row[] = '</tr>';
    return implode("\n", $row);
  }
}
