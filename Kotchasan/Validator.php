<?php

namespace Kotchasan;

/**
 * Class Validator
 *
 * Provides methods for validating various types of data.
 *
 * @package Kotchasan
 */
class Validator
{
    /**
     * List of potentially dangerous file signatures (magic numbers).
     *
     * @var array
     */
    private static $dangerousSignatures = [
        // PHP
        '<?php' => true,
        '<?' => true,
        '<%' => true, // ASP tags
        // Shell scripts
        '#!/bin/sh' => true,
        '#!/bin/bash' => true,
        '#!/usr/bin/env' => true,
        '#!/usr/bin/perl' => true,
        '#!/usr/bin/python' => true,
        '#!/usr/bin/ruby' => true,
        // Windows batch
        '@echo off' => true,
        // JavaScript
        '<script' => true,
        // Potential malicious HTML
        '<iframe' => true,
        '<object' => true,
        '<embed' => true
    ];

    /**
     * Common MIME types and their corresponding file extensions.
     *
     * @var array
     */
    private static $mimeToExtMap = [
        // Images
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/bmp' => ['bmp'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'image/tiff' => ['tif', 'tiff'],
        // Documents
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        // Text
        'text/plain' => ['txt', 'text'],
        'text/csv' => ['csv'],
        'text/html' => ['html', 'htm'],
        'text/css' => ['css'],
        'text/javascript' => ['js'],
        // Archives
        'application/zip' => ['zip'],
        'application/x-rar-compressed' => ['rar'],
        'application/x-tar' => ['tar'],
        'application/gzip' => ['gz'],
        // Audio
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        // Video
        'video/mp4' => ['mp4'],
        'video/mpeg' => ['mpeg', 'mpg'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm']
    ];

    /**
     * Gets the real MIME type of a file using finfo.
     *
     * @param string $filePath Path to the file
     * @return string|false The MIME type or false on failure
     */
    public static function getRealMimeType(string $filePath)
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($filePath);
    }

    /**
     * Checks if a file is safe by validating its MIME type and scanning for malicious content.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param array $allowedMimes List of allowed MIME types
     * @param bool $checkUpload Whether to check if the file was uploaded via HTTP POST (default: true)
     * @return bool True if the file is safe, false otherwise
     */
    public static function isFileSafe(array $file, array $allowedMimes = [], bool $checkUpload = true): bool
    {
        // Check if file exists and was uploaded (if required)
        if (!isset($file['tmp_name']) || ($checkUpload && !is_uploaded_file($file['tmp_name'])) || !file_exists($file['tmp_name'])) {
            return false;
        }

        // Check real MIME type
        $realMime = self::getRealMimeType($file['tmp_name']);
        if ($realMime === false) {
            return false;
        }

        // Check if MIME type is allowed
        if (!empty($allowedMimes) && !in_array($realMime, $allowedMimes)) {
            return false;
        }

        // Scan file content for potentially malicious code
        return self::scanFileContent($file['tmp_name']);
    }

    /**
     * Validates image dimensions.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param int $maxWidth Maximum allowed width (0 for no limit)
     * @param int $maxHeight Maximum allowed height (0 for no limit)
     * @param int $minWidth Minimum allowed width (0 for no limit)
     * @param int $minHeight Minimum allowed height (0 for no limit)
     * @param bool $checkUpload Whether to check if the file was uploaded via HTTP POST (default: true)
     * @return bool True if dimensions are valid, false otherwise
     */
    public static function isImageDimensionsValid(array $file, int $maxWidth = 0, int $maxHeight = 0, int $minWidth = 0, int $minHeight = 0, bool $checkUpload = true): bool
    {
        // Check if file exists and was uploaded (if required)
        if (!isset($file['tmp_name']) || ($checkUpload && !is_uploaded_file($file['tmp_name'])) || !file_exists($file['tmp_name'])) {
            return false;
        }

        // Get image dimensions
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return false;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];

        // Check maximum dimensions
        if (($maxWidth > 0 && $width > $maxWidth) || ($maxHeight > 0 && $height > $maxHeight)) {
            return false;
        }

        // Check minimum dimensions
        if (($minWidth > 0 && $width < $minWidth) || ($minHeight > 0 && $height < $minHeight)) {
            return false;
        }

