<?php
/**
 * @filesource Kotchasan/Orm/Recordset.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Orm;

use Kotchasan\ArrayTool;
use Kotchasan\Database\Query;
use Kotchasan\Database\Schema;

/**
 * Recordset base class.
 *
 * This class provides methods for querying and manipulating database records.
 *
 * @see https://www.kotchasan.com/
 */
class Recordset extends Query implements \Iterator
{
    /**
     * @var array The data records.
     */
    private $datas;

    /**
     * @var Field The field class instance.
     */
    private $field;

    /**
     * @var array The field names.
     */
    private $fields = [];

    /**
     * @var int The starting record for pagination.
     */
    private $firstRecord;

    /**
     * @var int The number of records per page for pagination.
     */
    private $perPage;

    /**
     * @var bool Determines the type of result: true for array, false for model.
     */
    private $toArray = false;

    /**
     * @var array If values are set, it will use prepare instead of execute.
     */
    private $values;

    /**
     * Create a new Recordset instance.
     *
     * @param string $field The name of the Field class.
     */
    public function __construct($field)
    {
        $this->field = new $field();
        parent::__construct($this->field->getConn());
        $this->sqls = [];
        $this->values = [];
        $this->field->initTableName($this->db);
        if (method_exists($this->field, 'getConfig')) {
            foreach ($this->field->getConfig() as $key => $value) {
                $this->buildQuery($key, $value);
            }
        }
    }

    /**
     * Retrieve all records.
     *
     * Executes the SELECT query and returns the result as an array or model objects.
     *
     * @param array|string|null $fields (optional) The fields to select. Default is null (select all fields).
     *
     * @return array|static The result as an array or model objects.
     */
    public function all($fields = null)
    {
        if (!empty($fields)) {
            $qs = [];
            foreach (func_get_args() as $item) {
                if (!empty($item)) {
                    $qs[] = $this->fieldName($item);
                }
            }
            $this->sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
        } elseif (empty($this->sqls['select'])) {
            $this->sqls['select'] = '*';
        }
        return $this->doExecute(0, 0);
    }

    /**
     * Enable caching.
     *
     * Enables caching and specifies whether to automatically save the cache.
     *
     * @param bool $auto_save (optional) Specifies whether to automatically save the cache. Default is true.
     *
     * @return static
     */
    public function cacheOn($auto_save = true)
    {
        $this->cache->cacheOn($auto_save);
        return $this;
    }

    /**
     * Get the record count.
     *
     * Executes a COUNT query and returns the number of records matching the conditions.
     *
     * @return int The number of records matching the conditions.
     */
    public function count()
    {
        $old_sqls = $this->sqls;
        $old_values = $this->values;
        $this->sqls = [];
        $this->sqls['select'] = 'COUNT(*) AS `count`';
        foreach ($old_sqls as $key => $value) {
            if ($key !== 'order' && $key !== 'limit' && $key !== 'select') {
                $this->sqls[$key] = $value;
            }
        }
        $sql = $this->createQuery(0, 0);
        $result = $this->db()->customQuery($sql, true, $this->values);
        $count = empty($result) ? 0 : (int) $result[0]['count'];
        $this->sqls = $old_sqls;
        $this->values = $old_values;
        return $count;
    }

    /**
     * Create a new instance of the Recordset class.
     *
     * @param string $field The name of the Field class.
     *
     * @return static A new instance of the Recordset class.
     */
    public static function create($field)
    {
        return new static($field);
    }

    /**
     * Build the SELECT query string.
     *
     * @param string $key The query key.
     * @param mixed $value The query value.
     */
    public function createQuery($start, $count)
    {
        $this->sqls['from'] = $this->field->getTableWithAlias();
        if (!empty($start) || !empty($count)) {
            $this->sqls['limit'] = $count;
            $this->sqls['start'] = $start;
        }
        return $this->db()->makeQuery($this->sqls);
    }

    /**
     * Create a view based on the specified table.
     *
     * @param string $table The name of the table.
     *
     * @return bool True if the view is created successfully, false otherwise.
     */
    public function createView($table)
    {
        $this->sqls['view'] = $this->field->getFullTableName($table);
        return $this;
    }

    /**
     * Create a temporary table.
     *
     * @param string $table The table name.
     *
     * @return static
     */
    public function createTmpTable($table)
    {
        $this->sqls['tmptable'] = $this->field->getFullTableName($table);
        return $this;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        $var = \current($this->datas);
        return $var;
    }

