<?php

namespace Kotchasan;

use Kotchasan\Database;

/**
 * Kotchasan Session Class
 *
 * This class handles session management using a database table.
 * It provides methods to open, close, read, write, destroy sessions,
 * and perform garbage collection on old sessions.
 *
 * @package Kotchasan
 */
class Session extends \Kotchasan\Model
{
    /**
     * @var Database Database connection
     */
    private $database;

    /**
     * @var string Table name for sessions
     */
    private $table = 'sessions';

    /**
     * Open the session.
     *
     * @return bool Always returns true
     */
    public function _open()
    {
        $this->database = Database::create();
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
        $result = $this->database->select('data')
            ->from($this->table)
            ->where('sess_id', $sess_id)
            ->execute();

        $row = $result->fetch();
        return $row ? $row['data'] : '';
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
        $result = $this->database->select('sess_id')
            ->from($this->table)
            ->where('sess_id', $sess_id)
            ->execute();

        if ($result->fetch()) {
            $this->database->update($this->table)
                ->set([
                    'access' => time(),
                    'data' => $data
                ])
                ->where('sess_id', $sess_id)
                ->execute();
        } else {
            $this->database->insert($this->table)
                ->values([
                    'sess_id' => $sess_id,
                    'access' => time(),
                    'data' => $data,
                    'created_at' => date('Y-m-d H:i:s')
                ])
                ->execute();
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
        $this->database->delete($this->table)
            ->where('sess_id', $sess_id)
            ->execute();
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
        $this->database->delete($this->table)
            ->where('access', '<', $old)
            ->execute();
        return true;
    }

    /**
     * Create sessions table if it doesn't exist.
     *
     * @return bool True on success
     */
    public function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
            `sess_id` varchar(32) NOT NULL,
            `data` longtext,
            `access` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`sess_id`),
            KEY `access` (`access`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->database->raw($sql);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get a value from session.
     *
     * @param string $name Session key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function session(string $name, $default = null)
    {
        return isset($_SESSION[$name]) ? $_SESSION[$name] : $default;
    }

    /**
     * Set a value to session.
     *
     * @param string $name Session key
     * @param mixed $value Value to set
     * @return self
     */
    public function setSession(string $name, $value): self
    {
        $_SESSION[$name] = $value;
        return $this;
    }
}
