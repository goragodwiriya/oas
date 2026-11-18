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
     * It does not escape the text: give it text that went through htmlspecialchars()
     * first. Links are checked here whatever the input: a [url] whose address would
     * run script (javascript:, data: ... also written with entities) stays plain text,
     * and the address is escaped so it cannot leave its href="".
     *
     * @param string $detail The input text
     *
     * @return string
     */
    public static function highlighter($detail)
    {
        $detail = preg_replace_callback('/\[([uo]l)\](.*)\[\/\\1\]/is', function ($match) {
            return '<'.$match[1].'><li>'.preg_replace('/<br(\s\/)?>/is', '</li><li>', $match[2]).'</li></'.$match[1].'>';
        }, (string) $detail);
        // [url]address[/url]
        $detail = preg_replace_callback('/\[url\](.*?)\[\/url\]/i', function ($match) {
            $href = self::bbcodeHref($match[1]);

            return $href === null ? $match[1] : '<a href="'.$href.'" target="_blank">'.$match[1].'</a>';
        }, $detail);
        // [url=address]text[/url] — an address without a scheme is a page of this site
        $detail = preg_replace_callback('/\[url=([^\]]*)\](.*?)\[\/url\]/i', function ($match) {
            $url = trim($match[1]);
            if (!preg_match('#^(ftp|https?)://#i', $url)) {
                $url = WEB_URL.ltrim($url, '/');
            }
            $href = self::bbcodeHref($url);

            return $href === null ? $match[2] : '<a href="'.$href.'" target="_blank">'.$match[2].'</a>';
        }, $detail);
        $patt[] = '/\[(i|dfn|b|strong|u|em|ins|del|sub|sup|small|big)\](.*)\[\/\\1\]/is';
        $replace[] = '<\\1>\\2</\\1>';
        $patt[] = '/\[color=([#a-z0-9]+)\]/i';
        $replace[] = '<span style="color:\\1">';
        $patt[] = '/\[size=([0-9]+)(px|pt|em|\%)\]/i';
        $replace[] = '<span style="font-size:\\1\\2">';
        $patt[] = '/\[\/(color|size)\]/i';
        $replace[] = '</span>';
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
     * The href="" value of a BBCode link, or null when the address would run script.
     *
     * A browser decodes character references in an attribute before it reads the
     * scheme, with or without the final ";" (javascript&#58alert(1)), so the check
     * is made on the decoded address. Entities already in the text are kept as written.
     *
     * @param string $url
     *
     * @return string|null
     */
    private static function bbcodeHref($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $probe = preg_replace_callback('/&#(x[0-9a-f]+|[0-9]+);?/i', function ($match) {
            $code = strtolower($match[1][0]) === 'x' ? hexdec(substr($match[1], 1)) : (int) $match[1];

            return $code > 0 && $code < 0x110000 ? mb_chr((int) $code, 'UTF-8') : '';
        }, $url);
        $probe = html_entity_decode((string) $probe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('#^ftp://#i', trim($probe)) && !self::isSafeHtmlUrl($probe, false)) {
            return null;
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8', false);
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
     * Returns the password as typed, without control characters (U+0000-U+001F, U+007F).
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

        // Every printable character belongs to the password (spaces, %, ^, ~, any
        // language); only control characters are removed. It used to keep a short
        // list only, so a stored hash may be of that shortened text: Password::verify()
        // refuses it and reports it as outdated (Password::legacyInput()).
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $text);
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
     * Sanitize a URL string for safe use in a hyperlink context.
     * Rejects dangerous pseudo-schemes (javascript:, vbscript:, data:) — case
     * insensitively and even when obfuscated with whitespace, control
     * characters or HTML entities (e.g. "JAVA\tSCRIPT:", "javascript&#58;") —
     * then strips characters that could break out of an href attribute or be
     * abused as a tagged-template call (quotes, parentheses, backticks, angle
     * brackets). Legitimate schemes (http, https, ftp, mailto, tel), relative
     * paths and anchors are preserved.
     *
     * @param string $text The input URL string
     *
     * @return string The sanitized URL string, or empty string if it resolves
     *                to a dangerous scheme
     */
    public static function url($text)
    {
        // Check if the input text is null
        if ($text === null || $text === '') {
            return '';
        }

        $text = trim($text);

        // Normalise for scheme detection: decode HTML entities and remove the
        // whitespace/control characters that browsers ignore when resolving a
        // scheme, so obfuscated payloads cannot slip past the check.
        $probe = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $probe = preg_replace('/[\x00-\x20]+/', '', $probe);
        if (preg_match('/^(?:javascript|vbscript|data):/i', $probe)) {
            return '';
        }

        // Remove characters that could break out of an href attribute or be
        // abused as a tagged-template call.
        $text = preg_replace('/[\(\)\'"`<>]+/', '', $text);

        // Convert remaining special characters to HTML entities
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
     * Uses random_int() (CSPRNG), so the result can serve as a one-time code.
     *
     * @param int $length The length of the generated string.
     * @param string $characters The characters to use for generating the random string.
     *
     * @return string The randomly generated string.
     */
    public static function generateRandomString($length = 4, $characters = '0123456789')
    {
        $characters = (string) $characters;
        $charLength = strlen($characters);
        if ($charLength === 0) {
            return '';
        }
        $output = '';
        for ($i = 0; $i < $length; $i++) {
            $output .= $characters[random_int(0, $charLength - 1)];
        }

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
     * Exact inverse of textarea(): decode only the entities textarea()
     * produces, back to their original characters. Used when returning
     * stored textarea content through a JSON API so a client-side
     * `field.value = ...` shows the real text instead of entity codes.
     * (Deliberately narrower than unhtmlspecialchars() — that also decodes
     * &amp;/&quot;/&#039;, which textarea() never encodes, and decoding
     * those would mutate content where the user literally typed an entity.)
     *
     * @param string|null $text The stored, textarea()-encoded text
     *
     * @return string
     */
    public static function untextarea($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return str_replace(
            ['&lt;', '&gt;', '&#92;', '&#x007B;', '&#x007D;', '&#36;'],
            ['<', '>', '\\', '{', '}', '$'],
            $text
        );
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
        $result = trim((string) preg_replace('/[\r\n\s\t\"\'\<\>]{1,}/isu', ' ', strip_tags(self::validUtf8($text))));
        if ($len > 0 && mb_strlen($result) > $len) {
            $result = mb_substr($result, 0, $len);
        }
        return $result;
    }

    /**
     * The text with byte sequences that are not UTF-8 replaced by "?".
     *
     * A pattern with /u returns null for such text, which used to turn a whole
     * description into an empty string because of one broken byte.
     *
     * @param string $text
     *
     * @return string
     */
    private static function validUtf8($text)
    {
        $text = (string) $text;

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
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

        $result = trim((string) preg_replace(array_keys($patt), array_values($patt), self::validUtf8($text)));

        if ($len > 0 && mb_strlen($result) > $len) {
            $result = mb_substr($result, 0, $len);
        }

        return $result;
    }

    /**
     * Encodes editor content for storage: PHP tags are removed and { } \ are
     * encoded; everything else is kept as written, including <script>,
     * <style>, forms, iframes and inline handlers. For content that authorised
     * authors write (articles, pages, mail templates).
     *
     * With $allowJs false, what can run JavaScript is removed first and the
     * rest (CSS, classes, forms, iframes) is kept. Removed: <script>, <object>,
     * <embed>, <applet>, <base>, inline <svg> and <math>, <noscript> and the
     * other elements a browser reads as raw text; every on* attribute, srcdoc
     * and http-equiv; a URL attribute with a javascript:, vbscript: or data:
     * scheme (data:image stays on src); a style attribute or <style> block
     * with expression(), behavior or -moz-binding; comments other than plain
     * ones. The markup comes back normalised (tags closed, attributes quoted).
     *
     * For HTML from people who are not trusted to format a page (comments,
     * public forms) use cleanHtml(), which keeps formatting only.
     *
     * @param string $text    The input text
     * @param bool   $allowJs false removes everything that can run JavaScript
     *
     * @return string
     */
    public static function detail(string $text, bool $allowJs = true): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        if (!$allowJs) {
            $text = self::filterHtml($text, self::htmlRules(false));
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
     * Cleans HTML from a rich-text editor before it is stored or shown, keeping
     * its formatting and removing everything that can run code.
     *
     * Removed: script, style, template, form controls, object/embed/applet,
     * meta/link/base, svg, math, noscript and frames with their content; an
     * iframe whose src does not start with one of $iframeHosts (an allowed one
     * gets a sandbox); every on* attribute, srcdoc, formaction and http-equiv;
     * a URL attribute whose scheme is not http, https, mailto or tel (data:image
     * is kept on src); a style with expression(), behavior, -moz-binding or a
     * script URL; comments and PHP tags. A target="_blank" link gets
     * rel="noopener noreferrer".
     *
     * Unlike detail() it does not encode { } and \ — use InputItem::cleanHtml()
     * for a value that goes into the database the way detail() did.
     *
     * @param string|null $html        Markup to clean
     * @param array|null  $iframeHosts host + path prefixes an iframe src may start with
     *                                 (default: YouTube, YouTube no-cookie, Vimeo players)
     *
     * @return string Clean markup
     */
    public static function cleanHtml($html, ?array $iframeHosts = null): string
    {
        if ($iframeHosts === null) {
            $iframeHosts = ['www.youtube.com/embed/', 'www.youtube-nocookie.com/embed/', 'player.vimeo.com/video/'];
        }

        return self::filterHtml((string) $html, self::htmlRules(true, $iframeHosts));
    }

    /**
     * The two sets of rules filterHtml() works with.
     *
     * strict (cleanHtml): formatting only, URL schemes from an allow list,
     * iframes from $iframeHosts. Not strict (detail() without JavaScript):
     * everything stays except what can run script.
     *
     * @param bool  $strict
     * @param array $iframeHosts
     *
     * @return array
     */
    private static function htmlRules(bool $strict, array $iframeHosts = []): array
    {
        // Elements whose content a browser reads as raw text or in another
        // namespace, where it and libxml do not agree on what is markup
        $rawText = ['svg', 'math', 'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'listing'];
        $always = ['script', 'frame', 'frameset', 'object', 'embed', 'applet', 'param', 'base'];

        if ($strict) {
            return [
                'drop' => array_merge($always, $rawText, [
                    'style', 'template', 'form', 'input', 'button', 'select', 'option', 'optgroup',
                    'textarea', 'datalist', 'meta', 'link', 'title', 'head'
                ]),
                'comments' => false,
                'iframeHosts' => $iframeHosts,
                'iframeSandbox' => 'allow-scripts allow-same-origin allow-presentation allow-popups',
                'removeAttributes' => ['srcdoc', 'formaction', 'http-equiv'],
                'urlAllowList' => true,
                'style' => '/expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:|url\s*\(\s*[\'"]?\s*data:(?!image\/)/i',
                'relNoopener' => true
            ];
        }

        return [
            'drop' => array_merge($always, $rawText),
            'comments' => true,
            'iframeHosts' => null,
            'iframeSandbox' => null,
            'removeAttributes' => ['srcdoc', 'http-equiv'],
            'urlAllowList' => false,
            'style' => '/expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:/i',
            'relNoopener' => false
        ];
    }

    /**
     * Parses markup, removes what $rules do not allow and returns the rest.
     *
     * @param string $html
     * @param array  $rules from htmlRules()
     *
     * @return string
     */
    private static function filterHtml(string $html, array $rules): string
    {
        if (trim($html) === '') {
            return '';
        }

        // libxml parses HTML 4: a stray html/head/body tag would move what follows
        // out of the body, and wbr/source/track/embed (void in HTML 5) would swallow
        // what follows — remove the first and close the second straight away
        $html = preg_replace('#</?(?:html|head|body)\b[^>]*>#i', '', $html);
        $html = preg_replace('#<(wbr|source|track|embed)\b([^>]*?)/?>#i', '<$1$2></$1>', $html);

        // It knows the HTML 4 entities only: &dollar; or &colon; would come back as
        // the text "&dollar;". Those become numeric references (not inside <style>,
        // where a browser does not read entities either).
        static $html4 = null;
        if ($html4 === null) {
            $html4 = array_flip(get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML401, 'UTF-8'));
        }
        $html = preg_replace_callback('#<(style|script)\b.*?</\1\s*>|&[A-Za-z][A-Za-z0-9]*;#is', function ($match) use ($html4) {
            if ($match[0][0] === '<' || isset($html4[$match[0]])) {
                return $match[0];
            }
            $text = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($text === $match[0]) {
                return $match[0];
            }
            $refs = '';
            foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                $refs .= '&#'.mb_ord($char, 'UTF-8').';';
            }

            return $refs;
        }, $html);

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        self::cleanHtmlNode($body, $rules);

        // libxml percent-encodes href, src, action and <a name> when it writes HTML:
        // "{WEBURL}img/ภาพ.jpg" would come back as "%7BWEBURL%7Dimg/%E0%B8%A0...".
        // They are written under another name, which it leaves alone, and renamed back.
        $mask = 'data-'.bin2hex(random_bytes(8)).'-';
        self::maskUrlAttributes($body, $mask);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = str_replace([' '.$mask.'href=', ' '.$mask.'src=', ' '.$mask.'action=', ' '.$mask.'name='], [' href=', ' src=', ' action=', ' name='], $out);

        // Void elements have no end tag
        return preg_replace('#</(?:wbr|source|track|embed)>#i', '', $out);
    }

    /**
     * Renames the attributes libxml would percent-encode on output (href, src,
     * action, and name on <a>) to $mask.name, keeping the order of attributes.
     *
     * @param \DOMNode $node
     * @param string   $mask
     */
    private static function maskUrlAttributes(\DOMNode $node, string $mask)
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $attributes = [];
            $masked = false;
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = $attribute->nodeName;
                $lower = strtolower($name);
                if (in_array($lower, ['href', 'src', 'action'], true) || ($lower === 'name' && strtolower($child->nodeName) === 'a')) {
                    $name = $mask.$lower;
                    $masked = true;
                }
                $attributes[] = [$name, $attribute->nodeValue, $attribute];
            }
            if ($masked) {
                foreach ($attributes as $item) {
                    $child->removeAttributeNode($item[2]);
                }
                foreach ($attributes as $item) {
                    if ($item[0] === $item[2]->nodeName) {
                        // The node itself goes back: libxml accepts names when parsing
                        // (c"false") that setAttribute() refuses
                        $child->setAttributeNode($item[2]);
                    } else {
                        $child->setAttribute($item[0], $item[1]);
                    }
                }
            }
            self::maskUrlAttributes($child, $mask);
        }
    }

    /**
     * Walks the children of a node for filterHtml().
     *
     * @param \DOMNode $node
     * @param array    $rules
     */
    private static function cleanHtmlNode(\DOMNode $node, array $rules)
    {
        /**
         * @var array
         */
        static $urlAttributes = ['href', 'src', 'action', 'formaction', 'poster', 'background', 'cite', 'data', 'xlink:href', 'codebase', 'ping', 'longdesc', 'usemap'];

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                // A browser ends a comment at "-->" or "--!>" and reads "<!-->" as an
                // empty one; only a comment that cannot be read two ways is kept
                if (!$rules['comments'] || preg_match('/[<>]|--/', $child->nodeValue)) {
                    $node->removeChild($child);
                }
                continue;
            }
            if ($child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if ($child instanceof \DOMCdataSection) {
                // How libxml holds the text of a <style>; anywhere else it is not HTML
                if (strtolower($node->nodeName) !== 'style') {
                    $node->removeChild($child);
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, $rules['drop'], true)) {
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'iframe') {
                if ($rules['iframeHosts'] !== null && !self::isAllowedIframe($child->getAttribute('src'), $rules['iframeHosts'])) {
                    $node->removeChild($child);
                    continue;
                }
                // A browser reads what is inside an iframe as text, never as markup
                while ($child->firstChild) {
                    $child->removeChild($child->firstChild);
                }
            } elseif ($tag === 'textarea' || $tag === 'title') {
                // Text only to a browser: keep the text, not what libxml made of it
                $text = $child->textContent;
                while ($child->firstChild) {
                    $child->removeChild($child->firstChild);
                }
                $child->appendChild($node->ownerDocument->createTextNode($text));
            } elseif ($tag === 'style' && (stripos($child->textContent, '</style') !== false || preg_match($rules['style'], $child->textContent))) {
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue;
                $image = $name === 'src' || $name === 'poster';
                $remove = strpos($name, 'on') === 0
                    // A stray html/head/body tag hands its attributes to the page's own
                    || in_array($tag, ['html', 'head', 'body'], true)
                    || in_array($name, $rules['removeAttributes'], true)
                    || (in_array($name, $urlAttributes, true) && !($rules['urlAllowList'] ? self::isSafeHtmlUrl($value, $image) : !self::isScriptUrl($value, $image)))
                    || (($name === 'srcset' || $name === 'imagesrcset') && !self::isSafeSrcset($value, $rules['urlAllowList']))
                    || ($name === 'style' && preg_match($rules['style'], $value));
                if ($remove) {
                    $child->removeAttributeNode($attribute);
                }
            }

            if ($tag === 'iframe' && $rules['iframeSandbox'] !== null) {
                $child->setAttribute('sandbox', $rules['iframeSandbox']);
            }
            if ($rules['relNoopener'] && $tag === 'a' && strtolower($child->getAttribute('target')) === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            self::cleanHtmlNode($child, $rules);
        }
    }

    /**
     * Whether a URL from HTML runs script when followed or loaded: javascript:,
     * vbscript: and data: (a data:image/... picture is not one when $image is
     * true). Control characters and spaces are ignored the way a browser
     * ignores them, so "java\tscript:" is javascript:.
     *
     * @param string $url
     * @param bool   $image
     *
     * @return bool
     */
    private static function isScriptUrl($url, bool $image = false): bool
    {
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $url);
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $match)) {
            return false;
        }
        $scheme = strtolower($match[1]);
        if ($scheme === 'data') {
            return !($image && preg_match('#^data:image/(png|gif|jpe?g|webp|avif|bmp)[;,]#i', $probe) === 1);
        }

        return in_array($scheme, ['javascript', 'vbscript', 'livescript', 'mocha'], true);
    }

    /**
     * Whether a URL from HTML may stay in a link or image for cleanHtml():
     * no scheme (a path, ../, ?query, #id, //host), or http, https, mailto, tel;
     * data:image/... too when $image is true. Control characters and spaces are
     * ignored the way a browser ignores them, so "java\tscript:" is javascript:.
     *
     * @param string $url
     * @param bool   $image
     *
     * @return bool
     */
    private static function isSafeHtmlUrl($url, bool $image = false): bool
    {
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $url);
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $match)) {
            return true;
        }
        $scheme = strtolower($match[1]);
        if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }

        return $image && preg_match('#^data:image/(png|gif|jpe?g|webp|avif|bmp)[;,]#i', $probe) === 1;
    }

    /**
     * Whether every URL of a srcset passes isSafeHtmlUrl() ($allowList true)
     * or is not a script URL ($allowList false).
     *
     * @param string $value
     * @param bool   $allowList
     *
     * @return bool
     */
    private static function isSafeSrcset($value, bool $allowList = true): bool
    {
        foreach (explode(',', (string) $value) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
            if ($url !== '' && !($allowList ? self::isSafeHtmlUrl($url, true) : !self::isScriptUrl($url, true))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an iframe src is http(s) and its host + path starts with one of $hosts.
     *
     * @param string $src
     * @param array  $hosts
     *
     * @return bool
     */
    private static function isAllowedIframe($src, array $hosts): bool
    {
        $src = trim((string) $src);
        if (strpos($src, '//') === 0) {
            $src = 'https:'.$src;
        }
        $parts = parse_url($src);
        if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        $where = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        foreach ($hosts as $prefix) {
            if (strpos($where, strtolower($prefix)) === 0) {
                return true;
            }
        }

        return false;
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
     * Get text with only the specified HTML formatting tags allowed.
     * Every other tag is removed, any attributes on the allowed tags are
     * stripped (so no event handlers such as onclick/onerror can survive),
     * and all remaining text is HTML-escaped. The result is safe to embed
     * directly into HTML output.
     *
     * @param string $text The input text
     * @param array $allowedTags Array of allowed tag names (default: ['em', 'b', 'strong', 'i'])
     *
     * @return string The processed text with only the bare allowed tags preserved
     */
    public static function htmlText($text, array $allowedTags = ['em', 'b', 'strong', 'i']): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Build strip_tags format: <em><b><strong><i>
        $stripTagsFormat = implode('', array_map(fn($tag) => "<{$tag}>", $allowedTags));

        // First strip all tags except the allowed ones. strip_tags keeps the
        // attributes of allowed tags, so they are removed in the next step.
        $allowed = strip_tags($text, $stripTagsFormat);

        // Build regex pattern for allowed tags
        $tagPattern = implode('|', array_map('preg_quote', $allowedTags));

        // Replace each allowed tag with a unique placeholder, discarding any
        // attributes. A random per-call prefix prevents user input from forging
        // a placeholder that would be turned back into a tag after escaping.
        $placeholders = [];
        $i = 0;
        $token = bin2hex(random_bytes(8));

        // Match allowed tags (opening and closing), including any attributes
        $allowed = preg_replace_callback(
            '/<(\/?)('.$tagPattern.')(\s[^>]*)?>/i',
            function ($matches) use (&$placeholders, &$i, $token) {
                $placeholder = "\x01{$token}_{$i}\x01";
                // Keep only the bare tag; drop all attributes
                $placeholders[$placeholder] = '<'.$matches[1].strtolower($matches[2]).'>';
                $i++;
                return $placeholder;
            },
            $allowed
        );

        // Escape HTML special characters in the remaining (non-tag) text so
        // that stray <, >, &, ", ' cannot break out into markup.
        $allowed = self::htmlspecialchars($allowed);

        // Restore the sanitized allowed tags
        return str_replace(array_keys($placeholders), array_values($placeholders), $allowed);
    }

    /**
     * Convert an HTML fragment (such as a value stored by htmlText()) to
     * plain text: tags are removed, then entities are decoded.
     * Use it wherever the value leaves HTML — JSON for a client that sets
     * textContent, an email subject or sender name, an HTTP header, a
     * manifest. Escape the result again if it goes back into HTML.
     *
     * @param string|null $text The HTML fragment
     *
     * @return string
     */
    public static function plainText($text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Tags first: decoding first would turn &lt;b&gt; typed as text into a tag
        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
