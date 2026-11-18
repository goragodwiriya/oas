<?php

namespace Kotchasan\Http\Auth;

use Kotchasan\Http\Middleware\AuthorizationMiddleware;
use Kotchasan\Http\Middleware\BasicAuthMiddleware;
use Kotchasan\Http\Middleware\BearerTokenAuthMiddleware;
use Kotchasan\Http\Middleware\DigestAuthMiddleware;
use Kotchasan\Http\Middleware\JwtMiddleware;

/**
 * Authentication Factory
 * Provides factory methods to create different authentication handlers
 *
 * @package Kotchasan\Http\Auth
 */
class AuthFactory
{
    /**
     * Create Basic Authentication middleware
     *
     * @param callable $callback Function to validate username and password
     * @param string $realm Authentication realm
     * @return BasicAuthMiddleware
     */
    public static function createBasicAuth(callable $callback, string $realm = 'API'): BasicAuthMiddleware
    {
        return new BasicAuthMiddleware($callback, $realm);
    }

    /**
     * Create Digest Authentication middleware
     *
     * @param callable $callback Function to retrieve password hash for a username
     * @param string $realm Authentication realm
     * @param string|null $secret Key that signs the nonces (null: derived from password_key)
     * @return DigestAuthMiddleware
     */
    public static function createDigestAuth(callable $callback, string $realm = 'API', ?string $secret = null): DigestAuthMiddleware
    {
        return new DigestAuthMiddleware($callback, $realm, $secret);
    }

    /**
     * Create JWT Authentication middleware
     *
     * @param string $secretKey The secret key for JWT verification
     * @param string $algorithm The algorithm to use for verification (default: HS256)
     * @return JwtMiddleware
     */
    public static function createJwtAuth(string $secretKey, string $algorithm = 'HS256'): JwtMiddleware
    {
        return new JwtMiddleware($secretKey, $algorithm);
    }

    /**
     * Create Bearer Token Authentication middleware
     *
     * @param callable $callback Function to validate token
     * @param string $headerName Custom header name for the token
     * @return BearerTokenAuthMiddleware
     */
    public static function createBearerTokenAuth(callable $callback, ?string $headerName = null): BearerTokenAuthMiddleware
    {
        return new BearerTokenAuthMiddleware($callback, $headerName);
    }

    /**
     * Create Authorization middleware
     *
     * @param array $allowedRoles Array of allowed roles
     * @return AuthorizationMiddleware
     */
    public static function createAuthorization(array $allowedRoles): AuthorizationMiddleware
    {
        return new AuthorizationMiddleware($allowedRoles);
    }

    /**
     * Hash a password using a secure algorithm
     *
     * @param string $password The password to hash
     * @param array $options Options for password_hash
     * @return string The hashed password
     */
    public static function hashPassword(string $password, array $options = []): string
    {
        return password_hash($password, PASSWORD_BCRYPT, $options);
    }

    /**
     * Verify a password against a hash
     *
     * @param string $password The password to verify
     * @param string $hash The hash to verify against
     * @return bool True if password is valid
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a random token
     *
     * @param int $length The length of the token
     * @return string The generated token
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Create a JWT token
     *
     * @param array $payload The JWT payload
     * @param string $secretKey The secret key for signing
     * @param string $algorithm The algorithm to use (default: HS256)
     * @return string The JWT token
     */
    public static function createJwtToken(array $payload, string $secretKey, string $algorithm = 'HS256'): string
    {
        return \Kotchasan\Jwt::encode($payload, $secretKey, $algorithm);
    }

    /**
     * Validate and decode a JWT token
     *
     * @param string $token The JWT token
     * @param string $secretKey The secret key for verification
     * @param string $algorithm The algorithm to use (default: HS256)
     * @return array|false The decoded payload or false on failure
     */
    public static function validateJwtToken(string $token, string $secretKey, string $algorithm = 'HS256')
    {
        $payload = \Kotchasan\Jwt::decode($token, $secretKey, [$algorithm]);

        return $payload === null ? false : $payload;
    }

    /**
     * Compare two hashes in constant time to prevent timing attacks
     *
     * @param string $a First hash
     * @param string $b Second hash
     * @return bool True if hashes match
     */
    public static function compareHashes(string $a, string $b): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }

        // Fallback implementation
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }

        return $result === 0;
    }
}
