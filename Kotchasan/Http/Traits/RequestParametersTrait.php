<?php

namespace Kotchasan\Http\Traits;

use Kotchasan\Http\InputItem;
use Kotchasan\Http\Inputs;

/**
 * Enhanced Request Parameters Trait with hybrid API support
 * Supports both old fluent interface and new type-safe API
 *
 * @package Kotchasan\Http\Traits
 */
trait RequestParametersTrait
{
    /**
     * Enhanced get() method with hybrid API
     *
     * @param string $name Parameter name
     * @param mixed $default Default value (optional)
     * @return mixed|InputItem|Inputs
     */
    public function get(string $name, $default = null)
    {
        $value = $this->queryParams[$name] ?? null;

        return $this->createFluentWrapper($value, $default, 'GET');
    }

    /**
     * Enhanced post() method with hybrid API
     *
     * @param string $name Parameter name
     * @param mixed $default Default value (optional)
     * @return mixed|InputItem|Inputs
     */
    public function post(string $name, $default = null)
    {
        $body = $this->getParsedBody();
        $value = null;

        if (is_array($body)) {
            // 1) Direct hit (items or plain field)
            if (array_key_exists($name, $body)) {
                $value = $this->sanitizeValue($body[$name]);
            } else {
                $value = $this->extractNestedParameters($body, $name);
            }
        }

        return $this->createFluentWrapper($value, $default, 'POST');
    }

    /**
     * Extract nested parameters such as items[0][name] into a proper array structure.
     * Supports multiple depths.
     *
     * @param array $body
     * @param string $rootName
     * @return array|null
     */
    protected function extractNestedParameters(array $body, string $rootName)
    {
        $result = [];
        $pattern = '/^'.preg_quote($rootName, '/').'((\[[^\]]+\])+)$/';

        foreach ($body as $fieldName => $fieldValue) {
            if (!is_string($fieldName)) {
                continue;
            }
            if (preg_match($pattern, $fieldName, $matches)) {
                $path = $matches[1]; // e.g. [0][name]
                $keys = [];
                // Split into tokens without brackets
                if (preg_match_all('/\[([^\]]+)\]/', $path, $pathMatches)) {
                    foreach ($pathMatches[1] as $segment) {
                        $keys[] = is_numeric($segment) ? (int) $segment : $this->sanitizeValue($segment);
                    }
                }
                if (!empty($keys)) {
                    $this->setNestedValue($result, $keys, $this->sanitizeValue($fieldValue));
                }
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Set a nested value by path (e.g., [0,'name']).
     *
     * @param array $array Reference array to mutate
     * @param array $path  List of keys/indexes
     * @param mixed $value Value to set
     */
    protected function setNestedValue(array &$array, array $path, $value)
    {
        $ref = &$array;
        $lastIndex = count($path) - 1;
        foreach ($path as $i => $key) {
            if ($i === $lastIndex) {
                $ref[$key] = $value;
            } else {
                if (!isset($ref[$key]) || !is_array($ref[$key])) {
                    $ref[$key] = [];
                }
                $ref = &$ref[$key];
            }
        }
    }

    /**
     * Create fluent wrapper for old API compatibility
     *
     * @param mixed $value Raw value
     * @param mixed $default Default value (optional)
     * @param string $type Input type
     * @return InputItem|Inputs
     */
    protected function createFluentWrapper($value, $default, string $type)
    {
        if ($value === null) {
            $value = $default;
        } else {
            $value = $this->convertToDefaultType($value, $default);
        }

        if (is_array($value)) {
            return new Inputs($value, $type);
        }

        return new InputItem($value, $type);
    }

    /**
     * Raw access methods (no wrapper, maximum performance)
     */
    public function postRaw(string $name, $default = null)
    {
        $body = $this->getParsedBody();
        if (is_array($body) && array_key_exists($name, $body)) {
            return $this->sanitizeValue($body[$name]);
        }
        return $default;
    }

    /**
     * @param string $name
     * @param $default
     * @return mixed
     */
    public function getRaw(string $name, $default = null)
    {
        return $this->queryParams[$name] ?? $default;
    }

    /**
     * Get a value from server parameters with type conversion
     *
     * @param string $name Parameter name
     * @param mixed $default Default value if parameter doesn't exist
     * @return mixed Returns value converted to same type as $default
     */
    public function server(string $name, $default = null)
    {
        if (isset($this->serverParams[$name])) {
            $value = $this->serverParams[$name];
            return $this->convertToDefaultType($value, $default);
        }

        return $default;
    }

    /**
     * Get parameter from POST, GET, or Cookie in order with type conversion
     *
     * @param string $name Parameter name
     * @param mixed $default Default value if parameter doesn't exist
     * @param string|null $cookie Cookie name to check
     * @return mixed|InputItem|Inputs
     */
    public function request(string $name, $default = null, $cookie = null)
    {

        // Check POST first
        $body = $this->getParsedBody();
        $value = null;
        if (is_array($body) && array_key_exists($name, $body)) {
            $value = $this->sanitizeValue($body[$name]);
            return $this->createFluentWrapper($value, $default, 'POST');
        }

        // Check GET second
        if (isset($this->queryParams[$name])) {
            $value = $this->sanitizeValue($this->queryParams[$name]);
            return $this->createFluentWrapper($value, $default, 'GET');
        }

        // Check cookie if specified
        if ($cookie !== null && isset($this->cookieParams[$cookie])) {
            $value = $this->sanitizeValue($this->cookieParams[$cookie]);
            return $this->createFluentWrapper($value, $default, 'COOKIE');
        }

        // Always return fluent wrapper for request
        return $this->createFluentWrapper(null, $default, 'REQUEST');
    }

    /**
     * Enhanced convertToDefaultType with array security and better validation
     *
     * @param mixed $value Value to convert
     * @param mixed $default Default value to match type
     * @return mixed Converted value
     * @throws \InvalidArgumentException
     */
    private function convertToDefaultType($value, $default)
    {
        if ($default === null) {
            return $value;
        }

        switch (gettype($default)) {
            case 'boolean':
                return $this->toBool($value);

            case 'integer':
                return $this->toInt($value);

            case 'double': // float
                return $this->toFloat($value);

            case 'string':
                return $this->toString($value);

            case 'array':
                if (!is_array($value)) {
                    return $value !== null ? [$value] : [];
                }

                // Security validation for arrays
                if ($this->getArrayDepth($value) > 5) {
                    throw new \InvalidArgumentException('Array depth exceeds security limit (5)');
                }

                if (count($value, COUNT_RECURSIVE) > 2000) {
                    throw new \InvalidArgumentException('Array size exceeds security limit (2000)');
                }

                return $value;

            default:
                return $value;
        }
    }

    /**
     * Calculate array depth for security validation
     *
     * @param array $array
     * @return int
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                $maxDepth = max($depth, $maxDepth);
            }
        }

        return $maxDepth;
    }

    /**
     * Safe boolean conversion
     *
     * @param mixed $value
     * @return bool
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return !in_array($lower, ['', '0', 'false', 'no', 'off', 'null'], true);
        }

        if (is_numeric($value)) {
            return $value != 0;
        }

        return !empty($value);
    }

    /**
     * Safe integer conversion
     *
     * @param mixed $value
     * @return int
     */
    private function toInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * Safe float conversion
     *
     * @param mixed $value
     * @return float
     */
    private function toFloat($value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Safe string conversion
     *
     * @param mixed $value
     * @return string
     */
    private function toString($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return '';
    }
}
