<?php
/**
 * @filesource Kotchasan/InputItem.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Input Object
 *
 * Represents an input item from various sources such as GET, POST, SESSION, or COOKIE.
 *
 * @see https://www.kotchasan.com/
 */
class InputItem
{
    /**
     * @var string|null The input type (e.g., GET, POST, SESSION, COOKIE)
     */
    protected $type;

    /**
     * @var mixed The input value
     */
    protected $value;

    /**
     * Class Constructor
     *
     * @param mixed $value The input value (default: null)
     * @param string|null $type The input type (e.g., GET, POST, SESSION, COOKIE) (default: null)
     */
    public function __construct($value = null, $type = null)
    {
        $this->value = $value;
        $this->type = $type;
    }

    /**
     * Get the raw input value.
     *
     * @return mixed The raw input value
     */
    public function all()
    {
        return $this->value;
    }

    /**
     * Get the color value. Returns a string or an array of strings.
     *
     * @assert create('#000')->color() [==] '#000'
     * @assert create('red')->color() [==] 'red'
     *
     * @return string|array The color value
     */
    public function color()
    {
        return $this->filter('\#a-zA-Z0-9');
    }

    /**
     * Create an InputItem object.
     *
     * @param mixed $value The input value
     * @param string|null $type The input type (e.g., GET, POST, SESSION, COOKIE) (default: null)
     *
     * @return static The created InputItem object
     */
    public static function create($value, $type = null)
    {
        return new static($value, $type);
    }

    /**
     * Get the date and time value.
     *
     * Returns null if the date value is empty or not in the correct format.
     *
     * @assert create('2016-01-01 20:20:20')->date() [==] '2016-01-01 20:20:20'
     * @assert create('2016-01-01   20:20:20')->date() [==] '2016-01-01   20:20:20'
     * @assert create('2016-01-01   20:20:20')->date(true) [==] '2016-01-01 20:20:20'
     * @assert create('20:20:20')->date() [==] '20:20:20'
     * @assert create('20:20')->date() [==] '20:20'
     * @assert create('20:20')->date(true) [==] '20:20:00'
     * @assert create('2016-01-01')->date() [==] '2016-01-01'
     * @assert create('')->date() [==] null
     * @assert create(null)->date() [==] null
     *
     * @param bool $strict True to validate the date value strictly, false to skip validation (default: false)
     *
     * @return string|array|null The date and time value
     */
    public function date($strict = false)
    {
        $ret = $this->filter('\d\s\-:');

        if ($strict) {
            if (preg_match('/^([0-9]{4,4}\-[0-9]{1,2}\-[0-9]{1,2})?[\s]{0,}([0-9]{1,2}:[0-9]{1,2})?(:[0-9]{1,2})?$/', $ret, $match)) {
                $ret = empty($match[1]) ? '' : $match[1];

                if (!empty($match[2])) {
                    $ret .= ($ret == '' ? '' : ' ').(empty($match[2]) ? '' : $match[2].(empty($match[3]) ? ':00' : $match[3]));
                }
            } else {
                $ret = null;
            }
        }

        return empty($ret) ? null : $ret;
    }

    /**
     * Get the time value.
     *
     * Returns null if the time value is empty or in the wrong format.
     *
     * @assert create('20:20:20')->time() [==] '20:20:20'
     * @assert create('--:--')->time() [==] null
     * @assert create('')->time() [==] null
     * @assert create('20:20:20')->time() [==] '20:20:20'
     * @assert create('20:20')->time() [==] '20:20'
     * @assert create('20:20')->time(true) [==] '20:20:00'
     *
     * @param bool $strict True to validate the time value strictly, false to skip validation (default: false)
     *
     * @return string|null The time value
     */
    public function time($strict = false)
    {
        if (!empty($this->value) && preg_match('/^([0-9]{1,2}:[0-9]{1,2})?(:[0-9]{1,2})?$/', $this->value, $match)) {
            if (empty($match[2])) {
                $match[2] = $strict ? ':00' : '';
            }
            return $match[1].$match[2];
        }
        return null;
    }

