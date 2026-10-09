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
     * สิทธิ์จัดการสมาชิก (หน้า /users) — แอดมิน (status=1) ทุกคน
     * ยกเว้นบัญชีตัวอย่างของ demo_mode ซึ่งอ่านอย่างเดียว
     *
     * @param object $login
     *
     * @return bool
     */
    public static function canManage($login)
    {
        return \Gcms\Api::isSuperAdmin($login) || (\Gcms\Api::isAdmin($login) && \Gcms\Api::isNotDemoMode($login));
    }

    /**
     * $login แก้ไขข้อมูลของสมาชิก $id ได้หรือไม่
     * ทุกคนแก้ไขของตัวเองได้ แอดมินแก้ไขของคนอื่นได้
     * ยกเว้นบัญชี id=1 ซึ่งแก้ไขได้โดยตัวเองเท่านั้น
     *
     * @param object $login
     * @param int    $id    0 = สมาชิกใหม่
     *
     * @return bool
     */
    public static function canEdit($login, $id)
    {
        $id = (int) $id;
        if ($id === (int) $login->id) {
            return true;
        }
        return $id !== 1 && self::canManage($login);
    }

    /**
     * เหตุที่ $login เปลี่ยนสถานะ (status และ active) ของสมาชิก $id ไม่ได้
     * บัญชี id=1 เปลี่ยนไม่ได้เลย ส่วนแอดมินเปลี่ยนสถานะของตัวเองไม่ได้
     * ต้องให้แอดมินคนอื่นเปลี่ยนให้
     *
     * @param object $login
     * @param int    $id    0 = สมาชิกใหม่
     *
     * @return string ข้อความผิดพลาด, '' = เปลี่ยนได้
     */
    public static function statusLockReason($login, $id)
    {
        $id = (int) $id;
        if ($id === 1) {
            return 'The status of this account cannot be changed';
        }
        if ($id === (int) $login->id) {
            return 'Your status can only be changed by another administrator';
        }
        return self::canManage($login) ? '' : 'Failed to process request';
    }

    /**
     * $login เปลี่ยนสถานะของสมาชิก $id ได้หรือไม่ (ดู statusLockReason)
     *
     * @param object $login
     * @param int    $id
     *
     * @return bool
     */
    public static function canChangeStatus($login, $id)
    {
        return self::statusLockReason($login, $id) === '';
    }

    /**
     * คัดเฉพาะ id ที่ $login เปลี่ยนสถานะได้
     *
     * @param object    $login
     * @param int|array $ids
     *
     * @return array
     */
    public static function statusChangeableIds($login, $ids)
    {
        return array_values(array_filter((array) $ids, fn($id) => self::canChangeStatus($login, $id)));
    }

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
                \Gcms\Api::removeStoredImage($item.'/'.(int) $id);
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
