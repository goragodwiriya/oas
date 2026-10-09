<?php
/**
 * @filesource modules/index/models/profile.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Profile;

use Index\Auth\Model as Auth;
use Index\UserRepository\Model as UserRepository;
use Kotchasan\Database\Sql;

/**
 * API Profile Model
 *
 * Handles user table operations
 *`
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Get user by ID
     * $id = 0 return new (for Register)
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function get($id)
    {
        if ($id <= 0) {
            return (object) [
                'id' => 0,
                'username' => '',
                'name' => '',
                'phone' => '',
                'status' => 0,
                'social' => 'user',
                'active' => 1,
                'metas' => [
                    'department' => empty(self::$cfg->default_department) ? [] : [self::$cfg->default_department]
                ]
            ];
        }

        return self::view($id);
    }

    /**
     * Get details by ID with province info
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function view($id)
    {
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas', ','))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.id', $id])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = Auth::parsePermission($user->permission);
            $user->metas = Auth::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Save data
     * Return ID
     *
     * @param \Kotchasan\DB $db Database connection
     * @param int $id User ID (0 for new user)
     * @param array $save Data to save
     *
     * @return int User ID
     */
    public static function save($db, $id, $save)
    {
        return UserRepository::saveUser($db, (int) $id, $save);
    }

    /**
     * @param $id
     * @param $meta
     */
    public static function saveMeta($id, $meta)
    {
        UserRepository::saveUserMeta(\Kotchasan\DB::create(), (int) $id, is_array($meta) ? $meta : []);
    }
}
