<?php
/**
 * @filesource Kotchasan/ArrayTool.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Array function class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class ArrayTool
{

  /**
   * ฟังก์ชั่น เรียงลำดับ array ตามชื่อฟิลด์
   *
   * @param array $array แอเรย์ที่ต้องการเรียงลำดับ
   * @param string $sort_key (optional) คืย์ของ $array ที่ต้องการในการเรียง (default id)
   * @param boolean $sort_desc true=เรียงจากมากไปหาน้อย, false=เรียงจากน้อยไปหามาก (default false)
   * @return array
   *
   * @assert (array(array('id' => 2, 'value' => 'two'), array('id' => 3, 'value' => 'three'), array('id' => 1, 'value' => 'one'))) [==] array(array('id' => 1, 'value' => 'one'), array('id' => 2, 'value' => 'two'), array('id' => 3, 'value' => 'three'))
   */
  public static function sort($array, $sort_key = 'id', $sort_desc = false)
  {
    if (!empty($array)) {
      $temp_array[key($array)] = array_shift($array);
      foreach ($array as $key => $val) {
        $offset = 0;
        $found = false;
        foreach ($temp_array as $tmp_key => $tmp_val) {
          $v1 = isset($val[$sort_key]) ? strtolower(self::toString('', $val[$sort_key])) : '';
          $v2 = isset($tmp_val[$sort_key]) ? strtolower(self::toString('', $tmp_val[$sort_key])) : '';
          if (!$found && $v1 > $v2) {
            $temp_array = array_merge((array)array_slice($temp_array, 0, $offset), array($key => $val), array_slice($temp_array, $offset));
            $found = true;
          }
          $offset++;
        }
        if (!$found) {
          $temp_array = array_merge($temp_array, array($key => $val));
        }
      }
      if ($sort_desc) {
        return $temp_array;
      } else {
        return array_reverse($temp_array);
      }
    }
    return $array;
  }

  /**
   * เลือกรายการ array ที่มีข้อมูลที่กำหนด
   *
   * @param array $array
   * @param string $search ข้อมูลที่ต้องการ
   * @return array
   *
   * @assert (array('one', 'One', 'two'), 'one') [==] array('one', 'One')
   */
  public static function filter($array, $search)
  {
    if ($search == '') {
      return $array;
    } else {
      $result = array();
      foreach ($array as $key => $value) {
        if (stripos(self::toString(' ', $value), $search) !== false) {
          $result[$key] = $value;
        }
      }
      return $result;
    }
  }

  /**
   * คืนค่ารายการที่มีคอลัมน์ตามที่กำหนด
   *
   * @param array $array
   * @param string $column_key ชื่อคอลัมน์ที่ต้องการ
   * @param mixed $index_key null คืนค่า index ของ $array, string คืนค่า index จากคอลัมน์ที่กำหนด
   * @return array
   *
   * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name') [==] array(0 => 'one', 1 => 'two', 2 => 'three')
   * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'three')), 'name', 'id') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   */
  public static function columns($array, $column_key, $index_key = null)
  {
    $result = array();
    if ($index_key == null) {
      foreach ($array as $i => $item) {
        if (isset($item[$column_key])) {
          $result[$i] = $item[$column_key];
        }
      }
    } else {
      foreach ($array as $i => $item) {
        if (isset($item[$column_key])) {
          $result[$item[$index_key]] = $item[$column_key];
        }
      }
    }
    return $result;
  }

  /**
   * ค้นหาแอเรย์ จากคอลัมน์
   * คืนค่า index ตาม array ต้นฉบับ
   *
   * @param array $input ข้อมูลแอเรย์
   * @param mixed $key คอลัมน์ที่ต้องการค้นหา
   * @param mixed $search ข้อความค้นหา
   * @return array คืนค่าทุกรายการที่พบ และ คืนค่าแอเรย์ว่างถ้าไม่พบ
   *
   * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'one')), 'name', 'one') [==] array(0 => array('id' => 1, 'name' => 'one'), 2 => array('id' => 3, 'name' => 'one'))
   * @assert (array(array('id' => 1, 'name' => 'one'), array('id' => 2, 'name' => 'two'), array('id' => 3, 'name' => 'one')), 'id', 'one') [==] array()
   */
  public static function search($input, $key, $search)
  {
    $result = array();
    foreach ($input as $i => $values) {
      if (isset($values[$key]) && $values[$key] == $search) {
        $result[$i] = $values;
      }
    }
    return $result;
  }

  /**
   * แปลงแอเรย์ $array เป็น string คั่นด้วย $glue
   *
   * @param string $glue ตัวคั่นข้อมูล
   * @param array $array แอเรย์ที่ต้องการนำมาเชื่อม
   * @return string
   *
   * @assert ('|', array('a' => 'A', 'b' => array('b', 'B'), 'c' => array('c' => array('c', 'C')))) [==] "A|b|B|c|C"
   */
  public static function toString($glue, $array)
  {
    if (is_array($array)) {
      $result = array();
      foreach ($array as $key => $value) {
        if (is_array($value)) {
          $result[] = self::toString($glue, $value);
        } else {
          $result[] = $value;
        }
      }
      return implode($glue, $result);
    } else {
      return $array;
    }
  }

  /**
   * ลบรายการที่ id สามารถลบได้หลายรายการโดยคั่นแต่ละรายการด้วย ,
   * รักษาคีย์ของรายการเดิมไว้
   *
   * @param array $array
   * @param string $ids รายการที่ต้องการลบ 1 หรือ 1,2,3
   * @return array คืนค่า array ใหม่หลังจากลบแล้ว
   *
   * @assert (array(0, 1, 2, 3, 4, 5), '0,2') [==] array(1 => 1, 3 => 3, 4 => 4, 5 => 5)
   */
  public static function delete($array, $ids)
  {
    $temp = array();
    $ids = explode(',', $ids);
    foreach ($array as $k => $v) {
      if (!in_array($k, $ids)) {
        $temp[$k] = $v;
      }
    }
    return $temp;
  }

  /**
   * ฟังก์ชั่นแยก $key และ $value ออกจาก array รองรับข้อมูลรูปแบบแอเรย์ย่อยๆ
   *
   * @param array $array array('key1' => 'value1', 'key2' => 'value2', array('key3' => 'value3', 'key4' => 'value4'))
   * @param array $keys คืนค่า $key Array ( [0] => key1 [1] => key2 [2] => key3 [3] => key4 )
   * @param array $values คืนค่า $value Array ( [0] => value1 [1] => value2 [2] => value3 [3] => value4 )
   */
  public static function extract($array, &$keys, &$values)
  {
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        self::extract($array[$key], $keys, $values);
      } else {
        $keys[] = $key;
        $values[] = $value;
      }
    }
  }

  /**
   * ฟังก์ชั่นรวมแอเรย์ รักษาแอเรย์ต้นฉบับไว้ และแทนที่ข้อมูลด้วยข้อมูลใหม่
   *
   * @param array $source แอเร์ยต้นฉบับ
   * @param array|object $replace ข้อมูลที่จะนำมาแทนที่ลงในแอเร์ยต้นฉบับ
   * @return array
   *
   * @assert (array(1 => 1, 2 => 2, 3 => 'three'), array(1 => 'one', 2 => 'two')) [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   */
  public static function replace($source, $replace)
  {
    foreach ($replace as $key => $value) {
      $source[$key] = $value;
    }
    return $source;
  }

  /**
   * ฟังก์ชั่นรวมแอเรย์ รักษาแอเรย์ต้นฉบับไว้ แทนที่แอเร์ยย่อยด้วย
   *
   * @param array $source แอเร์ยต้นฉบับ
   * @param array|object $with ข้อมูลที่จะนำมารวม
   * @assert (array(1 => 1, 2 => 2, 3 => 'three'), array(1 => 'one', 2 => 'two')) [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   * @return array
   */
  public static function merge($source, $with)
  {
    foreach (func_get_args() As $i => $items) {
      if ($i == 0) {
        $source = $items;
      } elseif (is_array($items) || is_object($items)) {
        foreach ($items as $key => $value) {
          if (isset($source[$key]) && is_array($source[$key])) {
            $source[$key] = self::merge($source[$key], $value);
          } else {
            $source[$key] = $value;
          }
        }
      }
    }
    return $source;
  }

  /**
   * ฟังก์ชั่นแปลงข้อความ serialize เป็นแอเรย์
   * และรวมข้อมูลเข้ากับ $source
   *
   * @param string $str ข้อมูล serialize
   * @param array $source ข้อมูลตั้งต้น ถ้าใช้ฟังก์ชั่นนี้ในการแปลงข้อมูล ค่านี้จะเป็นแอเรย์ว่าง (ค่าเริ่มต้น)
   * @param boolean $replace true (default) แทนที่ข้อมูลเดิม, false เก็บข้อมูลเดิมไว้
   * @return array
   *
   * @assert ('') [==] array()
   * @assert (serialize(array(1, 2, 3))) [==] array(1, 2, 3)
   * @assert (serialize(array(1 => 'One', 2 => 'Two', 3 => 'Three')), array(3 => 3, 4 => 'Four'), true) [==] array(1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four')
   * @assert (serialize(array(1 => 'One', 2 => 'Two', 3 => 'Three')), array(3 => 3, 4 => 'Four'), false) [==] array(1 => 'One', 2 => 'Two', 3 => 3, 4 => 'Four')
   */
  public static function unserialize($str, $source = array(), $replace = true)
  {
    if ($str != '') {
      $datas = @unserialize($str);
      if (is_array($datas)) {
        foreach ($datas as $key => $value) {
          if ($replace || !isset($source[$key])) {
            $source[$key] = $value;
          }
        }
      }
    }
    return $source;
  }

  /**
   * อ่านคีย์รายการแรก
   *
   * @param array $array
   * @return mix
   */
  public static function getFirstKey($array)
  {
    foreach ($array as $key => $value) {
      return $key;
    }
  }

  /**
   * แทรกข้อมูลลงในแอเรย์ ข้อมูลที่จะนำมาแทรกอยู่ก่อน
   * ถ้าไม่พบ $find ข้อมูลจะแทรกรายการสุดท้าย
   *
   * @param array $source แอเรย์ต้นฉบับ
   * @param int|string $find คีย์ของแอเรย์ต้นฉบับจุดที่จะแทรก ข้อมูลต้องเป็นชนิดเดียวกันกับคีย์ของแอเรย์ต้นฉบับ
   * @param int|string $key คีย์ของข้อมูลที่จะนำมาแทรก
   * @param mixed $value ข้อมูลที่จะนำมาแทรก
   * @return array
   *
   * @assert (array('one' => 1, 'three' => 3), 'three', 'two', 2) [==] array('one' => 1, 'two' => 2, 'three' => 3)
   * @assert (array(1 => 'one', 3 => 'three'), 3, 2, 'two') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   * @assert (array(1 => 'one', 3 => 'three'), 2, 2, 'two') [==] array(1 => 'one', 3 => 'three', 2 => 'two')
   */
  public static function insertBefore($source, $find, $key, $value)
  {
    $result = array();
    foreach ($source as $k => $v) {
      if ($find && $find === $k) {
        $result[$key] = $value;
        $find = null;
      }
      $result[$k] = $v;
    }
    if ($find !== null) {
      $result[$key] = $value;
    }
    return $result;
  }

  /**
   * แทรกข้อมูลลงในแอเรย์ ข้อมูลที่จะนำมาแทรกอยู่ถัดไป
   * ถ้าไม่พบ $find ข้อมูลจะแทรกรายการสุดท้าย
   *
   * @param array $source แอเรย์ต้นฉบับ
   * @param int|string $find คีย์ของแอเรย์ต้นฉบับจุดที่จะแทรก ข้อมูลต้องเป็นชนิดเดียวกันกับคีย์ของแอเรย์ต้นฉบับ
   * @param int|string $key คีย์ของข้อมูลที่จะนำมาแทรก
   * @param mixed $value ข้อมูลที่จะนำมาแทรก
   * @return array
   *
   * @assert (array('one' => 1, 'two' => 2), 'two', 'three', 3) [==] array('one' => 1, 'two' => 2, 'three' => 3)
   * @assert (array(1 => 'one', 2 => 'two'), 2, 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   * @assert (array(1 => 'one', 2 => 'two'), 3, 3, 'three') [==] array(1 => 'one', 2 => 'two', 3 => 'three')
   */
  public static function insertAfter($source, $find, $key, $value)
  {
    $result = array();
    foreach ($source as $k => $v) {
      $result[$k] = $v;
      if ($find && $find === $k) {
        $result[$key] = $value;
        $find = null;
      }
    }
    if ($find !== null) {
      $result[$key] = $value;
    }
    return $result;
  }

  /**
   * ตัดแอเรย์ตั้งแต่เริ่มต้นจนถึงตำแหน่งที่กำหนด
   *
   * @param array $source แอเรย์ต้นฉบับ
   * @param string|int $key คีย์ของ $source ตำแหน่งที่ต้องการตัด
   * @return array คืนค่าแอเรย์ถัดจากรายการที่ตัด ไม่พบคืนค่าเดิม
   *
   * @assert (array('one' => 1, 'two' => 2, 'three' => 3), 'two') [==] array('three' => 3)
   * @assert (array('one' => 1, 'two' => 2, 'three' => 3), 1) [==] array('one' => 1, 'two' => 2, 'three' => 3)
   */
  public static function shift($source, $key)
  {
    $result = array();
    foreach ($source as $k => $v) {
      if ($k == $key) {
        $result = array();
      } else {
        $result[$k] = $v;
      }
    }
    return $result;
  }
}
