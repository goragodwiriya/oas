<?php

namespace Kotchasan\Logger;

/**
 * System Logger Class
 * Logs messages to PHP/Apache error log using error_log() function
 *
 * @package Kotchasan\Logger
 */
class SystemLogger extends Logger
{
    /**
     * Log type for error_log() function
     * 0 = message is sent to PHP's system logger (Apache error log)
     * 3 = message is appended to the file destination
     * 4 = message is sent directly to the SAPI logging handler
     *
     * @var int
     */
    protected int $logType = 0;

    /**
     * Optional file destination when logType = 3
     *
     * @var string|null
     */
    protected ?string $destination = null;

    /**
     * Constructor
     *
     * @param int $logType Log type (0=system logger, 3=file, 4=SAPI)
     * @param string|null $destination File path when logType=3
     */
    public function __construct(int $logType = 0, ?string $destination = null)
    {
        $this->logType = $logType;
        $this->destination = $destination;
    }

    /**
     * {@inheritdoc}
     */
    protected function writeLog(string $level, string $message): void
    {
        // Strip HTML tags for system log
        $plainMessage = strip_tags($message);

        // Add log level prefix for better filtering
        $prefix = '['.strtoupper($level).'] ';

        if ($this->logType === 3 && $this->destination) {
            error_log($prefix.$plainMessage.PHP_EOL, 3, $this->destination);
        } else {
            error_log($prefix.$plainMessage, $this->logType);
        }
    }

    /**
     * Format message without HTML (override parent for plain text)
     *
     * @param string $level The log level.
     * @param string $message The message to log.
     * @param array $context Additional context information.
     * @return string The formatted log message.
     */
    protected function formatMessage(string $level, string $message, array $context = []): string
    {
        // Strip HTML tags
        $message = strip_tags($message);

        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');

        // Format context
        $contextString = '';
        if (!empty($context)) {
            $contextString = ' '.json_encode($context);
        }

        return "[{$timestamp}] {$message}{$contextString}";
    }

    /**
     * Log with additional context like request info
     *
     * @param string $level Log level
     * @param string $message Message to log
     * @param array $context Context data
     */
    public function logWithContext(string $level, string $message, array $context = []): void
    {
        // Add request context if available
        if (isset($_SERVER['REQUEST_URI'])) {
            $context['request_uri'] = $_SERVER['REQUEST_URI'];
        }
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $context['request_method'] = $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $context['client_ip'] = $_SERVER['REMOTE_ADDR'];
        }

        $this->log($level, $message, $context);
    }
}
