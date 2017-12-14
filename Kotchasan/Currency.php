<?php
/**
 * @filesource Kotchasan/Currency.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * แปลงตัวเลขเป็นจำนวนเงิน บาท ดอลล่าร์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Currency
{

  /**
   * ฟังก์ชั่น แปลงตัวเลขเป็นจำนวนเงิน
   *
   * @param double $amount จำนวนเงิน
   * @param string $thousands_sep (optional) เครื่องหมายหลักพัน (default ,)
   * @return string คืนค่าข้อความจำนวนเงิน
   */
  public static function format($amount, $thousands_sep = ',')
  {
    return number_format((double)$amount, 2, '.', $thousands_sep);
  }

  /**
   * ฟังก์ชั่นคำนวณภาษี
   * $vat_ex = true ราคาสินค้ารวม VAT เช่น ราคาสินค้า 100 + VAT 7 = ราคาขาย 107
   * $vat_ex = false ราคาสินค้ารวม VAT เช่น ราคาขาย 100 = ราคาสินค้า 93 + VAT 7
   *
   * @param double $amount ราคาขาย
   * @param double $vat VAT
   * @param boolean $vat_ex
   * @return double คืนค่า VAT จากราคาขาย
   *
   * @assert (1000, 7, true) [==] 70
   * @assert (1000, 7, false) [==] 65.420560747663558
   */
  public static function calcVat($amount, $vat, $vat_ex = true)
  {
    if ($vat_ex) {
      $result = (($vat * $amount) / 100);
    } else {
      $result = $amount - ($amount * (100 / (100 + $vat)));
    }
    return $result;
  }

  /**
   * แปลงจำนวนเงินเป็นตัวหนังสือ
   *
   * @param string $thb
   * @return string
   *
   * @assert (100.00) [==] 'one hundred Baht'
   * @assert (101.00) [==] 'one hundred and one Baht'
   * @assert (1000.50) [==] 'one thousand Baht and fifty Satang'
   * @assert (1000050) [==] 'one million fifty Baht'
   * @assert (-1000000050) [==] 'negative one billion fifty Baht'
   * @assert (1000000050) [==] 'one billion fifty Baht'
   * @assert (10000000050.25) [==] 'ten billion fifty Baht and twenty-five Satang'
   */
  public static function bahtEng($thb)
  {
    if (preg_match('/^(\-?[0-9]+)(\.([0-9]+))/', (string)$thb, $match)) {
      $thb = self::engFormat((int)$match[1]).' Baht';
      if ((int)$match[3] > 0) {
        $thb .= ' and '.self::engFormat((int)substr($match[3].'00', 0, 2)).' Satang';
      }
    } else {
      $thb = self::engFormat((int)$thb).' Baht';
    }
    return $thb;
  }

  /**
   * ตัวเลขเป็นตัวหนังสือ (ไทย)
   *
   * @param string $thb
   * @return string
   *
   * @assert (101.00) [==] 'หนึ่งร้อยเอ็ดบาทถ้วน'
   * @assert (1000.50) [==] 'หนึ่งพันบาทห้าสิบสตางค์'
   * @assert (1000.00) [==] 'หนึ่งพันบาทถ้วน'
   * @assert (1000) [==] 'หนึ่งพันบาทถ้วน'
   * @assert (1000050) [==] 'หนึ่งล้านห้าสิบบาทถ้วน'
   * @assert (-1000000050) [==] 'ลบหนึ่งพันล้านห้าสิบบาทถ้วน'
   * @assert (10000000050.25) [==] 'หนึ่งหมื่นล้านห้าสิบบาทยี่สิบห้าสตางค์'
   */
  public static function bahtThai($thb)
  {
    if (preg_match('/(\-){0,1}([0-9]+)(\.([0-9]+))?/', (string)$thb, $match)) {
      $thb = $match[2];
      $ths = !empty($match[4]) ? substr($match[4].'00', 0, 2) : '';
      $thaiNum = array('', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า');
      $unitBaht = array('บาท', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน');
      $unitSatang = array('สตางค์', 'สิบ');
      $THB = '';
      $j = 0;
      for ($i = strlen($thb) - 1; $i >= 0; $i--, $j++) {
        $num = $thb[$i];
        $tnum = $thaiNum[$num];
        $unit = $unitBaht[$j];
        switch (true) {
          case $j == 0 && $num == 1 && strlen($thb) > 1:
            $tnum = 'เอ็ด';
            break;
          case $j == 1 && $num == 1:
            $tnum = '';
            break;
          case $j == 1 && $num == 2:
            $tnum = 'ยี่';
            break;
          case $j == 6 && $num == 1 && strlen($thb) > 7:
            $tnum = 'เอ็ด';
            break;
          case $j == 7 && $num == 1:
            $tnum = '';
            break;
          case $j == 7 && $num == 2:
            $tnum = 'ยี่';
            break;
          case $j != 0 && $j != 6 && $num == 0:
            $unit = '';
            break;
        }
        $S = $tnum.$unit;
        $THB = $S.$THB;
      }
      $THB = ($match[1] == '-' ? 'ลบ' : '').$THB;
      if ($ths == '' || $ths == '00') {
        $THS = 'ถ้วน';
      } else {
        $j = 0;
        $THS = '';
        for ($i = strlen($ths) - 1; $i >= 0; $i--, $j++) {
          $num = $ths[$i];
          $tnum = $thaiNum[$num];
          $unit = $unitSatang[$j];
          switch (true) {
            case $j == 0 && $num == 1 && strlen($ths) > 1:
              $tnum = 'เอ็ด';
              break;
            case $j == 1 && $num == 1:
              $tnum = '';
              break;
            case $j == 1 && $num == 2:
              $tnum = 'ยี่';
              break;
            case $j != 0 && $j != 6 && $num == 0:
              $unit = '';
              break;
          }
          $S = $tnum.$unit;
          $THS = $S.$THS;
        }
      }
      return $THB.$THS;
    } else {
      return '';
    }
  }

  /**
   * ตัวเลขเป็นตัวหนังสือ (eng)
   *
   * @param int $number
   * @return string
   */
  private static function engFormat($number)
  {
    if (is_int($number) && $number < abs(pow(10, 18))) {
      switch ($number) {
        case $number < 0:
          $prefix = "negative";
          $suffix = self::engFormat(-1 * $number);
          $string = $prefix." ".$suffix;
          break;
        case 1:
          $string = "one";
          break;
        case 2:
          $string = "two";
          break;
        case 3:
          $string = "three";
          break;
        case 4:
          $string = "four";
          break;
        case 5:
          $string = "five";
          break;
        case 6:
          $string = "six";
          break;
        case 7:
          $string = "seven";
          break;
        case 8:
          $string = "eight";
          break;
        case 9:
          $string = "nine";
          break;
        case 10:
          $string = "ten";
          break;
        case 11:
          $string = "eleven";
          break;
        case 12:
          $string = "twelve";
          break;
        case 13:
          $string = "thirteen";
          break;
        case 15:
          $string = "fifteen";
          break;
        case $number < 20:
          $string = self::engFormat($number % 10);
          if ($number == 18) {
            $string .= "een";
          } else {
            $string .= "teen";
          }
          break;
        case 20:
          $string = "twenty";
          break;
        case 30:
          $string = "thirty";
          break;
        case 40:
          $string = "forty";
          break;
        case 50:
          $string = "fifty";
          break;
        case 60:
          $string = "sixty";
          break;
        case 70:
          $string = "seventy";
          break;
        case 80:
          $string = "eighty";
          break;
        case 90:
          $string = "ninety";
          break;
        case $number < 100:
          $prefix = self::engFormat($number - $number % 10);
          $suffix = self::engFormat($number % 10);
          $string = $prefix."-".$suffix;
          break;
        case $number < pow(10, 3):
          $string = self::engFormat(intval(floor($number / pow(10, 2))))." hundred";
          if ($number % pow(10, 2)) {
            $string .= " and ".self::engFormat($number % pow(10, 2));
          }
          break;
        case $number < pow(10, 6):
          $string = self::engFormat(intval(floor($number / pow(10, 3))))." thousand";
          if ($number % pow(10, 3)) {
            $string .= self::engFormat($number % pow(10, 3));
          }
          break;
        case $number < pow(10, 9):
          $string = self::engFormat(intval(floor($number / pow(10, 6))))." million";
          if ($number % pow(10, 6)) {
            $string .= ' '.self::engFormat($number % pow(10, 6));
          }
          break;
        case $number < pow(10, 12):
          $string = self::engFormat(intval(floor($number / pow(10, 9))))." billion";
          if ($number % pow(10, 9)) {
            $string .= ' '.self::engFormat($number % pow(10, 9));
          }
          break;
        case $number < pow(10, 15):
          $string = self::engFormat(intval(floor($number / pow(10, 12))))." trillion";
          if ($number % pow(10, 12)) {
            $string .= ' '.self::engFormat($number % pow(10, 12));
          }
          break;
        case $number < pow(10, 18):
          $string = self::engFormat(intval(floor($number / pow(10, 15))))." quadrillion";
          if ($number % pow(10, 15)) {
            $string .= ' '.self::engFormat($number % pow(10, 15));
          }
          break;
      }
      return $string;
    } else {
      return "zero";
    }
  }
}