        return true;
    }

    /**
     * Validates a CSRF token against a stored token.
     *
     * @param string $token The token from the form submission
     * @param string $sessionToken The token stored in the session
     * @return bool True if tokens match, false otherwise
     */
    public static function csrf(string $token, string $sessionToken): bool
    {
        // Use hash_equals for timing attack safe string comparison
        return !empty($token) && !empty($sessionToken) && hash_equals($sessionToken, $token);
    }

    /**
     * Scans file content for potentially malicious code.
     *
     * @param string $filePath Path to the file
     * @return bool True if the file is safe, false if potentially malicious
     */
    private static function scanFileContent(string $filePath): bool
    {
        // Get file MIME type
        $mimeType = self::getRealMimeType($filePath);

        // Skip binary files except common web formats
        $textBasedMimes = [
            'text/', 'application/json', 'application/javascript', 'application/xml',
            'application/x-httpd-php', 'application/x-sh', 'application/x-perl'
        ];

        $isTextBased = false;
        foreach ($textBasedMimes as $textMime) {
            if (strpos($mimeType, $textMime) === 0) {
                $isTextBased = true;
                break;
            }
        }

        // Special handling for SVG files which can contain script tags
        if (strpos($mimeType, 'image/svg+xml') === 0) {
            $isTextBased = true;
        }

        if (!$isTextBased) {
            // For binary files, check for executable file signatures
            if (strpos($mimeType, 'application/x-executable') === 0 ||
                strpos($mimeType, 'application/x-dosexec') === 0 ||
                strpos($mimeType, 'application/x-sharedlib') === 0) {
                return false; // Executable files are not allowed
            }
            return true; // Skip other binary files
        }

        // Read the first 4096 bytes to check for dangerous signatures
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false; // Can't open file
        }

        $content = fread($handle, 4096);
        fclose($handle);

        // Check for dangerous signatures
        foreach (self::$dangerousSignatures as $signature => $dummy) {
            if (stripos($content, $signature) !== false) {
                return false; // Found potentially malicious code
            }
        }

        // Additional checks for specific file types
        if (strpos($mimeType, 'text/html') === 0 || strpos($mimeType, 'image/svg+xml') === 0) {
            // Check for potentially dangerous HTML/SVG content
            $dangerousPatterns = [
                '/on\w+\s*=\s*["\'][^"\']*["\']/', // Event handlers like onclick, onload
                '/javascript\s*:/', // JavaScript protocol
                '/data\s*:/', // Data URI scheme which can be used for XSS
                '/base64/', // Base64 encoded content
                '/<script[^>]*>/', // Script tags
                '/<iframe[^>]*>/', // iframes
                '/<object[^>]*>/', // object tags
                '/<embed[^>]*>/', // embed tags
                '/<link[^>]*>/', // link tags
                '/<meta[^>]*>/' // meta tags with http-equiv
            ];

            foreach ($dangerousPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return false; // Found potentially malicious HTML/SVG content
                }
            }
        }

        return true;
    }

    /**
     * Validates that a file's extension matches its actual MIME type.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param bool $checkUpload Whether to check if the file was uploaded via HTTP POST (default: true)
     * @return bool True if the extension matches the MIME type, false otherwise
     */
    public static function isExtensionMatchingMimeType(array $file, bool $checkUpload = true): bool
    {
        if (!isset($file['tmp_name']) || ($checkUpload && !is_uploaded_file($file['tmp_name'])) || !file_exists($file['tmp_name'])) {
            return false;
        }

        // Get the file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Get the real MIME type
        $realMime = self::getRealMimeType($file['tmp_name']);
        if ($realMime === false) {
            return false;
        }

        // Check if the MIME type corresponds to the extension
        foreach (self::$mimeToExtMap as $mime => $extensions) {
            if ($mime === $realMime && in_array($ext, $extensions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enhanced method to validate image files with additional security checks.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param array $allowedTypes The allowed image types (e.g., ['jpg', 'jpeg', 'png', 'gif'])
     * @param int $maxWidth Maximum allowed width (0 for no limit)
     * @param int $maxHeight Maximum allowed height (0 for no limit)
     * @param bool $checkUpload Whether to check if the file was uploaded via HTTP POST (default: true)
     * @return array|bool Array with image info if valid, false otherwise
     */
    public static function validateImage(array $file, array $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'], int $maxWidth = 0, int $maxHeight = 0, bool $checkUpload = true)
    {
        if (!isset($file['tmp_name']) || ($checkUpload && !is_uploaded_file($file['tmp_name'])) || !file_exists($file['tmp_name'])) {
            return false;
        }

        // Get file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            return false;
        }

        // Get the real MIME type
        $realMime = self::getRealMimeType($file['tmp_name']);
        if ($realMime === false) {
            return false;
        }

        // Validate that the file is actually an image
        $validImageMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/bmp',
            'image/webp', 'image/tiff'
        ];

        // SVG requires special handling as it can contain scripts
        $isSvg = ($realMime === 'image/svg+xml');

        if (!in_array($realMime, $validImageMimes) && !$isSvg) {
            return false;
        }

        // For SVG files, scan for malicious content
        if ($isSvg && !self::scanFileContent($file['tmp_name'])) {
            return false;
        }

        // For raster images, check dimensions and integrity
        if (!$isSvg) {
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false || $imageInfo[0] == 0 || $imageInfo[1] == 0) {
                return false;
            }

            // Check maximum dimensions if specified
            if (($maxWidth > 0 && $imageInfo[0] > $maxWidth) ||
                ($maxHeight > 0 && $imageInfo[1] > $maxHeight)) {
                return false;
            }

            return [
                'ext' => $ext,
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'mime' => $imageInfo['mime']
            ];
        }

        // For SVG, we don't have dimensions
        return [
            'ext' => 'svg',
            'width' => 0,
            'height' => 0,
            'mime' => 'image/svg+xml'
        ];
    }

    /**
     * Validates a file upload with enhanced security checks.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param array $allowedTypes The allowed file extensions
     * @param array $allowedMimes The allowed MIME types (if empty, derived from allowedTypes)
     * @param int $maxSize The maximum file size in bytes
     * @param bool $checkUpload Whether to check if the file was uploaded via HTTP POST (default: true)
     * @return bool True if the file is valid and safe, false otherwise
     */
    public static function validateFile(array $file, array $allowedTypes = [], array $allowedMimes = [], int $maxSize = 0, bool $checkUpload = true): bool
    {
        if (!isset($file['tmp_name']) || ($checkUpload && !is_uploaded_file($file['tmp_name'])) || !file_exists($file['tmp_name'])) {
            return false;
        }

        // Check file size
        if ($maxSize > 0 && $file['size'] > $maxSize) {
            return false;
        }

        // Get file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Check file extension if specified
        if (!empty($allowedTypes) && !in_array($ext, $allowedTypes)) {
            return false;
        }

        // Get the real MIME type
        $realMime = self::getRealMimeType($file['tmp_name']);
        if ($realMime === false) {
            return false;
        }

        // If no specific MIME types are provided, derive them from the allowed extensions
        if (empty($allowedMimes) && !empty($allowedTypes)) {
            $allowedMimes = [];
            foreach (self::$mimeToExtMap as $mime => $extensions) {
                foreach ($extensions as $extension) {
                    if (in_array($extension, $allowedTypes)) {
                        $allowedMimes[] = $mime;
                        break;
                    }
                }
            }
        }

        // Check MIME type if specified
        if (!empty($allowedMimes) && !in_array($realMime, $allowedMimes)) {
            return false;
        }

        // Verify that the extension matches the MIME type
        if (!self::isExtensionMatchingMimeType($file, $checkUpload)) {
            return false;
        }

        // Scan file content for malicious code
        return self::scanFileContent($file['tmp_name']);
    }

    /**
     * Validates an email address.
     *
     * @param string $email The email address to validate
     * @return bool True if the email format is correct, false otherwise
     */
    public static function email($email): bool
    {
        if (function_exists('idn_to_ascii') && preg_match('/(.*)@(.*)/', $email, $match)) {
            // Thai domain
            $email = $match[1].'@'.idn_to_ascii($match[2], 0, INTL_IDNA_VARIANT_UTS46);
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Validates a URL.
     *
     * @param string $url The URL to validate
     * @return bool True if the URL format is correct, false otherwise
     */
    public static function url($url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * Validates an integer.
     *
     * @param mixed $value The value to validate
     * @return bool True if the value is an integer, false otherwise
     */
    public static function integer($value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_INT);
    }

    /**
     * Validates a float.
     *
     * @param mixed $value The value to validate
     * @return bool True if the value is a float, false otherwise
     */
    public static function float($value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_FLOAT);
    }

    /**
     * Validates a date.
     *
     * @param string $date The date to validate (format: YYYY-MM-DD)
     * @return bool True if the date format is correct, false otherwise
     */
    public static function date($date): bool
    {
        if (preg_match('/^([0-9]{4,4})\-([0-9]{1,2})\-([0-9]{1,2})$/', $date, $match)) {
            return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
        }
        return false;
    }

    /**
     * Validates a time.
     *
     * @param string $time The time to validate (format: HH:MM:SS or HH:MM)
     * @return bool True if the time format is correct, false otherwise
     */
    public static function time($time): bool
    {
        if (preg_match('/^([0-9]{1,2}):([0-9]{1,2})(:([0-9]{1,2}))?$/', $time, $match)) {
            $h = (int) $match[1];
            $m = (int) $match[2];
            $s = isset($match[4]) ? (int) $match[4] : 0;
            return ($h >= 0 && $h < 24 && $m >= 0 && $m < 60 && $s >= 0 && $s < 60);
        }
        return false;
    }

    /**
     * Validates a phone number.
     *
     * @param string $phone The phone number to validate
     * @return bool True if the phone number format is correct, false otherwise
     */
    public static function phone($phone): bool
    {
        return (bool) preg_match('/^[0-9\-+\s]+$/', $phone);
    }

    /**
     * Validates a username.
     *
     * @param string $username The username to validate
     * @return bool True if the username format is correct, false otherwise
     */
    public static function username($username): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9@\.\-_]+$/', $username);
    }

    /**
     * Validates a password.
     *
     * @param string $password The password to validate
     * @param int $minLength The minimum length of the password (default: 8)
     * @return bool True if the password format is correct, false otherwise
     */
    public static function password($password, $minLength = 8): bool
    {
        return mb_strlen($password) >= $minLength;
    }

    /**
     * Checks if an uploaded file is an image.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param array $allowedTypes The allowed image types (e.g., ['jpg', 'jpeg', 'png', 'gif'])
     * @return array|bool Array with image info if valid, false otherwise
     */
    public static function image($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'])
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Get file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            return false;
        }

        // Check if it's a valid image
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false || $imageInfo[0] == 0 || $imageInfo[1] == 0) {
            return false;
        }

        return [
            'ext' => $ext,
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'mime' => $imageInfo['mime']
        ];
    }

    /**
     * Validates a file upload.
     *
     * @param array $file The uploaded file ($_FILES array element)
     * @param array $allowedTypes The allowed file types
     * @param int $maxSize The maximum file size in bytes
     * @return bool True if the file is valid, false otherwise
     */
    public static function file($file, $allowedTypes = [], $maxSize = 0): bool
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Check file size
        if ($maxSize > 0 && $file['size'] > $maxSize) {
            return false;
        }

        // Check file type if specified
        if (!empty($allowedTypes)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates a value against a regular expression pattern.
     *
     * @param mixed $value The value to validate
     * @param string $pattern The regular expression pattern
     * @return bool True if the value matches the pattern, false otherwise
     */
    public static function pattern($value, $pattern): bool
    {
        return (bool) preg_match($pattern, $value);
    }

    /**
     * Validates if a value is not empty.
     *
     * @param mixed $value The value to validate
     * @return bool True if the value is not empty, false otherwise
     */
    public static function required($value): bool
    {
        return !empty($value) || $value === '0' || $value === 0;
    }

    /**
     * Validates if a value is in a list of allowed values.
     *
     * @param mixed $value The value to validate
     * @param array $allowedValues The list of allowed values
     * @return bool True if the value is in the list, false otherwise
     */
    public static function inList($value, array $allowedValues): bool
    {
        return in_array($value, $allowedValues);
    }

    /**
     * Validates if a value is between a minimum and maximum value.
     *
     * @param mixed $value The value to validate
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @return bool True if the value is between min and max, false otherwise
     */
    public static function between($value, $min, $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * Validates an array structure against a schema.
     * The schema is an associative array where keys are the expected keys in the array
     * and values are the validation rules for each key.
     *
     * @param array $array The array to validate
     * @param array $schema The schema to validate against
     * @return bool True if the array structure is valid, false otherwise
     */
    public static function isArrayValid(array $array, array $schema): bool
    {
        foreach ($schema as $key => $rules) {
            // Check if the key is required
            $required = isset($rules['required']) && $rules['required'];

            // If the key is required but doesn't exist, validation fails
            if ($required && !isset($array[$key])) {
                return false;
            }

            // If the key exists, validate its value
            if (isset($array[$key])) {
                $value = $array[$key];

                // Check type if specified
                if (isset($rules['type'])) {
                    switch ($rules['type']) {
                        case 'string':
                            if (!is_string($value)) {
                                return false;
                            }
                            break;
                        case 'integer':
                        case 'int':
                            if (!is_int($value)) {
                                return false;
                            }
                            break;
                        case 'float':
                        case 'double':
                            if (!is_float($value) && !is_int($value)) {
                                return false;
                            }
                            break;
                        case 'boolean':
                        case 'bool':
                            if (!is_bool($value)) {
                                return false;
                            }
                            break;
                        case 'array':
                            if (!is_array($value)) {
                                return false;
                            }
                            break;
                        case 'object':
                            if (!is_object($value)) {
                                return false;
                            }
                            break;
                        case 'null':
                            if ($value !== null) {
                                return false;
                            }
                            break;
                    }
                }

                // Check nested schema if specified
                if (isset($rules['schema']) && is_array($value)) {
                    if (!self::isArrayValid($value, $rules['schema'])) {
                        return false;
                    }
                }

                // Check validation rules
                if (isset($rules['validate'])) {
                    foreach ($rules['validate'] as $rule => $param) {
                        switch ($rule) {
                            case 'email':
                                if (!self::email($value)) {
                                    return false;
                                }
                                break;
                            case 'url':
                                if (!self::url($value)) {
                                    return false;
                                }
                                break;
                            case 'date':
                                if (!self::date($value)) {
                                    return false;
                                }
                                break;
                            case 'time':
                                if (!self::time($value)) {
                                    return false;
                                }
                                break;
                            case 'pattern':
                                if (!self::pattern($value, $param)) {
                                    return false;
                                }
                                break;
                            case 'in':
                                if (!self::inList($value, is_array($param) ? $param : explode(',', $param))) {
                                    return false;
                                }
                                break;
                            case 'between':
                                $params = is_array($param) ? $param : explode(',', $param);
                                if (!self::between($value, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX)) {
                                    return false;
                                }
                                break;
                            case 'min':
                                if (is_string($value) && mb_strlen($value) < $param) {
                                    return false;
                                } elseif (is_numeric($value) && $value < $param) {
                                    return false;
                                }
                                break;
                            case 'max':
                                if (is_string($value) && mb_strlen($value) > $param) {
                                    return false;
                                } elseif (is_numeric($value) && $value > $param) {
                                    return false;
                                }
                                break;
                            case 'callback':
                                if (is_callable($param) && !$param($value)) {
                                    return false;
                                }
                                break;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validates each element in an array using a callback function.
     *
     * @param array $array The array to validate
     * @param callable $callback The callback function to validate each element
     * @return bool True if all elements are valid, false otherwise
     */
    public static function validateArrayElements(array $array, callable $callback): bool
    {
        foreach ($array as $value) {
            if (!$callback($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates that an array has a minimum number of elements.
     *
     * @param array $array The array to validate
     * @param int $min The minimum number of elements
     * @return bool True if the array has at least $min elements, false otherwise
     */
    public static function arrayMin(array $array, int $min): bool
    {
        return count($array) >= $min;
    }

    /**
     * Validates that an array has a maximum number of elements.
     *
     * @param array $array The array to validate
     * @param int $max The maximum number of elements
     * @return bool True if the array has at most $max elements, false otherwise
     */
    public static function arrayMax(array $array, int $max): bool
    {
        return count($array) <= $max;
    }

    /**
     * Validates that an array has exactly a specific number of elements.
     *
     * @param array $array The array to validate
     * @param int $count The exact number of elements
     * @return bool True if the array has exactly $count elements, false otherwise
     */
    public static function arrayCount(array $array, int $count): bool
    {
        return count($array) == $count;
    }
}
