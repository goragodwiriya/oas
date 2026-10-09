<?php

namespace Kotchasan\Http\Traits;

use Kotchasan\Http\InputItem;
use Kotchasan\Http\Inputs;
use Kotchasan\Http\Traits\RequestParametersTrait;

/**
 * Request Cookie Trait
 * Handles cookie operations
 *
 * @package Kotchasan\Http\Traits
 */
trait RequestCookieTrait
{
    use RequestParametersTrait;
    /**
     * Get cookie value with backward compatibility and type conversion
     * Supports both fluent wrapper (InputItem/Inputs) and typed default behavior
     *
     * @param string $name Cookie name
     * @param mixed $default Default value
     * @return mixed|InputItem|Inputs Returns fluent wrapper when no default provided (or default === []), otherwise returns value converted to same type as $default
     */
    public function cookie(string $name, $default = null)
    {
        $value = null;
        if (isset($this->cookieParams[$name])) {
            $value = $this->sanitizeValue($this->cookieParams[$name]);
        }
        return $this->createFluentWrapper($value, $default, 'COOKIE');
    }

    /**
     * Get all cookies
     *
     * @param bool $sanitize Whether to sanitize values
     * @return array
     */
    public function cookies(bool $sanitize = true): array
    {
        if (!$sanitize) {
            return $this->cookieParams;
        }

        $cookies = [];
        foreach ($this->cookieParams as $name => $value) {
            $cookies[$name] = $this->sanitizeValue($value);
        }

        return $cookies;
    }

    /**
     * Check if cookie exists
     *
     * @param string $name Cookie name
     * @return bool
     */
    public function hasCookie(string $name): bool
    {
        return array_key_exists($name, $this->cookieParams);
    }
}
