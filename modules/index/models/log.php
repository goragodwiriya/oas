<?php
/**
 * @filesource modules/index/models/log.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Log;

/**
 * จัดการ log
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model
{
    /**
     * เพิ่ม log
     *
     * @param int $id
     * @param string $module
     * @param string $action
     * @param string $topic
     * @param int $member_id
     * @param mixed $datas
     * @param string $reason
     */
    public static function add($id, $module, $action, $topic, $member_id, $datas = null, $reason = null)
    {
        $model = new \Kotchasan\Model;
        $model->db()->insert($model->getTableName('logs'), array(
            'src_id' => $id,
            'module' => $module,
            'action' => $action,
            'create_date' => date('Y-m-d H:i:s'),
            'topic' => $topic,
            'member_id' => $member_id,
            'datas' => is_array($datas) ? json_encode($datas, JSON_UNESCAPED_UNICODE) : $datas,
            'reason' => $reason
        ));
    }

    /**
     * Query ข้อมูลสำหรับส่งให้กับ DataTable
     *
     * @param int $id
     * @param string $module
     * @param string|array $actions
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($id, $module, $actions = '')
    {
        $where = array(
            array('O.src_id', $id),
            array('O.module', $module)
        );
        if (!empty($actions)) {
            $where[] = array('O.action', $actions);
        }
        return \Kotchasan\Model::createQuery()
            ->select('O.id', 'O.create_date', 'O.topic', 'O.reason', 'U.name')
            ->from('logs O')
            ->join('user U', 'LEFT', array('U.id', 'O.member_id'))
            ->where($where);
    }
}
