<?php
/**
 * @filesource modules/index/models/authvalidation.php
 *
 * Centralized validation for auth-related API requests.
 */

namespace Index\AuthValidation;

class Model extends \Kotchasan\KBase
{
    /**
     * Validate login request payload.
     *
     * @param string $username
     * @param string $password
     *
     * @return array
     */
    public static function validateLogin($username, $password)
    {
        $errors = [];

        if (empty($username)) {
            $errors['username'] = 'Username is required';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        }

        return self::result($errors);
    }

    /**
     * Validate update profile request payload.
     *
     * @param string $name
     * @param string $password
     * @param string $repassword
     *
     * @return array
     */
    public static function validateUpdateProfile($name, $password, $repassword)
    {
        $errors = [];

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if (!empty($password) && $password !== $repassword) {
            $errors['repassword'] = 'Password confirmation does not match';
        }

        return self::result($errors);
    }

    /**
     * Validate forgot password request payload.
     *
     * @param string $identifier
     *
     * @return array
     */
    public static function validateForgotPassword($identifier)
    {
        $errors = [];

        if (empty($identifier)) {
            $errors['username'] = 'Email or username is required';
        }

        return self::result($errors);
    }

    /**
     * Validate reset password request payload.
     *
     * @param string $token
     * @param int $userId
     * @param string $password
     * @param string $repassword
     *
     * @return array
     */
    public static function validateResetPassword($token, $userId, $password, $repassword)
    {
        $errors = [];

        if (empty($token) || empty($userId)) {
            $errors['token'] = 'Invalid reset link';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $repassword) {
            $errors['repassword'] = 'Password confirmation does not match';
        }

        return self::result($errors);
    }

    /**
     * Validate register request payload.
     *
     * @param string $username
     * @param string $name
     * @param string $password
     * @param string $repassword
     * @param array $loginFields
     *
     * @return array
     */
    public static function validateRegister($username, $name, $password, $repassword, array $loginFields)
    {
        $errors = [];

        if (empty($username)) {
            $errors['username'] = 'Username is required';
        }

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $repassword) {
            $errors['repassword'] = 'Password confirmation does not match';
        }

        if (!empty($username)
            && in_array('email', $loginFields, true)
            && count($loginFields) === 1
            && !filter_var($username, FILTER_VALIDATE_EMAIL)
        ) {
            $errors['username'] = 'Please enter a valid email address';
        }

        return self::result($errors);
    }

    /**
     * Convert validation errors into unified format.
     *
     * @param array $errors
     *
     * @return array
     */
    private static function result(array $errors)
    {
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? '' : implode(', ', array_values($errors))
        ];
    }
}
