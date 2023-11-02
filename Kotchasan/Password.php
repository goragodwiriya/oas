<?php
/**
 * @filesource Kotchasan/Password.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides functions for password encryption and decryption.
 *
 * @see https://www.kotchasan.com/
 */
class Password
{
    /**
     * Decrypts a string.
     *
     * Decodes the given string using the provided password.
     * Returns the decrypted string.
     * Throws an exception if decryption fails.
     *
     * @assert (Password::encode("ทดสอบภาษาไทย", 12345678), 12345678) [==] "ทดสอบภาษาไทย"
     * @assert (Password::encode(1234, 12345678), 12345678) [==] 1234
     * @assert ('12345678', 12345678) [throws] \Exception
     *
     * @param string $string The encoded string to be decrypted (output of encode() function).
     * @param string $password The encryption key.
     *
     * @return string The decrypted string.
     *
     * @throws Exception If $string is invalid.
     */
    public static function decode($string, $password)
    {
        $base64 = base64_decode($string);
        $ds = explode('::', $base64, 2);
        if (isset($ds[0]) && isset($ds[1])) {
            return openssl_decrypt($ds[0], 'aes-256-cbc', $password, 0, $ds[1]);
        }
        // Invalid string. Decryption failed.
        throw new \Exception('Invalid string');
    }

    /**
     * Encrypts a string.
     *
     * Encodes the given string using the provided password.
     * Returns the encrypted string.
     *
     * @param string $string The string to be encrypted.
     * @param string $password The encryption key.
     *
     * @return string The encrypted string.
     */
    public static function encode($string, $password)
    {
        $iv = self::uniqid(16);
        $encrypted = openssl_encrypt($string, 'aes-256-cbc', $password, 0, $iv);
        return base64_encode($encrypted.'::'.$iv);
    }

    /**
     * Generates a sign for API communication.
     *
     * @param array $params The parameters array.
     * @param string $secret The secret key.
     *
     * @return string The generated sign.
     */
    public static function generateSign($params, $secret)
    {
        // Sort the parameters by key
        ksort($params);
        // Concatenate the data
        $data = '';
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        // Return the hashed string
        return strtoupper(hash_hmac('sha256', $data, $secret));
    }

    /**
     * Generates a random password.
     *
     * @param int $length The desired length of the password.
     *
     * @return string The generated password.
     */
    public static function uniqid($length = 13)
    {
        if (function_exists('random_bytes')) {
            $token = random_bytes(ceil($length / 2));
        } else {
            $token = openssl_random_pseudo_bytes(ceil($length / 2));
        }
        return substr(bin2hex($token), 0, $length);
    }
}
