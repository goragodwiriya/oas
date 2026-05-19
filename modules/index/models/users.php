<?php
/**
 * @filesource modules/index/models/users.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Users;

use Kotchasan\Database\Sql;

/**
 * API Users Model
 *
 * Handles user table operations
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Get social icon
     *
     * @param int $social
     *
     * @return string
     */
    public static function getSocialIcon($social)
    {
        return self::$socialIcons[$social] ?? 'icon-user';
    }

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        // Filters (AND conditions)
        $where = [];
        if ($params['status'] !== '') {
            $where[] = ['U.status', (int) $params['status']];
        }
        if ($params['department'] !== '') {
            $where[] = ['M.value', $params['department']];
        }

        // Default query
        $query = static::createQuery()
            ->select(
                'U.id',
                'U.username',
                'U.name',
                'U.phone',
                'U.status',
                'U.active',
                'U.social',
                'U.created_at',
                Sql::GROUP_CONCAT(['M.value'], 'department')
            )
            ->from('user U')
            ->join('user_meta M', [['M.member_id', 'U.id'], ['M.name', 'department']], 'LEFT')
            ->where($where);

        // Search (OR condition)
        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $where = [
                ['U.name', 'LIKE', $search],
                ['U.username', 'LIKE', $search],
                ['U.phone', 'LIKE', $search]
            ];

            $query->where($where, 'OR');
        }

        return $query->groupBy('U.id');
    }

    /**
     * Delete user
     * Return number of deleted users
     *
     * @param int|array $ids User ID or array of user IDs
     *
     * @return int
     */
    public static function remove($ids)
    {
        $remove_ids = [];
        // Delete file
        foreach ((array) $ids as $id) {
            if ($id == 1) {
                continue;
            }

            // The name of the folder where the files of the member you want to delete are stored.
            foreach (self::$cfg->member_images as $item => $value) {
                $img = ROOT_PATH.DATA_FOLDER.$item.'/'.$id.self::$cfg->stored_img_type;
                if (file_exists($img)) {
                    unlink($img);
                }
            }
            $remove_ids[] = $id;
        }

        if (empty($remove_ids)) {
            return 0;
        }

        // Remove user
        return \Kotchasan\DB::create()->delete('user', ['id', $remove_ids], 0);
    }

    /**
     * returns an array of options for a select element
     *
     * @param array $where
     *
     * @return array
     */
    public static function toOptions($where = [])
    {
        $where[] = ['name', '!=', ''];

        return static::createQuery()
            ->select('id value', 'name text')
            ->from('user')
            ->where($where)
            ->orderBy('name')
            ->fetchAll();
    }
}
