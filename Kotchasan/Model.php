<?php

namespace Kotchasan;

use Kotchasan\QueryBuilder\DeleteBuilder;
use Kotchasan\QueryBuilder\InsertBuilder;
use Kotchasan\QueryBuilder\SelectBuilder;
use Kotchasan\QueryBuilder\UpdateBuilder;

/**
 * Model Class
 *
 * This class serves as the base class for all models in the application.
 * It provides an abstraction over the Database class and QueryBuilders for easier database operations.
 *
 * @package Kotchasan
 */
class Model extends \Kotchasan\KBase
{
    /**
     * The database instance.
     *
     * @var Database
     */
    protected $db;

    /**
     * The name of the database connection to be used.
     *
     * @var string
     */
    protected $conn = 'default';

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->db = Database::create($this->conn);
    }

    /**
     * Create a new query builder instance directly.
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function createQuery()
    {
        $model = new static();
        return $model->getDB()->createQuery();
    }

    /**
     * Backward-compatible factory to create a model instance.
     * Some code calls Model::create() to get a model instance.
     *
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    /**
     * Create a new database connection instance.
     *
     * @return Database
     */
    public static function createDb()
    {
        $model = new static();
        return $model->getDB();
    }

    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    public function beginTransaction()
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit a database transaction.
     *
     * @return bool
     */
    public function commit()
    {
        return $this->db->commit();
    }

    /**
     * Rollback a database transaction.
     *
     * @return bool
     */
    public function rollback()
    {
        return $this->db->rollBack();
    }

    /**
     * Create a SELECT query builder.
     *
     * @param mixed ...$columns The columns to select
     * @return SelectBuilder
     */
    public function select(...$columns)
    {
        // Handle different parameter patterns:
        // select() -> '*'
        // select('col') -> 'col'
        // select('col1', 'col2') -> ['col1', 'col2']
        // select(['col1', 'col2']) -> ['col1', 'col2']

        if (empty($columns)) {
            $columnsToPass = '*';
        } elseif (count($columns) === 1) {
            $columnsToPass = $columns[0];
        } else {
            $columnsToPass = $columns;
        }

        return $this->db->select($columnsToPass);
    }

    /**
     * Create an INSERT query builder.
     *
     * @param string $table The table to insert into
     * @return InsertBuilder
     */
    public function insert(string $table)
    {
        return $this->db->insert($table);
    }

    /**
     * Create an UPDATE query builder.
     *
     * @param string $table The table to update
     * @return UpdateBuilder
     */
    public function update(string $table)
    {
        return $this->db->update($table);
    }

    /**
     * Create a DELETE query builder.
     *
     * @param string $table The table to delete from
     * @return DeleteBuilder
     */
    public function delete(string $table)
    {
        return $this->db->delete($table);
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql The SQL query
     * @param array $params The query parameters
     * @return mixed
     */
    public function raw(string $sql, array $params = [])
    {
        return $this->db->raw($sql, $params);
    }

    /**
     * Get the last inserted ID.
     *
     * @return int|string
     */
    public function lastInsertId()
    {
        return $this->db->lastInsertId();
    }

    /**
     * Get the underlying database instance.
     *
     * @return Database
     */
    public function getDB()
    {
        return $this->db;
    }

    /**
     * Get the configured table name with prefix applied.
     *
     * @param string $table The logical table name
     * @return string The physical table name with prefix
     */
    public function getTableName(string $table): string
    {
        return $this->db->getTableName($table);
    }

    /**
     * Get the configured table prefix.
     *
     * @return string The table prefix (without underscore)
     */
    public function getPrefix(): string
    {
        return $this->db->getPrefix();
    }
}
