<?php
/**
 * @filesource Kotchasan/Database/PdoMysqlDriver.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

/**
 * PDO MySQL Database Adapter Class.
 *
 * This class provides a PDO-based database adapter for MySQL.
 *
 * @see https://www.kotchasan.com/
 */
class PdoMysqlDriver extends Driver
{
    /**
     * Close the database connection.
     */
    public function close()
    {
        $this->connection = null;
    }

    /**
     * Connect to the database.
     *
     * @param mixed $params Connection parameters.
     *
     * @return static
     * @throws \InvalidArgumentException if the database configuration is invalid.
     * @throws \Exception if there's an error connecting to the database.
     */
    public function connect($params)
    {
        $this->options = [
            \PDO::ATTR_STRINGIFY_FETCHES => 0,
            \PDO::ATTR_EMULATE_PREPARES => 0,
            \PDO::ATTR_PERSISTENT => 1,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];

        foreach ($params as $key => $value) {
            $this->{$key} = $value;
        }

        if ($this->settings->dbdriver == 'mysql') {
            $this->options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES '.$this->settings->char_set;
            $this->options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = 1;
        }

        $dsn = $this->settings->dbdriver.':host='.$this->settings->hostname;
        $dsn .= empty($this->settings->port) ? '' : ';port='.$this->settings->port;
        $dsn .= empty($this->settings->dbname) ? '' : ';dbname='.$this->settings->dbname;

        if (isset($this->settings->username) && isset($this->settings->password)) {
            try {
                $this->connection = new \PDO($dsn, $this->settings->username, $this->settings->password, $this->options);

                if (defined('SQL_MODE')) {
                    $this->connection->query("SET SESSION sql_mode='".constant('SQL_MODE')."'");
                }
            } catch (\PDOException $e) {
                throw new \Exception($e->getMessage(), 500, $e);
            }
        } else {
            throw new \InvalidArgumentException('Database configuration is invalid');
        }

        return $this;
    }

    /**
     * Get the number of fields in the query result.
     *
     * @return int
     */
    public function fieldCount()
    {
        if (isset($this->result_id)) {
            return $this->result_id->columnCount();
        } else {
            return 0;
        }
    }

    /**
     * Get the list of fields from the query result.
     *
     * @return array
     */
    public function getFields()
    {
        $fieldList = [];

        for ($i = 0, $c = $this->fieldCount(); $i < $c; ++$i) {
            $result = @$this->result_id->getColumnMeta($i);
            if ($result) {
                $fieldList[$result['name']] = $result;
            }
        }

        return $fieldList;
    }

