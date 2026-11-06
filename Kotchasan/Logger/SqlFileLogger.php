<?php
namespace Kotchasan\Logger;

/**
 * File logger specialized for SQL logs with retention cleanup.
 *
 * @package Kotchasan\Logger
 */
class SqlFileLogger extends FileLogger
{
    /**
     * Retention period in days.
     *
     * @var int
     */
    protected int $retentionDays;

    /**
     * Cleanup interval in seconds.
     *
     * @var int
     */
    protected int $cleanupInterval;

    /**
     * Cleanup marker cache for this request.
     *
     * @var array<string, int>
     */
    protected static array $lastCleanupAt = [];

    /**
     * Constructor.
     *
     * @param string|null $logFile
     * @param int $retentionDays
     * @param int $cleanupInterval
     */
    public function __construct($logFile = null, int $retentionDays = 7, int $cleanupInterval = 86400)
    {
        parent::__construct($logFile);
        $this->retentionDays = max(0, $retentionDays);
        $this->cleanupInterval = max(0, $cleanupInterval);
    }

    /**
     * {@inheritdoc}
     */
    protected function writeLog(string $level, string $message): void
    {
        $logFile = ROOT_PATH.$this->logFile;
        $this->cleanupExpiredEntries($logFile);
        parent::writeLog($level, $message);
    }

    /**
     * Remove entries older than the configured retention period.
     *
     * @param string $logFile
     *
     * @return void
     */
    protected function cleanupExpiredEntries(string $logFile): void
    {
        if ($this->retentionDays === 0 || !is_file($logFile)) {
            return;
        }

        $marker = $this->getCleanupMarkerPath($logFile);
        $lastCleanupAt = self::$lastCleanupAt[$logFile] ?? (is_file($marker) ? (int) filemtime($marker) : 0);
        if ($this->cleanupInterval > 0 && $lastCleanupAt > 0 && (time() - $lastCleanupAt) < $this->cleanupInterval) {
            return;
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        $cutoff = time() - ($this->retentionDays * 86400);
        $keptLines = [];
        foreach ($lines as $index => $line) {
            if ($index === 0 && strpos($line, '<?php exit() ?>') === 0) {
                $keptLines[] = $line;
                continue;
            }

            if ($this->shouldKeepLine($line, $cutoff)) {
                $keptLines[] = $line;
            }
        }

        $content = implode(PHP_EOL, $keptLines);
        if ($content !== '') {
            $content .= PHP_EOL;
        }
        @file_put_contents($logFile, $content, LOCK_EX);
        @touch($marker);
        self::$lastCleanupAt[$logFile] = time();
    }

    /**
     * Determine if a log line should be retained.
     *
     * @param string $line
     * @param int $cutoff
     *
     * @return bool
     */
    protected function shouldKeepLine(string $line, int $cutoff): bool
    {
        if ($line === '') {
            return false;
        }

        if (preg_match('/^\[([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})\]/', $line, $match)) {
            $timestamp = strtotime($match[1]);
            return ($timestamp === false || $timestamp >= $cutoff) && $this->isSqlLogLine($line);
        }

        return true;
    }

    /**
     * Check whether a log line is an allowed SQL log entry.
     *
     * @param string $line
     *
     * @return bool
     */
    protected function isSqlLogLine(string $line): bool
    {
        return strpos($line, 'Executing query') !== false
        || strpos($line, 'Query executed') !== false
        || strpos($line, 'Query error') !== false;
    }

    /**
     * Get the hidden cleanup marker path for a log file.
     *
     * @param string $logFile
     *
     * @return string
     */
    protected function getCleanupMarkerPath(string $logFile): string
    {
        return dirname($logFile).'/.'.basename($logFile).'.cleanup';
    }
}