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
     * @param string $jwt
     * @param string $secret
     * @param array $allowedAlgos
     * @return mixed
     */
    public static function decode($jwt, $secret, $allowedAlgos = ['HS256'])
    {
        $parts = explode('.', $jwt);
        if (count($parts) != 3) {
            return null;
        }
        list($bh, $bp, $bs) = $parts;
        $header = json_decode(self::b64u_decode($bh), true);
        $payload = json_decode(self::b64u_decode($bp), true);
        $sig = self::b64u_decode($bs);
        if (empty($header) || empty($payload)) {
            return null;
        }
        if (!in_array($header['alg'] ?? '', $allowedAlgos)) {
            return null;
        }
        $signingInput = $bh.'.'.$bp;
        $expected = self::sign($signingInput, $secret, $header['alg']);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        // check exp
        if (isset($payload['exp']) && time() >= $payload['exp']) {
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
            default:
                return hash_hmac('sha256', $data, $secret, true);
        }
    }
}
