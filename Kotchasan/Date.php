<?php
/**
 * @filesource Kotchasan/Date.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class for managing dates and times.
 *
 * @see https://www.kotchasan.com/
 */
class Date
{
    /**
     * @var mixed
     */
    private static $lang;

    /**
     * Class constructor.
     */
    public function __construct()
    {
        self::$lang = Language::getItems([
            'DATE_SHORT',
            'DATE_LONG',
            'MONTH_SHORT',
            'MONTH_LONG',
            'YEAR_OFFSET'
        ]);
    }

    /**
     * Calculates the difference between two dates (e.g., age).
     * Returns the number of days (can be negative), years, months, and days [days, year, month, day].
     *
     * @assert (mktime(0, 0, 0, 2, 1, 2016), mktime(0, 0, 0, 3, 1, 2016)) [==]  array('days' => 29, 'year' => 0,'month' => 0, 'day' => 29)
     * @assert ('2016-3-1', '2016-2-1') [==]  array('days' => -29, 'year' => 0,'month' => 0, 'day' => 29)
     *
     * @param string|int  $begin_date The start date or birth date (Unix timestamp or date in the format YYYY-m-d)
     * @param istring|int $end_date   The end date or today's date (Unix timestamp or date in the format YYYY-m-d)
     *
     * @return array
     */
    public static function compare($begin_date, $end_date)
    {
        if (is_int($begin_date)) {
            $begin_date = date('Y-m-d H:i:s', $begin_date);
        }
        if (is_int($end_date)) {
            $end_date = date('Y-m-d H:i:s', $end_date);
        }
        $diff = date_diff(date_create($begin_date), date_create($end_date));
        return [
            'days' => $diff->invert == 1 ? -$diff->days : $diff->days,
            'year' => $diff->y,
            'month' => $diff->m,
            'day' => $diff->d
        ];
    }

    /**
     * คืนค่าอายุเป็นข้อความ ปี เดือน วัน
     *
     * @param string $date
     *
     * @return string
     */
    public static function age($date)
    {
        $diff = self::compare($date, date('Y-m-d'));
        $age = [];
        if ($diff['year'] > 0) {
            $age[] = $diff['year'];
            $age[] = '{LNG_year}';
        }
        if ($diff['month'] > 0) {
            $age[] = $diff['month'];
            $age[] = '{LNG_month}';
        }
        if ($diff['day'] > 0) {
            $age[] = $diff['day'];
            $age[] = '{LNG_days}';
        }
        return implode(' ', $age);
    }

    /**
     * Returns the time difference in milliseconds.
     *
     * @assert ('08:00', '09:00') [==] 3600
     *
     * @param  $firstTime
     * @param  $lastTime
     *
     * @return int
     */
    public static function timeDiff($firstTime, $lastTime)
    {
        $firstTime = strtotime($firstTime);
        $lastTime = strtotime($lastTime);
        $timeDiff = $lastTime - $firstTime;
        return $timeDiff;
    }

    /**
     * Converts a number to the name of the day in the current language.
     * Returns the name of the day (e.g., Sunday...Saturday).
     *
     * @assert (0) [==] 'อา.'
     * @assert (0, false) [==] 'อาทิตย์'
     *
     * @param int  $date       0-6
     * @param bool $short_date true (default) for short day name format (e.g., อ.), false for full month name format (e.g., อาทิตย์)
     *
     * @return string
     */
    public static function dateName($date, $short_date = true)
    {
        // create class
        if (!isset(self::$lang)) {
            new static;
        }
        $var = $short_date ? self::$lang['DATE_SHORT'] : self::$lang['DATE_LONG'];
        return isset($var[$date]) ? $var[$date] : '';
    }

