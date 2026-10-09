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
    public static function createDatabase()
    {
        $model = new static();
        return $model->getDB();
    }

    /**
     * Create a DB helper bound to this model connection.
     *
     * Use this for direct insert/update/delete helper methods while keeping
     * the connection tied to the model's conn setting.
     *
     * @return \Kotchasan\DB
     */
    public static function createDB()
    {
        $db = static::createDatabase();
        return \Kotchasan\DB::create($db);
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

    /**
     * Physical column names of a (logical) table, via SHOW COLUMNS.
     *
     * Used by CRUD models to restrict writes to columns that actually exist
     * on a table whose CREATE TABLE is not always shipped in-repo (e.g.
     * central/cross-connection tables). Returns an empty array if the table
     * is unreachable so callers can fail closed.
     *
     * Runs against this model's own connection (static::createDB()), so a
     * subclass bound to a non-default connection (e.g. `wsr`) resolves
     * columns against that connection, not whichever is `default`.
     *
     * @param string $logicalTable
     *
     * @return array
     */
    protected static function tableColumns($logicalTable)
    {
        try {
            $db = static::createDB();
            $name = $db->getTableName($logicalTable);
            $result = $db->raw('SHOW COLUMNS FROM `'.$name.'`');

            if ($result === null) {
                return [];
            }

            $columns = [];
            foreach ($result->fetchAll() as $row) {
                $field = is_object($row) ? ($row->Field ?? $row->field ?? null) : ($row['Field'] ?? $row['field'] ?? null);
                if (is_string($field) && $field !== '') {
                    $columns[] = $field;
                }
            }

            return $columns;
        } catch (\Exception $e) {
            return [];
        }
    }
}
