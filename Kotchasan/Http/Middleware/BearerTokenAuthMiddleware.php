<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Bearer Token Authentication Middleware
 * Handles token-based authentication using the Bearer scheme
 *
 * @package Kotchasan\Http\Middleware
 */
class BearerTokenAuthMiddleware
{
    /**
     * Authentication callback
     * @var callable
     */
    private $callback;

    /**
     * Custom header name for the token
     * @var string|null
     */
    private $headerName;

    /**
     * Constructor
     *
     * @param callable $callback Function to validate token
     * @param string|null $headerName Custom header name for the token
     */
    public function __construct(callable $callback, ?string $headerName = null)
    {
        $this->callback = $callback;
        $this->headerName = $headerName;
    }

    /**
     * Extract token from request
     *
     * @param Request $request The request
     * @return string|null The token or null if not found
     */
    private function extractToken(Request $request): ?string
    {
        // Try to get token from custom header
        if ($this->headerName !== null) {
            $token = $request->getHeaderLine($this->headerName);
            if (!empty($token)) {
                return $token;
            }
        }

        // Try to get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Try to get token from query parameter
        $token = $request->get('access_token');
        if (!empty($token)) {
            return $token;
        }

        // No token found
        return null;
    }

    /**
     * Create unauthorized response
     *
     * @param string $message Error message
     * @return Response
     */
    private function unauthorized(string $message): Response
    {
        return Response::makeUnauthorized([
            'error' => 'Unauthorized',
            'message' => $message
        ])->withHeader('WWW-Authenticate', 'Bearer');
    }

    /**
     * Handle the request through middleware
     *
     * @param Request $request The incoming request
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request,  ? callable $next = null)
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorized('No token provided');
        }

        $userData = call_user_func($this->callback, $token);

        if ($userData === false || $userData === null) {
            return $this->unauthorized('Invalid token');
        }

        // Set authenticated user in the request
        $request->setAttribute('authenticated_user', is_array($userData) ? $userData : ['id' => $userData]);
        $request->setAttribute('token', $token);

        if ($next) {
            return $next($request);
        }

        return $request;
    }
}