    /**
     * Converts a time to a date in the specified format.
     * Returns the date and time in the specified format.
     *
     * @assert (0, 'y-m-d H:i:s') [==]  date('y-m-d H:i:s')
     * @assert (null) [==]  ''
     * @assert (1454259600, 'Y-m-d H:i:s') [==] '2559-02-01 00:00:00'
     *
     * @param int|string $time   int for Unix timestamp, string for Y-m-d or Y-m-d H:i:s format (if not specified or empty, it means today)
     * @param string     $format The desired format of the date (if not specified, it uses the format from the language file DATE_FORMAT)
     *
     * @return string
     */
    public static function format($time = 0, $format = null)
    {
        if ($time === 0) {
            $time = time();
        } elseif (is_string($time)) {
            if (preg_match('/^[0-9]+$/', $time)) {
                $time = (int) $time;
            } else {
                $time = strtotime($time);
            }
        } elseif (!is_int($time)) {
            return '';
        }
        // create class
        if (!isset(self::$lang)) {
            new static;
        }
        $format = empty($format) ? 'DATE_FORMAT' : $format;
        $format = Language::get($format);
        if (preg_match_all('/(.)/u', $format, $match)) {
            $ret = '';
            foreach ($match[0] as $item) {
                switch ($item) {
                    case ' ':
                    case ':':
                    case '/':
                    case '-':
                    case '.':
                    case ',':
                        $ret .= $item;
                        break;
                    case 'l':
                        $ret .= self::$lang['DATE_SHORT'][date('w', $time)];
                        break;
                    case 'L':
                        $ret .= self::$lang['DATE_LONG'][date('w', $time)];
                        break;
                    case 'M':
                        $ret .= self::$lang['MONTH_SHORT'][date('n', $time)];
                        break;
                    case 'F':
                        $ret .= self::$lang['MONTH_LONG'][date('n', $time)];
                        break;
                    case 'Y':
                        $ret .= (int) date('Y', $time) + (int) self::$lang['YEAR_OFFSET'];
                        break;
                    default:
                        $ret .= trim($item) == '' ? ' ' : date($item, $time);
                        break;
                }
            }
        } else {
            $ret = date($format, $time);
        }
        return $ret;
    }

    /**
     * Converts a number to the name of the month in the current language.
     * Returns the name of the month (e.g., January...December).
     *
     * @assert (1) [==] 'ม.ค.'
     * @assert (1, false) [==] 'มกราคม'
     *
     * @param int  $month       1-12
     * @param bool $short_month true (default) for short month name format (e.g., มค.), false for full month name format (e.g., มกราคม)
     *
     * @return string
     */
    public static function monthName($month, $short_month = true)
    {
        // create class
        if (!isset(self::$lang)) {
            new static;
        }
        $var = $short_month ? self::$lang['MONTH_SHORT'] : self::$lang['MONTH_LONG'];
        return isset($var[$month]) ? $var[$month] : '';
    }

    /**
     * Parses a date string into an array.
     * Returns an array containing year, month, day, hour, minute, second, or an empty array if it's not a date.
     *
     * @param string $date The date string to parse
     *
     * @return array|bool
     */
    public static function parse($date)
    {
        if (preg_match('/([0-9]{1,4})-([0-9]{1,2})-([0-9]{1,2})(\s([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2}))?/', $date, $match)) {
            if (isset($match[4])) {
                return array('y' => $match[1], 'm' => $match[2], 'd' => $match[3], 'h' => $match[5], 'i' => $match[6], 's' => $match[7]);
            } else {
                return array('y' => $match[1], 'm' => $match[2], 'd' => $match[3]);
            }
        }
        return false;
    }

    /**
     * Converts a date to a human-readable time ago format
     *
     * @param string $date
     *
     * @return string
     */
    public static function timeAgo($date)
    {
        // Get the current date and time
        $today = new \DateTime();

        // Calculate the difference between the given date and the current date
        $diff = $today->diff(new \DateTime($date));

        // Check for the time difference in years
        if ($diff->y > 0) {
            return $diff->y.' {LNG_year} {LNG_ago}';
        }

        // Check for the time difference in months
        if ($diff->m > 0) {
            return $diff->m.' {LNG_month} {LNG_ago}';
        }

        // Check for the time difference in weeks if it's more than 7 days
        if ($diff->d > 7) {
            $week = floor($diff->d / 7);
            return $week.' {LNG_week} {LNG_ago}';
        }

        // Check for the time difference in days if it's more than 2 days
        if ($diff->d > 2) {
            return $diff->d.' {LNG_days} {LNG_ago}';
        }

        // Check for the time difference in hours
        if ($diff->h > 0) {
            return $diff->h.' {LNG_hours} {LNG_ago}';
        }

        // Check for the time difference in minutes
        if ($diff->i > 0) {
            return $diff->i.' {LNG_minutes} {LNG_ago}';
        }

        // If there is no significant time difference, return '{LNG_now}'
        return '{LNG_now}';
    }

}
