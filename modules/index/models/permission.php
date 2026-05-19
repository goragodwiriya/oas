<?php
/**
 * @filesource modules/index/models/permission.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Permission;

/**
 * API Permission Model
 *
 * Handles permission table operations
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
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
        $where = [
            ['U.id', '!=', 1]
        ];
        if (isset($params['status']) && $params['status'] !== '') {
            $where[] = ['U.status', (int) $params['status']];
        }

        // Default query
        $query = static::createQuery()
            ->select(
                'U.id',
                'U.name',
                'U.status',
                'U.permission'
            )
            ->from('user U')
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

        return $query;
    }

    /**
     * @param $id
     * @param $permission
     * @return mixed
     */
    public static function updatePermission($id, $permission, $value)
    {
        $user = static::createQuery()
            ->select('id', 'name', 'permission')
            ->from('user')
            ->where(['id', $id])
            ->first();

        if ($user) {
            $newPermission = [];
            foreach (\Index\Auth\Model::parsePermission($user->permission) as $perm) {
                if ($perm != $permission) {
                    $newPermission[] = $perm;
                }
            }

            if ($value) {
                $newPermission[] = $permission;
            }

            static::createQuery()
                ->update('user')
                ->set(['permission' => empty($newPermission) ? '' : ','.implode(',', $newPermission).','])
                ->where(['id', $id])
                ->execute();
        }

        return $user->name;
    }
}