    /**
     * Get the sanitized and truncated text.
     *
     * Removes tags and unwanted characters from the text.
     *
     * @assert create("ท.ด(ส     )อ\"บ'\r\n\t<?php echo '555'?>")->description() [==] 'ท.ด(ส )อ บ'
     * @assert create('ทดสอบ<style>body {color: red}</style>')->description() [==] 'ทดสอบ'
     * @assert create('ทดสอบ<b>ตัวหนา</b>')->description() [==] 'ทดสอบตัวหนา'
     * @assert create('ทดสอบ{LNG_Language name}')->description() [==] 'ทดสอบ'
     * @assert create('ทดสอบ[code]ตัวหนา[/code]')->description() [==] 'ทดสอบ'
     * @assert create('ทดสอบ[b]ตัวหนา[/b]')->description() [==] 'ทดสอบตัวหนา'
     * @assert create('2 > 1 < 3 > 2{WIDGET_XXX}')->description() [==] '2 > 1 < 3 > 2'
     * @assert create('ทดสอบ<!--WIDGET_XXX-->')->description() [==] 'ทดสอบ'
     * @assert create('ท&amp;ด&quot;\&nbsp;/__ส-อ+บ')->description() [==] 'ท ด \ /__ส-อ+บ'
     * @assert create('ภาคภูมิ')->description(2) [==] 'ภา'
     * @assert create('U1.username ทดสอบภาษาไทย')->description(5) [throws] \Kotchasan\InputItemException
     *
     * @param int $len The maximum length of the text (default: 0)
     *
     * @return string The sanitized and truncated text
     */
    public function description($len = 0)
    {
        $patt = array(
            /* style */
            '@<(script|style)[^>]*?>.*?</\\1>@isu' => '',
            /* tag */
            '@<[a-z\/\!\?][^>]{0,}>@isu' => '',
            /* keywords */
            '/{(WIDGET|LNG)_[\w\s\.\-\'\(\),%\/:&\#;]+}/su' => '',
            /* BBCode (code) */
            '/(\[code(.+)?\]|\[\/code\]|\[ex(.+)?\])/ui' => '',
            /* BBCode ทั่วไป [b],[i] */
            '/\[([a-z]+)([\s=].*)?\](.*?)\[\/\\1\]/ui' => '\\3',
            /* ตัวอักษรที่ไม่ต้องการ */
            '/(&rdquo;|&quot;|&nbsp;|&amp;|[\r\n\s\t\"\']){1,}/isu' => ' '
        );

        $text = trim(preg_replace(array_keys($patt), array_values($patt), $this->value));

        return $this->checkValue($this->cut($text, $len));
    }

    /**
     * Remove PHP tags and escape characters for text input from an editor.
     *
     * @assert create('{ทด\/สอบ<?php echo "555"?>}')->detail() [==] '&#x007B;ทด&#92;/สอบ&#x007D;'
     * @assert create('<?=555?>U1.username')->detail() [throws] \Kotchasan\InputItemException
     *
     * @return string The sanitized text
     */
    public function detail()
    {
        return $this->checkValue(preg_replace(
            array('#<\?(.*?)\?>#is', '#\{#', '#\}#', '#\\\#'),
            array('', '&#x007B;', '&#x007D;', '&#92;'),
            $this->value
        ));
    }

    /**
     * Check if the input variable exists.
     *
     * @return bool True if the input variable exists, false otherwise
     */
    public function exists()
    {
        return $this->type !== null;
    }

    /**
     * Filter the input value based on a regular expression pattern.
     *
     * @assert create('admin,1234')->filter('0-9a-zA-Z,') [==] 'admin,1234'
     * @assert create('adminกข,12ฟ34')->filter('0-9a-zA-Z,') [==] 'admin,1234'
     * @assert create('U1.username')->filter('a-zA-Z0-9\.') [throws] \Kotchasan\InputItemException
     *
     * @param string $format The regular expression pattern to filter the input value
     * @param string $replace The replacement string for filtered characters (default: '')
     *
     * @return string|array The filtered input value
     */
    public function filter($format, $replace = '')
    {
        if ($this->value === null) {
            return '';
        }
        return $this->checkValue(trim(preg_replace('/[^'.$format.']/', $replace, $this->value)));
    }

    /**
     * Check if the input is from a COOKIE variable.
     *
     * @return bool True if the input is from a COOKIE variable, false otherwise
     */
    public function isCookie()
    {
        return $this->type === 'COOKIE';
    }

    /**
     * Check if the input is from a GET variable.
     *
     * @return bool True if the input is from a GET variable, false otherwise
     */
    public function isGet()
    {
        return $this->type === 'GET';
    }

    /**
     * Check if the input is from a POST variable.
     *
     * @return bool True if the input is from a POST variable, false otherwise
     */
    public function isPost()
    {
        return $this->type === 'POST';
    }

    /**
     * Check if the input is from a SESSION variable.
     *
     * @return bool True if the input is from a SESSION variable, false otherwise
     */
    public function isSession()
    {
        return $this->type === 'SESSION';
    }

