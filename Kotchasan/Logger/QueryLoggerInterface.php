<?php

namespace Kotchasan\Logger;

/**
 * Interface QueryLoggerInterface
 *
 * Extends the base LoggerInterface with query-specific logging methods.
 *
 * @package Kotchasan\Logger
 */
interface QueryLoggerInterface extends LoggerInterface
{
    /**
     * Logs a query before execution.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @return float|null The start time if tracking is enabled, null otherwise.
     */
    public function logQuery(string $query, array $bindings = []): ?float;

    /**
     * Logs a query after execution.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @param float|null $startTime The start time from logQuery.
     * @param int $rowCount The number of affected rows.
     * @return void
     */
    public function logQueryResult(string $query, array $bindings, ?float $startTime, int $rowCount): void;

    /**
     * Logs a query error.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @param string $error The error message.
     * @return void
     */
    public function logQueryError(string $query, array $bindings, string $error): void;

    /**
     * Gets all executed queries.
     *
     * @return array The executed queries.
     */
    public function getQueries(): array;

    /**
     * Gets the total execution time of all queries.
     *
     * @return float The total execution time in seconds.
     */
    public function getTotalTime(): float;

    /**
     * Gets the average execution time of all queries.
     *
     * @return float The average execution time in seconds.
     */
    public function getAverageTime(): float;

    /**
     * Gets the number of executed queries.
     *
     * @return int The number of executed queries.
     */
    public function getQueryCount(): int;
}
