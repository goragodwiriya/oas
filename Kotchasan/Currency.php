<?php
/**
 * @filesource Kotchasan/Currency.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Convert numbers to currency format (Baht, Dollar)
 *
 * @see https://www.kotchasan.com/
 */
class Currency
{
    /**
     * Convert number to text (English)
     *
     * @assert (13.00) [==] 'thirteen Baht'
     * @assert (101.55) [==] 'one hundred one Baht and fifty-five Satang'
     * @assert (1234.56) [==] 'one thousand two hundred thirty-four Baht and fifty-six Satang'
     * @assert (12345.67) [==] 'twelve thousand three hundred forty-five Baht and sixty-seven Satang'
     * @assert (-1000000050) [==] 'negative one billion fifty Baht'
     * @assert (1416921) [==] 'one million four hundred sixteen thousand nine hundred twenty-one Baht'
     * @assert (269346000.00) [==] 'two hundred sixty-nine million three hundred forty-six thousand Baht'
     * @assert (1000000000.00) [==] 'one billion Baht'
     * @assert (10000000050.25) [==] 'ten billion fifty Baht and twenty-five Satang'
     * @assert (100000000000.00) [==] 'one hundred billion Baht'
     * @assert (1000000000000) [==] 'one trillion Baht'
     * @assert (999999999999999) [==] 'nine hundred ninety-nine trillion nine hundred ninety-nine billion nine hundred ninety-nine million nine hundred ninety-nine thousand nine hundred ninety-nine Baht'
     * @assert (1000000000000000500) [==] 'one thousand quadrillion five hundred Baht'
     *
     * @param string $thb
     *
     * @return string
     */
    public static function bahtEng($thb)
    {
        if (preg_match('/(-)?([0-9]+)(\.([0-9]+))?/', (string) $thb, $match)) {
            $thb = self::engFormat(intval($match[2])).' Baht';
            if (isset($match[4]) && intval($match[4]) > 0) {
                $thb .= ' and '.self::engFormat(intval(substr($match[4].'00', 0, 2))).' Satang';
            }
            return ($match[1] == '-' ? 'negative ' : '').$thb;
        }
        return '';
    }

