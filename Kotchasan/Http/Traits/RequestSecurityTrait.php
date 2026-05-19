<?php

namespace Kotchasan\Http\Traits;

/**
 * Request Security Trait
 * Handles CSRF, sanitization, and security checks
 *
 * @package Kotchasan\Http\Traits
 */
trait RequestSecurityTrait
{
    /**
     * Sanitize a single value or array recursively
     *
     * @param mixed $value
     * @return mixed
     */
    protected function sanitizeValue($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        // Remove null bytes only — all other sanitization is delegated to
        return str_replace("\0", '', $value);
    }

    /**
     * Generates a CSRF token for form protection (backward compatibility)
     *
     * @return string The generated token
     */
    public function generateCsrfToken(): string
    {
        // Generate token and store metadata under the token key
        $token = bin2hex(random_bytes(32));
        $meta = [
            'times' => 0,
            'expired' => time() + (defined('TOKEN_AGE') ? TOKEN_AGE : 3600),
            'created' => time()
        ];

        // Primary storage: token => meta (used by RequestSecurityTrait)
        $_SESSION[$token] = $meta;

        return $token;
    }

    /**
     * Validate a CSRF token (unified with Request::isSafe)
     *
     * @param string $token The token to validate
     * @return bool True if the token is valid
     */
    public static function validateCsrfToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // Preferred path: token metadata stored keyed by token
        if (isset($_SESSION[$token]) && is_array($_SESSION[$token])) {
            $meta = $_SESSION[$token];

            // Check expiration
            if ($meta['expired'] < time()) {
                unset($_SESSION[$token]);
                return false;
            }

            // Check usage limit
            $tokenLimit = defined('TOKEN_LIMIT') ? TOKEN_LIMIT : 100;
            if ($meta['times'] >= $tokenLimit) {
                unset($_SESSION[$token]);
                return false;
            }

            // Increment usage
            $_SESSION[$token]['times']++;
            return true;
        }

        return false;
    }

    /**
     * Remove CSRF token (backward compatibility)
     *
     * @param string $token Token to remove
     * @return void
     */
    public function removeCsrfToken($token): void
    {
        unset($_SESSION[$token]);
    }

    /**
     * Check if request has valid referer
     *
     * @return bool
     */
    public function isValidReferer(): bool
    {
        $referer = $this->server('HTTP_REFERER');

        if (empty($referer)) {
            return false;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        $currentHost = $this->server('HTTP_HOST', $this->server('SERVER_NAME'));

        return $refererHost === $currentHost;
    }

    /**
     * Get sanitized input data
     *
     * @param array|null $data Data to sanitize (if null, uses all input)
     * @return array
     */
    public function sanitize(?array $data = null): array
    {
        if ($data === null) {
            $data = $this->all(false);
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    /**
     * Validate input against rules
     *
     * @param array $rules Validation rules
     * @return array Validation errors
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $data = $this->all();

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $fieldRules = is_string($rule) ? explode('|', $rule) : $rule;

            foreach ($fieldRules as $fieldRule) {
                $error = $this->validateField($field, $value, $fieldRule);
                if ($error) {
                    $errors[$field][] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate single field
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Validation rule
     * @return string|null Error message or null if valid
     */
    protected function validateField(string $field, $value, string $rule): ?string
    {
        [$ruleName, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        // Convert null to empty string for consistent validation
        $stringValue = (string) $value;

        switch ($ruleName) {
            case 'required':
                return empty($value) ? "{$field} is required" : null;

            case 'email':
                return empty($value) ? null : (!filter_var($value, FILTER_VALIDATE_EMAIL) ? "{$field} must be a valid email" : null);

            case 'min':
                return empty($value) ? null : (strlen($stringValue) < (int) $parameter ? "{$field} must be at least {$parameter} characters" : null);

            case 'max':
                return empty($value) ? null : (strlen($stringValue) > (int) $parameter ? "{$field} must not exceed {$parameter} characters" : null);

            case 'numeric':
                return empty($value) ? null : (!is_numeric($value) ? "{$field} must be numeric" : null);

            case 'integer':
                return empty($value) ? null : (!filter_var($value, FILTER_VALIDATE_INT) ? "{$field} must be an integer" : null);

            case 'url':
                return empty($value) ? null : (!filter_var($value, FILTER_VALIDATE_URL) ? "{$field} must be a valid URL" : null);

            default:
                return null;
        }
    }
}
