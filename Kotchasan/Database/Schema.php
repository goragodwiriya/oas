<?php
/**
 * @filesource Kotchasan/Database/Schema.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

/**
 * Database schema class
 *
 * This class is responsible for retrieving and managing database schema information.
 *
 * @see https://www.kotchasan.com/
 */
class Schema
{
    /**
     * Database object
     *
     * @var Driver
     */
    private $db;
    /**
     * List of loaded schemas
     *
     * @var array
     */
    private $tables = [];

    /**
     * Create Schema Class
     *
     * @param Driver $db The database driver object
     *
     * @return static
     */
    public static function create(Driver $db)
    {
        $obj = new static;
        $obj->db = $db;
        return $obj;
    }

    /**
     * Get the field names of a table
     *
     * Retrieve all field names in the specified table.
     *
     * @param string $table The table name
     *
     * @return array The array of field names
     *
     * @throws \InvalidArgumentException if the table name is empty
     */
    public function fields($table)
    {
        if (empty($table)) {
            throw new \InvalidArgumentException('Table name is empty in fields');
        } else {
            $this->init($table);
            return array_keys($this->tables[$table]);
        }
    }

    /**
     * Initialize the schema data for a table
     *
     * @param string $table The table name
     */
    private function init($table)
    {
        if (empty($this->tables[$table])) {
            $sql = "SHOW FULL COLUMNS FROM $table";
            $columns = $this->db->cacheOn()->customQuery($sql, true);
            if (empty($columns)) {
                throw new \InvalidArgumentException($this->db->getError());
            } else {
                $datas = [];
                foreach ($columns as $column) {
                    $datas[$column['Field']] = $column;
                }
                $this->tables[$table] = $datas;
            }
        }
    }
}
