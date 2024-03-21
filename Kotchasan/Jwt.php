<?php
/**
 * @filesource Kotchasan/Jwt.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * JWT encoding, decoding, and verification class
 *
 * @see https://www.kotchasan.com/
 */
class Jwt
{
    /**
     * Secret key for JWT encoding
     *
     * @var string
     */
    private $secretKey;

    /**
     * JWT expiration time
     * 3600 = 1 hour.
     * 0 = no expiration time (default).
     * If an expiration time is specified, it will be checked during verification.
     * The expired time will be added to the payload automatically during encoding,
     * and removed when decoding.
     * It is not recommended to specify the expiration time in the payload that needs to be encoded separately.
     *
     * @var int
     */
    private $expireTime;

    /**
     * Algorithm used for encoding with hash_hmac
     *
     * @var string
     */
    private $algorithm;

    /**
     * Algorithms supported by hash_hmac
     *
     * @var array
     */
    protected $hashHmacAlgorithms = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512'
    ];

    /**
     * Class constructor
     *
     * @param string $secretKey Secret key for JWT encoding
     * @param int $expireTime JWT expiration time 0 = no expiration time (default), > 0 specifies expiration time in seconds
     * @param int $algo Algorithm used for encoding, supported by $hashHmacAlgorithms
     */
    private function __construct($secretKey, $expireTime, $algo)
    {
        if (isset($this->hashHmacAlgorithms[$algo])) {
            $this->algorithm = $algo;
        } else {
            throw new \Exception('Algorithm `'.$algo.'` is not supported');
        }
        $this->secretKey = $secretKey;
        $this->expireTime = $expireTime;
    }

    /**
     * Create a Jwt instance
     *
     * @param string $secretKey Secret key for JWT encoding
     * @param int $expireTime JWT expiration time 0 = no expiration time (default), > 0 specifies expiration time in seconds
     * @param string $algo Algorithm used for encoding, supported by $hashHmacAlgorithms
     *
     * @return static
     */
    public static function create($secretKey = 'my_secret_key', $expireTime = 0, $algo = 'HS256')
    {
        return new static($secretKey, $expireTime, $algo);
    }

    /**
     * Encodes the payload into a JWT.
     *
     * @assert (array('name' => 'ภาษาไทย', 'id' => 1234567890)) [==] 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJuYW1lIjoiXHUwZTIwXHUwZTMyXHUwZTI5XHUwZTMyXHUwZTQ0XHUwZTE3XHUwZTIyIiwiaWQiOjEyMzQ1Njc4OTB9.fAdzmsl4AIGAyNGt7MfNum9DUIxn6DGMhdn1hw4PwwE'
     *
     * @param array $payload The payload data to be encoded.
     *
     * @return string The encoded JWT.
     */
    public function encode($payload)
    {
        // Prepare the JWT header
        $header = [
            'typ' => 'JWT', // JWT type
            'alg' => $this->algorithm // Algorithm used for encoding
        ];

        // Encode the header
        $headerEncoded = $this->base64UrlEncode(json_encode($header));

        // Check if JWT expiration time is specified
        if ($this->expireTime > 0) {
            $payload['expired'] = time() + $this->expireTime; // Add expiration time to payload
        }

        // Encode the payload
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        // Generate the signature
        $signature = $this->generateSignature($headerEncoded, $payloadEncoded);

        // Combine the header, payload, and signature into a JWT and return
        return "$headerEncoded.$payloadEncoded.$signature";
    }

    /**
     * Decodes a JWT and retrieves the payload.
     *
     * @assert ('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJuYW1lIjoiXHUwZTIwXHUwZTMyXHUwZTI5XHUwZTMyXHUwZTQ0XHUwZTE3XHUwZTIyIiwiaWQiOjEyMzQ1Njc4OTB9.fAdzmsl4AIGAyNGt7MfNum9DUIxn6DGMhdn1hw4PwwE') [==] array('name' => 'ภาษาไทย', 'id' => 1234567890)
     *
     * @param string $jwt The JWT to decode.
     *
     * @return array The decoded payload data.
     * @throws \Exception If the token format is invalid.
     */
    public function decode($jwt)
    {
        // Split the JWT into Header, Payload, and Signature parts
        $parts = explode('.', $jwt);

        // Check if all three parts (Header, Payload, and Signature) are present
        if (count($parts) !== 3) {
            throw new \Exception('Invalid token format');
        }

        // Decode the Payload
        $decodedPayload = $this->base64UrlDecode($parts[1]);

        // Convert the decoded payload from JSON to an associative array
        $payloadData = json_decode($decodedPayload, true);

        // Remove the expiration time if it is specified in the payload
        if ($this->expireTime > 0) {
            unset($payloadData['expired']);
        }

        // Return the decoded payload
        return $payloadData;
    }

    /**
     * Verifies the integrity and validity of a JWT.
     *
     * @assert ('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJuYW1lIjoiXHUwZTIwXHUwZTMyXHUwZTI5XHUwZTMyXHUwZTQ0XHUwZTE3XHUwZTIyIiwiaWQiOjEyMzQ1Njc4OTB9.fAdzmsl4AIGAyNGt7MfNum9DUIxn6DGMhdn1hw4PwwE') [==] array('name' => 'ภาษาไทย', 'id' => 1234567890)
     * @assert ('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJuYW1lIjoiXHUwZTIwXHUwZTMyXHUwZTI5XHUwZTMyXHUwZTQ0XHAwZTE3XHUwZTIyIiwiaWQiOjEyMzQ1Njc4OTB9.fAdzmsl4AIGAyNGt7MfNum9DUIxn6DGMhdn1hw4PwwE') [throws] \Exception
     *
     * @param string $jwt The JWT to verify.
     *
     * @return array The decoded payload data.
     * @throws \Exception If the token format is invalid, the signature is invalid, or the token has expired.
     */
    public function verify($jwt)
    {
        // Split the JWT into Header, Payload, and Signature parts
        $parts = explode('.', $jwt);

        // Check if all three parts (Header, Payload, and Signature) are present
        if (count($parts) !== 3) {
            throw new \Exception('Invalid token format');
        }

        // Generate the expected signature based on the received Header and Payload
        $signatureExpected = $this->generateSignature($parts[0], $parts[1]);

        // Check if the expected signature matches the signature in the JWT
        if ($signatureExpected !== $parts[2]) {
            throw new \Exception('Invalid signature');
        }

        // Decode the Payload
        $decodedPayload = $this->base64UrlDecode($parts[1]);

        // Convert the decoded payload from JSON to an associative array
        $payloadData = json_decode($decodedPayload, true);

        if ($this->expireTime > 0) {
            // Check if the Payload has expired (if expiration time is specified)
            if ($payloadData['expired'] < time()) {
                throw new \Exception('Token has expired');
            }

            // Remove the expiration time
            unset($payloadData['expired']);
        }

        // Return the decoded payload
        return $payloadData;
    }

    /**
     * Generates a signature using the specified algorithm.
     *
     * @param string $header The JWT header.
     * @param string $payload The JWT payload.
     *
     * @return string The generated signature.
     */
    private function generateSignature($header, $payload)
    {
        // Encode the secret key
        $signature = hash_hmac($this->hashHmacAlgorithms[$this->algorithm], "$header.$payload", $this->secretKey, true);
        // Return the encoded data
        return $this->base64UrlEncode($signature);
    }

    /**
     * Encodes data using Base64.
     *
     * @param string $data
     *
     * @return string
     */
    private function base64UrlEncode($data)
    {
        // Replace '+' with '-' and '/' with '_'
        $base64Url = strtr(base64_encode($data), '+/', '-_');
        // Remove trailing '=' and return the result
        return rtrim($base64Url, '=');
    }

    /**
     * Decodes data encoded with base64UrlEncode.
     *
     * @param string $data
     *
     * @return string
     */
    private function base64UrlDecode($data)
    {
        // Pad the data with trailing '=' to match Base64 format
        $data = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        // Replace '-' with '+' and '_' with '/' and return the result
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
