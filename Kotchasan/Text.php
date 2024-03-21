<?php
/**
 * @filesource Kotchasan/Text.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * String functions
 *
 * @see https://www.kotchasan.com/
 */
class Text
{
    /**
     * Truncates a string to the specified length.
     * If the source string is longer than the specified length,
     * it will be truncated and '...' will be appended.
     *
     * @assert ('สวัสดี ประเทศไทย', 8) [==] 'สวัสดี..'
     * @assert ('123456789', 8) [==] '123456..'
     *
     * @param string $source The source string
     * @param int    $len    The desired length of the string (including the '...')
     *
     * @return string
     */
    public static function cut($source, $len)
    {
        if (!empty($len)) {
            $len = (int) $len;
            $source = (mb_strlen($source) <= $len || $len < 3) ? $source : mb_substr($source, 0, $len - 2).'..';
        }
        return $source;
    }

    /**
     * Converts the size of a file from bytes to KB, MB, etc.
     * Returns the file size as a string in KB, MB, etc.
     *
     * @assert (256) [==] '256 Bytes'
     * @assert (1024) [==] '1 KB'
     * @assert (1024 * 1024) [==] '1 MB'
     * @assert (1024 * 1024 * 1024) [==] '1 GB'
     * @assert (1024 * 1024 * 1024 * 1024) [==] '1 TB'
     *
     * @param int $bytes     The file size in bytes
     * @param int $precision The number of decimal places (default 2)
     *
     * @return string
     */
    public static function formatFileSize($bytes, $precision = 2)
    {
        $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
        if ($bytes <= 0) {
            return '0 Byte';
        }
        $pow = min((int) log($bytes, 1024), count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * HTML highlighter function.
     * Converts BBCode.
     * Converts URLs to links.
     *
     * @param string $detail The input text
     *
     * @return string
     */
    public static function highlighter($detail)
    {
        $detail = preg_replace_callback('/\[([uo]l)\](.*)\[\/\\1\]/is', function ($match) {
            return '<'.$match[1].'><li>'.preg_replace('/<br(\s\/)?>/is', '</li><li>', $match[2]).'</li></'.$match[1].'>';
        }, $detail);
        $patt[] = '/\[(i|dfn|b|strong|u|em|ins|del|sub|sup|small|big)\](.*)\[\/\\1\]/is';
        $replace[] = '<\\1>\\2</\\1>';
        $patt[] = '/\[color=([#a-z0-9]+)\]/i';
        $replace[] = '<span style="color:\\1">';
        $patt[] = '/\[size=([0-9]+)(px|pt|em|\%)\]/i';
        $replace[] = '<span style="font-size:\\1\\2">';
        $patt[] = '/\[\/(color|size)\]/i';
        $replace[] = '</span>';
        $patt[] = '/\[url\](.*)\[\/url\]/i';
        $replace[] = '<a href="\\1" target="_blank">\\1</a>';
        $patt[] = '/\[url=(ftp|https?):\/\/(.*)\](.*)\[\/url\]/i';
        $replace[] = '<a href="\\1://\\2" target="_blank">\\3</a>';
        $patt[] = '/\[url=(\/)?(.*)\](.*)\[\/url\]/i';
        $replace[] = '<a href="'.WEB_URL.'\\2" target="_blank">\\3</a>';
        $patt[] = '/([^["]]|\r|\n|\s|\t|^)((ftp|https?):\/\/([a-z0-9\.\-_]+)\/([^\s<>\"\']{1,})([^\s<>\"\']{20,20}))/i';
        $replace[] = '\\1<a href="\\2" target="_blank">\\3://\\4/...\\6</a>';
        $patt[] = '/([^["]]|\r|\n|\s|\t|^)((ftp|https?):\/\/([^\s<>\"\']+))/i';
        $replace[] = '\\1<a href="\\2" target="_blank">\\2</a>';
        $patt[] = '/(<a[^>]+>)(https?:\/\/[^\%<]+)([\%][^\.\&<]+)([^<]{5,})(<\/a>)/i';
        $replace[] = '\\1\\2...\\4\\5';
        $patt[] = '/\[youtube\]([a-z0-9-_]+)\[\/youtube\]/i';
        $replace[] = '<div class="youtube"><iframe src="//www.youtube.com/embed/\\1?wmode=transparent"></iframe></div>';
        return preg_replace($patt, $replace, $detail);
    }

    /**
     * Converts special characters to HTML entities.
     * This function replaces special characters like "&", "<", ">", etc.
     * with their corresponding HTML entities.
     *
     * @assert ('&"\'<>\\{}$') [==] '&amp;&quot;&#039;&lt;&gt;&#92;&#x007B;&#x007D;&#36;'
     *
     * @param string $text          The input text
     * @param bool   $double_encode Whether to double encode existing entities (default true)
     *
     * @return string
     */
    public static function htmlspecialchars($text, $double_encode = true)
    {
        if ($text === null) {
            return '';
        }

        // Replace special characters with their HTML entities
        $str = preg_replace(
            array('/&/', '/"/', "/'/", '/</', '/>/', '/\\\/', '/\{/', '/\}/', '/\$/'),
            array('&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'),
            $text
        );

        if (!$double_encode) {
            // Decode previously encoded entities if double_encode is false
            $str = preg_replace('/&(amp;([#a-z0-9]+));/i', '&\\2;', $str);
        }

        return $str;
    }

    /**
     * Returns a one-line version of the given text, with optional length limit.
     * This function removes any leading/trailing whitespace, line breaks, tabs, and multiple spaces,
     * and then optionally cuts the text to the specified length.
     *
     * @assert (" \tทดสอบ\r\nภาษาไทย") [==] 'ทดสอบ ภาษาไทย'
     *
     * @param string $text The input text
     * @param int    $len  The maximum length of the one-line text (default 0, no limit)
     *
     * @return string
     */
    public static function oneLine($text, $len = 0)
    {
        if ($text === null) {
            return '';
        }

        // Remove leading/trailing whitespace, line breaks, tabs, and multiple spaces
        $cleanText = trim(preg_replace('/[\r\n\t\s]+/', ' ', $text));

        // Optionally cut the text to the specified length
        return self::cut($cleanText, $len);
    }

    /**
     * Returns a password-safe version of the given text.
     * This function removes any characters that are not word characters,
     * along with specific allowed characters (@, #, *, $, &, {, }, !, ?, +, _, -, =, ., [, ], ก-ฮ).
     *
     * @assert (" 0\n12   34\r\r6\t5ทดสอบ@#$&{}!?+_-=.[]*") [==] '0123465ทดสอบ@#$&{}!?+_-=.[]*'
     *
     * @param string $text The input text
     *
     * @return string
     */
    public static function password($text)
    {
        if ($text === null) {
            return '';
        }

        // Remove characters that are not word characters or specific allowed characters
        $safeText = preg_replace('/[^\w\@\#\*\$\&\{\}\!\?\+_\-=\.\[\]ก-ฮ]+/', '', $text);

        return $safeText;
    }

    /**
     * Removes non-character bytes from the given text.
     * This function uses a regular expression to match and remove any bytes that are not valid UTF-8 characters.
     *
     * @assert (chr(0).chr(127).chr(128).chr(255)) [==] chr(0).chr(127)
     *
     * @param string $text The input text
     *
     * @return string
     */
    public static function removeNonCharacters($text)
    {
        return preg_replace(
            '/((?:[\x00-\x7F]|[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}){1,100})|./x',
            '\\1',
            $text
        );
    }

    /**
     * Repeats a string a specified number of times.
     *
     * @assert ('0', 10) [==] '0000000000'
     *
     * @param string $text  The string to repeat
     * @param int    $count The number of times to repeat the string
     *
     * @return string The repeated string
     */
    public static function repeat($text, $count)
    {
        $result = '';
        for ($i = 0; $i < $count; ++$i) {
            $result .= $text;
        }
        return $result;
    }

    /**
     * Replaces keys in a string with corresponding values.
     *
     * @assert ("SELECT * FROM table WHERE id=:id AND lang IN (:lang, '')", array(':id' => 1, array(':lang' => 'th'))) [==] "SELECT * FROM table WHERE id=1 AND lang IN (th, '')"
     *
     * @param string $source  The source string to replace keys in
     * @param array  $replace An associative array of keys and values
     *
     * @return string The modified string with replaced keys
     */
    public static function replace($source, $replace)
    {
        if (!empty($replace)) {
            $keys = [];
            $values = [];

            // Extract keys and values from the replace array
            ArrayTool::extract($replace, $keys, $values);

            // Replace keys with corresponding values in the source string
            $source = str_replace($keys, $values, $source);
        }

        return $source;
    }

    /**
     * Convert special characters to their HTML entities for editor display.
     *
     * @assert ('&"'."'<>{}&amp;&#38;") [==] "&amp;&quot;&#039;&lt;&gt;&#x007B;&#x007D;&amp;&#38;"
     *
     * @param string $text The input text
     *
     * @return string The text with special characters converted to HTML entities
     */
    public static function toEditor($text)
    {
        if ($text === null) {
            return '';
        }

        // Define arrays for search and replacement patterns
        $searchPatterns = array('/&/', '/"/', "/'/", '/</', '/>/', '/{/', '/}/', '/&(amp;([\#a-z0-9]+));/');
        $replacePatterns = array('&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#x007B;', '&#x007D;', '&\\2;');

        // Replace special characters with their corresponding HTML entities
        $text = preg_replace($searchPatterns, $replacePatterns, $text);

        return $text;
    }

    /**
     * Clean and format a topic text.
     *
     * @assert (' ทด\/สอบ$'."\r\n\t".'<?php echo \'555\'?> ') [==] 'ทด&#92;/สอบ&#36; &lt;?php echo &#039;555&#039;?&gt;'
     * @assert ('&nbsp;') [==] '&amp;nbsp;'
     * @assert ('&nbsp;', false) [==] '&nbsp;'
     *
     * @param string $text           The input text
     * @param bool   $double_encode  Whether to double encode special characters (default: true)
     *
     * @return string The cleaned and formatted topic text
     */
    public static function topic($text, $double_encode = true)
    {
        // Check if the input text is null
        if ($text === null) {
            return '';
        }

        // Clean and format the text
        $cleanedText = self::htmlspecialchars($text, $double_encode); // Convert special characters to HTML entities
        $trimmedText = trim($cleanedText); // Remove leading and trailing whitespace
        $formattedText = preg_replace('/[\r\n\s\t]+/', ' ', $trimmedText); // Replace consecutive whitespace characters with a single space

        return $formattedText;
    }

    /**
     * Convert HTML entities back to their corresponding characters.
     *
     * @assert (\Kotchasan\Text::htmlspecialchars('&"\'<>\\{}$')) [==] '&"\'<>\\{}$'
     *
     * @param string $text The input text
     *
     * @return string The text with HTML entities converted back to characters
     */
    public static function unhtmlspecialchars($text)
    {
        // Check if the input text is null
        if ($text === null) {
            return '';
        }

        // Convert HTML entities back to characters
        $decodedText = str_replace(
            array('&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'),
            array('&', '"', "'", '<', '>', '\\', '{', '}', '$'),
            $text
        );

        return $decodedText;
    }

    /**
     * Sanitize a URL string.
     *
     * @assert (" http://www.kotchasan.com?a=1&b=2&amp;c=3 ") [==] 'http://www.kotchasan.com?a=1&amp;b=2&amp;c=3'
     * @assert ("javascript:alert('xxx')") [==] 'alertxxx'
     * @assert ("http://www.xxx.com/javascript/") [==] 'http://www.xxx.com/javascript/'
     *
     * @param string $text The input URL string
     *
     * @return string The sanitized URL string
     */
    public static function url($text)
    {
        // Check if the input text is null
        if ($text === null) {
            return '';
        }

        // Remove JavaScript and unwanted characters from the URL
        $text = preg_replace('/(^javascript:|[\(\)\'\"]+)/', '', trim($text));

        // Convert special characters to HTML entities
        $sanitizedText = self::htmlspecialchars($text, false);

        return $sanitizedText;
    }

    /**
     * Sanitize a username string.
     *
     * @assert (' ad_min@demo.com') [==] 'ad_min@demo.com'
     * @assert ('012 3465') [==] '0123465'
     *
     * @param string $text The input username string
     *
     * @return string The sanitized username string
     */
    public static function username($text)
    {
        // Check if the input text is null
        if ($text === null) {
            return '';
        }

        // Remove non-alphanumeric characters, @, ., -, and _ from the username
        $sanitizedText = preg_replace('/[^a-zA-Z0-9@\.\-_]+/', '', $text);

        return $sanitizedText;
    }
}
