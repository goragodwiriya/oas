<?php
/**
 * @filesource Kotchasan/Log/Logger.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Log;

use Kotchasan\File;
use Kotchasan\Language;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Kotchasan Logger Class (PSR-3)
 *
 * @see https://www.kotchasan.com/
 */
class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * Log Levels
     *
     * @var array
     */
    protected $logLevels = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7
    ];

    /**
     * Log options
     *
     * @var array
     */
    protected $options = [
        'dateFormat' => 'Y-m-d H:i:s',
        'logFormat' => '[{datetime}] {level}: {message} {context}',
        'logFilePath' => 'logs/',
        'extension' => 'php'
    ];

    /**
     * Singleton instance
     *
     * @var Singleton
     */
    protected static $instance = null;

    /**
     * Create a Logger instance (Singleton).
     *
     * @param array $options Log options (optional).
     *
     * @return static
     */
    public static function create(array $options = [])
    {
        if (null === self::$instance) {
            self::$instance = new static($options);
        }

        return self::$instance;
    }

    /**
     * Logs a message with an arbitrary level.
     *
     * @param mixed  $level   The log level.
     * @param string $message The log message.
     * @param array  $context The log context (optional).
     *
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        $patt = [
            'datetime' => date($this->options['dateFormat'], time()),
            'level' => isset($this->logLevels[$level]) ? strtoupper($level) : 'UNKNOW',
            'message' => $message,
            'context' => empty($context) ? '' : json_encode($context)
        ];

        $message = $this->options['logFormat'];

        foreach ($patt as $key => $value) {
            $message = str_replace('{'.$key.'}', $value, $message);
        }

        $message = "\n".preg_replace('/[\s\n\t\r]+/', ' ', $message);

        if (File::makeDirectory($this->options['logFilePath'])) {
            switch ($level) {
                case LogLevel::DEBUG:
                case LogLevel::INFO:
                case LogLevel::ALERT:
                    $file = $this->options['logFilePath'].date('Y-m-d').'.'.$this->options['extension'];
                    break;
                default:
                    $file = $this->options['logFilePath'].'error_log.'.$this->options['extension'];
                    break;
            }

            if (file_exists($file)) {
                $f = @fopen($file, 'a');
            } else {
                $f = @fopen($file, 'w');

                if ($f && $this->options['extension'] === 'php') {
                    fwrite($f, '<?php exit();?>');
                }
            }

            if ($f) {
                fwrite($f, $message);
                fclose($f);
            } else {
                printf(Language::get('File %s cannot be created or is read-only.'), 'log');
            }
        } else {
            printf(Language::get('Directory %s cannot be created or is read-only.'), 'logs/');
            echo $message;
        }
    }

    /**
     * Private constructor.
     *
     * @param array $options Log options.
     */
    private function __construct($options)
    {
        $this->options['logFilePath'] = ROOT_PATH.'datas/logs/';

        foreach ($options as $key => $value) {
            $this->options[$key] = $value;
        }
    }
}
