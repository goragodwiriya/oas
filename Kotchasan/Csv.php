<?php
/**
 * @filesource Kotchasan/Csv.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * CSV function
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Csv
{
  private $columns;
  private $datas;
  private $charset;
  private $keys;

  /**
   * ฟังก์ชั่นนำเข้าข้อมูล CSV
   *
   * @param string $csv ชื่อไฟล์รวมพาธ
   * @param array $columns ข้อมูลคอลัมน์ array('column1' => 'data type', 'column2' => 'data type', ....)
   * @param array $keys ชื่อคอลัมน์สำหรับตรวจข้อมูลซ้ำ คอลัมน์นี้ต้องไม่เป็นค่าว่าง
   * @param string $charset รหัสภาษาของไฟล์ ค่าเริ่มต้นคือ Windows-874 (ภาษาไทย)
   * @param int $skip จำนวนแถวของ header ที่ข้ามการอ่านข้อมูล ค่าเริ่มต้นคือ 1
   * @return array คืนค่าข้อมูลที่อ่านได้เป็นแอเรย์
   */
  public static function import($csv, $columns, $keys = array(), $charset = 'Windows-874', $skip = 1)
  {
    $obj = new static;
    $obj->columns = $columns;
    $obj->datas = array();
    $obj->charset = strtoupper($charset);
    $obj->keys = $keys;
    $obj->read($csv, array($obj, 'importDatas'));
    return $obj->datas;
  }

  /**
   * ฟังก์ชั่นรับค่าจากการอ่าน CSV
   *
   * @param array $data
   */
  private function importDatas($data)
  {
    $save = array();
    $n = 0;
    foreach ($this->columns as $key => $type) {
      $save[$key] = null;
      if (isset($data[$n])) {
        if (is_array($type)) {
          $save[$key] = call_user_func($type, $data[$n]);
        } elseif ($type == 'int') {
          $save[$key] = (int)$data[$n];
        } elseif ($type == 'double') {
          $save[$key] = (double)$data[$n];
        } elseif ($type == 'float') {
          $save[$key] = (float)$data[$n];
        } elseif ($type == 'number') {
          $save[$key] = preg_replace('/[^0-9]+/', '', $data[$n]);
        } elseif ($type == 'en') {
          $save[$key] = preg_replace('/[^a-zA-Z0-9]+/', '', $data[$n]);
        } elseif ($type == 'date') {
          if (preg_match('/^([0-9]{4,4})\-([0-9]{2,2})\-([0-9]{2,2})$/', $data[$n])) {
            $save[$key] = $data[$n];
          }
        } elseif ($type == 'datetime') {
          if (preg_match('/^([0-9]{4,4})\-([0-9]{2,2})\-([0-9]{2,2})\s([0-9]{2,2}):([0-9]{2,2}):([0-9]{2,2})$/', $data[$n])) {
            $save[$key] = $data[$n];
          }
        } elseif ($type == 'time') {
          if (preg_match('/^([0-9]{2,2}):([0-9]{2,2}):([0-9]{2,2})$/', $data[$n])) {
            $save[$key] = $data[$n];
          }
        } elseif ($this->charset == 'UTF-8') {
          $save[$key] = \Kotchasan\Text::topic($data[$n]);
        } else {
          $save[$key] = iconv($this->charset, 'UTF-8', \Kotchasan\Text::topic($data[$n]));
        }
      }
      $n++;
    }
    if (empty($this->keys)) {
      $this->datas[] = $save;
    } else {
      $keys = '';
      foreach ($this->keys as $item) {
        if ($save[$item] !== null && $save[$item] !== '') {
          $keys .= $save[$item];
        } else {
          $save = null;
          continue;
        }
      }
      if (!empty($save) && !isset($this->datas[$keys])) {
        $this->datas[$keys] = $save;
      }
    }
  }

  /**
   * อ่านไฟล์ CSV ทีละแถวส่งไปยังฟังก์ชั่น $onRow
   *
   * @param string $file ชื่อไฟล์รวมพาธ
   * @param callable $onRow ฟังก์ชั่นรับค่าแต่ละแถว มีพารามิเตอร์คือ $data
   * @param int $skip จำนวนแถวของ header ที่ข้ามการอ่านข้อมูล ค่าเริ่มต้นคือ 1
   */
  public static function read($file, $onRow, $skip = 1)
  {
    $n = 0;
    $f = @fopen($file, 'r');
    if ($f) {
      $i = 0;
      while (($data = fgetcsv($f)) !== false) {
        if ($i < $skip) {
          $i++;
        } else {
          call_user_func($onRow, $data);
        }
      }
      fclose($f);
    }
  }

  /**
   * สร้างไฟล์ CSV สำหรับดาวน์โหลด
   *
   * @param string $file ชื่อไฟล์ ไม่ต้องมีนามสกุล
   * @param array $header ส่วนหัวของข้อมูล
   * @param array $datas ข้อมูล
   * @param string $charset ภาษาของ CSV ค่าเริ่มต้นคือ Windows-874 (ภาษาไทย)
   */
  public static function send($file, $header, $datas, $charset = 'Windows-874')
  {
    // header
    header('Content-Type: text/csv;charset="'.$charset.'"');
    header('Content-Disposition: attachment;filename="'.$file.'.csv"');
    // create stream
    $f = fopen('php://output', 'w');
    // charset
    $charset = strtoupper($charset);
    // csv header
    if (!empty($header)) {
      fputcsv($f, self::convert($header, $charset));
    }
    // content
    foreach ($datas as $item) {
      fputcsv($f, self::convert($item, $charset));
    }
    // close
    fclose($f);
  }

  /**
   * แปลงข้อมูลเป็นภาษาที่เลือก
   *
   * @param array $datas
   * @param string $charset
   * @return array
   */
  private static function convert($datas, $charset)
  {
    if ($charset != 'UTF-8') {
      foreach ($datas as $k => $v) {
        if ($v != '') {
          $datas[$k] = iconv('UTF-8', $charset.'//IGNORE', $v);
        }
      }
    }
    return $datas;
  }
}