<?php

namespace Kotchasan\Logger;

/**
 * Logger Interface
 * Defines the methods for logging messages
 *
 * @package Kotchasan\Logger
 */
interface LoggerInterface
{
    /**
     * Logs a message with the specified level.
     *
     * @param string $level The log level (debug, info, warning, error).
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * Logs a debug message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Logs an info message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Logs a warning message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Logs an error message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return void
     */
    public function error(string $message, array $context = []): void;

    /**
     * Checks if debugging is enabled.
     *
     * @return bool True if debugging is enabled, false otherwise.
     */
    public function isDebugEnabled(): bool;

    /**
     * Enables or disables debugging.
     *
     * @param bool $enabled Whether debugging should be enabled.
     * @return void
     */
    public function setDebugEnabled(bool $enabled): void;
}