    /**
     * Insert a new row into a table.
     *
     * @param string $table The name of the table.
     * @param $data  The data to be inserted. Format array('key1'=>'value1', 'key2'=>'value2', ...)
     *
     * @return int|bool The ID of the inserted row or false on failure.
     */
    public function insert($table_name, $save)
    {
        $params = [];
        $sql = $this->makeInsert($table_name, $save, $params);
        try {
            $query = $this->connection->prepare($sql);
            $query->execute($params);
            $this->log('insert', $sql, $params);
            ++self::$query_count;
            return (int) $this->connection->lastInsertId();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500, $e);
        }
    }

    /**
     * Insert a new row into a table or update an existing row if a unique key constraint is violated.
     *
     * @param string $table_name The name of the table.
     * @param array|object $save The data to be inserted or updated.
     *
     * @return int The ID of the inserted row.
     * @throws \Exception if there's an error executing the query.
     */
    public function insertOrUpdate($table_name, $save)
    {
        $updates = [];
        $params = [];

        foreach ($save as $key => $value) {
            $updates[] = '`'.$key.'`=:U'.$key;
            $params[':U'.$key] = $value;
        }

        $sql = $this->makeInsert($table_name, $save, $params);
        $sql .= ' ON DUPLICATE KEY UPDATE '.implode(', ', $updates);

        try {
            $query = $this->connection->prepare($sql);
            $query->execute($params);
            $this->log(__FUNCTION__, $sql);
            ++self::$query_count;
            return (int) $this->connection->lastInsertId();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500, $e);
        }
    }

    /**
     * Generate an SQL query command based on the given query builder parameters.
     *
     * @assert (array('update' => '`user`', 'where' => '`id` = 1', 'set' => array('`id` = 1', "`email` = 'admin@localhost'"))) [==] "UPDATE `user` SET `id` = 1, `email` = 'admin@localhost' WHERE `id` = 1"
     * @assert (array('insert' => '`user`', 'keys' => array('id' => ':id', 'email' => ':email'))) [==] "INSERT INTO `user` (`id`, `email`) VALUES (:id, :email)"
     * @assert (array('insert' => '`user`', 'keys' => array('id' => ':id'), 'orupdate' => array('`id`=VALUES(`id`)'))) [==] "INSERT INTO `user` (`id`) VALUES (:id) ON DUPLICATE KEY UPDATE `id`=VALUES(`id`)"
     * @assert (array('select'=>'*', 'from'=>'`user`','where'=>'`id` = 1', 'order' => '`id`', 'start' => 1, 'limit' => 10, 'join' => array(" INNER JOIN ..."))) [==] "SELECT * FROM `user` INNER JOIN ... WHERE `id` = 1 ORDER BY `id` LIMIT 1,10"
     * @assert (array('select'=>'*', 'from'=>'`user`','where'=>'`id` = 1', 'order' => '`id`', 'start' => 1, 'limit' => 10, 'group' => '`id`')) [==] "SELECT * FROM `user` WHERE `id` = 1 GROUP BY `id` ORDER BY `id` LIMIT 1,10"
     * @assert (array('delete' => '`user`', 'where' => '`id` = 1')) [==] "DELETE FROM `user` WHERE `id` = 1"
     *
     * @param array $sqls The SQL commands from the query builder.
     *
     * @return string The generated SQL command.
     */
    public function makeQuery($sqls)
    {
        if (!empty($sqls['tmptable'])) {
            $sql = 'CREATE TEMPORARY TABLE '.$sqls['tmptable'].' ';
        } elseif (!empty($sqls['view'])) {
            $sql = 'CREATE OR REPLACE VIEW '.$sqls['view'].' AS ';
        } elseif (!empty($sqls['explain'])) {
            $sql = 'EXPLAIN ';
        } else {
            $sql = '';
        }

        if (isset($sqls['insert'])) {
            if (isset($sqls['select'])) {
                $sql .= 'INSERT INTO '.$sqls['insert'];
                if (!empty($sqls['keys'])) {
                    $sql .= ' (`'.implode('`, `', $sqls['keys']).'`)';
                }
                $sql .= ' '.$sqls['select'];
            } else {
                $keys = array_keys($sqls['keys']);
                $sql .= 'INSERT INTO '.$sqls['insert'].' (`'.implode('`, `', $keys);
                $sql .= '`) VALUES ('.implode(', ', $sqls['keys']).')';
            }

            if (isset($sqls['orupdate'])) {
                $sql .= ' ON DUPLICATE KEY UPDATE '.implode(', ', $sqls['orupdate']);
            }
        } else {
            if (isset($sqls['union'])) {
                if (isset($sqls['select'])) {
                    $sql .= 'SELECT '.$sqls['select'].' FROM (('.implode(') UNION (', $sqls['union']).')) AS U9';
                } else {
                    $sql .= '('.implode(') UNION (', $sqls['union']).')';
                }
            } elseif (isset($sqls['unionAll'])) {
                if (isset($sqls['select'])) {
                    $sql .= 'SELECT '.$sqls['select'].' FROM (('.implode(') UNION ALL (', $sqls['unionAll']).')) AS U9';
                } else {
                    $sql .= '('.implode(') UNION ALL (', $sqls['unionAll']).')';
                }
            } else {
                if (isset($sqls['select'])) {
                    $sql .= 'SELECT '.$sqls['select'];
                    if (isset($sqls['from'])) {
                        $sql .= ' FROM '.$sqls['from'];
                    }
                } elseif (isset($sqls['update'])) {
                    $sql .= 'UPDATE '.$sqls['update'];
                } elseif (isset($sqls['delete'])) {
                    $sql .= 'DELETE FROM '.$sqls['delete'];
                }
            }

            if (isset($sqls['join'])) {
                foreach ($sqls['join'] as $join) {
                    $sql .= $join;
                }
            }

            if (isset($sqls['set'])) {
                $sql .= ' SET '.implode(', ', $sqls['set']);
            }

            if (isset($sqls['where'])) {
                $sql .= ' WHERE '.$sqls['where'];

                if (isset($sqls['exists'])) {
                    $sql .= ' AND '.implode(' AND ', $sqls['exists']);
                }
            } elseif (isset($sqls['exists'])) {
                $sql .= ' WHERE '.implode(' AND ', $sqls['exists']);
            }

            if (isset($sqls['group'])) {
                $sql .= ' GROUP BY '.$sqls['group'];
            }

            if (isset($sqls['having'])) {
                $sql .= ' HAVING '.$sqls['having'];
            }

            if (isset($sqls['order'])) {
                $sql .= ' ORDER BY '.$sqls['order'];
            }

            if (isset($sqls['limit'])) {
                $sql .= ' LIMIT '.(empty($sqls['start']) ? '' : $sqls['start'].',').$sqls['limit'];
            }
        }

        return $sql;
    }

    /**
     * Retrieve data from the specified table.
     *
     * @param string $table_name The table name.
     * @param mixed  $condition  The query WHERE condition.
     * @param array  $sort       The sorting criteria.
     * @param int    $limit      The number of data to retrieve.
     *
     * @return array The resulting data in array format. Returns an empty array if unsuccessful.
     */
    public function select($table_name, $condition = [], $sort = [], $limit = 0)
    {
        $values = [];
        $sql = 'SELECT * FROM '.$table_name;

        if (!empty($condition)) {
            $condition = $this->buildWhere($condition);

            if (is_array($condition)) {
                $values = $condition[1];
                $condition = $condition[0];
            }

            $sql .= ' WHERE '.$condition;
        }

        if (!empty($sort)) {
            if (is_string($sort) && preg_match('/^([a-z0-9_]+)\s(asc|desc)$/i', trim($sort), $match)) {
                $sql .= ' ORDER BY `'.$match[1].'`'.(empty($match[2]) ? '' : ' '.$match[2]);
            } elseif (is_array($sort)) {
                $qs = [];

                foreach ($sort as $item) {
                    if (preg_match('/^([a-z0-9_]+)\s(asc|desc)$/i', trim($item), $match)) {
                        $qs[] = '`'.$match[1].'`'.(empty($match[2]) ? '' : ' '.$match[2]);
                    }
                }

                if (count($qs) > 0) {
                    $sql .= ' ORDER BY '.implode(', ', $qs);
                }
            }
        }

        if (is_int($limit) && $limit > 0) {
            $sql .= ' LIMIT '.$limit;
        }

        return $this->doCustomQuery($sql, $values);
    }

    /**
     * Selects a database.
     *
     * @param string $database The name of the database.
     *
     * @return bool Returns true on success, false on failure.
     */
    public function selectDB($database)
    {
        $this->settings->dbname = $database;
        $result = $this->connection->query("USE $database");
        ++self::$query_count;
        return $result === false ? false : true;
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
    public function update($table_name, $condition, $save)
    {
        $sets = [];
        $values = [];

        foreach ($save as $key => $value) {
            if ($value instanceof QueryBuilder) {
                $sets[] = '`'.$key.'` = ('.$value->text().')';
            } elseif ($value instanceof Sql) {
                $sets[] = '`'.$key.'` = '.$value->text();
                $values = $value->getValues($values);
            } else {
                $k = ':'.$key.count($values);
                $sets[] = '`'.$key.'` = '.$k;
                $values[$k] = $value;
            }
        }

        $q = Sql::WHERE($condition);
        $sql = 'UPDATE '.$table_name.' SET '.implode(', ', $sets).' WHERE '.$q->text();
        $values = $q->getValues($values);

        try {
            $query = $this->connection->prepare($sql);
            $query->execute($values);
            $this->log(__FUNCTION__, $sql, $values);
            ++self::$query_count;
            return true;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500, $e);
        }
    }

    /**
     * Executes an SQL query to retrieve data and returns the result as an array of matching records.
     *
     * @param string $sql    The SQL query string.
     * @param array  $values If specified, it will use prepared statements instead of direct query execution.
     *
     * @return array|bool Returns an array of records that match the condition on success, or false on failure.
     */
    protected function doCustomQuery($sql, $values = [])
    {
        $action = $this->cache->getAction();
        if ($action) {
            $cache = $this->cache->init($sql, $values);
            $result = $this->cache->get($cache);
        } else {
            $result = false;
        }
        if (!$result) {
            try {
                if (empty($values)) {
                    $this->result_id = $this->connection->query($sql);
                } else {
                    $this->result_id = $this->connection->prepare($sql);
                    $this->result_id->execute($values);
                }
                ++self::$query_count;
                $result = $this->result_id->fetchAll(\PDO::FETCH_ASSOC);
                if ($action == 1) {
                    $this->cache->save($cache, $result);
                } elseif ($action == 2) {
                    $this->cache_item = $cache;
                }
            } catch (\PDOException $e) {
                throw new \Exception($e->getMessage(), 500, $e);
            }
            $this->log('Database', $sql, $values);
        } else {
            $this->cache->setAction(0);
            $this->cache_item = null;
            $this->log('Cached', $sql, $values);
        }
        return $result;
    }

    /**
     * Executes an SQL query that does not require a result set, such as CREATE, INSERT, or UPDATE statements.
     *
     * @param string $sql    The SQL query string.
     * @param array  $values If specified, it will use prepared statements instead of direct query execution.
     *
     * @return int|bool Returns the number of affected rows on success, or false on failure.
     */
    protected function doQuery($sql, $values = [])
    {
        try {
            if (empty($values)) {
                $query = $this->connection->query($sql);
            } else {
                $query = $this->connection->prepare($sql);
                $query->execute($values);
            }
            ++self::$query_count;
            $this->log(__FUNCTION__, $sql, $values);
            return $query->rowCount();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500, $e);
        }
    }

    /**
     * Generates an SQL INSERT statement for saving data.
     *
     * @param string       $table_name The table name.
     * @param array|object $save       The data to be saved in the format array('key1'=>'value1', 'key2'=>'value2', ...).
     * @param array        $params     An array variable to receive parameter values for execution.
     *
     * @return string Returns the generated INSERT statement.
     */
    private function makeInsert($table_name, $save, &$params)
    {
        $keys = [];
        $values = [];
        foreach ($save as $key => $value) {
            if ($value instanceof QueryBuilder) {
                $keys[] = $key;
                $values[] = '('.$value->text().')';
            } elseif ($value instanceof Sql) {
                $keys[] = $key;
                $values[] = $value->text();
                $params = $value->getValues($params);
            } else {
                $keys[] = $key;
                $values[] = ':'.$key;
                $params[':'.$key] = $value;
            }
        }
        return 'INSERT INTO '.$table_name.' (`'.implode('`,`', $keys).'`) VALUES ('.implode(',', $values).')';
    }
}
