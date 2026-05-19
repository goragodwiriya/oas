<?php
namespace Kotchasan\Logger;

/**
 * Query logger that suppresses non-SQL passthrough logs.
 *
 * @package Kotchasan\Logger
 */
class SqlQueryLogger extends QueryLogger
{
    /**
     * Ignore non-query log passthrough.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
    }

    /**
     * Ignore debug passthrough from connection/bootstrap code.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
    }

    /**
     * Ignore info passthrough from connection/bootstrap code.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
    }

    /**
     * Ignore warning passthrough from connection/bootstrap code.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
    }

    /**
     * Ignore generic errors that are not emitted through logQueryError().
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
    }
}