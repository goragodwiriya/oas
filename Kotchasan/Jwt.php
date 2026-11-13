<?php
namespace Kotchasan;

/**
 * Minimal JWT helper (HS256) for simple token issuance/validation.
 * Not a full-featured library — suitable as a starter for migration to JWT.
 */
class Jwt
{
    /**
     * base64url encode
     * @param string $data
     */
    private static function b64u_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url decode
     * @param string $data
     * @return mixed
     */
    private static function b64u_decode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Encode JWT
     * @param array $payload
     * @param $secret
     * @param $algo
     */
    public static function encode(array $payload, $secret, $algo = 'HS256')
    {
        if ($secret === null || $secret === '') {
            throw new \InvalidArgumentException('JWT secret must not be empty');
        }
        $header = ['typ' => 'JWT', 'alg' => $algo];
        $segments = [];
        $segments[] = self::b64u_encode(json_encode($header));
        $segments[] = self::b64u_encode(json_encode($payload));
        $signingInput = implode('.', $segments);
        $sig = self::sign($signingInput, $secret, $algo);
        $segments[] = self::b64u_encode($sig);
        return implode('.', $segments);
    }

    /**
     * Decode JWT
     *
     * Returns the payload of a token signed with $secret by one of $allowedAlgos,
     * not expired (exp) and already valid (nbf), or null. exp and nbf, when
     * present, must be numbers.
     *
     * @param string $jwt
     * @param string|null $secret
     * @param array $allowedAlgos
     * @param int $leeway Seconds of clock difference accepted on exp and nbf
     * @return array|null
     */
    public static function decode($jwt, $secret, $allowedAlgos = ['HS256'], $leeway = 0)
    {
        if (!is_string($jwt)) {
            return null;
        }
        $leeway = max(0, (int) $leeway);
        $parts = explode('.', $jwt);
        if (count($parts) != 3) {
            return null;
        }
        // Reject empty secrets — an empty secret would make signatures forgeable.
        if ($secret === null || $secret === '') {
            return null;
        }
        list($bh, $bp, $bs) = $parts;
        $header = json_decode(self::b64u_decode($bh), true);
        $payload = json_decode(self::b64u_decode($bp), true);
        $sig = self::b64u_decode($bs);
        if (empty($header) || empty($payload) || !is_array($header) || !is_array($payload)) {
            return null;
        }
        $alg = $header['alg'] ?? '';
        // The algorithm MUST be in the server-controlled allow-list. This also
        // rejects "none" and prevents algorithm-confusion attacks.
        if (!in_array($alg, $allowedAlgos, true)) {
            return null;
        }
        $signingInput = $bh.'.'.$bp;
        try {
            $expected = self::sign($signingInput, $secret, $alg);
        } catch (\InvalidArgumentException $e) {
            // Unsupported algorithm — refuse rather than silently fall back.
            return null;
        }
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        // exp/nbf must be numbers: time() >= "soon" is false in PHP 8, so a text
        // value used to make a token that never expires
        foreach (['exp', 'nbf'] as $claim) {
            if (array_key_exists($claim, $payload) && !is_int($payload[$claim]) && !is_float($payload[$claim])) {
                return null;
            }
        }
        $now = time();
        // check nbf (not before)
        if (isset($payload['nbf']) && $now + $leeway < $payload['nbf']) {
            return null;
        }
        // check exp
        if (isset($payload['exp']) && $now - $leeway >= $payload['exp']) {
            return null;
        }
        return $payload;
    }

    /**
     * Sign JWT
     * @param string $data
     * @param string $secret
     * @param string $algo
     */
    private static function sign($data, $secret, $algo)
    {
        switch ($algo) {
        case 'HS256':
            return hash_hmac('sha256', $data, $secret, true);
        case 'HS384':
            return hash_hmac('sha384', $data, $secret, true);
        case 'HS512':
            return hash_hmac('sha512', $data, $secret, true);
        default:
            // Refuse unknown/unsupported algorithms instead of silently
            // HMAC-signing them (which would enable algorithm confusion).
            throw new \InvalidArgumentException('Unsupported JWT algorithm: '.$algo);
        }
    }
}
