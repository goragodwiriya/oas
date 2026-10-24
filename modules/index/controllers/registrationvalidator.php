<?php
/**
 * @filesource modules/index/controllers/registrationvalidator.php
 *
 * Registration Validation Layer
 * Separates validation logic from business logic
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\RegistrationValidator;

use Kotchasan\Language;

/**
 * Registration Validator
 *
 * Handles validation for different registration flows:
 * - Guest registration (full validation)
 * - Admin user creation (flexible validation)
 * - Social login (minimal validation)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\KBase
{
    /**
     * Validate guest registration
     *
     * @param array $data User data
     *
     * @return array ['valid' => bool, 'errors' => array, 'message' => string]
     */
    public static function validateGuestRegistration(array $data)
    {
        $errors = [];

        // 1. Required fields (username and name are ALWAYS required)
        if (empty($data['username'])) {
            $errors['username'] = Language::get('Username is required');
        }

        if (empty($data['name'])) {
            $errors['name'] = Language::get('Name is required');
        }

        if (empty($data['password'])) {
            $errors['password'] = Language::get('Password is required');
        }

        // 2. Username format validation
        if (!empty($data['username'])) {
            // Check if email format is required
            if (in_array('email', self::$cfg->login_fields ?? []) && count(self::$cfg->login_fields) === 1) {
                if (!filter_var($data['username'], FILTER_VALIDATE_EMAIL)) {
                    $errors['username'] = Language::get('Please enter a valid email address');
                }
            }
        }

        // 3. Password validation
        if (!empty($data['password']) && strlen($data['password']) < 8) {
            $errors['password'] = Language::get('Password must be at least 8 characters');
        }

        // 4. Check uniqueness for username, phone, and id_card
        if (!empty($data['username']) && !self::isFieldUnique('username', $data['username'])) {
            $errors['username'] = Language::get('This username is already registered');
        }

        if (!empty($data['phone']) && !self::isFieldUnique('phone', $data['phone'])) {
            $errors['phone'] = Language::get('This phone number is already registered');
        }

        if (!empty($data['id_card']) && !self::isFieldUnique('id_card', $data['id_card'])) {
            $errors['id_card'] = Language::get('This ID card is already registered');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? '' : implode(', ', $errors)
        ];
    }

    /**
     * Validate admin user creation
     * More flexible - can skip certain validations
     *
     * @param array $data User data
     * @param array $options Validation options
     *
     * @return array ['valid' => bool, 'errors' => array, 'message' => string]
     */
    public static function validateAdminCreation(array $data, array $options = [])
    {
        $options = array_merge([
            'check_duplicate' => true,
            'require_password' => false
        ], $options);

        $errors = [];

        // 1. Required fields (username and name are ALWAYS required)
        if (empty($data['username'])) {
            $errors['username'] = Language::get('Username is required');
        }

        if (empty($data['name'])) {
            $errors['name'] = Language::get('Name is required');
        }

        // 2. Check uniqueness for username, phone, and id_card (if enabled)
        if ($options['check_duplicate']) {
            $existingUserId = $data['id'] ?? 0;

            if (!empty($data['username']) && !self::isFieldUnique('username', $data['username'], $existingUserId)) {
                $errors['username'] = Language::get('This username is already registered');
            }

            if (!empty($data['phone']) && !self::isFieldUnique('phone', $data['phone'], $existingUserId)) {
                $errors['phone'] = Language::get('This phone number is already registered');
            }

            if (!empty($data['id_card']) && !self::isFieldUnique('id_card', $data['id_card'], $existingUserId)) {
                $errors['id_card'] = Language::get('This ID card is already registered');
            }
        }

        // 3. Password validation (if required)
        if ($options['require_password']) {
            if (empty($data['password'])) {
                $errors['password'] = Language::get('Password is required');
            } elseif (strlen($data['password']) < 8) {
                $errors['password'] = Language::get('Password must be at least 8 characters');
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? '' : implode(', ', $errors)
        ];
    }

    /**
     * Validate social login registration
     * Minimal validation - no duplicate username check
     *
     * @param array $data User data from OAuth provider
     *
     * @return array ['valid' => bool, 'errors' => array, 'message' => string]
     */
    public static function validateSocialLogin(array $data)
    {
        $errors = [];

        // 1. Required fields (name is ALWAYS required)
        if (empty($data['name'])) {
            $errors['name'] = Language::get('Name is required');
        }

        // 2. Validate social provider type
        if (empty($data['social'])) {
            $errors['social'] = 'Social provider type is required';
        } elseif (!in_array($data['social'], ['google', 'facebook', 'line', 'telegram'])) {
            $errors['social'] = 'Invalid social provider type';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? '' : implode(', ', $errors)
        ];
    }

    /**
     * Check if field value is unique
     * Works for username, phone, email, id_card, etc.
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param int $excludeId User ID to exclude from check (for updates)
     *
     * @return bool True if unique
     */
    private static function isFieldUnique($field, $value, $excludeId = 0)
    {
        if (empty($value)) {
            return true;
        }

        $user = \Kotchasan\Model::createQuery()
            ->select('id')
            ->from('user')
            ->where([$field, $value])
            ->first();

        if ($excludeId > 0) {
            return $user ? $user->id == $excludeId : true;
        }

        return !$user;
    }
}
