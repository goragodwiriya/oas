<?php
/**
 * @filesource Kotchasan/Session.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Session handling class.
 * This class provides methods for handling sessions using a database.
 *
 * @see https://www.kotchasan.com/
 */
class Session extends \Kotchasan\Model
{
    /**
     * @var Object $database Database object
     */
    private $database;

    /**
     * @var string $table Table name for sessions
     */
    private $table;

    /**
     * Open the session.
     *
     * @return bool Always returns true
     */
    public function _open()
    {
        $model = new static;
        $this->database = $model->db();
        $this->table = $model->getTableName('sessions');
        return true;
    }

    /**
     * Close the session.
     *
     * @return bool Always returns true
     */
    public function _close()
    {
        return true;
    }

    /**
     * Read the session data.
     *
     * @param string $sess_id Session ID
     *
     * @return string Session data
     */
    public function _read($sess_id)
    {
        $search = $this->database->first($this->table, array('sess_id', $sess_id), true);
        return $search ? $search->data : '';
    }

    /**
     * Write the session data.
     *
     * @param string $sess_id Session ID
     * @param string $data    Session data
     *
     * @return bool Always returns true
     */
    public function _write($sess_id, $data)
    {
        $search = $this->database->first($this->table, array('sess_id', $sess_id), true);
        if ($search) {
            $this->database->update($this->table, array('sess_id', $sess_id), array(
                'access' => time(),
                'data' => $data
            ));
        } else {
            $this->database->insert($this->table, array(
                'sess_id' => $sess_id,
                'access' => time(),
                'data' => $data,
                'create_date' => date('Y-m-d H:i:s')
            ));
        }
        return true;
    }

    /**
     * Destroy the session.
     *
     * @param string $sess_id Session ID
     *
     * @return bool Always returns true
     */
    public function _destroy($sess_id)
    {
        $this->database->delete($this->table, array('sess_id', $sess_id));
        return true;
    }

    /**
     * Garbage collection for the session.
     *
     * @param int $max Maximum session age in seconds
     *
     * @return bool Always returns true
     */
    public function _gc($max)
    {
        $old = time() - $max;
        $this->database->delete($this->table, array('access', '<', $old));
        return true;
    }
}
