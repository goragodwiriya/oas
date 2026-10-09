<?php
/**
 * @filesource Kotchasan/Http/InputItem.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * Enhanced InputItem - Direct processing (no cache)
 */

namespace Kotchasan\Http;

use Kotchasan\Text;

/**
 * Enhanced Input Item with fluent interface (no cache)
 */
class InputItem
{
    /**
     * @var mixed The input value
     */
    protected $value;

    /**
     * @var string|null The input type (GET, POST, COOKIE, SESSION)
     */
    protected $type;

    /**
     * @var array Allowed methods for security
     */
    protected static $allowedMethods = [
        'topic', 'filter', 'toInt', 'username', 'email', 'url', 'text', 'htmlText', 'bool', 'color',
        'alphanumeric', 'phone', 'date', 'datetime', 'float', 'json', 'base64', 'password', 'oneLine', 'cut',
        'description', 'detail', 'keywords', 'time', 'quote', 'textarea', 'number',
        'toBoolean', 'toDouble', 'toFloat', 'toObject', 'toString', 'toArray', 'toJson', 'exists'
    ];

    /**
     * Constructor
     *
     * @param mixed $value The input value
     * @param string|null $type The input type
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
     * Static method for var_export() compatibility
     *
     * @param array $state
     *
     * @return self
     */
    public static function __set_state(array $state)
    {
        $instance = new self();
        $instance->value = $state['value'] ?? null;
        $instance->type = $state['type'] ?? null;
        return $instance;
    }

    /**
     * String representation for backward compatibility
     *
     * @return string
     */
    public function __toString()
    {
        return (string) ($this->value ?? '');
    }

    /**
     * Return value as string (explicit method)
     * Kept for code that expects an object method `toString()`.
     *
     * @return mixed
     */
    public function toString()
    {
        return (string) ($this->value ?? '');
    }

    /**
     * Convert to JSON
     *
     * @return mixed
     */
    public function toJson()
    {
        return json_encode($this->value);
    }

    /**
     * Get raw value
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get input type
     *
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Check if value is empty
     *
     * @return mixed
     */
    public function isEmpty()
    {
        return empty($this->value);
    }

    /**
     * Helper: map a callback over the value if it's an array, otherwise apply to single value
     *
     * @param callable $fn
     *
     * @return mixed
     */
    protected function map(callable $fn)
    {
        if (is_array($this->value)) {
            $result = [];
            foreach ($this->value as $k => $v) {
                $result[$k] = $fn($v);
            }
            return $result;
        }
        return $fn($this->value);
    }

    /**
     * Convert to safe topic string
     *
     * @param int $len
     *
     * @return mixed
     */
    public function topic(int $len = 0)
    {
        return $this->map(function ($v) use ($len) {
            return Text::topic($v ?? '', $len);
        });
    }

    /**
     * Filter input based on pattern
     *
     * @param string $pattern
     * @param string $replacement
     *
     * @return mixed
     */
    public function filter(string $pattern, string $replacement = '')
    {
        return $this->map(function ($v) use ($pattern, $replacement) {
            return Text::filter($v, $pattern, $replacement);
        });
    }

    /**
     * Convert to integer
     *
     * @param bool $strict
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function toInt(bool $strict = false)
    {
        return $this->map(function ($v) use ($strict) {
            if ($strict && !is_numeric($v)) {
                throw new \InvalidArgumentException('Value is not a valid number');
            }
            return (int) ($v ?? 0);
        });
    }

    /**
     * Convert to float
     *
     * @param bool $strict
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function float(bool $strict = false)
    {
        if ($strict && !is_numeric($this->value)) {
            throw new \InvalidArgumentException('Value is not a valid number');
        }
        return (float) ($this->value ?? 0.0);
    }

    /**
     * Convert to boolean
     *
     * @return mixed
     */
    public function bool(): bool
    {
        $value = $this->value;
        return !empty($value) && $value !== '0' && $value !== 'false';
    }

    /**
     * Get safe text with HTML special characters escaped
     *
     * @param bool $double_encode Whether to double encode existing entities
     *
     * @return mixed
     */
    public function text(bool $double_encode = true)
    {
        return $this->map(function ($v) use ($double_encode) {
            return Text::htmlspecialchars($v ?? '', $double_encode);
        });
    }

    /**
     * Get text with specified HTML formatting tags allowed
     *
     * @param array $allowedTags Array of allowed tag names (default: ['em', 'b', 'strong', 'i'])
     *
     * @return mixed
     */
    public function htmlText(array $allowedTags = ['em', 'b', 'strong', 'i'])
    {
        return $this->map(function ($v) use ($allowedTags) {
            return Text::htmlText($v, $allowedTags);
        });
    }

