<?php
/**
 * @filesource Kotchasan/Database/Db.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

use Kotchasan\Database;

/**
 * Database base class
 *
 * Provides the base functionality for database operations.
 *
 * @see https://www.kotchasan.com/
 */
abstract class Db extends \Kotchasan\KBase
{
    /**
     * Database connection.
     *
     * @var \Kotchasan\Database\Driver
     */
    protected $db;

    /**
     * Class constructor.
     *
     * @param string $conn The connection name. If not specified, no database connection will be made.
     */
    public function __construct($conn)
    {
        $this->db = Database::create($conn);
    }

    /**
     * Get the database connection.
     *
     * @return \Kotchasan\Database\Driver The database connection.
     */
    public function db()
    {
        return $this->db;
    }

    /**
     * Get the value of a database setting.
     *
     * @param string $key The setting key.
     *
     * @return mixed The value of the setting.
     */
    public function getSetting($key)
    {
        if (isset($this->db->settings->$key)) {
            return $this->db->settings->$key;
        }
    }

    /**
     * Get all database settings.
     *
     * @return object The database settings object.
     */
    public function getAllSettings()
    {
        return $this->db->settings;
    }
}
