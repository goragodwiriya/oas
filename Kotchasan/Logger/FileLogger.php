<?php

namespace Kotchasan\Logger;

use Kotchasan\Logger\Logger;

/**
 * File Logger Class
 * Logs messages to a file
 *
 * @package Kotchasan\Logger
 */
class FileLogger extends Logger
{
    /**
     * Default log file name
     *
     * @var string
     */
    protected $logFile = 'error_log.php';

    /**
     * Constructor
     *
     * @param string $logFile Optional custom log file name
     */
    public function __construct($logFile = null)
    {
        if ($logFile !== null) {
            $this->logFile = $logFile;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function writeLog(string $level, string $message): void
    {
        $logFile = ROOT_PATH.$this->logFile;

        // Create log file if it doesn't exist
        if (!is_file($logFile)) {
            $f = @fopen($logFile, 'w');
            if ($f) {
                fwrite($f, '<'.'?php exit() ?'.'>'."\n");
                fclose($f);
            }
        }

        // Append log message to file
        $f = @fopen($logFile, 'a');
        if ($f) {
            fwrite($f, $message."\n");
            fclose($f);
        }
    }
}
