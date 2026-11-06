<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Digest Authentication Middleware
 * Implements HTTP Digest Authentication according to RFC 7616
 *
 * @package Kotchasan\Http\Middleware
 */
class DigestAuthMiddleware
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
     * @param callable $callback Function to retrieve password hash for a username
     * @param string $realm Authentication realm
     */
    public function __construct(callable $callback, string $realm = 'API')
    {
        $this->callback = $callback;
        $this->realm = $realm;
    }

    /**
     * Parse Digest authentication header
     *
     * @param string $digestHeader The digest header string
     * @return array Parsed digest parts
     */
    private function parseDigestHeader(string $digestHeader): array
    {
        $needed_parts = [
            'username' => 1,
            'realm' => 1,
            'nonce' => 1,
            'uri' => 1,
            'response' => 1,
            'opaque' => 1,
            'qop' => 1,
            'nc' => 1,
            'cnonce' => 1
        ];
        $data = [];

        preg_match_all('/(\w+)=(?:"([^"]+)"|([^,]+))/', $digestHeader, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $data[$match[1]] = isset($match[3]) ? $match[3] : $match[2];
            unset($needed_parts[$match[1]]);
        }

        return $data;
    }

    /**
     * Validate digest authentication
     *
     * @param array $digest Parsed digest parts
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return bool True if digest is valid
     */
    private function validateDigest(array $digest, string $method, string $uri): bool
    {
        // Check required fields
        if (empty($digest['username']) ||
            empty($digest['realm']) ||
            empty($digest['nonce']) ||
            empty($digest['uri']) ||
            empty($digest['response'])) {
            return false;
        }

        // Verify realm
        if ($digest['realm'] !== $this->realm) {
            return false;
        }

        // Get password hash for user
        $A1 = call_user_func($this->callback, $digest['username']);

        if ($A1 === false || $A1 === null) {
            return false; // User not found
        }

        // Calculate expected response
        $A2 = md5($method.':'.$uri);

        if (!empty($digest['qop'])) {
            if (empty($digest['nc']) || empty($digest['cnonce'])) {
                return false;
            }

            $expected = md5($A1.':'.$digest['nonce'].':'.$digest['nc'].':'.
                $digest['cnonce'].':'.$digest['qop'].':'.$A2);
        } else {
            $expected = md5($A1.':'.$digest['nonce'].':'.$A2);
        }

        return hash_equals($expected, $digest['response']);
    }

    /**
     * Create unauthorized response
     *
     * @return Response
     */
    private function unauthorized(): Response
    {
        $nonce = md5(uniqid());
        $opaque = md5($this->realm);

        $digestHeader = 'Digest realm="'.$this->realm.'", '.
            'qop="auth", '.
            'nonce="'.$nonce.'", '.
            'opaque="'.$opaque.'"';

        return Response::makeUnauthorized([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ])->withHeader('WWW-Authenticate', $digestHeader);
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
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorized();
        }

        if (!preg_match('/^Digest\s+(.+)$/i', $authHeader, $matches)) {
            return $this->unauthorized();
        }

        $digestParts = $this->parseDigestHeader($matches[1]);

        if (!$this->validateDigest($digestParts, $request->getMethod(), $request->getUri())) {
            return $this->unauthorized();
        }

        // Set authenticated user in the request
        $request->setAttribute('authenticated_user', $digestParts['username']);

        if ($next) {
            return $next($request);
        }

        return $request;
    }
}
