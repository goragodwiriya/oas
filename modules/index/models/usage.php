<?php
/**
 * @filesource modules/index/models/usage.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Usage;

use Kotchasan\Database\Sql;

/**
 * API Usage Model
 *
 * Handles usage table operations
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
        $where = [];
        if (!empty($params['src'])) {
            $where[] = ['O.module', $params['src']];
        }
        if (!empty($params['from'])) {
            $where[] = ['O.created_at', '>=', $params['from']];
        }
        if (!empty($params['to'])) {
            $where[] = ['O.created_at', '<=', $params['to']];
        }
        if (!empty($params['member_id'])) {
            $where[] = ['O.member_id', $params['member_id']];
        }

        // Default query
        $query = static::createQuery()
            ->select(
                'O.id',
                'O.created_at',
                'O.module src',
                'O.action',
                'O.topic',
                'O.reason',
                'U.name'
            )
            ->from('logs O')
            ->join('user U', [['U.id', Sql::column('O.member_id')]], 'LEFT')
            ->where($where);

        // Search (OR condition)
        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $where = [
                ['U.name', 'LIKE', $search],
                ['O.topic', 'LIKE', $search],
                ['O.reason', 'LIKE', $search]
            ];

            $query->where($where, 'OR');
        }

        return $query;
    }

    /**
     * Get module options for filters
     *
     * @return array
     */
    public static function getModuleOptions()
    {
        return static::createQuery()
            ->select(Sql::DISTINCT('module', 'text'), Sql::create('LOWER(module) value'))
            ->from('logs')
            ->CacheOn()
            ->fetchAll();
    }

    /**
     * Delete usage
     * Return number of deleted usages
     *
     * @param int|array $ids Usage ID or array of usage IDs
     *
     * @return int
     */
    public static function remove($ids)
    {
        if (empty($ids)) {
            return 0;
        }
        return \Kotchasan\DB::create()->delete('logs', ['id', $ids], 0);
    }
}
