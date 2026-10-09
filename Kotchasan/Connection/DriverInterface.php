<?php

namespace Kotchasan\Connection;

use Kotchasan\Execution\StatementInterface;

/**
 * Interface DriverInterface
 *
 * Defines methods for database driver implementations.
 *
 * @package Kotchasan\Connection
 */
interface DriverInterface
{
    /**
     * Establishes a connection to the database.
     *
     * @param array $config The configuration parameters for the connection.
     * @return bool True if the connection was successful, false otherwise.
     */
    public function connect(array $config): bool;

    /**
     * Closes the database connection.
     *
     * @return bool True if the connection was successfully closed, false otherwise.
     */
    public function disconnect(): bool;

    /**
     * Checks if the connection is active.
     *
     * @return bool True if the connection is active, false otherwise.
     */
    public function isConnected(): bool;

    /**
     * Prepares a statement for execution.
     *
     * @param string $query The SQL query to prepare.
     * @return StatementInterface The prepared statement.
     */
    public function prepare(string $query): StatementInterface;

    /**
     * Begins a transaction.
     *
     * @return bool True if the transaction was successfully started, false otherwise.
     */
    public function beginTransaction(): bool;

    /**
     * Commits a transaction.
     *
     * @return bool True if the transaction was successfully committed, false otherwise.
     */
    public function commit(): bool;

    /**
     * Rolls back a transaction.
     *
     * @return bool True if the transaction was successfully rolled back, false otherwise.
     */
    public function rollback(): bool;

    /**
     * Checks if a transaction is currently active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public function inTransaction(): bool;

    /**
     * Returns the last inserted ID.
     *
     * @param string|null $name The name of the sequence object (if applicable).
     * @return string The last inserted ID.
     */
    public function lastInsertId(?string $name = null): string;

    /**
     * Returns the last error that occurred.
     *
     * @return string|null The last error message or null if no error occurred.
     */
    public function getLastError(): ?string;

    /**
     * Escapes a string for use in a query.
     *
     * @param string $value The string to escape.
     * @return string The escaped string.
     */
    public function escape(string $value): string;

    /**
     * Returns the driver name.
     *
     * @return string The driver name (e.g., "mysql", "pgsql", "sqlsrv").
     */
    public function getName(): string;

    /**
     * Empty a table using database-specific optimized method.
     *
     * @param string $tableName Physical table name (already resolved with prefix)
     * @param array $options Driver-specific options
     * @return bool True on success, false on failure
     */
    public function emptyTable(string $tableName, array $options = []): bool;

    /**
     * Optimizes a table using database-specific optimized method.
     *
     * @param string $tableName Physical table name (already resolved with prefix)
     * @return bool True on success, false on failure
     */
    public function optimizeTable(string $tableName): bool;

    /**
     * Format a SQL function based on database-specific syntax.
     *
     * This method generates the appropriate SQL syntax for various SQL functions
     * based on the target database type (MySQL, PostgreSQL, SQLite, MSSQL).
     *
     * @param string $type The SQL function type (YEAR, CONCAT, NOW, etc.)
     * @param array $parameters Parameters for the function
     * @param string|null $alias Optional alias for the function result
     * @return string The formatted SQL function string
     */
    public function formatSqlFunction(string $type, array $parameters, ?string $alias): string;
}
