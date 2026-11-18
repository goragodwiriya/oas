<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Jwt;

/**
 * JWT Authentication Middleware
 *
 * Signs and checks tokens through Kotchasan\Jwt, so both ends agree on the
 * algorithms (HS256, HS384, HS512) and on the checks. It used to check HS256
 * only (a token it issued with HS384/HS512 was refused with an exception),
 * accepted an empty secret (anyone could sign a valid token), and ignored the
 * issuer/audience/leeway options.
 *
 * @package Kotchasan\Http\Middleware
 */
class JwtMiddleware implements MiddlewareInterface
{
    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var array
     */
    private $options;

    /**
     * @param string $secretKey Secret key used to sign tokens (must not be empty)
     * @param string|array $options Algorithm ('HS256', 'HS384', 'HS512') or options
     *                              [algorithm, tokenParam, expiration, issuer, audience, leeway]
     * @param string|null $tokenParam Query parameter that may carry the token (legacy signature)
     *
     * @throws \InvalidArgumentException When the secret is empty or the algorithm is not supported
     */
    public function __construct(string $secretKey, $options = 'HS256', ?string $tokenParam = null)
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('JWT secret must not be empty');
        }
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

        if (!in_array($this->options['algorithm'], ['HS256', 'HS384', 'HS512'], true)) {
            throw new \InvalidArgumentException('Unsupported JWT algorithm: '.(is_scalar($this->options['algorithm']) ? $this->options['algorithm'] : gettype($this->options['algorithm'])));
        }
    }

    /**
     * Handle the request
     *
     * @param Request $request
     * @param callable|null $next
     *
     * @return mixed
     */
    public function handle(Request $request, ?callable $next = null)
    {
        $token = null;

        // Try to get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }

        // If no token in header and tokenParam is set, try to get from query parameter
        if ($token === null && $this->options['tokenParam'] !== null) {
            $token = $request->get($this->options['tokenParam'])->toString();
        }

        // If no token found, return unauthorized
        if (!is_string($token) || $token === '') {
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
     * Payload of a valid token, or false.
     *
     * Signature, algorithm, exp and nbf (with the leeway) through Jwt::decode();
     * then iss and aud when the options name them.
     *
     * @param string $token
     *
     * @return array|false
     */
    private function validateToken(string $token)
    {
        $payload = Jwt::decode($token, $this->secretKey, [$this->options['algorithm']], (int) $this->options['leeway']);
        if (!is_array($payload)) {
            return false;
        }

        if ($this->options['issuer'] !== null && ($payload['iss'] ?? null) !== $this->options['issuer']) {
            return false;
        }

        if ($this->options['audience'] !== null) {
            $audience = $payload['aud'] ?? null;
            $audiences = is_array($audience) ? $audience : [$audience];
            if (!in_array($this->options['audience'], $audiences, true)) {
                return false;
            }
        }

        return $payload;
    }

    /**
     * @param string $message
     *
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
     * Generate a JWT token
     *
     * @param int|array $payload User id, or the payload
     * @param int|array $expiresIn Seconds until it expires (with a user id: an array is extra payload)
     *
     * @return string
     */
    public function generateToken($payload, $expiresIn = 3600): string
    {
        // Check if first parameter is user ID
        if (is_int($payload)) {
            $data = [
                'userId' => $payload,
                'sub' => $payload // Add 'sub' claim for userId per JWT standards
            ];

            // If second parameter is an array, merge it with data
            if (is_array($expiresIn)) {
                $data = array_merge($data, $expiresIn);
                $expiry = (int) $this->options['expiration'];
            } else {
                $expiry = (int) $expiresIn;
            }
        } else {
            $data = (array) $payload;
            $expiry = (int) $expiresIn;
        }

        // Add issuance and expiration time to payload
        $issuedAt = time();
        $data['iat'] = $issuedAt;
        $data['exp'] = $issuedAt + $expiry;
        if ($this->options['issuer'] !== null && !isset($data['iss'])) {
            $data['iss'] = $this->options['issuer'];
        }
        if ($this->options['audience'] !== null && !isset($data['aud'])) {
            $data['aud'] = $this->options['audience'];
        }

        return Jwt::encode($data, $this->secretKey, $this->options['algorithm']);
    }

    /**
     * Decode and validate a JWT token
     *
     * @param string $token
     *
     * @return array
     *
     * @throws \Exception When the token is not valid
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
