<?php
/**
 * @filesource Kotchasan/load.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 *
 * ไฟล์หลักสำหรับกำหนดค่าเริ่มต้นให้กับคชสารในการโหลดเฟรมเวิร์ค
 * ต้อง include ไฟล์นี้ก่อนเสมอ
 */
/**
 * การแสดงข้อผิดพลาด
 * 0 บันทึกข้อผิดพลาดร้ายแรงลง error_log .php (ขณะใช้งานจริง)
 * 1 บันทึกข้อผิดพลาดและคำเตือนลง error_log .php
 * 2 แสดงผลข้อผิดพลาดและคำเตือนออกทางหน้าจอ (เฉพาะตอนออกแบบเท่านั้น)
 *
 * @var int
 */
if (!defined('DEBUG')) {
  define('DEBUG', 0);
}
/* display error */
if (DEBUG > 0) {
  /* ขณะออกแบบ แสดง error และ warning ของ PHP */
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(-1);
} else {
  /* ขณะใช้งานจริง */
  error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
}
/**
 * Framework Version
 *
 * @var string
 */
define('VERSION', '1.3.0');
/**
 * กำหนดการบันทึกการ query ฐานข้อมูล
 * ควรกำหนดเป็น false ขณะใช้งานจริง
 *
 * $var bool
 */
if (!defined('DB_LOG')) {
  define('DB_LOG', false);
}
/**
 * ไดเรคทอรี่ของ Framework
 */
$vendorDir = str_replace('load.php', '', __FILE__);
if (DIRECTORY_SEPARATOR != '/') {
  $vendorDir = str_replace('\\', '/', $vendorDir);
}
define('VENDOR_DIR', $vendorDir);

/**
 *  document root (Server)
 */
$contextPrefix = '';
if (isset($_SERVER['APPL_PHYSICAL_PATH'])) {
  $docRoot = rtrim(realpath($_SERVER['APPL_PHYSICAL_PATH']), DIRECTORY_SEPARATOR);
} elseif (strpos($_SERVER['SCRIPT_FILENAME'], $_SERVER['DOCUMENT_ROOT']) !== false) {
  $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR);
} else {
  $docRoot = dirname($vendorDir);
  $dir = basename($docRoot);
  $ds = explode($dir, dirname($_SERVER['SCRIPT_NAME']), 2);
  if (sizeof($ds) > 1) {
    $contextPrefix = $ds[0].$dir;
    $appPath = $ds[1];
    if (DIRECTORY_SEPARATOR != '/') {
      $contextPrefix = str_replace('\\', '/', $contextPrefix);
    }
  }
  if (!defined('APP_PATH')) {
    define('APP_PATH', $docRoot.$appPath.'/');
  }
  if (!defined('BASE_PATH')) {
    define('BASE_PATH', $contextPrefix.$appPath.'/');
  }
}
if (DIRECTORY_SEPARATOR != '/' && $docRoot != '') {
  $docRoot = str_replace('\\', '/', $docRoot);
}
/**
 * พาธของ Application เช่น D:/htdocs/kotchasan/
 */
if (!defined('APP_PATH')) {
  $appPath = dirname($_SERVER['SCRIPT_NAME']);
  if (DIRECTORY_SEPARATOR != '/') {
    $appPath = str_replace('\\', '/', $appPath);
  }
  define('APP_PATH', rtrim($docRoot.$appPath, '/').'/');
}
/**
 * พาธของ Server ตั้งแต่ระดับราก เช่น D:/htdocs/kotchasan/
 */
if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', APP_PATH);
}
/**
 *  http หรือ https
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
  $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'].'://';
} elseif ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
  $scheme = 'https://';
} else {
  $scheme = 'http://';
}
/**
 * host name
 */
if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
  $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
} elseif (empty($_SERVER['HTTP_HOST'])) {
  $host = $_SERVER['SERVER_NAME'];
} else {
  $host = $_SERVER['HTTP_HOST'];
}

/**
 * ไดเร็คทอรี่ที่ติดตั้งเว็บไซต์ตั้งแต่ DOCUMENT_ROOT
 * เช่น kotchasan/
 */
if (!defined('BASE_PATH')) {
  if (empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    define('BASE_PATH', str_replace($docRoot, '', APP_PATH));
  } else {
    $basePath = str_replace($_SERVER['CONTEXT_DOCUMENT_ROOT'], '', dirname($_SERVER['SCRIPT_NAME']));
    if (DIRECTORY_SEPARATOR != '/') {
      $basePath = str_replace('\\', '/', $basePath);
    }
    define('BASE_PATH', rtrim($basePath, '/').'/');
  }
}
/**
 * URL ของเว็บไซต์รวม path เช่น http://domain.tld/folder
 */
