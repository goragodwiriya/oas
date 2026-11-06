<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Exception\DatabaseException;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * JWT Authentication Middleware
 * Handles JWT-based authentication
 *
 * @package Kotchasan\Http\Middleware
 */
class JwtMiddleware
{
    /**
     * Secret key for JWT validation
     * @var string
     */
    private $secretKey;

    /**
     * JWT options
     * @var array
     */
    private $options;

    /**
     * Constructor
     *
     * @param string $secretKey The secret key for JWT verification
     * @param string|array $options Algorithm string or options array for JWT configuration
     * @param string|null $tokenParam Query parameter name for token (deprecated, use options instead)
     */
    public function __construct(string $secretKey, $options = 'HS256', ?string $tokenParam = null)
    {
        $this->secretKey = $secretKey;

        // Default options
        $this->options = [
            'algorithm' => 'HS256',
            'tokenParam' => null,
            'expiration' => 3600,
            'issuer' => null,
            'audience' => null,
            'leeway' => 0 // Time leeway in seconds to account for clock skew
        ];

        // Handle backward compatibility with string algorithm
        if (is_string($options)) {
            $this->options['algorithm'] = $options;
            if ($tokenParam !== null) {
                $this->options['tokenParam'] = $tokenParam;
            }
        } elseif (is_array($options)) {
            $this->options = array_merge($this->options, $options);
        }
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
        $token = null;

        // Try to get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // If no token in header and tokenParam is set, try to get from query parameter
        if ($token === null && $this->options['tokenParam'] !== null) {
            $token = $request->get($this->options['tokenParam']);
        }

        // If no token found, return unauthorized
        if (empty($token)) {
            return $this->unauthorized('No token provided');
        }

        $payload = $this->validateToken($token);

        if ($payload === false) {
            return $this->unauthorized('Invalid token');
        }

        // Set authenticated user in the request
        $request->setAttribute('authenticated_user', $payload['sub'] ?? null);
        $request->setAttribute('jwt_payload', $payload);

        if ($next) {
            return $next($request);
        }

        return $request;
    }

    /**
     * Validate JWT token
     *
     * @param string $token The JWT token
     * @return array|false The decoded payload or false on failure
     */
    private function validateToken(string $token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Decode header and payload
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        if ($header === null || $payload === null) {
            return false;
        }

        // Check algorithm
        if (!isset($header['alg']) || $header['alg'] !== $this->options['algorithm']) {
            return false;
        }

        // Verify signature
        if (!$this->verifySignature($headerEncoded, $payloadEncoded, $signatureEncoded)) {
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        // Check not before
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            return false;
        }

        return $payload;
    }

    /**
     * Verify JWT signature
     *
     * @param string $headerEncoded Encoded header
     * @param string $payloadEncoded Encoded payload
     * @param string $signatureEncoded Encoded signature
     * @return bool True if signature is valid
     */
    private function verifySignature(string $headerEncoded, string $payloadEncoded, string $signatureEncoded): bool
    {
        $data = $headerEncoded.'.'.$payloadEncoded;
        $signature = $this->base64UrlDecode($signatureEncoded);

        if ($this->options['algorithm'] === 'HS256') {
            $hash = hash_hmac('sha256', $data, $this->secretKey, true);
            return hash_equals($hash, $signature);
        }

        throw new DatabaseException("Unsupported JWT algorithm: {$this->options['algorithm']}");
    }

    /**
     * Decode Base64URL string
     *
     * @param string $input Base64URL encoded string
     * @return string Decoded string
     */
    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
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
        ]);
    }

    /**
     * Generate a JWT token with the given payload
     *
     * @param array|int $payload The data to include in the token, or user ID if numeric
     * @param int|array $expiresIn Expiration time in seconds from now, or additional payload data
     * @return string The JWT token
     */
    public function generateToken($payload, $expiresIn = 3600): string
    {
        // Create header
        $header = [
            'alg' => $this->options['algorithm'],
            'typ' => 'JWT'
        ];

        // Check if first parameter is user ID
        if (is_int($payload)) {
            $data = [
                'userId' => $payload,
                'sub' => $payload // Add 'sub' claim for userId per JWT standards
            ];

            // If second parameter is an array, merge it with data
            if (is_array($expiresIn)) {
                $data = array_merge($data, $expiresIn);
                $expiry = 3600; // Default expiry
            } else {
                $expiry = $expiresIn;
            }
        } else {
            $data = $payload;
            $expiry = $expiresIn;
        }

        // Add issuance and expiration time to payload
        $issuedAt = time();
        $data['iat'] = $issuedAt;
        $data['exp'] = $issuedAt + $expiry;

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($data));

        // Create signature
        $signature = $this->createSignature($headerEncoded, $payloadEncoded);
        $signatureEncoded = $this->base64UrlEncode($signature);

        // Create and return token
        return $headerEncoded.'.'.$payloadEncoded.'.'.$signatureEncoded;
    }

    /**
     * Create signature for JWT token
     *
     * @param string $headerEncoded
     * @param string $payloadEncoded
     * @return string
     */
    private function createSignature(string $headerEncoded, string $payloadEncoded): string
    {
        $data = $headerEncoded.'.'.$payloadEncoded;

        switch ($this->options['algorithm']) {
            case 'HS256':
                return hash_hmac('sha256', $data, $this->secretKey, true);
            case 'HS384':
                return hash_hmac('sha384', $data, $this->secretKey, true);
            case 'HS512':
                return hash_hmac('sha512', $data, $this->secretKey, true);
            default:
                throw new \InvalidArgumentException('Unsupported algorithm: '.$this->options['algorithm']);
        }
    }

    /**
     * Helper method for base64url encoding
     *
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode and validate a JWT token
     *
     * @param string $token The JWT token to decode
     * @return array The decoded payload
     * @throws \Exception If the token is invalid or expired
     */
    public function decodeToken(string $token): array
    {
        $payload = $this->validateToken($token);

        if ($payload === false) {
            throw new \Exception('Invalid token');
        }

        return $payload;
    }
}