    /**
     * Execute a custom SQL query to retrieve data.
     * Returns the result as an array or object based on the $toArray parameter.
     *
     * @param string $sql     The query string.
     * @param bool   $toArray (optional) True to return the result as an array, false to return as an object. Default is true.
     * @param array  $values  (optional) If specified, it will use prepared statements instead of direct query execution.
     *
     * @return array|object The result data.
     */
    public function customQuery($sql, $toArray = true, $values = [])
    {
        return $this->db()->customQuery($sql, $toArray, $values);
    }

    /**
     * Delete records based on the specified condition.
     * Returns true if successful.
     *
     * @param mixed  $condition The condition for deletion. It can be an int (primaryKey), string (SQL QUERY), or array.
     * @param bool   $all       (optional) If false (default), delete a single record. If true, delete all records that match the condition.
     * @param string $operator  (optional) The operator to join each $condition. Use 'AND' (default) or 'OR'.
     *
     * @return bool True if the deletion is successful, false otherwise.
     */
    public function delete($condition = [], $all = false, $operator = 'AND')
    {
        $ret = $this->buildWhereValues($condition, $operator, $this->field->getPrimarykey());
        $sqls = array(
            'delete' => $this->field->table_name,
            'where' => $ret[0]
        );
        if (!$all) {
            $sqls['limit'] = 1;
        }
        $sql = $this->db()->makeQuery($sqls);
        return $this->db()->query($sql, $ret[1]);
    }

    /**
     * Function to delete all data in the table.
     * Returns true if successful.
     *
     * @return bool True if the operation is successful, false otherwise.
     */
    public function emptyTable()
    {
        return $this->db()->emptyTable($this->field->table_name);
    }

    /**
     * Execute a query with pagination.
     * SELECT ...
     *
     * @param array|string $fields (optional) If null, SELECT all fields. Otherwise, specify the fields to select.
     *
     * @return array|\static
     */
    public function execute($fields = null)
    {
        if (!empty($fields)) {
            $qs = [];
            foreach (func_get_args() as $item) {
                if (!empty($item)) {
                    $qs[] = $this->fieldName($item);
                }
            }
            $this->sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
        } elseif (empty($this->sqls['select'])) {
            $this->sqls['select'] = '*';
        }
        return $this->doExecute($this->firstRecord, $this->perPage);
    }

    /**
     * Check if a field exists.
     * Returns true if the field exists, false otherwise.
     *
     * @param string $field The field name.
     *
     * @return bool True if the field exists, false otherwise.
     */
    public function fieldExists($field)
    {
        if (empty($this->fields)) {
            $this->fields = Schema::create($this->db())->fields($this->field->table_name);
        }
        return in_array($field, $this->fields);
    }

    /**
     * Retrieve a single record by $primaryKey.
     *
     * @param int $id The ID of the record to retrieve.
     *
     * @return Field The record of the specified ID.
     */
    public function find($id)
    {
        return $this->where((int) $id)->first();
    }

    /**
     * Query for a single record.
     * Returns false if not found, the record data if found.
     * SELECT .... LIMIT 1
     *
     * @param array|string $fields (optional) If null, SELECT all fields. Otherwise, specify the fields to select.
     *
     * @return bool|array|Field False if not found, the record data if found.
     */
    public function first($fields = null)
    {
        $sqls = array(
            'from' => $this->field->getTableWithAlias(),
            'limit' => 1
        );
        if (!empty($fields)) {
            $qs = [];
            foreach (func_get_args() as $item) {
                if (!empty($item)) {
                    $qs[] = $this->fieldName($item);
                }
            }
            $sqls['select'] = empty($qs) ? '*' : implode(', ', $qs);
        } elseif (empty($this->sqls['select'])) {
            $sqls['select'] = '*';
        }
        $sqls = ArrayTool::replace($this->sqls, $sqls);
        $sql = $this->db()->makeQuery($sqls);
        $this->datas = $this->db()->customQuery($sql, true, $this->values);
        if (empty($this->datas)) {
            return false;
        } elseif ($this->toArray) {
            return $this->datas[0];
        } else {
            $class = get_class($this->field);
            return new $class($this->datas[0]);
        }
    }

    /**
     * Get the Field object of the Recordset.
     *
     * @return Field The Field object.
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * Get all the field names of the Model.
     *
     * @return array The array of field names.
     */
    public function getFields()
    {
        if (empty($this->datas)) {
            $this->first();
        }
        return $this->db()->getFields();
    }

