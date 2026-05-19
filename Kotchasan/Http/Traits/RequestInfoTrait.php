<?php

namespace Kotchasan\Http\Traits;

/**
 * Request Info Trait
 * Handles request information like IP, user agent, etc.
 *
 * @package Kotchasan\Http\Traits
 */
trait RequestInfoTrait
{
    /**
     * Check if request is AJAX
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return $this->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if request is over HTTPS
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        return (
            (!empty($this->serverParams['HTTPS']) && $this->serverParams['HTTPS'] !== 'off') ||
            (!empty($this->serverParams['SERVER_PORT']) && $this->serverParams['SERVER_PORT'] == 443) ||
            (!empty($this->serverParams['HTTP_X_FORWARDED_PROTO']) && $this->serverParams['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($this->serverParams['HTTP_X_FORWARDED_SSL']) && $this->serverParams['HTTP_X_FORWARDED_SSL'] === 'on')
        );
    }

    /**
     * Get client IP address with proxy support
     *
     * @param bool $trustProxies Whether to trust proxy headers
     * @return string
     */
    public function getClientIp(bool $trustProxies = true): string
    {
        if (!$trustProxies) {
            return $this->server('REMOTE_ADDR', '127.0.0.1');
        }

        // Check for IP from shared internet
        if (!empty($this->serverParams['HTTP_CLIENT_IP'])) {
            $ip = $this->serverParams['HTTP_CLIENT_IP'];
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Check for IP passed from proxy
        if (!empty($this->serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $this->serverParams['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Check for IP from CloudFlare
        if (!empty($this->serverParams['HTTP_CF_CONNECTING_IP'])) {
            $ip = $this->serverParams['HTTP_CF_CONNECTING_IP'];
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Check for IP from remote address
        return $this->server('REMOTE_ADDR', '127.0.0.1');
    }

    /**
     * Validate IP address
     *
     * @param string $ip
     * @return bool
     */
    protected function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Get user agent
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->server('HTTP_USER_AGENT', '');
    }

    /**
     * Get host from request
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->server('HTTP_HOST', $this->server('SERVER_NAME', 'localhost'));
    }

    /**
     * Get port from request
     *
     * @return int
     */
    public function getPort(): int
    {
        return (int) $this->server('SERVER_PORT', $this->isSecure() ? 443 : 80);
    }

    /**
     * Get scheme (http/https)
     *
     * @return string
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * Get full URL
     *
     * @return string
     */
    public function getFullUrl(): string
    {
        $scheme = $this->getScheme();
        $host = $this->getHost();
        $port = $this->getPort();
        $uri = $this->server('REQUEST_URI', '/');

        // Don't include default ports
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return "{$scheme}://{$host}{$uri}";
        }

        return "{$scheme}://{$host}:{$port}{$uri}";
    }

    /**
     * Get base URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host = $this->getHost();
        $port = $this->getPort();

        // Don't include default ports
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return "{$scheme}://{$host}";
        }

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * Get acceptable languages from Accept-Language header
     *
     * @return array Language tags sorted by quality score
     */
    public function getAcceptableLanguages(): array
    {
        $acceptLanguage = $this->getHeaderLine('Accept-Language');

        if (empty($acceptLanguage)) {
            return [];
        }

        $languages = [];
        $items = explode(',', $acceptLanguage);

        foreach ($items as $item) {
            $parts = array_map('trim', explode(';', $item));
            $language = $parts[0];

            // Parse quality value
            $quality = 1.0;
            if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
                $quality = (float) substr($parts[1], 2);
            }

            $languages[] = [
                'language' => $language,
                'quality' => $quality
            ];
        }

        // Sort by quality (highest first)
        usort($languages, fn($a, $b) => $b['quality'] <=> $a['quality']);

        return array_column($languages, 'language');
    }

    /**
     * Get content type
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->getHeaderLine('Content-Type');
    }

    /**
     * Check if request expects JSON response
     *
     * @return bool
     */
    public function expectsJson(): bool
    {
        return $this->isAjax() ||
        str_contains($this->getHeaderLine('Accept'), 'application/json') ||
        str_contains($this->getContentType(), 'application/json');
    }

    /**
     * Check if request is from mobile device
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        $userAgent = $this->getUserAgent();

        $mobileAgents = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod',
            'BlackBerry', 'Windows Phone', 'Opera Mini'
        ];

        foreach ($mobileAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get request fingerprint for rate limiting
     *
     * @return string
     */
    public function getFingerprint(): string
    {
        $acceptLanguage = $this->getHeaderLine('Accept-Language');

        return hash('sha256', implode('|', [
            $this->getClientIp(),
            $this->getUserAgent(),
            $acceptLanguage,
            $this->server('HTTP_ACCEPT_ENCODING', '')
        ]));
    }
}
