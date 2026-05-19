<?php
namespace Kotchasan;

/**
 * Kotchasan Number Class
 *
 * This class provides methods for formatting numbers, performing division,
 * and formatting numbers with date placeholders.
 *
 * @package Kotchasan
 */
class Number
{
    /**
     * Format a number with comma as thousands separator (supports decimal values).
     * If there are no decimal places, return an integer value.
     * No rounding is performed.
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
     * @param mixed $dividend The dividend
     * @param mixed $divisor The divisor
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
            ['%YY', '%yy', '%Y', '%y', '%M', '%m', '%D', '%d', '%s'],
            [$Y, $y, substr($Y, 2, 2), substr($y, 2, 2), $m, (int) $m, $d, (int) $d, $prefix],
            $format
        );
        return sprintf($format, $value);
    }
}