    /**
     * Convert number to text (Thai Baht)
     *
     * @assert (13.00) [==] 'สิบสามบาทถ้วน'
     * @assert (101.55) [==] 'หนึ่งร้อยเอ็ดบาทห้าสิบห้าสตางค์'
     * @assert (1234.56) [==] 'หนึ่งพันสองร้อยสามสิบสี่บาทห้าสิบหกสตางค์'
     * @assert (12345.67) [==] 'หนึ่งหมื่นสองพันสามร้อยสี่สิบห้าบาทหกสิบเจ็ดสตางค์'
     * @assert (-1000000050) [==] 'ลบหนึ่งพันล้านห้าสิบบาทถ้วน'
     * @assert (1416921) [==] 'หนึ่งล้านสี่แสนหนึ่งหมื่นหกพันเก้าร้อยยี่สิบเอ็ดบาทถ้วน'
     * @assert (269346000.00) [==] 'สองร้อยหกสิบเก้าล้านสามแสนสี่หมื่นหกพันบาทถ้วน'
     * @assert (1000000000.00) [==] 'หนึ่งพันล้านบาทถ้วน'
     * @assert (10000000050.25) [==] 'หนึ่งหมื่นล้านห้าสิบบาทยี่สิบห้าสตางค์'
     * @assert (100000000000.00) [==] 'หนึ่งแสนล้านบาทถ้วน'
     * @assert (1000000000000) [==] 'หนึ่งล้านล้านบาทถ้วน'
     *
     * @param string $thb
     *
     * @return string
     */
    public static function bahtThai($thb)
    {
        if (preg_match('/(-)?([0-9]+)(\.([0-9]+))?/', (string) $thb, $match)) {
            $isNegative = $match[1] == '-';
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
                if ($j == 0 && $num == 1 && strlen($thb) > 1) {
                    $tnum = 'เอ็ด';
                } elseif ($j == 1 && $num == 1) {
                    $tnum = '';
                } elseif ($j == 1 && $num == 2) {
                    $tnum = 'ยี่';
                } elseif ($j == 6 && $num == 1 && strlen($thb) > 7) {
                    $tnum = 'เอ็ด';
                } elseif ($j == 7 && $num == 1) {
                    $tnum = '';
                } elseif ($j == 7 && $num == 2) {
                    $tnum = 'ยี่';
                } elseif ($j != 0 && $j != 6 && $num == 0) {
                    $unit = '';
                }
                $THB = $tnum.$unit.$THB;
            }
            $THB = ($isNegative ? 'ลบ' : '').$THB;
            if ($ths == '' || $ths == '00') {
                $THS = 'ถ้วน';
            } else {
                $j = 0;
                $THS = '';
                for ($i = strlen($ths) - 1; $i >= 0; $i--, $j++) {
                    $num = $ths[$i];
                    $tnum = $thaiNum[$num];
                    $unit = $unitSatang[$j];
                    if ($j == 0 && $num == 1 && strlen($ths) > 1) {
                        $tnum = 'เอ็ด';
                    } elseif ($j == 1 && $num == 1) {
                        $tnum = '';
                    } elseif ($j == 1 && $num == 2) {
                        $tnum = 'ยี่';
                    } elseif ($j != 0 && $j != 6 && $num == 0) {
                        $unit = '';
                    }
                    $THS = $tnum.$unit.$THS;
                }
            }
            return $THB.$THS;
        }
        return '';
    }

    /**
     * Calculate the VAT amount based on the given amount and VAT rate.
     *
     * @assert (1000, 7, true) [==] 70
     * @assert (1000, 7, false) [==] 65.420560747663558
     *
     * @param float $amount The amount to calculate VAT for.
     * @param float $vat The VAT rate.
     * @param bool $vat_ex Indicates whether the amount is VAT-exclusive (true) or VAT-inclusive (false).
     *
     * @return float The VAT amount.
     */
    public static function calcVat($amount, $vat, $vat_ex = true)
    {
        if ($vat_ex) {
            // Calculate VAT amount for VAT-exclusive amount
            $result = ($vat * $amount) / 100;
        } else {
            // Calculate VAT amount for VAT-inclusive amount
            $result = $amount - ($amount * (100 / (100 + $vat)));
        }

        return $result;
    }

    /**
     * Format a number with specified decimal digits and thousands separator.
     *
     * @assert (1000000.444) [==] '1,000,000.44'
     * @assert (1000000.555) [==] '1,000,000.56'
     * @assert (1000000.55455, 3, ',', false) [==] '1,000,000.554'
     * @assert (1000000.55455, 3) [==] '1,000,000.555'
     *
     * @param float $amount The number to format.
     * @param int $digit The number of decimal digits to include. Default is 2.
     * @param string $thousands_sep The character used as a thousands separator. Default is ','.
     * @param bool $round Whether to round the number or not. Default is true.
     *
     * @return string The formatted number as a string.
     */
    public static function format($amount, $digit = 2, $thousands_sep = ',', $round = true)
    {
        // Check if rounding is disabled and the number has more decimal digits than specified
        if (!$round && preg_match('/^([0-9]+)(\.[0-9]{'.$digit.','.$digit.'})[0-9]+$/', (string) $amount, $match)) {
            // Concatenate the integer part and the specified decimal digits without rounding
            return number_format((float) $match[1].$match[2], $digit, '.', $thousands_sep);
        } else {
            // Round the number according to the specified decimal digits and format it
            return number_format((float) $amount, $digit, '.', $thousands_sep);
        }
    }

    /**
     * Format a number into its English word representation.
     *
     * @param int $number The number to format.
     *
     * @return string The English word representation of the number.
     */
    private static function engFormat($number)
    {
        // Define an array of English words for numbers 0 to 90
        $amount_words = array(
            0 => 'zero',
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
            11 => 'eleven',
            12 => 'twelve',
            13 => 'thirteen',
            14 => 'fourteen',
            15 => 'fifteen',
            16 => 'sixteen',
            17 => 'seventeen',
            18 => 'eighteen',
            19 => 'nineteen',
            20 => 'twenty',
            30 => 'thirty',
            40 => 'forty',
            50 => 'fifty',
            60 => 'sixty',
            70 => 'seventy',
            80 => 'eighty',
            90 => 'ninety'
        );

        // Check if the number exists in the $amount_words array
        if (isset($amount_words[$number])) {
            return $amount_words[$number];
        }

        // Handle numbers from 0 to 99
        if ($number < 100) {
            // Recursively format the tens place and ones place
            $prefix = self::engFormat(floor($number / 10) * 10);
            $suffix = self::engFormat($number % 10);
            return $prefix.'-'.$suffix;
        }

        // Handle numbers from 100 to 999,999,999,999,999
        $amount_units = array(
            1000 => [100, ' hundred'],
            1000000 => [1000, ' thousand'],
            1000000000 => [1000000, ' million'],
            1000000000000 => [1000000000, ' billion'],
            1000000000000000 => [1000000000000, ' trillion']
        );
        foreach ($amount_units as $amount => $units) {
            if ($number < $amount) {
                // Recursively format the whole part and the remaining part
                $string = self::engFormat(floor($number / $units[0])).$units[1];
                if ($number % $units[0]) {
                    $string .= ' '.self::engFormat($number % $units[0]);
                }
                return $string;
            }
        }

        // Handle numbers greater than 999,999,999,999,999
        $string = self::engFormat(floor($number / 1000000000000000)).' quadrillion';
        if ($number % 1000000000000000) {
            $string .= ' '.self::engFormat($number % 1000000000000000);
        }
        return $string;
    }
}
