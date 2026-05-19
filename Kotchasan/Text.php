<?php
namespace Kotchasan;

/**
 * Kotchasan Text Class
 *
 * This class provides various text manipulation methods,
 * including string truncation, file size formatting, HTML highlighting,
 * and more.
 *
 * @package Kotchasan
 */
class Text
{
    /**
     * Truncates a string to the specified length.
     * If the source string is longer than the specified length,
     * it will be truncated and '...' will be appended.
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
     * @param int $bytes     The file size in bytes
     * @param int $precision The number of decimal places (default 2)
     *
     * @return string
     */
    public static function formatFileSize($bytes, $precision = 2)
    {
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
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
     * @param string $text          The input text
     * @param bool   $double_encode Whether to double encode existing entities (default true)
     *
     * @return string
     */
    public static function htmlspecialchars($text, $double_encode = true)
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Replace special characters with their HTML entities
        $str = preg_replace(
            ['/&/', '/"/', "/'/", '/</', '/>/', '/\\\/', '/\$/'],
            ['&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#92;', '&#36;'],
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
     * @param string $text The input text
     * @param int    $len  The maximum length of the one-line text (default 0, no limit)
     *
     * @return string
     */
    public static function oneLine($text, $len = 0)
    {
        if ($text === null || $text === '') {
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
     * @param string $text The input text
     *
     * @return string
     */
    public static function password($text)
    {
        if ($text === null || $text === '') {
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
     * @param string $text The input text
     *
     * @return string The text with special characters converted to HTML entities
     */
    public static function toEditor($text)
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Define arrays for search and replacement patterns
        $searchPatterns = ['/&/', '/"/', "/'/", '/</', '/>/', '/{/', '/}/', '/&(amp;([\#a-z0-9]+));/'];
        $replacePatterns = ['&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#x007B;', '&#x007D;', '&\\2;'];

        // Replace special characters with their corresponding HTML entities
        $text = preg_replace($searchPatterns, $replacePatterns, $text);

        return $text;
    }

    /**
     * Clean a plain text for safe storage (no HTML encoding).
     * - strip_tags to remove markup
     * - removeNonCharacters to drop invalid/control bytes
     * - oneLine to normalize whitespace and optionally limit length
     *
     * @param string $text The input text
     * @param int    $len  Optional max length (0 = no cut)
     *
     * @return string Cleaned text
     */
    public static function topic($text, $len = 0)
    {
        $clean = strip_tags($text ?? '');
        $clean = self::removeNonCharacters($clean);
        return self::oneLine($clean, $len);
    }

    /**
     * Convert HTML entities back to their corresponding characters.
     *
     * @param string $text The input text
     *
     * @return string The text with HTML entities converted back to characters
     */
    public static function unhtmlspecialchars($text)
    {
        // Check if the input text is null
        if ($text === null || $text === '') {
            return '';
        }

        // Convert HTML entities back to characters
        $decodedText = str_replace(
            ['&amp;', '&quot;', '&#039;', '&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'],
            ['&', '"', "'", '<', '>', '\\', '{', '}', '$'],
            $text
        );

        return $decodedText;
    }

    /**
     * Sanitize a URL string.
     *
     * @param string $text The input URL string
     *
     * @return string The sanitized URL string
     */
    public static function url($text)
    {
        // Check if the input text is null
        if ($text === null || $text === '') {
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
     * @param string $text The input username string
     *
     * @return string The sanitized username string
     */
    public static function username($text)
    {
        // Check if the input text is null
        if ($text === null || $text === '') {
            return '';
        }

        // Remove non-alphanumeric characters, @, ., -, and _ from the username
        $sanitizedText = preg_replace('/[^a-zA-Z0-9@\.\-_]+/', '', $text);

        return $sanitizedText;
    }

    /**
     * Generates a random string of specified length using the given characters.
     *
     * @param int $length The length of the generated string.
     * @param string $characters The characters to use for generating the random string.
     *
     * @return string The randomly generated string.
     */
    public static function generateRandomString($length = 4, $characters = '0123456789')
    {
        // Initialize an empty string to store the generated random string
        $output = '';

        // Get the length of the character set
        $charLength = strlen($characters);

        // Loop through the specified length to generate random characters
        for ($i = 0; $i < $length; $i++) {
            // Append a random character from the character set to the output string
            $output .= $characters[rand(0, $charLength - 1)];
        }

        // Return the generated random string
        return $output;
    }

    /**
     * Filter text by keeping only characters matching the pattern.
     *
     * @param string|null $text The input text
     * @param string $pattern Character pattern to keep (regex character class)
     * @param string $replacement Replacement for non-matching chars
     *
     * @return string
     */
    public static function filter($text, string $pattern, string $replacement = ''): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return trim(preg_replace('/[^'.$pattern.']/', $replacement, $text));
    }

    /**
     * Extract alphanumeric characters only.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function alphanumeric($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return preg_replace('/[^a-zA-Z0-9]/', '', $text);
    }

    /**
     * Extract phone number digits only.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function phone($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return preg_replace('/[^0-9]/', '', $text);
    }

    /**
     * Validate and sanitize color value.
     *
     * @param string|null $text The input color
     * @param string $default Default color if invalid/empty
     *
     * @return string Valid color or default if invalid
     */
    public static function color($text, string $default = ''): string
    {
        if ($text === null || $text === '') {
            return $default;
        }
        $color = trim($text);
        if (preg_match('/^\#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?([0-9A-Fa-f]{2})?$/', $color) || preg_match('/^[a-zA-Z]+$/', $color)) {
            return $color;
        }
        return $default;
    }

    /**
     * Escape single quotes to HTML entity.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function quote($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return str_replace("'", '&#39;', trim($text));
    }

    /**
     * Escape text for textarea display.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function textarea($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return trim(preg_replace(['/</s', '/>/s', '/\\\\/s', '/\{/', '/\}/', '/\$/'], ['&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'], $text), " \n\r\0\x0B");
    }

    /**
     * Extract digits only from text.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function number($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return preg_replace('/[^\d]/', '', $text);
    }

    /**
     * Convert text to decimal
     *
     * @param string|null $text The input text
     *
     * @return float
     */
    public static function toDouble($text): float
    {
        if (empty($text)) {
            return 0;
        }
        return (float) str_replace(',', '', $text);
    }

    /**
     * Sanitize and truncate keywords.
     *
     * @param string|null $text The input text
     * @param int $len Maximum length (0 = no limit)
     *
     * @return string
     */
    public static function keywords($text, int $len = 0): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $result = trim(preg_replace('/[\r\n\s\t\"\'\<\>]{1,}/isu', ' ', strip_tags($text)));
        if ($len > 0 && mb_strlen($result) > $len) {
            $result = mb_substr($result, 0, $len);
        }
        return $result;
    }

    /**
     * Sanitize and truncate description text.
     *
     * @param string|null $text The input text
     * @param int $len Maximum length (0 = no limit)
     *
     * @return string
     */
    public static function description($text, int $len = 0): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $patt = [
            '@<(script|style)[^>]*?>.*?</\\1>@isu' => '',
            '@<[a-z\/\!\?][^>]{0,}>@isu' => '',
            '/{(WIDGET|LNG)_[\w\s\.\-\'\(\),%\/:\&#;]+}/su' => '',
            '/(\[code(.+)?\]|\[\/code\]|\[ex(.+)?\])/ui' => '',
            '/\[([a-z]+)([\s=].*)?\](.*?)\[\/\\1\]/ui' => '\\3',
            '/(&rdquo;|&quot;|&nbsp;|&amp;|[\r\n\s\t\"\']){1,}/isu' => ' '
        ];

        $result = trim(preg_replace(array_keys($patt), array_values($patt), $text));

        if ($len > 0 && mb_strlen($result) > $len) {
            $result = mb_substr($result, 0, $len);
        }

        return $result;
    }

    /**
     * Escape text for editor content.
     *
     * @param string|null $text The input text
     *
     * @return string
     */
    public static function detail(string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Remove PHP processing instructions to prevent server-side code injection.
        // All HTML tags (script, style, iframe, event handlers, etc.) are intentionally
        // preserved — this function is used for trusted editor (admin) content.
        return str_replace(
            ['{', '}', '\\'],
            ['&#x007B;', '&#x007D;', '&#92;'],
            preg_replace('#<\?(.*?)\?>#is', '', $text)
        );
    }

    /**
     * Parse and validate time format.
     *
     * @param string|null $text The input time
     * @param bool $strict Whether to append :00 for seconds
     *
     * @return string|null
     */
    public static function time($text, bool $strict = false): ?string
    {
        if (empty($text)) {
            return null;
        }
        if (preg_match('/^([0-9]{1,2}:[0-9]{1,2})?(:[0-9]{1,2})?$/', $text, $match)) {
            if (empty($match[2])) {
                $match[2] = $strict ? ':00' : '';
            }
            return $match[1].$match[2];
        }
        return null;
    }

    /**
     * Parse and format date.
     *
     * @param string|null $text The input date
     * @param string $format Output date format
     *
     * @return string|null
     */
    public static function date($text, string $format = 'Y-m-d'): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $timestamp = strtotime($text);
        return $timestamp ? date($format, $timestamp) : null;
    }

    /**
     * Get text with specified HTML formatting tags allowed.
     *
     * @param string $text The input text
     * @param array $allowedTags Array of allowed tag names (default: ['em', 'b', 'strong', 'i'])
     *
     * @return string The processed text with allowed tags preserved
     */
    public static function htmlText($text, array $allowedTags = ['em', 'b', 'strong', 'i']): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Build strip_tags format: <em><b><strong><i>
        $stripTagsFormat = implode('', array_map(fn($tag) => "<{$tag}>", $allowedTags));

        // First strip all tags except allowed ones
        $allowed = strip_tags($text, $stripTagsFormat);

        // Build regex pattern for allowed tags
        $tagPattern = implode('|', array_map('preg_quote', $allowedTags));

        // Escape HTML special characters but preserve the allowed tags
        // by temporarily replacing them with placeholders
        $placeholders = [];
        $i = 0;

        // Match allowed tags (opening and closing)
        $allowed = preg_replace_callback(
            '/<(\/?)('.$tagPattern.')>/i',
            function ($matches) use (&$placeholders, &$i) {
                $placeholder = "ALLOWEDTAG{$i}PLACEHOLDER";
                $placeholders[$placeholder] = '<'.$matches[1].strtolower($matches[2]).'>';
                $i++;
                return $placeholder;
            },
            $allowed
        );

        // Restore allowed tags
        return str_replace(array_keys($placeholders), array_values($placeholders), $allowed);
    }
}
