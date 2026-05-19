<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Basic Authentication Middleware
 * Implements HTTP Basic Authentication according to RFC 7617
 *
 * @package Kotchasan\Http\Middleware
 */
class BasicAuthMiddleware extends BaseMiddleware
{
    /**
     * Authentication callback
     * @var callable
     */
    private $callback;

    /**
     * Authentication realm
     * @var string
     */
    private $realm;

    /**
     * Constructor
     *
     * @param callable $callback Function to validate username and password
     * @param string $realm Authentication realm
     */
    public function __construct(callable $callback, string $realm = 'API')
    {
        $this->callback = $callback;
        $this->realm = $realm;
    }

    /**
     * Handle the request through middleware
     *
     * @param Request $request The incoming request
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, ?callable $next = null)
    {
        $credentials = $this->extractCredentials($request);

        if (!$credentials) {
            return $this->createUnauthorizedResponse();
        }

        [$username, $password] = $credentials;

        if (!$this->authenticate($username, $password)) {
            return $this->createUnauthorizedResponse();
        }

        $request->setAttribute('authenticated_user', $username);

        return $this->callNext($request, $next);
    }

    /**
     * Extract credentials from Authorization header
     *
     * @param Request $request
     * @return array|null [username, password] or null if invalid
     */
    private function extractCredentials(Request $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $credentials = base64_decode($matches[1]);

        if ($credentials === false || !str_contains($credentials, ':')) {
            return null;
        }

        return explode(':', $credentials, 2);
    }

    /**
     * Authenticate user with callback
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    private function authenticate(string $username, string $password): bool
    {
        return call_user_func($this->callback, $username, $password);
    }

    /**
     * Create unauthorized response with WWW-Authenticate header
     *
     * @return Response
     */
    private function createUnauthorizedResponse(): Response
    {
        return $this->createErrorResponse(
            401,
            'Unauthorized',
            'Invalid credentials',
            ['WWW-Authenticate' => 'Basic realm="'.$this->realm.'"']
        );
    }

    // ===== Static Factory Methods =====

    /**
     * Create middleware with simple username/password validation
     *
     * @param string $username Expected username
     * @param string $password Expected password
     * @param string $realm Authentication realm
     * @return self
     */
    public static function withCredentials(string $username, string $password, string $realm = 'API'): self
    {
        return new self(
            fn($user, $pass) => $user === $username && $pass === $password,
            $realm
        );
    }

    /**
     * Create middleware with multiple user credentials
     *
     * @param array $users Array of username => password pairs
     * @param string $realm Authentication realm
     * @return self
     */
    public static function withUsers(array $users, string $realm = 'API'): self
    {
        return new self(
            fn($username, $password) => isset($users[$username]) && $users[$username] === $password,
            $realm
        );
    }

    /**
     * Create middleware with database user validation
     *
     * @param callable $userProvider Function that returns user data by username
     * @param string $realm Authentication realm
     * @return self
     */
    public static function withUserProvider(callable $userProvider, string $realm = 'API'): self
    {
        return new self(
            function ($username, $password) use ($userProvider) {
                $user = $userProvider($username);
                return $user && password_verify($password, $user['password'] ?? '');
            },
            $realm
        );
    }

    /**
     * Create middleware for API access with token-like authentication
     *
     * @param array $tokens Array of valid tokens
     * @param string $realm Authentication realm
     * @return self
     */
    public static function withTokens(array $tokens, string $realm = 'API'): self
    {
        return new self(
            fn($username, $password) => in_array($password, $tokens),
            $realm
        );
    }
}
