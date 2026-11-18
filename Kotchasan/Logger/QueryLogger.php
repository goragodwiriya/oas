<?php

namespace Kotchasan\Logger;

/**
 * Class QueryLogger
 *
 * Specialized logger for database queries.
 *
 * @package Kotchasan\Logger
 */
class QueryLogger implements QueryLoggerInterface
{
    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Array of executed queries.
     *
     * @var array
     */
    protected array $queries = [];

    /**
     * Flag indicating whether to track execution time.
     *
     * @var bool
     */
    protected bool $trackTime = false;

    /**
     * QueryLogger constructor.
     *
     * @param LoggerInterface $logger The logger to use.
     * @param bool $trackTime Whether to track query execution time.
     */
    public function __construct(LoggerInterface $logger, bool $trackTime = true)
    {
        $this->logger = $logger;
        $this->trackTime = $trackTime;
    }

    /**
     * Logs a query before execution.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @return float|null The start time if tracking is enabled, null otherwise.
     */
    public function logQuery(string $query, array $bindings = []): ?float
    {
        $this->logger->debug('Executing query', [
            'query' => $query,
            'bindings' => $bindings
        ]);

        $startTime = null;
        if ($this->trackTime) {
            $startTime = microtime(true);
        }

        return $startTime;
    }

    /**
     * Logs a query after execution.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @param float|null $startTime The start time from logQuery.
     * @param int $rowCount The number of affected rows.
     * @return void
     */
    public function logQueryResult(string $query, array $bindings, ?float $startTime, int $rowCount): void
    {
        $executionTime = null;
        if ($startTime !== null) {
            $executionTime = microtime(true) - $startTime;
        }

        $this->queries[] = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => $executionTime,
            'rows' => $rowCount
        ];

        if ($executionTime !== null) {
            $timeMs = number_format($executionTime * 1000, 2);
            $this->logger->debug('Query executed', [
                'query' => $query,
                'bindings' => $bindings,
                'time' => "{$timeMs}ms",
                'rows' => $rowCount
            ]);
        } else {
            $this->logger->debug('Query executed', [
                'query' => $query,
                'bindings' => $bindings,
                'rows' => $rowCount
            ]);
        }
    }

    /**
     * Logs a query error.
     *
     * @param string $query The SQL query.
     * @param array $bindings The parameter bindings.
     * @param string $error The error message.
     * @return void
     */
    public function logQueryError(string $query, array $bindings, string $error): void
    {
        $this->logger->error('Query error', [
            'query' => $query,
            'bindings' => $bindings,
            'error' => $error
        ]);
    }

    /**
     * Gets all executed queries.
     *
     * @return array The executed queries.
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Gets the total execution time of all queries.
     *
     * @return float The total execution time in seconds.
     */
    public function getTotalTime(): float
    {
        $total = 0.0;

        foreach ($this->queries as $query) {
            if (isset($query['time'])) {
                $total += $query['time'];
            }
        }

        return $total;
    }

    /**
     * Gets the average execution time of all queries.
     *
     * @return float The average execution time in seconds.
     */
    public function getAverageTime(): float
    {
        $count = count($this->queries);

        if ($count === 0) {
            return 0.0;
        }

        return $this->getTotalTime() / $count;
    }

    /**
     * Gets the number of executed queries.
     *
     * @return int The number of executed queries.
     */
    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Clears the query log.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->queries = [];
    }

    /**
     * Logs a message at a specified level.
     *
     * @param string $level The log level.
     * @param string $message The log message.
     * @param array $context The log context.
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }

    /**
     * Logs a debug message.
     *
     * @param string $message The log message.
     * @param array $context The log context.
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    /**
     * Logs an info message.
     *
     * @param string $message The log message.
     * @param array $context The log context.
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Logs a warning message.
     *
     * @param string $message The log message.
     * @param array $context The log context.
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Logs an error message.
     *
     * @param string $message The log message.
     * @param array $context The log context.
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * Checks if debug logging is enabled.
     *
     * @return bool True if debug logging is enabled, false otherwise.
     */
    public function isDebugEnabled(): bool
    {
        return $this->logger->isDebugEnabled();
    }

    /**
     * Sets whether debug logging is enabled.
     *
     * @param bool $enabled Whether debug logging should be enabled.
     * @return void
     */
    public function setDebugEnabled(bool $enabled): void
    {
        $this->logger->setDebugEnabled($enabled);
    }
}
