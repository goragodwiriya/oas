<?php
/**
 * @filesource modules/index/models/loginattempts.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Loginattempts;

/**
 * API Login Attempts Model
 *
 * อ่าน/ลบตาราง login_attempt ที่ Gcms\LoginAttempt ใช้นับการเดารหัสผ่าน
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
        return self::buildQuery(static::createQuery(), $params);
    }

    /**
     * Delete login attempts
     * Return number of deleted rows
     *
     * @param int|array $ids
     *
     * @return int
     */
    public static function remove($ids)
    {
        if (empty($ids)) {
            return 0;
        }

        return \Kotchasan\DB::create()->delete('login_attempt', ['id', $ids], 0);
    }

    /**
     * Filters (AND) + search (OR) ใช้ร่วมกับ Sysadmin\Loginattempts\Model
     *
     * @param \Kotchasan\QueryBuilder\QueryBuilderInterface $query
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function buildQuery($query, $params)
    {
        // attempted_at เป็น datetime · วันที่ "ถึง" ต้องรวมทั้งวัน ไม่ใช่แค่ 00:00:00
        $where = [];
        if (!empty($params['from'])) {
            $where[] = ['attempted_at', '>=', $params['from'].' 00:00:00'];
        }
        if (!empty($params['to'])) {
            $where[] = ['attempted_at', '<=', $params['to'].' 23:59:59'];
        }

        $query->select('id', 'attempted_at', 'username', 'ip_address', 'user_agent')
            ->from('login_attempt')
            ->where($where);

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['username', 'LIKE', $search],
                ['ip_address', 'LIKE', $search]
            ], 'OR');
        }

        return $query;
    }
}
