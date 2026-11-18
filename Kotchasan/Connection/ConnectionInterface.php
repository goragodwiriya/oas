<?php

namespace Kotchasan\Connection;

use Kotchasan\Cache\QueryCache;
use Kotchasan\Logger\LoggerInterface;
use Kotchasan\Logger\QueryLoggerInterface;

/**
 * Interface ConnectionInterface
 *
 * Defines methods for database connection management.
 *
 * @package Kotchasan\Connection
 */
interface ConnectionInterface
{
    /**
     * Establishes a connection to the database.
     *
     * @return bool True if the connection was successful, false otherwise.
     */
    public function connect(): bool;

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
     * Returns the connection resource or object.
     *
     * @return mixed The connection resource or object.
     */
    public function getConnection();

    /**
     * Returns the last error that occurred.
     *
     * @return string|null The last error message or null if no error occurred.
     */
    public function getLastError(): ?string;

    /**
     * Returns the driver-specific implementation.
     *
     * @return DriverInterface The driver implementation.
     */
    public function getDriver(): DriverInterface;

    /**
     * Sets a logger for this connection.
     *
     * @param LoggerInterface|QueryLoggerInterface $logger The logger to use.
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void;

    /**
     * Gets the logger for this connection.
     *
     * @return LoggerInterface|QueryLoggerInterface|null The logger, or null if no logger is set.
     */
    public function getLogger(): ?LoggerInterface;

    /**
     * Sets a query cache for this connection.
     *
     * @param QueryCache $queryCache The query cache to use.
     * @return void
     */
    public function setQueryCache(QueryCache $queryCache): void;

    /**
     * Gets the query cache for this connection.
     *
     * @return QueryCache|null The query cache, or null if no cache is set.
     */
    public function getQueryCache(): ?QueryCache;

    /**
     * Optimize a database table.
     *
     * Performs database-specific optimization operations to reclaim unused space,
     * defragment data files, and update table statistics for better performance.
     *
     * @param string $tableName The table name to optimize (with prefix if needed)
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
