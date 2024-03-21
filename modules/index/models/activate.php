<?php
/**
 * @filesource modules/index/models/activate.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Activate;

/**
 * activate.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model
{
    /**
     * activate สมาชิก
     *
     * @param string $id
     */
    public static function execute($activatecode)
    {
        $model = \Kotchasan\Model::create();
        $model->db()->update($model->getTableName('user'), array('activatecode', $activatecode), array('activatecode' => ''));
    }
}