    /**
     * Get the values for execution.
     *
     * @return array The values for execution.
     */
    public function getValues()
    {
        return $this->values;
    }

    /**
     * Function for grouping commands and connecting each group with an operator.
     * Returns the grouped query within ().
     *
     * @param array  $params The commands in the format array('field1', 'condition', 'field2').
     * @param string $operator The operator (AND or OR).
     *
     * @return string The grouped query.
     */
    public function group($params, $operator = 'AND')
    {
        switch (strtoupper($operator)) {
            case 'AND':
                return $this->groupAnd($params);
                break;
            case 'OR':
                return $this->groupOr($params);
                break;
        }
    }

    /**
     * Insert data.
     * Returns the ID of the inserted data on success, false on failure.
     *
     * @param Field $field The field object to be inserted.
     *
     * @return int|bool The ID of the inserted data if successful, false if an error occurred.
     */
    public function insert(Field $field)
    {
        $save = [];
        foreach (Schema::create($this->db())->fields($this->field->table_name) as $item) {
            if (isset($field->$item)) {
                $save[$item] = $field->$item;
            }
        }
        if (empty($save)) {
            $result = false;
        } else {
            $result = $this->db()->insert($this->field->table_name, $save);
        }
        return $result;
    }

    /**
     * INNER JOIN table ON ...
     *
     * @param string $field The field class of the table to join.
     * @param string $type The type of join (e.g., LEFT, RIGHT, INNER...).
     * @param mixed  $on The join condition.
     *
     * @return static
     */
    public function join($field, $type, $on)
    {
        return $this->doJoin($field, $type, $on);
    }

    /**
     * Inherited from Iterator.
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        $var = key($this->datas);
        return $var;
    }

    /**
     * Limit the number of results and specify the starting record.
     *
     * @param int $count The number of results to limit.
     * @param int $start The starting record.
     *
     * @return static
     */
    public function limit($count, $start = 0)
    {
        if (!empty($start)) {
            $this->sqls['start'] = (int) $start;
        }
        $this->sqls['limit'] = (int) $count;
        return $this;
    }

    /**
     * Inherited from Iterator.
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $var = next($this->datas);
        return $var;
    }

    /**
     * Create a query with sorting.
     *
     * @param mixed $sorts An array of sort orders ('field ASC', 'field DESC') or multiple arguments 'field ASC', 'field DESC', ...
     *
     * @return static
     */
    public function order($sorts)
    {
        $sorts = is_array($sorts) ? $sorts : func_get_args();
        $ret = $this->buildOrder($sorts);
        if (!empty($ret)) {
            $this->sqls['order'] = $ret;
        }
        return $this;
    }

    /**
     * Process SQL statements that do not require a result, such as CREATE, INSERT, and UPDATE.
     * Returns true on success, false on failure.
     *
     * @param string $sql The SQL statement to be processed.
     * @param array  $values If specified, it forces the use of prepared statements instead of query.
     *
     * @return bool True if successful, false if an error occurred.
     */
    public function query($sql, $values = [])
    {
        $this->db()->query($sql, $values);
    }

    /**
     * Get the total number of records queried.
     *
     * @return int The total number of records.
     */
    public function recordCount()
    {
        return count($this->datas);
    }

    /**
     * Inherited from Iterator.
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        reset($this->datas);
    }

    /**
     * Limit the number of results.
     * LIMIT $start, $count.
     *
     * @param int $start The starting index.
     * @param int $count The number of results to retrieve.
     *
     * @return static
     */
    public function take()
    {
        $count = func_num_args();
        if ($count == 1) {
            $this->perPage = (int) func_get_arg(0);
            $this->firstRecord = 0;
        } elseif ($count == 2) {
            $this->perPage = (int) func_get_arg(1);
            $this->firstRecord = (int) func_get_arg(0);
        }
        return $this;
    }

    /**
     * Retrieve data as an array.
     * This function should be called before querying data.
     *
     * @return static
     */
    public function toArray()
    {
        $this->toArray = true;
        return $this;
    }

    /**
     * Export the database query as a QueryBuilder instance.
     *
     * @return \Kotchasan\Database\QueryBuilder The QueryBuilder instance.
     */
    public function toQueryBuilder()
    {
        return $this->db()->createQuery()->assignment($this);
    }