    /**
     * Get the sanitized and truncated keywords from the input value.
     *
     * Removes tags, whitespace, and unwanted characters from the input value.
     *
     * @assert create("<b>ทด</b>   \r\nสอบ")->keywords() [==] 'ทด สอบ'
     * @assert create('<b>U1.username</b> ทดสอบ')->keywords(5) [throws] \Kotchasan\InputItemException
     *
     * @param int $len The maximum length of the keywords (default: 0)
     *
     * @return string The sanitized and truncated keywords
     */
    public function keywords($len = 0)
    {
        if ($this->value === null) {
            return '';
        }

        $text = trim(preg_replace('/[\r\n\s\t\"\'<>]{1,}/isu', ' ', strip_tags($this->value)));
        return $this->checkValue($this->cut($text, $len));
    }

    /**
     * Extracts numbers or an array of numbers from the input.
     *
     * @assert create(12345)->number() [==] '12345'
     * @assert create(0.12345)->number() [==] '012345'
     * @assert create('ทด0123สอ4บ5')->number() [==] '012345'
     *
     * @return string|array The extracted numbers or an array of extracted numbers
     */
    public function number()
    {
        return $this->filter('\d');
    }

    /**
     * Validates and filters the input as a password.
     *
     * This function is used to validate and filter the input as a password. It ensures that
     * the value consists of non-space characters only. The resulting value is filtered using
     * the `Text::password()` function.
     *
     * @return string|array The validated and filtered password
     */
    public function password()
    {
        return $this->checkValue(Text::password($this->value));
    }

    /**
     * Accepts text input and converts single quotes to HTML entity '&#39;',
     * and trims leading and trailing spaces.
     *
     * This function accepts text input and converts single quotes to the HTML entity '&#39;'.
     * It also trims leading and trailing spaces from the input value.
     *
     * @assert create("ทด'สอบ")->quote() [==] "ทด&#39;สอบ"
     * @assert create(' U1.username ')->quote() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed text
     */
    public function quote()
    {
        if ($this->value === null) {
            return '';
        }

        return $this->checkValue(str_replace("'", '&#39;', trim($this->value)));
    }

    /**
     * Accepts text input and converts special characters '&', '"', "'", '<', '>', and '\'
     * to their corresponding HTML entities, and trims leading and trailing spaces.
     *
     * This function accepts text input and converts the special characters '&', '"', "'", '<',
     * '>', and '\' to their corresponding HTML entities. It also trims leading and trailing
     * spaces from the input value. This function is useful for sanitizing input that does not
     * allow HTML tags.
     *
     * @assert create(" ทด\/สอบ<?php echo '555'?> ")->text() [==] 'ทด&#92;/สอบ&lt;?php echo &#039;555&#039;?&gt;'
     * @assert create('U1.username')->text() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed text
     */
    public function text()
    {
        return $this->checkValue(trim(Text::htmlspecialchars($this->value)));
    }

    /**
     * Converts '<', '>', '\', '{', '}', and '\n' to their corresponding HTML entities,
     * converts '\n' to '<br>', and trims leading and trailing spaces.
     * Used for receiving data from a textarea input.
     *
     * @assert create("ทด\/สอบ\n<?php echo '$555'?>")->textarea() [==] "ทด&#92;/สอบ\n&lt;?php echo '&#36;555'?&gt;"
     * @assert create('U1.username')->textarea() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed textarea input
     */
    public function textarea()
    {
        if ($this->value === null) {
            return '';
        }

        return $this->checkValue(trim(preg_replace(
            array('/</s', '/>/s', '/\\\/s', '/\{/', '/\}/', '/\$/'),
            array('&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'),
            $this->value
        ), " \n\r\0\x0B"));
    }

    /**
     * Converts the input value to a boolean or an array of booleans.
     *
     * This function returns 1 if the input value is not empty, and 0 otherwise.
     *
     * @assert create(true)->toBoolean() [==] 1
     * @assert create(false)->toBoolean() [==] 0
     * @assert create(1)->toBoolean() [==] 1
     * @assert create(0)->toBoolean() [==] 0
     * @assert create(null)->toBoolean() [==] 0
     *
     * @return bool|array The converted boolean value
     */
    public function toBoolean()
    {
        return empty($this->value) ? 0 : 1;
    }

    /**
     * Converts the input value to a double.
     *
     * This function converts the input value to a double. If the value is null,
     * it returns 0. The function removes any commas from the value before conversion.
     *
     * @assert create(0.454)->toDouble() [==] 0.454
     * @assert create(0.545)->toDouble() [==] 0.545
     * @assert create('15,362.454')->toDouble() [==] 15362.454
     *
     * @return float|array The converted double value
     */
    public function toDouble()
    {
        if ($this->value === null) {
            return 0;
        }
        return (float) str_replace(',', '', $this->value);
    }

