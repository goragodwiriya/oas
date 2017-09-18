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