if (!defined('WEB_URL')) {
  define('WEB_URL', $scheme.$host.$contextPrefix.str_replace($docRoot, '', ROOT_PATH));
}
/**
 * กำหนดจำนวนครั้งในการตรวจสอบ token
 * ถ้ามีการตรวจสอบ token เกินกว่าที่กำหนดจะถูกลบออก
 * ป้องกันการ buteforce
 *
 * @var int
 */
if (!defined('TOKEN_LIMIT')) {
  define('TOKEN_LIMIT', 10);
}

/**
 * ฟังก์ชั่นใช้สำหรับสร้างคลาส
 *
 * @param string $className ชื่อคลาส
 * @param mixed $param
 * @return \static
 */
function createClass($className, $param = null)
{
  return new $className($param);
}
/**
 * custom error handler
 * ถ้าอยู่ใน mode debug จะแสดง error ถ้าไม่จะเขียนลง log อย่างเดียว
 */
if (DEBUG != 2) {
  set_error_handler(function($errno, $errstr, $errfile, $errline) {
    switch ($errno) {
      case E_WARNING:
        $type = 'PHP warning';
        break;
      case E_NOTICE:
        $type = 'PHP notice';
        break;
      case E_USER_ERROR:
        $type = 'User error';
        break;
      case E_USER_WARNING:
        $type = 'User warning';
        break;
      case E_USER_NOTICE:
        $type = 'User notice';
        break;
      case E_RECOVERABLE_ERROR:
        $type = 'Recoverable error';
        break;
      default:
        $type = 'PHP Error';
    }
    \Kotchasan\Log\Logger::create()->error('<br>'.$type.' : <em>'.$errstr.'</em> in <b>'.$errfile.'</b> on line <b>'.$errline.'</b>');
  });
  set_exception_handler(function($e) {
    $tract = $e->getTrace();
    if (empty($tract)) {
      $tract = array(
        'file' => $e->getFile(),
        'line' => $e->getLine()
      );
    } else {
      $tract = next($tract);
    }
    \Kotchasan\Log\Logger::create()->error('<br>Exception : <em>'.$e->getMessage().'</em> in <b>'.$tract['file'].'</b> on line <b>'.$tract['line'].'</b>');
  });
}

/**
 * ตรวจสอบและคืนค่าชื่อไฟล์รวมพาธของคลาส
 *
 * @param string $className
 * @return string|null คืนค่าไฟล์รวมพาธของคลาส ถ้าไม่พบคืนค่า null
 */
function getClassPath($className)
{
  $className = str_replace('\\', '/', $className);
  if (preg_match('/^Kotchasan\/([a-zA-Z]+)Interface$/', $className, $match)) {
    if (is_file(VENDOR_DIR.'Interfaces/'.$match[1].'Interface.php')) {
      return VENDOR_DIR.'Interfaces/'.$match[1].'Interface.php';
    }
  } elseif (preg_match('/^Kotchasan\/([\/a-zA-Z]+)$/', $className, $match)) {
    if (is_file(VENDOR_DIR.$match[1].'.php')) {
      return VENDOR_DIR.$match[1].'.php';
    }
  } elseif (preg_match('/^([\/a-zA-Z0-9]+)$/', $className)) {
    if (is_file(VENDOR_DIR.$className.'.php')) {
      return VENDOR_DIR.$className.'.php';
    } elseif (is_file(APP_PATH.$className.'.php')) {
      return APP_PATH.$className.'.php';
    } elseif (is_file(ROOT_PATH.$className.'.php')) {
      return ROOT_PATH.$className.'.php';
    } else {
      $match = explode('/', strtolower($className));
      if (isset($match[2])) {
        if (isset($match[3])) {
          $module = "modules/{$match[0]}/{$match[3]}s/{$match[1]}/{$match[2]}.php";
        } else {
          $module = "modules/{$match[0]}/{$match[2]}s/{$match[1]}.php";
        }
        if (is_file(APP_PATH.$module)) {
          return APP_PATH.$module;
        } elseif (is_file(ROOT_PATH.$module)) {
          return ROOT_PATH.$module;
        }
      }
    }
  }
  return null;
}
/**
 * โหลดคลาสโดยอัตโนมัติตามชื่อของ Classname เมื่อมีการเรียกใช้งานคลาส
 * PSR-4
 *
 * @param string $className
 */
spl_autoload_register(function($className) {
  $file = getClassPath($className);
  if ($file !== null) {
    require $file;
  }
});

/**
 * load base class
 */
require VENDOR_DIR.'KBase.php';
require VENDOR_DIR.'Kotchasan.php';
require VENDOR_DIR.'Config.php';
require VENDOR_DIR.'Http/Request.php';
