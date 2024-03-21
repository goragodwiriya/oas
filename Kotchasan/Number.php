<?php
/**
 * @filesource Kotchasan/Number.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides functions for working with numbers.
 * It includes formatting numbers, performing divisions, and formatting numbers with date placeholders.
 *
 * @see https://www.kotchasan.com/
 */
class Number
{
    /**
     * Format a number with comma as thousands separator (supports decimal values).
     * If there are no decimal places, return an integer value.
     * No rounding is performed.
     *
     * @assert (100) [==] "100"
     * @assert (100.1) [==] "100.1"
     * @assert (1000.12) [==] "1,000.12"
     * @assert (1000.1555) [==] "1,000.1555"
     *
     * @param float  $value The number to be formatted
     * @param string $thousands_sep (optional) Thousands separator (default ',')
     *
     * @return string The formatted number
     */
    public static function format($value, $thousands_sep = ',')
    {
        $values = explode('.', $value);
        return number_format((float) $values[0], 0, '', $thousands_sep).(empty($values[1]) ? '' : '.'.$values[1]);
    }

    /**
     * Perform division.
     * If the divisor is equal to 0, return 0.
     *
     * @assert (1, 2) [==] 0.5
     * @assert (1, 0) [==] 0
     *
     * @param $dividend The dividend
     * @param $divisor The divisor
     *
     * @return mixed The result of the division
     */
    public static function division($dividend, $divisor)
    {
        return $divisor == 0 ? 0 : $dividend / $divisor;
    }

    /**
     * Format a number with placeholders for date values (year, month, day).
     *
     * @assert ('G%04d', 1) [==] "G0001"
     * @assert ('G-%s-%04d', 1, 'PREFIX') [==] "G-PREFIX-0001"
     * @example G-%Y-%M-%D-%04d    G-64-08-09-0001
     * @example G-%y-%m-%d-%04d    G-21-8-9-0001
     * @example G-%YY-%M-%D-%04d   G-2564-08-09-0001
     * @example G-%yy-%m-%d-%04d   G-2021-8-9-0001
     *
     * @param string $format The format string
     * @param mixed  $value The number to be formatted
     * @param string $prefix (optional) A prefix to be added to the format string
     *
     * @return string The formatted number with date placeholders
     */
    public static function printf($format, $value, $prefix = '')
    {
        $y = date('Y');
        $Y = $y + 543;
        $m = date('m');
        $d = date('d');
        $format = str_replace(
            array('%YY', '%yy', '%Y', '%y', '%M', '%m', '%D', '%d', '%s'),
            array($Y, $y, substr($Y, 2, 2), substr($y, 2, 2), $m, (int) $m, $d, (int) $d, $prefix),
            $format
        );
        return sprintf($format, $value);
    }
}