    /**
     * Update data.
     *
     * @param array       $condition The condition for the update.
     * @param array|Field $save The data to be saved.
     *
     * @return bool True if successful, false if an error occurred.
     */
    public function update($condition, $save)
    {
        $db = $this->db();
        $schema = Schema::create($db);
        $datas = [];
        if ($save instanceof Field) {
            foreach ($schema->fields($this->field->table_name) as $field) {
                if (isset($save->$field)) {
                    $datas[$field] = $save->$field;
                }
            }
        } else {
            foreach ($schema->fields($this->field->table_name) as $field) {
                if (isset($save[$field])) {
                    $datas[$field] = $save[$field];
                }
            }
        }
        if (empty($datas)) {
            $result = false;
        } else {
            $result = $db->update($this->field->table_name, $condition, $datas);
            if ($db->cacheGetAction() == 1) {
                $db->cacheSave($datas);
            }
        }
        return $result;
    }

    /**
     * Update data for all records.
     *
     * @param array $save The data to be saved (array('key1' => 'value1', 'key2' => 'value2', ...)).
     *
     * @return bool True if successful, false if an error occurred.
     */
    public function updateAll($save)
    {
        return $this->db()->updateAll($this->field->table_name, $save);
    }

    /**
     * Determine if there are more records to iterate over.
     *
     * @return mixed The validity of the current record.
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        $key = key($this->datas);
        $var = ($key !== null && $key !== false);
        return $var;
    }

    /**
     * Add a WHERE clause to the query.
     *
     * @param mixed  $where The condition(s) for the WHERE clause.
     * @param string $operator (optional) The operator to use for multiple conditions (AND by default).
     *
     * @return static The updated Recordset instance.
     */
    public function where($where = [], $operator = 'AND')
    {
        if (is_int($where) || (is_string($where) && $where != '') || (is_array($where) && !empty($where))) {
            $where = $this->buildWhere($where, $operator, $this->field->table_alias.'.'.$this->field->getPrimarykey());
            if (is_array($where)) {
                $this->values = ArrayTool::replace($this->values, $where[1]);
                $where = $where[0];
            }
            $this->sqls['where'] = $where;
        }
        return $this;
    }

    /**
     * Build a query based on the provided configuration.
     *
     * @param string $method The query method to build.
     * @param mixed  $param The parameters for the query.
     */
    private function buildQuery($method, $param)
    {
        if ($method == 'join') {
            foreach ($param as $item) {
                $this->doJoin($item[1], $item[0], $item[2]);
            }
        } else {
            $func = 'build'.ucfirst($method);
            if (method_exists($this, $func)) {
                $ret = $this->{$func}($param);
                if (is_array($ret)) {
                    $this->sqls[$method] = $ret[0];
                    $this->values = ArrayTool::replace($this->values, $ret[1]);
                } else {
                    $this->sqls[$method] = $ret;
                }
            }
        }
    }

    /**
     * Execute a query with pagination.
     *
     * @param int $start The start index of the query results.
     * @param int $end The end index of the query results.
     *
     * @return array|static The query results or the updated Recordset instance.
     */
    private function doExecute($start, $end)
    {
        $sql = $this->createQuery($start, $end);
        $result = $this->db()->customQuery($sql, true, $this->values);
        if ($this->toArray) {
            return $result;
        } else {
            $class = get_class($this->field);
            $this->datas = [];
            foreach ($result as $item) {
                $this->datas[] = new $class($item);
            }
            return $this;
        }
    }

    /**
     * Perform a JOIN operation on the query.
     *
     * @param string $field The name of the field or table to join.
     * @param string $type The type of join operation (e.g., INNER, LEFT, RIGHT).
     * @param string|array $on The join condition(s).
     *
     * @return static The updated Recordset instance.
     */
    private function doJoin($field, $type, $on)
    {
        if (preg_match('/^([a-zA-Z0-9\\\\]+)(\s+(as|AS))?[\s]+([A-Z0-9]{1,2})?$/', $field, $match)) {
            $field = $match[1];
        }
        $rs = new self($field);
        $table = $rs->field->getTableWithAlias(isset($match[4]) ? $match[4] : null);
        $ret = $rs->buildJoin($table, $type, $on);
        if (is_array($ret)) {
            $this->sqls['join'][] = $ret[0];
            $this->values = ArrayTool::replace($this->values, $ret[1]);
        } else {
            $this->sqls['join'][] = $ret;
        }
        return $this;
    }
}
