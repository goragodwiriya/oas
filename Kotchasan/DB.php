<?php
namespace Kotchasan;

/**
 * Database Utility Class
 *
 * Provides convenient, short methods for common database operations.
 * This class is a wrapper around the QueryBuilder system that provides
 * simple, one-line methods for frequent database tasks.
 *
 * Usage:
 *   $db = DB::create();
 *   $db->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
 *   $db->update('users', ['name' => 'Jane'], ['id' => 1]);
 *   $db->emptyTable('cache');
 *
 * @package Kotchasan
 */
class DB
{
    /**
     * The underlying Database instance.
     *
     * @var Database
     */
    private Database $database;

    /**
     * Constructor.
     *
     * @param Database|null $database Optional Database instance
     */
    public function __construct(?Database $database = null)
    {
        $this->database = $database ?: Database::create();
    }

    /**
     * Create a new DB utility instance.
     *
     * @param Database|null $database Optional Database instance
     * @return static
     */
    public static function create(?Database $database = null): self
    {
        return new static($database);
    }

    /**
     * Insert a record into a table.
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value pairs
     * @return int|null The inserted record ID (for auto-increment columns) or null
     * @throws \Exception If the insert fails
     */
    public function insert(string $table, array $data): ?int
    {
        $builder = $this->database->createQuery()->insert($table)->values($data);
        $result = $builder->execute();

        // Get last insert ID from the database
        if ($result) {
            try {
                $id = $this->database->lastInsertId();
                return $id ? (int) $id : null;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Update records in a table.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions (same format as QueryBuilder)
     * @param array $data Associative array of column => value pairs to update
     * @return int Number of affected rows
     * @throws \Exception If the update fails
     */
    public function update(string $table, array $where, array $data): int
    {
        $result = $this->database->createQuery()
            ->update($table)
            ->set($data)
            ->where($where)
            ->execute();

        return $result ? $result->rowCount() : 0;
    }

    /**
     * Delete records from a table.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions (same format as QueryBuilder)
     * @param int $limit Number of rows to delete (default: 1)
     * @param string $operator Logical operator for WHERE conditions (default: 'AND')
     * @return int Number of affected rows
     * @throws \Exception If the delete fails
     */
    public function delete(string $table, array $where = [], int $limit = 1, string $operator = 'AND'): int
    {
        $result = $this->database->createQuery()
            ->delete($table)
            ->where($where, $operator)
            ->limit($limit)
            ->execute();

        return $result ? $result->rowCount() : 0;
    }

    /**
     * Select records from a table.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions (same format as QueryBuilder)
     * @param array $options Additional options:
     *                       - orderBy: string|array Column(s) to order by
     *                       - limit: int Number of rows
     *                       - offset: int Offset for pagination
     *                       - cache: bool Enable caching (default: false)
     *                       - cacheTtl: int Cache TTL in seconds (default: 3600)
     * @param array $columns Columns to select (default: ['*'])
     * @return array Array of results
     * @throws \Exception If the query fails
     */
    public function select(string $table, array $where = [], array $options = [], array $columns = ['*']): array
    {
        $builder = $this->database->createQuery()
            ->select(...$columns)
            ->from($table)
            ->where($where);

        // Add ORDER BY
        if (!empty($options['orderBy'])) {
            if (is_array($options['orderBy'])) {
                foreach ($options['orderBy'] as $column => $direction) {
                    $builder->orderBy($column, $direction);
                }
            } else {
                $builder->orderBy($options['orderBy']);
            }
        }

        // Add LIMIT
        if (!empty($options['limit'])) {
            $offset = $options['offset'] ?? 0;
            $builder->limit($options['limit'], $offset);
        }

        // Enable caching if requested
        if (!empty($options['cache'])) {
            $ttl = $options['cacheTtl'] ?? 3600;
            $builder->cacheOn(true, $ttl);
        }

        $result = $builder->execute();

        return $result ? $result->fetchAll() : [];
    }

    /**
     * Find a single record by conditions.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions
     * @param array $columns Columns to select (default: ['*'])
     * @param bool $cache Enable caching (default: false)
     * @param int $cacheTtl Cache TTL in seconds (default: 3600)
     * @return object|null Single record or null if not found
     * @throws \Exception If the query fails
     */
    public function first(string $table, array $where = [], array $columns = ['*'], bool $cache = false, int $cacheTtl = 3600): ?object
    {
        $options = ['limit' => 1];
        if ($cache) {
            $options['cache'] = true;
            $options['cacheTtl'] = $cacheTtl;
        }

        $results = $this->select($table, $where, $options, $columns);

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Check if a record exists.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions
     * @param bool $cache Enable caching (default: false)
     * @param int $cacheTtl Cache TTL in seconds (default: 3600)
     * @return bool True if record exists, false otherwise
     * @throws \Exception If the query fails
     */
    public function exists(string $table, array $where = [], bool $cache = false, int $cacheTtl = 3600): bool
    {
        $result = $this->first($table, $where, ['1 as _exists'], $cache, $cacheTtl);

        return !empty($result);
    }

    /**
     * Count records in a table.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions (optional)
     * @param bool $cache Enable caching (default: false)
     * @param int $cacheTtl Cache TTL in seconds (default: 3600)
     * @return int Number of records
     * @throws \Exception If the query fails
     */
    public function count(string $table, array $where = [], bool $cache = false, int $cacheTtl = 3600): int
    {
        $builder = $this->database->createQuery()
            ->select(['COUNT(*) as count'])
            ->from($table)
            ->where($where);

        if ($cache) {
            $builder->cacheOn(true, $cacheTtl);
        }

        $result = $builder->execute();

        if ($result) {
            $row = $result->fetch();
            return (int) ($row->count ?? 0);
        }

        return 0;
    }

    /**
     * Get a single scalar value from a table.
     *
     * @param string $table Table name
     * @param string $column Column name or expression
     * @param array $where WHERE conditions
     * @param mixed $default Default value if not found
     * @param bool $cache Enable caching (default: false)
     * @param int $cacheTtl Cache TTL in seconds (default: 3600)
     * @return mixed
     */
    public function value(string $table, string $column, array $where = [], $default = null, bool $cache = false, int $cacheTtl = 3600)
    {
        $row = $this->first($table, $where, [$column], $cache, $cacheTtl);
        if (!$row) {
            return $default;
        }

        $key = $this->resolveColumnAlias($column);
        return $row->$key ?? $default;
    }

    /**
     * Get an array of values from a single column.
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param array $where WHERE conditions
     * @param array $options Additional options (orderBy, limit, offset, cache, cacheTtl)
     * @return array
     */
    public function pluck(string $table, string $column, array $where = [], array $options = []): array
    {
        $rows = $this->select($table, $where, $options, [$column]);
        $key = $this->resolveColumnAlias($column);
        $values = [];
        foreach ($rows as $row) {
            if (isset($row->$key)) {
                $values[] = $row->$key;
            }
        }
        return $values;
    }

    /**
     * Get the next available ID for a table.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions (optional)
     * @param string $column Column name (default: 'id')
     *
     * @return int Next available ID
     * @throws \Exception If the query fails
     */
    public function nextId(string $table, $where = [], $column = 'id'): int
    {
        $result = $this->database->createQuery()
            ->select(['MAX(CAST(`'.$column.'` AS INT)) as id'])
            ->from($table)
            ->where($where)
            ->execute()
            ->fetch();

        if ($result) {
            return (int) ($result->id ?? 0) + 1;
        }

        return 1;
    }

    /**
     * Increment a numeric column by a given amount.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions
     * @param string|array $column Column name or array of column names
     * @param int|float $amount Increment amount (default: 1)
     * @return int Number of affected rows
     * @throws \Exception If the update fails
     */
    public function increment(string $table, array $where, $column, $amount = 1): int
    {
        if (!is_numeric($amount)) {
            $amount = 1;
        }
        $amount = $amount + 0;

        if (is_array($column)) {
            $set = [];
            foreach ($column as $col) {
                $set[$col] = \Kotchasan\Database\Sql::raw($col.' + '.$amount);
            }
        } else {
            $set = [$column => \Kotchasan\Database\Sql::raw($column.' + '.$amount)];
        }

        $result = $this->database->createQuery()
            ->update($table)
            ->set($set)
            ->where($where)
            ->execute();

        return $result ? $result->rowCount() : 0;
    }

    /**
     * Decrement a numeric column by a given amount.
     *
     * @param string $table Table name
     * @param array $where WHERE conditions
     * @param string $column Column name
     * @param int|float $amount Decrement amount (default: 1)
     * @return int Number of affected rows
     * @throws \Exception If the update fails
     */
    public function decrement(string $table, array $where, string $column, $amount = 1): int
    {
        if (!is_numeric($amount)) {
            $amount = 1;
        }
        $amount = $amount + 0;
        return $this->increment($table, $where, $column, -$amount);
    }

    /**
     * Empty (truncate) a table.
     *
     * @param string $table Table name
     * @param array $options Additional options (reset_autoincrement, etc.)
     * @return bool True on success, false on failure
     * @throws \Exception If the operation fails
     */
    public function emptyTable(string $table, array $options = []): bool
    {
        return $this->database->emptyTable($table, $options);
    }

    /**
     * Get the real table name (with prefix if configured).
     *
     * @param string $table Table name without prefix
     * @return string Full table name with prefix
     */
    public function getTableName(string $table): string
    {
        return $this->database->getTableName($table);
    }

    /**
     * Get the configured table prefix.
     *
     * @return string The table prefix (without underscore)
     */
    public function getPrefix(): string
    {
        return $this->database->getPrefix();
    }

    /**
     * Get last executed query.
     *
     * @return string|null
     */
    public function lastQuery(): ?string
    {
        return $this->database->getLastQuery();
    }

    /**
     * Get last error message.
     *
     * @return string|null
     */
    public function lastError(): ?string
    {
        return $this->database->getLastError();
    }

    /**
     * Check if a database exists.
     *
     * @param string $databaseName Database name
     * @return bool True if database exists, false otherwise
     * @throws \Exception If the query fails
     */
    public function databaseExists(string $databaseName): bool
    {
        try {
            // Use raw SQL for checking database existence
            $sql = "SELECT 1";
            $result = $this->raw($sql);
            return $result !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a field (column) exists in a table.
     *
     * @param string $table Table name
     * @param string $fieldName Field/column name
     * @return bool True if field exists, false otherwise
     * @throws \Exception If the query fails
     */
    public function fieldExists(string $table, string $fieldName): bool
    {
        try {
            $fullTableName = $this->getTableName($table);
            // Simple approach: try to select the field
            $sql = "SELECT `{$fieldName}` FROM `{$fullTableName}` LIMIT 0";
            $result = $this->raw($sql);
            return $result !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql Raw SQL query
     * @param array $bindings Parameter bindings (optional)
     * @return \Kotchasan\Result\ResultInterface|null Query result or null on failure
     * @throws \Exception If the query fails
     */
    public function raw(string $sql, array $bindings = [])
    {
        try {
            return $this->database->raw($sql, $bindings);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the underlying Database instance.
     *
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Begin a database transaction.
     *
     * @return bool True on success, false on failure
     */
    public function beginTransaction(): bool
    {
        return $this->database->beginTransaction();
    }

    /**
     * Commit a database transaction.
     *
     * @return bool True on success, false on failure
     */
    public function commit(): bool
    {
        return $this->database->commit();
    }

    /**
     * Rollback a database transaction.
     *
     * @return bool True on success, false on failure
     */
    public function rollback(): bool
    {
        return $this->database->rollback();
    }

    /**
     * Optimize a database table.
     *
     * This command is used to reclaim the unused space and defragment the data file.
     * The actual implementation varies by database driver:
     * - MySQL: OPTIMIZE TABLE
     * - PostgreSQL: VACUUM
     * - SQLite: VACUUM
     * - SQL Server: ALTER INDEX ... REORGANIZE
     *
     * @param string $table The table name to optimize
     * @return bool True on success, false on failure
     */
    public function optimizeTable(string $table): bool
    {
        return $this->database->optimizeTable($table);
    }

    /**
     * Resolve output property name for a column/expression.
     *
     * @param string $column
     * @return string
     */
    protected function resolveColumnAlias(string $column): string
    {
        // Normalize "AS" alias
        $parts = preg_split('/\s+as\s+/i', $column);
        if (count($parts) > 1) {
            return trim(end($parts));
        }
        // If it's a table.column, use last segment
        if (strpos($column, '.') !== false) {
            $segments = explode('.', $column);
            return trim(end($segments));
        }
        return trim($column);
    }
}