    /**
     * Get username (supports both email and username formats)
     * Sanitizes input by removing unwanted characters, keeps alphanumeric + @.-_
     *
     * @param string $default Default value when input is null/empty
     *
     * @return mixed The sanitized username or default if no input; returns array when input is array
     */
    public function username(string $default = '')
    {
        return $this->map(function ($v) use ($default) {
            if ($v === null || $v === '') {
                return $default;
            }
            return Text::username(trim($v));
        });
    }

    /**
     * Get email (sanitizes input to valid email format)
     *
     * @param string $default Default value when input is null/empty
     *
     * @return mixed The sanitized email or default if no input; returns array when input is array
     */
    public function email(string $default = '')
    {
        return $this->map(function ($v) use ($default) {
            if ($v === null || $v === '') {
                return $default;
            }
            return Text::username(trim($v));
        });
    }

    /**
     * Get password-safe string or array of password-safe strings
     * Keeps word characters and specific allowed characters (@#*$&{}!?+_-=.[]ก-ฮ)
     *
     * @param string $default Default value when input is null/empty
     *
     * @return mixed The password-safe string or default if no input; if the input is an array, returns an array of sanitized values
     */
    public function password(string $default = '')
    {
        return $this->map(function ($v) use ($default) {
            if ($v === null || $v === '') {
                return $default;
            }
            return Text::password((string) $v);
        });
    }

    /**
     * Get one-line text (removes line breaks, tabs, multiple spaces)
     * Perfect for titles, subjects, single-line inputs
     *
     * @param int $maxLength Maximum length (0 = no limit)
     * @param string $default Default value when input is null/empty
     *
     * @return mixed The one-line text or default if no input; returns array when input is array
     */
    public function oneLine(int $maxLength = 0, string $default = '')
    {
        return $this->map(function ($v) use ($maxLength, $default) {
            if ($v === null || $v === '') {
                return $default;
            }
            return Text::oneLine($v, $maxLength);
        });
    }

    /**
     * Cut text to specified length with ellipsis
     * Useful for previews, summaries
     *
     * @param int $length Maximum length (including ellipsis)
     * @param string $default Default value when input is null/empty
     *
     * @return mixed The cut text or default if no input; returns array when input is array
     */
    public function cut(int $length, string $default = '')
    {
        return $this->map(function ($v) use ($length, $default) {
            if ($v === null || $v === '') {
                return $default;
            }
            return Text::cut($v, $length);
        });
    }
/**
 * Get URL (validates URL format)
 * Returns sanitized URL only if valid, empty string if invalid format
 *
 * @param string $default Default value when input is null/empty
 *
 * @return mixed Valid URL, empty string if invalid, or default if no input; returns array when input is array
 */
    public function url(string $default = '')
    {
        return $this->map(function ($v) use ($default) {
            if ($v === null || $v === '') {
                return $default;
            }
            $url = filter_var(trim($v), FILTER_VALIDATE_URL);
            return $url !== false ? $url : '';
        });
    }

    /**
     * Get color (validates color format)
     * Returns color if valid format (#hex or color name), empty string if invalid
     *
     * @param string $default Default value when input is null/empty
     *
     * @return mixed Valid color, empty string if invalid, or default if no input; returns array when input is array
     */
    public function color(string $default = '#000000')
    {
        return $this->map(function ($v) use ($default) {
            return Text::color($v, $default);
        });
    }

    /**
     * Get alphanumeric
     *
     * @return mixed
     */
    public function alphanumeric()
    {
        return $this->map(function ($v) {
            return Text::alphanumeric($v);
        });
    }

    /**
     * Get phone number
     *
     * @return mixed
     */
    public function phone()
    {
        return $this->map(function ($v) {
            return Text::phone($v);
        });
    }

    /**
     * Get date
     *
     * @param string $format
     * @return mixed
     */
    public function date(string $format = 'Y-m-d')
    {
        return $this->map(function ($v) use ($format) {
            return Text::date($v, $format);
        });
    }

    /**
     * Get datetime
     *
     * @param string $format
     * @return mixed
     */
    public function datetime(string $format = 'Y-m-d H:i:s')
    {
        return $this->map(function ($v) use ($format) {
            return Text::date($v, $format);
        });
    }

