<?php

namespace Kotchasan\Logger;

/**
 * Class Logger
 *
 * Base implementation of the LoggerInterface.
 *
 * @package Kotchasan\Logger
 */
abstract class Logger implements LoggerInterface
{
    /**
     * Available log levels.
     */
    protected const LEVEL_DEBUG = 'debug';
    protected const LEVEL_INFO = 'info';
    protected const LEVEL_WARNING = 'warning';
    protected const LEVEL_ERROR = 'error';

    /**
     * Flag indicating whether debugging is enabled.
     *
     * @var bool
     */
    protected bool $debugEnabled = false;

    /**
     * {@inheritdoc}
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Skip debug messages if debugging is disabled
        if ($level === self::LEVEL_DEBUG && !$this->debugEnabled) {
            return;
        }

        $this->writeLog($level, $this->formatMessage($level, $message, $context));
    }

    /**
     * {@inheritdoc}
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function isDebugEnabled(): bool
    {
        return $this->debugEnabled;
    }

    /**
     * {@inheritdoc}
     */
    public function setDebugEnabled(bool $enabled): void
    {
        $this->debugEnabled = $enabled;
    }

    /**
     * Formats a log message.
     *
     * @param string $level The log level.
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return string The formatted log message.
     */
    protected function formatMessage(string $level, string $message, array $context = []): string
    {
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');

        // Format level
        $level = strtoupper($level);

        // Format context
        $contextString = '';
        if (!empty($context)) {
            $contextString = ' '.json_encode($context);
        }

        return "[{$timestamp}] [{$level}] {$message}{$contextString}";
    }

    /**
     * Writes a log message to the log destination.
     *
     * @param string $level The log level.
     * @param string $message The formatted log message.
     * @return void
     */
    abstract protected function writeLog(string $level, string $message): void;
}