    /**
     * Converts the input value to a float or an array of floats.
     *
     * @assert create(0.454)->toFloat() [==] 0.454
     * @assert create(0.545)->toFloat() [==] 0.545
     *
     * @return float|array The converted float value
     */
    public function toFloat()
    {
        return (float) $this->value;
    }

    /**
     * Converts the input value to an integer or an array of integers.
     *
     * @assert create(0.454)->toInt() [==] 0
     * @assert create(2.945)->toInt() [==] 2
     *
     * @return int|array The converted integer value
     */
    public function toInt()
    {
        return (int) $this->value;
    }

    /**
     * Converts the input value to an object or an array of objects.
     *
     * @assert create('test')->toObject() [==] (object)'test'
     *
     * @return object|array The converted object value
     */
    public function toObject()
    {
        return (object) $this->value;
    }

    /**
     * Converts the input value to a string, an array of strings, or null.
     * ***Caution: This function converts the value to a string without any validation.
     * Use with caution.***
     *
     * @assert create('ทดสอบ')->toString() [==] 'ทดสอบ'
     * @assert create('1')->toString() [==] '1'
     * @assert create(1)->toString() [==] '1'
     * @assert create(null)->toString() [==] null
     *
     * @return string|array|null The converted string value
     */
    public function toString()
    {
        return $this->value === null ? null : (string) $this->value;
    }

    /**
     * Converts the input value to an array.
     *
     * This function returns the input value as an array.
     * If the value is not an array, an error is thrown.
     *
     * @assert create(array('one', 'two'))->toArray() [==] array('one', 'two')
     *
     * @return array The converted array value
     * @throws \Kotchasan\InputItemException if the value is not an array
     */
    public function toArray()
    {
        return $this->value;
    }

    /**
     * Converts tags and removes extra spaces (not exceeding 1 space) without line breaks.
     * Used for article titles.
     *
     * @param bool $double_encode true (default) to convert HTML entities like &amp; to &amp;amp;, false otherwise
     *
     * @assert create(' ทด\/สอบ'."\r\n\t".'<?php echo \'555\'?> ')->topic() [==] 'ทด&#92;/สอบ &lt;?php echo &#039;555&#039;?&gt;'
     * @assert create('U1.username')->topic() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed topic string
     */
    public function topic($double_encode = true)
    {
        return $this->checkValue(Text::topic($this->value, $double_encode));
    }

    /**
     * Converts tags without converting &amp; and removes trailing spaces.
     * Used for URLs or emails.
     *
     * @assert create(" http://www.kotchasan.com?a=1&b=2&amp;c=3 ")->url() [==] 'http://www.kotchasan.com?a=1&amp;b=2&amp;c=3'
     * @assert create("javascript:alert('xxx')")->url() [==] 'alertxxx'
     * @assert create("http://www.xxx.com/javascript/")->url() [==] 'http://www.xxx.com/javascript/'
     * @assert create('U1.username')->url() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed URL string
     */
    public function url()
    {
        return $this->checkValue(Text::url($this->value));
    }

    /**
     * Accepts email addresses and phone numbers only.
     *
     * @assert create(' admin@demo.com')->username() [==] 'admin@demo.com'
     * @assert create('012 3465')->username() [==] '0123465'
     * @assert create('U1.username')->username() [throws] \Kotchasan\InputItemException
     *
     * @return string|array The processed username string
     */
    public function username()
    {
        return $this->checkValue(Text::username($this->value));
    }

    /**
     * Truncate a string to a specified length.
     *
     * Truncates the given string to the specified length. If the length parameter
     * is provided and the string is not empty, the string will be shortened to the
     * specified length using `mb_substr()`.
     *
     * @param string $str The string to be truncated
     * @param int $len The maximum length of the truncated string
     *
     * @return string The truncated string
     */
    private function cut($str, $len)
    {
        if (!empty($len) && !empty($str)) {
            $str = mb_substr($str, 0, (int) $len);
        }

        return $str;
    }

    /**
     * Check the validity of a value.
     *
     * Validates the value against a regular expression pattern. If the value matches
     * the pattern, an exception is thrown indicating an invalid value.
     *
     * @param string $value The value to be checked
     *
     * @return string The validated value
     *
     * @throws \Kotchasan\InputItemException If the value is invalid
     */
    private function checkValue($value)
    {
        if (preg_match('/^(SQL\(.+\)|[A-Z][0-9]{0,2}\.[a-z0-9_`]+)$/', $value)) {
            throw new \Kotchasan\InputItemException('Invalid value "'.$value.'"');
        }

        return $value;
    }

}
