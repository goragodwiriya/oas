<?php
namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Class SecurityMiddleware
 *
 * Provides security features for API requests.
 *
 * @package Kotchasan\Http\Middleware
 */
class SecurityMiddleware implements MiddlewareInterface
{
    /**
     * CSRF protection enabled.
     *
     * @var bool
     */
    protected bool $csrfProtection = true;

    /**
     * Rate limiting enabled.
     *
     * @var bool
     */
    protected bool $rateLimiting = true;

    /**
     * Maximum requests per minute.
     *
     * @var int
     */
    protected int $maxRequestsPerMinute = 60;

    /**
     * Request methods requiring CSRF validation.
     *
     * @var array
     */
    protected array $csrfMethods = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Rate limit storage key prefix.
     *
     * @var string
     */
    protected string $rateLimitPrefix = 'rate_limit:';

    /**
     * Constructor.
     *
     * @param array $options Middleware options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['csrfProtection'])) {
            $this->csrfProtection = (bool) $options['csrfProtection'];
        }

        if (isset($options['rateLimiting'])) {
            $this->rateLimiting = (bool) $options['rateLimiting'];
        }

        if (isset($options['maxRequestsPerMinute'])) {
            $this->maxRequestsPerMinute = (int) $options['maxRequestsPerMinute'];
        }

        if (isset($options['csrfMethods'])) {
            $this->csrfMethods = (array) $options['csrfMethods'];
        }
    }

    /**
     * Handle the request.
     *
     * @param Request $request HTTP request
     * @param callable|null $next Next middleware or handler
     * @return Response|null
     */
    public function handle(Request $request,  ? callable $next = null): ?Response
    {
        // Check CSRF token
        if ($this->csrfProtection && in_array($request->getMethod(), $this->csrfMethods)) {
            $sessionStartedHere = false;
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
                $sessionStartedHere = true;
            }

            try {
                $csrfToken = $request->getHeaderLine('X-CSRF-Token');
                if (empty($csrfToken)) {
                    $csrfToken = $request->getParameter('csrf_token');
                }

                if (!$csrfToken || !$request->validateCsrfToken((string) $csrfToken)) {
                    return Response::makeForbidden(['error' => 'CSRF token validation failed']);
                }
            } finally {
                if ($sessionStartedHere && session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
            }
        }

        // Apply rate limiting
        if ($this->rateLimiting) {
            $clientIp = $request->getClientIp();

            if ($clientIp && !$this->checkRateLimit($clientIp)) {
                return Response::makeJson(
                    ['error' => 'Rate limit exceeded'],
                    429,
                    ['Retry-After' => '60']
                );
            }
        }

        // Call next middleware or handler
        if ($next) {
            return $next($request);
        }

        return null;
    }

    /**
     * Check rate limit for a client.
     *
     * @param string $clientIp Client IP address
     * @return bool Whether the client is within the rate limit
     */
    protected function checkRateLimit(string $clientIp): bool
    {
        $key = $this->rateLimitPrefix.md5($clientIp);
        $now = time();
        $windowStart = $now - 60; // 1 minute window

        // In a real application, you would use Redis or another storage
        // For this example, we'll use APCu if available
        if (function_exists('apcu_enabled') && function_exists('apcu_fetch') && function_exists('apcu_store')) {
            $apcuFetch = 'apcu_fetch';
            $apcuStore = 'apcu_store';

            $requests = $apcuFetch($key) ?: [];

            // Filter out requests older than the window
            $requests = array_filter($requests, fn($timestamp) => $timestamp >= $windowStart);

            // Add current request
            $requests[] = $now;

            // Store updated requests
            $apcuStore($key, $requests, 120); // Store for 2 minutes

            // Check if rate limit is exceeded
            return count($requests) <= $this->maxRequestsPerMinute;
        }

        // For demonstration, if APCu is not available, we'll use a simple
        // file-based storage. In production, use a proper caching solution.
        $storageDir = sys_get_temp_dir().'/rate_limits';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $file = $storageDir.'/'.$key;

        if (file_exists($file)) {
            $requests = unserialize(file_get_contents($file)) ?: [];
        } else {
            $requests = [];
        }

        // Filter out requests older than the window
        $requests = array_filter($requests, fn($timestamp) => $timestamp >= $windowStart);

        // Add current request
        $requests[] = $now;

        // Store updated requests
        file_put_contents($file, serialize($requests));

        // Check if rate limit is exceeded
        return count($requests) <= $this->maxRequestsPerMinute;
    }
}
