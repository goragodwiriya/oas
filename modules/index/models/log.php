<?php
/**
 * @filesource modules/index/models/log.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Log;

/**
 * Manage log
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model
{
    /**
     * Add log
     *
     * @param int $src_id ID of updated item
     * @param string $module Module name
     * @param string $action Action performed
     * @param string $topic Topic
     * @param int $member_id ID of member
     * @param string $reason Reason
     * @param mixed $datas Additional data
     */
    public static function add($src_id, $module, $action, $topic, $member_id, $reason = null, $datas = null)
    {
        \Kotchasan\DB::create()->insert('logs', [
            'src_id' => $src_id,
            'action' => $action,
            'module' => $module,
            'created_at' => date('Y-m-d H:i:s'),
            'topic' => $topic,
            'member_id' => $member_id,
            'datas' => is_array($datas) ? json_encode($datas, JSON_UNESCAPED_UNICODE) : $datas,
            'reason' => $reason
        ]);
    }
}
