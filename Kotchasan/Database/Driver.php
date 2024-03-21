<?php
/**
 * @filesource Kotchasan/Database/Driver.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

use Kotchasan\ArrayTool;
use Kotchasan\Cache\CacheItem as Item;
use Kotchasan\Database\DbCache as Cache;
use Kotchasan\Log\Logger;
use Kotchasan\Text;

/**
 * Kotchasan Database driver Class (base class)
 *
 * @see https://www.kotchasan.com/
 */
abstract class Driver extends Query
{
    /**
     * @var Cache cache class
     */
    protected $cache;

    /**
     * @var Item Cacheitem
     */
    protected $cache_item;

    /**
     * @var object database connection
     */
    protected $connection = null;

    /**
     * @var string database error message
     */
    protected $error_message = '';

    /**
     * @var int number of queries
     */
    protected static $query_count = 0;

    /**
     * @var resource|object result object from query
     */
    protected $result_id;

    /**
     * @var array query statements for execution
     */
    protected $sqls;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->cache = Cache::create();
    }

    /**
     * Get the cache action.
     *
     * Returns the cache action status:
     * - 0: Cache is not used.
     * - 1: Load and automatically save cache.
     * - 2: Load data from cache, but do not automatically save cache.
     *
     * @return int The cache action status
     */
    public function cacheGetAction()
    {
        return $this->cache->getAction();
    }

    /**
     * Enable caching.
     *
     * @param bool $auto_save (optional) Whether to automatically save the cache results (default: true)
     *
     * @return static
     */
    public function cacheOn($auto_save = true)
    {
        $this->cache->cacheOn($auto_save);
        return $this;
    }

    /**
     * Save cache data.
     *
     * @param array $datas The data to be saved
     *
     * @return bool True if the cache is saved successfully, false otherwise
     */
    public function cacheSave($datas)
    {
        if ($this->cache_item instanceof Item) {
            return $this->cache->save($this->cache_item, $datas);
        }
        return false;
    }

    /**
     * Close the database connection.
     */
    public function close()
    {
        $this->connection = null;
    }

    /**
     * Get the current database connection.
     *
     * @return resource The database connection
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * Create a new query builder instance.
     *
     * @return \Kotchasan\Database\QueryBuilder The query builder instance
     */
    public function createQuery()
    {
        return new QueryBuilder($this);
    }

    /**
     * Process an SQL query command to retrieve data.
     *
     * Returns an array of records that match the conditions.
     * If no records are found, an empty array is returned.
     *
     * @param string $sql     The query string
     * @param bool   $toArray Optional. Default is false. Set to true to return results as an array, otherwise returns results as objects.
     * @param array  $values  Optional. If specified, prepares the query using these values instead of executing the query directly.
     *
     * @return array An array of records that match the conditions
     */
    public function customQuery($sql, $toArray = false, $values = [])
    {
        $result = $this->doCustomQuery($sql, $values);
        if ($result && !$toArray) {
            foreach ($result as $i => $item) {
                $result[$i] = (object) $item;
            }
        }
        return $result;
    }

    /**
     * Checks if a database exists.
     *
     * @param string $database The name of the database to check
     *
     * @return bool Returns true if the database exists, false otherwise
     */
    public function databaseExists($database)
    {
        $search = $this->doCustomQuery("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$database'");
        return $search && count($search) == 1;
    }

    /**
     * Deletes records from a table based on the given condition.
     *
     * @param string       $table_name The name of the table to delete records from
     * @param array|string $condition  The condition for deleting records (can be an array or a string)
     * @param int          $limit      Optional. The maximum number of records to delete (default: 1)
     * @param string       $operator   Optional. The operator used to combine multiple conditions (default: 'AND')
     *
     * @return bool Returns true if the delete operation is successful, false otherwise
     */
    public function delete($table_name, $condition, $limit = 1, $operator = 'AND')
    {
        $condition = $this->buildWhere($condition, $operator);
        if (is_array($condition)) {
            $values = $condition[1];
            $condition = $condition[0];
        } else {
            $values = [];
        }
        $sql = 'DELETE FROM '.$table_name.' WHERE '.$condition;
        if (is_int($limit) && $limit > 0) {
            $sql .= ' LIMIT '.$limit;
        }
        return $this->doQuery($sql, $values);
    }

    /**
     * Empties a table by deleting all its records.
     *
     * @param string $table_name The name of the table to empty
     *
     * @return bool Returns true if the table is successfully emptied, false otherwise
     */
    public function emptyTable($table_name)
    {
        return $this->query("TRUNCATE TABLE $table_name") === false ? false : true;
    }

    /**
     * Executes one or multiple SQL queries and returns the result.
     *
     * @param mixed  $sqls     The SQL query or an array of SQL queries to execute
     * @param array  $values   An array of parameter values to bind to the query (optional)
     * @param int    $debugger Debugging mode: 0 - disabled, 1 - echo debug info, 2 - collect debug info (optional)
     *
     * @return mixed Returns the result of the executed query/queries
     */
    public function execQuery($sqls, $values = [], $debugger = 0)
    {
        $sql = $this->makeQuery($sqls);

        if (isset($sqls['values'])) {
            $values = ArrayTool::replace($sqls['values'], $values);
        }

        if ($debugger > 0) {
            $debug = debug_backtrace();
            $line = $debug[2]['file'].' on line '.$debug[2]['line'];

            if ($debugger == 1) {
                echo $line."\n".$sql."\n";
                if (!empty($values)) {
                    echo var_export($values, true)."\n";
                }
            } elseif ($debugger == 2) {
                if (\Kotchasan::$debugger === null) {
                    \Kotchasan::$debugger = [];
                    register_shutdown_function('doShutdown');
                }
                \Kotchasan::$debugger[] = '"'.$line.'"';
                \Kotchasan::$debugger[] = '"'.str_replace(array('/', '"'), array('\/', '\"'), $sql).'"';
                if (!empty($values)) {
                    \Kotchasan::$debugger[] = json_encode($values);
                }
            }
        }

        if ($sqls['function'] == 'customQuery') {
            $result = $this->customQuery($sql, true, $values);
        } else {
            $result = $this->query($sql, $values);
        }

        return $result;
    }

    /**
     * Returns the number of fields in the query result.
     *
     * @return int The number of fields
     */
    abstract public function fieldCount();

    /**
     * Checks if a column exists in the table.
     *
     * @param string $table_name  The table name
     * @param string $column_name The column name
     *
     * @return bool True if the column exists, false otherwise
     */
    public function fieldExists($table_name, $column_name)
    {
        $result = $this->customQuery("SHOW COLUMNS FROM `$table_name` LIKE '$column_name'");
        return !empty($result);
    }

    /**
     * Queries data and returns all items matching the condition.
     *
     * @param string $table_name The table name
     * @param mixed  $condition  The query WHERE condition
     * @param array  $sort       Sorting options
     *
     * @return array An array of objects representing the retrieved data, or an empty array if not found
     */
    public function find($table_name, $condition, $sort = [])
    {
        $result = [];
        foreach ($this->select($table_name, $condition, $sort) as $item) {
            $result[] = (object) $item;
        }
        return $result;
    }

    /**
     * Queries data and returns the first item matching the condition.
     *
     * @param string $table_name The table name
     * @param mixed  $condition  The query WHERE condition
     *
     * @return mixed An object representing the retrieved data, or false if not found
     */
    public function first($table_name, $condition)
    {
        $result = $this->select($table_name, $condition, [], 1);
        return count($result) == 1 ? (object) $result[0] : false;
    }

    /**
     * Returns the error message of the database.
     *
     * @return string The error message
     */
    public function getError()
    {
        return $this->error_message;
    }

    /**
     * Returns the list of all fields from the query result.
     *
     * @return array An array containing the names of all fields
     */
    abstract public function getFields();

    /**
     * Gets the next ID for the specified table based on the primary key.
     *
     * @param string $table_name The name of the table.
     * @param array $condition An array of conditions for the query (default is empty).
     * @param string $operator The logical operator for combining conditions (default is 'AND').
     * @param string $primary_key The primary key column name (default is 'id').
     *
     * @return int The next ID for the specified table.
     */
    public function getNextId($table_name, $condition = [], $operator = 'AND', $primary_key = 'id')
    {
        $sql = "SELECT MAX(`$primary_key`) AS `Auto_increment` FROM `$table_name`";
        $values = [];
        if (!empty($condition)) {
            $condition = $this->buildWhere($condition, $operator);
            if (is_array($condition)) {
                $values = $condition[1];
                $condition = $condition[0];
            }
            $sql .= ' WHERE '.$condition;
        }
        $result = $this->doCustomQuery($sql, $values);
        return (int) $result[0]['Auto_increment'] + 1;
    }

    /**
     * Check if an index exists in a table.
     *
     * @param string $database_name The database name.
     * @param string $table_name    The table name.
     * @param string $index         The index name.
     *
     * @return bool Returns true if the index exists, false otherwise.
     */
    public function indexExists($database_name, $table_name, $index)
    {
        $result = $this->customQuery("SELECT * FROM information_schema.statistics WHERE table_schema='$database_name' AND table_name = '$table_name' AND column_name = '$index'");
        return empty($result) ? false : true;
    }

    /**
     * Insert new data into a table.
     *
     * @param string $table_name The table name.
     * @param array  $save       The data to be saved.
     *
     * @return int|bool Returns the ID of the inserted data if successful, or false if an error occurs.
     */
    abstract public function insert($table_name, $save);

    /**
     * Insert new data into a table or update existing data if a unique key constraint is violated.
     *
     * @param string       $table_name The table name.
     * @param array|object $save       The data to be saved in the format array('key1'=>'value1', 'key2'=>'value2', ...).
     *
     * @return int Returns the ID of the inserted data, 0 if an update occurred, or null if an error occurs.
     * @throws \Exception if there's an error executing the query.
     */
    abstract public function insertOrUpdate($table_name, $save);

    /**
     * Generate an SQL query command.
     *
     * @param array $sqls The SQL commands from the query builder.
     *
     * @return string Returns the SQL command.
     */
    abstract public function makeQuery($sqls);

    /**
     * Execute an SQL query for retrieving data.
     *
     * @param string $sql    The SQL query string.
     * @param array  $values If specified, it will use prepared statements instead of direct query execution.
     *
     * @return array|bool Returns an array of records that match the condition on success, or false on failure.
     */
    abstract protected function doCustomQuery($sql, $values = []);

    /**
     * Execute an SQL query that does not require a result, such as CREATE, INSERT, or UPDATE.
     *
     * @param string $sql    The SQL query string.
     * @param array  $values If specified, it will use prepared statements instead of direct query execution.
     *
     * @return int|bool Returns the number of affected rows on success, or false on failure.
     */
    abstract protected function doQuery($sql, $values = []);

    /**
     * Optimize a table.
     *
     * @param string $table_name The table name.
     *
     * @return bool Returns true if successful.
     */
    public function optimizeTable($table_name)
    {
        return $this->query("OPTIMIZE TABLE $table_name") === false ? false : true;
    }

    /**
     * Execute an SQL query that does not require a result, such as CREATE, INSERT, or UPDATE.
     *
     * @param string $sql    The query string.
     * @param array  $values If specified, it will use prepared statements instead of directly querying the database.
     *
     * @return bool Returns true if successful, or false if an error occurs.
     */
    public function query($sql, $values = [])
    {
        return $this->doQuery($sql, $values);
    }

    /**
     * Get the total count of executed SQL queries.
     *
     * @return int
     */
    public static function queryCount()
    {
        return self::$query_count;
    }

    /**
     * Repair a table.
     *
     * @param string $table_name The table name.
     *
     * @return bool Returns true if successful.
     */
    public function repairTable($table_name)
    {
        return $this->query("REPAIR TABLE $table_name") === false ? false : true;
    }

    /**
     * Retrieve data from a table.
     *
     * @param string $table_name The table name.
     * @param mixed  $condition  The query WHERE condition.
     * @param array  $sort       The sorting criteria.
     * @param int    $limit      The number of data to retrieve.
     *
     * @return array The resulting data in array format. Returns an empty array if unsuccessful.
     */
    abstract public function select($table_name, $condition = [], $sort = [], $limit = 0);

    /**
     * Selects a database.
     *
     * @param string $database The name of the database.
     *
     * @return bool Returns true on success, false on failure.
     */
    abstract public function selectDB($database);

    /**
     * Check if a table exists.
     *
     * @param string $table_name The table name.
     *
     * @return bool Returns true if the table exists, false otherwise.
     */
    public function tableExists($table_name)
    {
        $result = $this->doCustomQuery("SHOW TABLES LIKE '$table_name'");
        return empty($result) ? false : true;
    }

    /**
     * Updates data in the specified table.
     *
     * @param string       $table_name The table name.
     * @param mixed        $condition  The query WHERE condition.
     * @param array|object $save       The data to be saved in the format array('key1'=>'value1', 'key2'=>'value2', ...)
     *
     * @return bool Returns true on success, false on failure.
     */
    abstract public function update($table_name, $condition, $save);

    /**
     * Update data for all records in a table.
     * Returns true if successful, false otherwise.
     *
     * @param string $table_name The table name.
     * @param array  $save       The data to be saved in the format array('key1'=>'value1', 'key2'=>'value2', ...).
     *
     * @return bool
     */
    public function updateAll($table_name, $save)
    {
        return $this->update($table_name, array(1, 1), $save);
    }

    /**
     * Log the SQL query.
     *
     * @param string $type    The type of query.
     * @param string $sql     The SQL query string.
     * @param array  $values  (Optional) The values for prepared statements.
     */
    protected function log($type, $sql, $values = [])
    {
        if (DB_LOG == true) {
            $datas = array('<b>'.$type.' :</b> '.Text::replace($sql, $values));
            foreach (debug_backtrace() as $a => $item) {
                if (isset($item['file']) && isset($item['line'])) {
                    if ($item['function'] == 'all' || $item['function'] == 'first' || $item['function'] == 'count' || $item['function'] == 'save' || $item['function'] == 'find' || $item['function'] == 'execute') {
                        $datas[] = '<br>['.$a.'] <b>'.$item['function'].'</b> in <b>'.$item['file'].'</b> line <b>'.$item['line'].'</b>';
                        break;
                    }
                }
            }
            // Log the data
            Logger::create()->info(implode('', $datas));
        }
    }
}
