<?php
namespace Kotchasan;

/**
 * Kotchasan Password Class
 *
 * This class provides methods for encoding and decoding strings,
 * generating unique IDs, and creating secure API signatures.
 *
 * @package Kotchasan
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
        $data = http_build_query($params, '', '&');
        // Return the hashed string
        return strtoupper(hash_hmac('sha256', $data, $secret));
    }

    /**
     * Hashes a user password for storage.
     *
     * The password is first keyed with the site's password_key (HMAC-SHA256) so a
     * leaked database alone is not enough to attack it, then hashed with bcrypt
     * (cost 12) through password_hash(). The result is a standard 60-character
     * "$2y$..." string. Passwords used to be stored as sha1(key.password.salt),
     * a fast hash that can be brute-forced offline; verify() still accepts that
     * form so that existing accounts keep working, and needsRehash() tells the
     * caller to upgrade the stored hash after a successful login.
     *
     * @param string $password    Plain-text password
     * @param string $passwordKey Site-wide password_key from settings/config.php
     *
     * @return string
     */
    public static function hash($password, $passwordKey)
    {
        return password_hash(self::pepper($password, $passwordKey), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verifies a password against a stored hash of either format.
     *
     * @param string $password    Plain-text password
     * @param string $stored      Value of the password column
     * @param string $salt        Value of the salt column (legacy sha1 form only)
     * @param string $passwordKey Site-wide password_key
     *
     * @return bool
     */
    public static function verify($password, $stored, $salt, $passwordKey)
    {
        $password = (string) $password;
        $stored = (string) $stored;
        if ($password === '' || $stored === '') {
            return false;
        }
        if (self::isLegacyHash($stored)) {
            // sha1(key.password.salt), and the even older sha1(password.salt) without a key
            return hash_equals($stored, sha1($passwordKey.$password.$salt))
                || hash_equals($stored, sha1($password.$salt));
        }
        return password_verify(self::pepper($password, $passwordKey), $stored);
    }

    /**
     * Whether a stored hash should be replaced by hash() after the next successful login
     * (legacy sha1 form, or bcrypt with an outdated cost).
     *
     * @param string $stored
     *
     * @return bool
     */
    public static function needsRehash($stored)
    {
        return self::isLegacyHash($stored) || password_needs_rehash((string) $stored, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Whether a stored value is a legacy sha1 hash (40 hex characters).
     *
     * @param string $stored
     *
     * @return bool
     */
    public static function isLegacyHash($stored)
    {
        return preg_match('/^[0-9a-f]{40}$/i', (string) $stored) === 1;
    }

    /**
     * Keys the password with the site password_key before bcrypt. HMAC output is
     * 64 hex characters, safely under bcrypt's 72-byte limit whatever the password length.
     *
     * @param string $password
     * @param string $passwordKey
     *
     * @return string
     */
    protected static function pepper($password, $passwordKey)
    {
        return hash_hmac('sha256', (string) $password, (string) $passwordKey);
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
