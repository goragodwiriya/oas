<?php
/**
 * @filesource Kotchasan/Log/Logger.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Log;

use \Psr\Log\LoggerInterface;
use \Psr\Log\LogLevel;
use \Kotchasan\Language;
use \Kotchasan\File;
use \Kotchasan\Log\AbstractLogger;

/**
 * Kotchasan Logger Class (PSR-3)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Logger extends AbstractLogger implements LoggerInterface
{
  /**
   * @var Singleton สำหรับเรียกใช้ class นี้เพียงครั้งเดียวเท่านั้น
   */
  protected static $instance = null;
  /**
   * รูปแบบของ log
   *
   * @var array
   */
  protected $options = array(
    'dateFormat' => 'Y-m-d H:i:s',
    'logFormat' => '[{datetime}] {level}: {message} {context}',
    'logFilePath' => 'logs/',
    'extension' => 'php'
  );
  /**
   * Log Levels
   *
   * @var array
   */
  protected $logLevels = array(
    LogLevel::EMERGENCY => 0,
    LogLevel::ALERT => 1,
    LogLevel::CRITICAL => 2,
    LogLevel::ERROR => 3,
    LogLevel::WARNING => 4,
    LogLevel::NOTICE => 5,
    LogLevel::INFO => 6,
    LogLevel::DEBUG => 7
  );

  /**
   * Singleton
   */
  private function __construct($options)
  {
    $this->options['logFilePath'] = ROOT_PATH.'datas/logs/';
    foreach ($options as $key => $value) {
      $this->options[$key] = $value;
    }
  }

  /**
   * create Logger instance (Singleton)
   *
   * @param array $options
   * @return \static
   */
  public static function create(array $options = array())
  {
    if (null === self::$instance) {
      self::$instance = new static($options);
    }
    return self::$instance;
  }

  /**
   * Logs with an arbitrary level.
   *
   * @param mixed  $level
   * @param string $message
   * @param array  $context
   */
  public function log($level, $message, array $context = array())
  {
    $patt = array(
      'datetime' => date($this->options['dateFormat'], time()),
      'level' => isset($this->logLevels[$level]) ? strtoupper($level) : 'UNKNOW',
      'message' => $message,
      'context' => empty($context) ? '' : json_encode($context)
    );
    $message = $this->options['logFormat'];
    foreach ($patt as $key => $value) {
      $message = str_replace('{'.$key.'}', $value, $message);
    }
    $message = "\n".preg_replace('/[\s\n\t\r]+/', ' ', $message);
    if (File::makeDirectory($this->options['logFilePath'])) {
      // ไฟล์ log
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
      // save
      if (file_exists($file)) {
        $f = @fopen($file, 'a');
      } else {
        $f = @fopen($file, 'w');
        if ($f && $this->options['extension'] == 'php') {
          fwrite($f, '<'.'?php exit() ?'.'>');
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
}
