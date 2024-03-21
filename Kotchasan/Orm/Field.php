<?php
/**
 * @filesource Kotchasan/Orm/Field.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Orm;

/**
 * ORM Field base class
 *
 * @see https://www.kotchasan.com/
 */
class Field extends \Kotchasan\Database\Db
{
    /**
     * @var string The table alias
     */
    public $table_alias;

    /**
     * @var string The table name
     */
    public $table_name;

    /**
     * @var string The connection name used to load the config from settings/database.php
     */
    protected $conn = 'mysql';

    /**
     * @var bool True if the field is queried, false if it is a new item
     */
    protected $exists;

    /**
     * @var string The name of the INT(11) AUTO_INCREMENT primary key field
     */
    protected $primaryKey = 'id';

    /**
     * @var string The table name
     */
    protected $table;

    /**
     * Class constructor
     *
     * @param array|object $param Initial data
     */
    public function __construct($param = null)
    {
        if (!empty($param)) {
            foreach ($param as $key => $value) {
                $this->{$key} = $value;
            }
            $this->exists = true;
        } else {
            $this->exists = false;
        }
    }

    /**
     * Create a record
     *
     * @return static
     */
    public static function create()
    {
        $obj = new static;
        return $obj;
    }

    /**
     * Delete a record
     *
     * @return bool True on success, false on failure
     */
    public function delete()
    {
        $rs = new Recordset(get_called_class());
        return $rs->delete(array($this->primaryKey, (int) $this->{$this->primaryKey}), 1);
    }

    /**
     * Get the connection name
     *
     * @return string The connection name
     */
    public function getConn()
    {
        return $this->conn;
    }

    /**
     * Get the full table name
     *
     * @param string $table The table name specified in settings/database.php
     * @return string The full table name with prefix, enclosed in backticks (`) if no table name is specified
     */
    public function getFullTableName($table)
    {
        $dbname = empty($this->db->settings->dbname) ? '' : '`'.$this->db->settings->dbname.'`.';

        $prefix = empty($this->db->settings->prefix) ? '' : $this->db->settings->prefix.'_';
        return $dbname.'`'.$prefix.(isset($this->db->tables->$table) ? $this->db->tables->$table : $table).'`';
    }

    /**
     * Get the primary key field name
     *
     * @return string The primary key field name
     */
    public function getPrimarykey()
    {
        return $this->primaryKey;
    }

    /**
     * Get the table name
     *
     * @return string The table name
     */
    public function getTableName()
    {
        return $this->table_name;
    }

    /**
     * Get the table name with alias
     *
     * @param string|null $alias The desired alias. If not specified, the defined alias will be used
     * @return string The table name with the specified alias or the defined alias
     */
    public function getTableWithAlias($alias = null)
    {
        return $this->table_name.' AS '.(empty($alias) ? $this->table_alias : $alias);
    }

    /**
     * Initialize table name and alias
     *
     * @param \Kotchasan\Database\Query $db The database query object
     */
    public function initTableName($db)
    {
        $this->db = $db;
        if (empty($this->table)) {
            $class = get_called_class();
            if (preg_match('/[a-z0-9]+\\\\([a-z0-9_]+)\\\\Model/i', $class, $match)) {
                $t = strtolower($match[1]);
            } elseif (preg_match('/Models\\\\([a-z0-9_]+)/i', $class, $match)) {
                $t = strtolower($match[1]);
            } else {
                $t = strtolower($class);
            }
            $this->table_name = $this->getFullTableName($t);
            $this->table_alias = $t;
        } elseif (preg_match('/^([a-z0-9A-Z_]+)(\s+(as|AS))?\s+([a-zA-Z0-9]{1,})$/', $this->table, $match)) {
            $this->table_name = $this->getFullTableName($match[1]);
            $this->table_alias = strlen($match[4]) < 3 ? $match[4] : '`'.$match[4].'`';
        } else {
            $this->table_name = $this->getFullTableName($this->table);
            $this->table_alias = '`'.$this->table.'`';
        }
    }

    /**
     * Insert or update a record
     */
    public function save()
    {
        $rs = new Recordset(get_called_class());
        if ($this->exists) {
            $rs->update(array($this->primaryKey, (int) $this->{$this->primaryKey}), $this);
        } else {
            $rs->insert($this);
        }
    }
}