    /**
     * Get JSON decoded value
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function json($default = null)
    {
        return $this->map(function ($v) use ($default) {
            $decoded = json_decode($v ?? '', true);
            return $decoded !== null ? $decoded : $default;
        });
    }

    /**
     * Get base64 decoded value
     *
     * @return mixed
     */
    public function base64()
    {
        return $this->map(function ($v) {
            $decoded = base64_decode($v ?? '', true);
            return $decoded !== false ? $decoded : '';
        });
    }

    /**
     * Get the sanitized and truncated text.
     *
     * Removes tags and unwanted characters from the text.
     *
     * @param int $len The maximum length of the text (default: 0)
     *
     * @return mixed The sanitized and truncated text
     */
    public function description(int $len = 0)
    {
        return $this->map(function ($v) use ($len) {
            return Text::description((string) ($v ?? ''), $len);
        });
    }

    /**
     * Remove PHP tags and escape characters for text input from an editor.
     *
     * @return mixed The sanitized text or array when input is array
     */
    public function detail()
    {
        return $this->map(function ($v) {
            return Text::detail((string) ($v ?? ''));
        });
    }

    /**
     * Get the sanitized and truncated keywords from the input value.
     *
     * Removes tags, whitespace, and unwanted characters from the input value.
     *
     * @param int $len The maximum length of the keywords (default: 0)
     *
     * @return mixed The sanitized keywords or array when input is array
     */
    public function keywords(int $len = 0)
    {
        return $this->map(function ($v) use ($len) {
            return Text::keywords((string) ($v ?? ''), $len);
        });
    }

    /**
     * Get the time value.
     *
     * Returns null if the time value is empty or in the wrong format.
     *
     * @param bool $strict True to validate the time value strictly, false to skip validation (default: false)
     *
     * @return mixed The time value or array when input is array
     */
    public function time(bool $strict = false)
    {
        return $this->map(function ($v) use ($strict) {
            return Text::time($v, $strict);
        });
    }

    /**
     * Accepts text input and converts single quotes to HTML entity '&#39;',
     * and trims leading and trailing spaces.
     *
     * @return mixed The processed text or array when input is array
     */
    public function quote()
    {
        return $this->map(function ($v) {
            return Text::quote($v);
        });
    }

    /**
     * Converts '<', '>', '\', '{', '}', and '\n' to their corresponding HTML entities,
     * converts '\n' to '<br>', and trims leading and trailing spaces.
     * Used for receiving data from a textarea input.
     *
     * @return mixed The processed textarea input or array when input is array
     */
    public function textarea()
    {
        return $this->map(function ($v) {
            return Text::textarea($v);
        });
    }

    /**
     * Extracts numbers or an array of numbers from the input.
     *
     * @return mixed The extracted numbers
     */
    public function number()
    {
        return $this->map(function ($v) {
            return Text::number($v);
        });
    }

    /**
     * Converts the input value to a boolean.
     *
     * This function returns 1 if the input value is not empty, and 0 otherwise.
     *
     * @return mixed The converted boolean value 1 or 0
     */
    public function toBoolean()
    {
        $value = $this->value;
        return !empty($value) && $value !== '0' && $value !== 'false' ? 1 : 0;
    }

    /**
     * Converts the input value to a double.
     *
     * This function converts the input value to a double. If the value is null,
     * it returns 0. The function removes any commas from the value before conversion.
     *
     * @return mixed The converted double value
     */
    public function toDouble()
    {
        return $this->map(function ($v) {
            return Text::toDouble($v);
        });
    }

    /**
     * Converts the input value to a float.
     *
     * @return mixed The converted float value
     */
    public function toFloat()
    {
        return (float) ($this->value ?? 0);
    }

    /**
     * Converts the input value to an object.
     *
     * @return mixed The converted object value
     */
    public function toObject()
    {
        return (object) $this->value;
    }

    /**
     * Converts the input value to an array.
     *
     * This function returns the input value as an array.
     * If the value is not an array, returns the original value.
     *
     * @return mixed The converted array value
     */
    public function toArray()
    {
        if (is_array($this->value)) {
            return $this->value;
        }
        return [$this->value];
    }

    /**
     * Check if the input variable exists.
     *
     * @return bool True if the input variable exists, false otherwise
     */
    public function exists()
    {
        return $this->value !== null;
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
    public function isSession(): bool
    {
        return $this->type === 'SESSION';
    }

    /**
     * Create InputItem instance
     *
     * @param mixed $value
     * @param string|null $type
     *
     * @return static
     */
    public static function create($value = null, ?string $type = null): self
    {
        return new static($value, $type);
    }
}
