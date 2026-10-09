<?php

namespace Kotchasan\Http\Traits;

/**
 * Request Method Trait
 * Handles HTTP method detection and method spoofing
 *
 * @package Kotchasan\Http\Traits
 */
trait RequestMethodTrait
{
    /**
     * Get HTTP method with override support
     *
     * @return string
     */
    public function getMethod()
    {
        $originalMethod = $this->getOriginalMethod();

        // Only allow method override for POST requests
        if ($originalMethod !== 'POST') {
            return $originalMethod;
        }

        // Check X-HTTP-Method-Override header first (highest priority)
        $headerOverride = $this->getHeaderLine('X-HTTP-Method-Override');
        if (!empty($headerOverride)) {
            $method = strtoupper(trim($headerOverride));
            if ($this->isValidHttpMethod($method)) {
                return $method;
            }
        }

        // Check _method parameter (lower priority)
        $paramOverride = $this->post('_method');
        if (!empty($paramOverride)) {
            $method = strtoupper(trim($paramOverride));
            if ($this->isValidHttpMethod($method)) {
                return $method;
            }
        }

        return $originalMethod;
    }

    /**
     * Get original HTTP method before any override
     *
     * @return string
     */
    public function getOriginalMethod(): string
    {
        return strtoupper($this->server('REQUEST_METHOD', 'GET'));
    }

    /**
     * Check if HTTP method is valid
     *
     * @param string $method
     * @return bool
     */
    protected function isValidHttpMethod(string $method): bool
    {
        return in_array($method, [
            'GET', 'POST', 'PUT', 'PATCH', 'DELETE',
            'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'
        ], true);
    }

    /**
     * Check if request method is GET
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->getMethod() === 'GET';
    }

    /**
     * Check if request method is POST
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->getMethod() === 'POST';
    }

    /**
     * Check if request method is PUT
     *
     * @return bool
     */
    public function isPut(): bool
    {
        return $this->getMethod() === 'PUT';
    }

    /**
     * Check if request method is PATCH
     *
     * @return bool
     */
    public function isPatch(): bool
    {
        return $this->getMethod() === 'PATCH';
    }

    /**
     * Check if request method is DELETE
     *
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->getMethod() === 'DELETE';
    }

    /**
     * Check if request method is HEAD
     *
     * @return bool
     */
    public function isHead(): bool
    {
        return $this->getMethod() === 'HEAD';
    }

    /**
     * Check if request method is OPTIONS
     *
     * @return bool
     */
    public function isOptions(): bool
    {
        return $this->getMethod() === 'OPTIONS';
    }

    /**
     * Check if request method is safe (GET, HEAD, OPTIONS, TRACE)
     *
     * @return bool
     */
    public function isSafeMethod(): bool
    {
        return in_array($this->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    /**
     * Check if request method is idempotent
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return in_array($this->getMethod(), ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'], true);
    }

    /**
     * Generate method override field for forms
     *
     * @param string $method HTTP method
     * @return string HTML input field
     */
    public function methodField(string $method): string
    {
        $method = strtoupper($method);

        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return sprintf('<input type="hidden" name="_method" value="%s">', htmlspecialchars($method));
        }

        return '';
    }

    /**
     * Check if method matches any of the given methods
     *
     * @param string ...$methods Methods to check against
     * @return bool
     */
    public function isMethod(string ...$methods): bool
    {
        $currentMethod = $this->getMethod();

        foreach ($methods as $method) {
            if ($currentMethod === strtoupper($method)) {
                return true;
            }
        }

        return false;
    }
}