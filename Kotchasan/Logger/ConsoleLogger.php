<?php

namespace Kotchasan\Logger;

/**
 * Class ConsoleLogger
 *
 * Logs messages to the console (standard output).
 *
 * @package Kotchasan\Logger
 */
class ConsoleLogger extends Logger
{
    /**
     * ANSI color codes for different log levels.
     */
    protected const COLORS = [
        'debug' => '36', // Cyan
        'info' => '32', // Green
        'warning' => '33', // Yellow
        'error' => '31' // Red
    ];

    /**
     * Flag indicating whether to use colors.
     *
     * @var bool
     */
    protected bool $useColors;

    /**
     * ConsoleLogger constructor.
     *
     * @param bool $useColors Whether to use colors in the output.
     */
    public function __construct(bool $useColors = true)
    {
        $this->useColors = $useColors && $this->isColorSupported();
    }

    /**
     * {@inheritdoc}
     */
    protected function writeLog(string $level, string $message): void
    {
        if ($this->useColors && isset(self::COLORS[strtolower($level)])) {
            $colorCode = self::COLORS[strtolower($level)];
            $message = "\033[{$colorCode}m{$message}\033[0m";
        }

        echo $message.PHP_EOL;
    }

    /**
     * Checks if color output is supported.
     *
     * @return bool True if color output is supported, false otherwise.
     */
    protected function isColorSupported(): bool
    {
        // Check if we're on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            return false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI') || 'xterm' === getenv('TERM');
        }

        // Check if we're in a terminal
        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
