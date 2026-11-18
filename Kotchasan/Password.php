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
     * A string from encode() carries a MAC, checked before anything is
     * decrypted: one whose IV or ciphertext was changed is refused (without it,
     * whoever held a token could rewrite its first 16 bytes by changing the IV).
     * A string written before the MAC existed (no MAC) still decodes, for values
     * kept in a database; pass $authenticated = true for strings that come from
     * users (a download link) so those are refused too.
     *
     * @param string $string The encoded string to be decrypted (output of encode() function).
     * @param string $password The encryption key.
     * @param bool $authenticated Refuse a string without a MAC
     *
     * @return string The decrypted string.
     *
     * @throws Exception If $string is invalid, or its MAC does not match.
     */
    public static function decode($string, $password, $authenticated = false)
    {
        $base64 = base64_decode((string) $string);
        $ds = explode('::', (string) $base64, 3);
        if (isset($ds[0]) && isset($ds[1])) {
            if (isset($ds[2])) {
                if (!hash_equals(self::mac($ds[0].'::'.$ds[1], $password), $ds[2])) {
                    throw new \Exception('Invalid string');
                }
            } elseif ($authenticated) {
                throw new \Exception('Invalid string');
            }
            return openssl_decrypt($ds[0], 'aes-256-cbc', $password, 0, $ds[1]);
        }
        // Invalid string. Decryption failed.
        throw new \Exception('Invalid string');
    }

    /**
     * MAC of an encoded string (encrypt-then-MAC), with a key derived from the
     * password so the encryption key is not used twice.
     *
     * @param string $data Ciphertext and IV, as encode() joins them
     * @param string $password The encryption key
     *
     * @return string Hex HMAC-SHA256
     */
    private static function mac($data, $password)
    {
        return hash_hmac('sha256', $data, hash_hmac('sha256', 'Kotchasan\\Password MAC', (string) $password, true));
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
     * @return string The encrypted string, with a MAC that decode() checks.
     */
    public static function encode($string, $password)
    {
        $iv = self::uniqid(16);
        $encrypted = openssl_encrypt($string, 'aes-256-cbc', $password, 0, $iv);
        return base64_encode($encrypted.'::'.$iv.'::'.self::mac($encrypted.'::'.$iv, $password));
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
     * "$2y$..." string, and the only form verify() accepts.
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
     * Verifies a password against a stored hash.
     *
     * Only the form hash() writes is accepted: bcrypt of the password keyed with
     * $passwordKey. A hash an earlier version stored is refused, even with the
     * right password:
     *   - sha1(password_key.password.salt), and sha1(password.salt) without a key
     *   - bcrypt of the plain password, without the key
     *   - any hash of the password as Text::password() used to shorten it (it
     *     dropped spaces, %, ^, ~, commas, most non-Thai letters ...)
     * $outdated tells the caller that $password is right but stored in one of those
     * forms. The account must set a new password ("Forgot password"); an upgrade
     * script, the one place allowed to, stores hash() of it instead.
     *
     * @param string $password    Plain-text password
     * @param string $stored      Value of the password column
     * @param string $salt        Value of the salt column (only the sha1 forms use it)
     * @param string $passwordKey Site-wide password_key
     * @param bool   $outdated    Set to true when $password matches a form of an earlier version
     *
     * @return bool
     */
    public static function verify($password, $stored, $salt, $passwordKey, &$outdated = null)
    {
        $outdated = false;
        $password = (string) $password;
        $stored = (string) $stored;
        if ($password === '' || $stored === '') {
            return false;
        }
        if (!self::isLegacyHash($stored) && password_verify(self::pepper($password, $passwordKey), $stored)) {
            return true;
        }
        $legacy = self::legacyInput($password);
        $outdated = self::matchesOutdated($password, $stored, $salt, $passwordKey)
            || ($legacy !== null && (self::matchesOutdated($legacy, $stored, $salt, $passwordKey)
                || (!self::isLegacyHash($stored) && password_verify(self::pepper($legacy, $passwordKey), $stored))));

        return false;
    }

    /**
     * The password as Text::password() shortened it before it kept every character,
     * or null when that is the password itself (nothing would have been removed).
     *
     * For code that checks a stored hash itself instead of through verify().
     *
     * @param string $password
     *
     * @return string|null
     */
    public static function legacyInput($password)
    {
        $password = (string) $password;
        // The exact pattern of the old Text::password(), byte for byte (no /u)
        $legacy = preg_replace('/[^\w\@\#\*\$\&\{\}\!\?\+_\-=\.\[\]ก-ฮ]+/', '', $password);

        return ($legacy === null || $legacy === '' || $legacy === $password) ? null : $legacy;
    }

    /**
     * One password against the forms of earlier versions: the sha1 forms, or
     * bcrypt without the key.
     *
     * @param string $password
     * @param string $stored
     * @param string $salt
     * @param string $passwordKey
     *
     * @return bool
     */
    private static function matchesOutdated($password, $stored, $salt, $passwordKey)
    {
        if (self::isLegacyHash($stored)) {
            // sha1(key.password.salt), and the even older sha1(password.salt) without a key
            return hash_equals(strtolower($stored), sha1($passwordKey.$password.$salt))
                || hash_equals(strtolower($stored), sha1($password.$salt));
        }

        return password_verify($password, $stored);
    }

    /**
     * Whether a stored hash should be replaced by hash() once the plain password is
     * known: bcrypt with an outdated cost (verify() accepts it, store it again after
     * the login), or a sha1 form of an earlier version.
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
        $length = max(1, (int) $length);

        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}
