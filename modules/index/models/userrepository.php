<?php
/**
 * @filesource modules/index/models/userrepository.php
 *
 * User Repository - Data Access Layer
 * Handles all database operations for users
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\UserRepository;

/**
 * User Repository
 *
 * Data Access Layer for user operations
 * Separates database queries from business logic
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Create a new user
     *
     * @param array $data User data
     *
     * @return int User ID
     */
    public static function createUser(array $data)
    {
        return self::saveUser(\Kotchasan\DB::create(), 0, $data);
    }

    /**
     * Save one user aggregate across user and user_meta.
     *
     * @param \Kotchasan\DB $db
     * @param int $id
     * @param array $data
     *
     * @return int
     */
    public static function saveUser($db, int $id, array $data)
    {
        $isNew = $id === 0;
        $metas = isset($data['metas']) && is_array($data['metas']) ? $data['metas'] : [];
        unset($data['metas']);

        if ($isNew) {
            $id = (int) $db->insert('user', $data);
        } else {
            unset($data['id']);
            $db->update('user', ['id', $id], $data);
        }

        if ($isNew) {
            $defaultDepartment = trim((string) (self::$cfg->default_department ?? ''));
            $hasDepartment = isset($metas['department'])
            && is_array($metas['department'])
            && !empty($metas['department']);

            if ($defaultDepartment !== '' && !$hasDepartment) {
                $metas['department'] = [$defaultDepartment];
            }
        }

        self::saveUserMeta($db, $id, $metas);

        return $id;
    }

    /**
     * Find user by username
     *
     * @param string $username
     *
     * @return object|null
     */
    public static function findByUsername($username)
    {
        return \Kotchasan\DB::create()->first('user', ['username', $username]);
    }

    /**
     * Replace all user_meta rows for the member.
     *
     * @param \Kotchasan\DB $db
     * @param int $userId
     * @param array $metas
     *
     * @return void
     */
    public static function saveUserMeta($db, int $userId, array $metas): void
    {
        $db->delete('user_meta', ['member_id', $userId], 0);

        foreach ($metas as $name => $value) {
            foreach (self::normalizeMetaValues($value) as $item) {
                $db->insert('user_meta', [
                    'member_id' => $userId,
                    'name' => $name,
                    'value' => $item
                ]);
            }
        }
    }

    /**
     * Normalize one meta field into a flat scalar list.
     *
     * @param mixed $value
     *
     * @return array
     */
    private static function normalizeMetaValues($value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($values as $item) {
            if (is_string($item)) {
                $item = trim($item);
            }
            if ($item === '' || $item === null) {
                continue;
            }
            $result[] = $item;
        }

        return array_values($result);
    }

    /**
     * Check if field value is unique
     *
     * @param string $field Field name (username, phone, email)
     * @param mixed $value Field value
     * @param int $excludeId User ID to exclude from check
     *
     * @return bool True if unique
     */
    public static function isFieldUnique($field, $value, $excludeId = 0)
    {
        if (empty($value)) {
            return true;
        }

        $user = \Kotchasan\DB::create()->first('user', [$field, $value], ['id']);

        if ($excludeId > 0) {
            return $user ? $user->id == $excludeId : true;
        }

        return !$user;
    }
}
