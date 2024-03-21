<?php
/**
 * @filesource modules/index/models/usage.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Usage;

use Gcms\Login;
use Kotchasan\Database\Sql;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=usage
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query ข้อมูลสำหรับส่งให้กับ DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['mod'] != '') {
            $where[] = array('L.module', $params['mod']);
        }
        if ($params['act'] != '') {
            $where[] = array('L.action', $params['act']);
        }
        if ($params['from'] != '') {
            $where[] = array('L.create_date', '>=', $params['from'].' 00:00:00');
        }
        if ($params['to'] != '') {
            $where[] = array('L.create_date', '<=', $params['to'].' 23:59:59');
        }
        return static::createQuery()
            ->select('L.id', 'L.create_date', 'L.topic', 'U.name', 'L.module', 'L.action')
            ->from('logs L')
            ->join('user U', 'LEFT', array('U.id', 'L.member_id'))
            ->where($where);
    }

    /**
     * คืนค่ารายการ module ทั้งหมด
     * สำหรับใส่ลงใน select
     *
     * @return array
     */
    public static function modules()
    {
        $query = static::createQuery()
            ->select(Sql::DISTINCT('module'))
            ->from('logs')
            ->order('module')
            ->cacheOn();
        $result = [];
        foreach ($query->execute() as $item) {
            $result[$item->module] = $item->module;
        }
        return $result;
    }

    /**
     * คืนค่ารายการ action ทั้งหมด
     * สำหรับใส่ลงใน select
     *
     * @return array
     */
    public static function actions()
    {
        $query = static::createQuery()
            ->select(Sql::DISTINCT('action'))
            ->from('logs')
            ->order('action')
            ->cacheOn();
        $result = [];
        foreach ($query->execute() as $item) {
            $result[$item->action] = Language::get($item->action);
        }
        return $result;
    }

    /**
     * รับค่าจาก action (index.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = [];
        // session, referer, แอดมิน, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isAdmin()) {
            if (Login::notDemoMode($login)) {
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                // id ที่ส่งมา
                if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->filter('0-9,'), $match)) {
                    if ($action === 'delete' && $login['id'] == 1) {
                        // ลบ (Super Admin)
                        $this->db()->delete($this->getTableName('logs'), array('id', $match[1]), 0);
                        // reload
                        $ret['location'] = 'reload';
                    }
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่า JSON
        echo json_encode($ret);
    }
}
