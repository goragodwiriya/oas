<?php

namespace Kotchasan;

/**
 * Kotchasan Logger Class
 *
 * This class provides a simple logging interface for Kotchasan applications.
 * It supports different log levels and can be extended to use various logging backends.
 * Supports multiple destinations based on LOG_DESTINATION constant.
 *
 * @package Kotchasan
 */
class Logger
{
    /**
     * Singleton instance for file logger
     *
     * @var \Kotchasan\Logger\LoggerInterface
     */
    private static $fileInstance = null;

    /**
     * Singleton instance for system logger
     *
     * @var \Kotchasan\Logger\LoggerInterface
     */
    private static $systemInstance = null;

    /**
     * Create and return a File Logger instance
     *
     * @return \Kotchasan\Logger\LoggerInterface
     */
    public static function create()
    {
        if (null === self::$fileInstance) {
            // Create a file logger by default
            self::$fileInstance = new \Kotchasan\Logger\FileLogger();

            // Enable debug mode based on DEBUG constant
            if (defined('DEBUG') && DEBUG > 0) {
                self::$fileInstance->setDebugEnabled(true);
            }
        }
        return self::$fileInstance;
    }

    /**
     * Create and return a System Logger instance (logs to Apache/PHP error log)
     *
     * @return \Kotchasan\Logger\SystemLogger
     */
    public static function system()
    {
        if (null === self::$systemInstance) {
            self::$systemInstance = new \Kotchasan\Logger\SystemLogger();

            // Enable debug mode based on DEBUG constant
            if (defined('DEBUG') && DEBUG > 0) {
                self::$systemInstance->setDebugEnabled(true);
            }
        }
        return self::$systemInstance;
    }

    /**
     * Log to appropriate destination(s) based on LOG_DESTINATION
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message The message to log
     * @param array $context Additional context information
     */
    public static function logToDestination($level, $message, $context = [])
    {
        $destination = defined('LOG_DESTINATION') ? LOG_DESTINATION : 'LOG_BOTH';

        if ($destination === 'LOG_SYSTEM' || $destination === 'LOG_BOTH') {
            self::system()->log($level, strip_tags($message), $context);
        }

        if ($destination === 'LOG_FILE' || $destination === 'LOG_BOTH') {
            self::create()->log($level, $message, $context);
        }
    }

    /**
     * Log a debug message
     *
     * @param string $message The message to log
     * @param array $context Additional context information
     */
    public static function debug($message, $context = [])
    {
        self::create()->debug($message, $context);
    }

    /**
     * Log an info message
     *
     * @param string $message The message to log
     * @param array $context Additional context information
     */
    public static function info($message, $context = [])
    {
        self::create()->info($message, $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message The message to log
     * @param array $context Additional context information
     */
    public static function warning($message, $context = [])
    {
        self::create()->warning($message, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message The message to log
     * @param array $context Additional context information
     */
    public static function error($message, $context = [])
    {
        self::create()->error($message, $context);
    }

    /**
     * Log a security event
     *
     * @param string $event The security event name
     * @param array $context Additional context information (IP, user_agent, etc.)
     */
    public static function security($event, $context = [])
    {
        $message = "Security Event: {$event}";

        // Add timestamp and format context for better readability
        $formattedContext = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'event_type' => $event
        ], $context);

        self::create()->warning($message, $formattedContext);

        // Also log to system for security events
        self::system()->warning($message, $formattedContext);
    }

    /**
     * Log an exception with full stack trace
     *
     * @param \Throwable $exception The exception to log
     * @param string $additionalContext Additional context message
     */
    public static function exception(\Throwable $exception, $additionalContext = '')
    {
        $message = 'Exception: '.$exception->getMessage();
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];

        if ($additionalContext) {
            $context['additional'] = $additionalContext;
        }

        self::logToDestination('error', $message, $context);
    }

    /**
     * Log an API error
     *
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param array $context Additional context (request URI, method, etc.)
     */
    public static function apiError($code, $message, $context = [])
    {
        $context = array_merge([
            'http_code' => $code,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $context);

        self::logToDestination('error', "API Error [{$code}]: {$message}", $context);
    }
}
