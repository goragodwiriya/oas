<?php
/**
 * @filesource Kotchasan/load.php
 *
 * Main file for setting default configurations during framework loading
 * This file must be included before anything else.
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

/**
 * Error reporting level.
 * 0: Log severe errors to error_log.php (in production)
 * 1: Log errors and warnings to error_log.php
 * 2: Display errors and warnings on screen (for development only)
 *
 * @var int
 */
if (!defined('DEBUG')) {
    define('DEBUG', 0);
}

/* Display errors */
if (DEBUG > 0) {
    /* During development, display PHP errors and warnings */
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(-1);
} else {
    /* During production */
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
}

/*
 * Framework Version
 *
 * @var string
 */
define('VERSION', '6.0.0');

/*
 * Enable database query logging.
 * It should be set to false in production.
 *
 * @var bool
 */
if (!defined('DB_LOG')) {
    define('DB_LOG', false);
}

/**
 * Framework directory.
 */
$vendorDir = str_replace('load.php', '', __FILE__);
if (DIRECTORY_SEPARATOR != '/') {
    $vendorDir = str_replace('\\', '/', $vendorDir);
}
define('VENDOR_DIR', $vendorDir);

/*
 * Application path, e.g., D:/htdocs/kotchasan/
 */
$appPath = '';

/*
 * Server's document root path, e.g., D:/htdocs/
 */
$docRoot = dirname($vendorDir);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $docRoot.'/');
}

/**
 * Document root (Server).
 */
$contextPrefix = '';
if (!empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['SCRIPT_FILENAME'], $_SERVER['DOCUMENT_ROOT']) !== false) {
    $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR);
    if (DIRECTORY_SEPARATOR != '/' && $docRoot != '') {
        $docRoot = str_replace('\\', '/', $docRoot);
    }
} else {
    $dir = basename($docRoot);
    $ds = explode($dir, dirname($_SERVER['SCRIPT_NAME']), 2);
    if (count($ds) > 1) {
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

/*
 * Application path, e.g., D:/htdocs/kotchasan/
 */
if (!defined('APP_PATH')) {
    $appPath = dirname($_SERVER['SCRIPT_NAME']);
    if (DIRECTORY_SEPARATOR != '/') {
        $appPath = str_replace('\\', '/', $appPath);
    }
    define('APP_PATH', rtrim($docRoot.$appPath, '/').'/');
}

/*
 *  http or https
 */
if (defined('HTTPS')) {
    $scheme = HTTPS ? 'https://' : 'http://';
} else {
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'].'://';
    } elseif ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
        $scheme = 'https://';
    } else {
        $scheme = 'http://';
    }
    define('HTTPS', $scheme == 'https://');
}

/*
 * Host name
 */
if (defined('HOST')) {
    $host = HOST;
} else {
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
    } elseif (empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['SERVER_NAME'];
    } else {
        $host = $_SERVER['HTTP_HOST'];
    }
    define('HOST', str_replace('www.', '', $host));
}

/*
 * Base directory of the website installation starting from DOCUMENT_ROOT
 * For example, kotchasan/
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
 * URL of the website including the path, e.g., http://domain.tld/folder
 */
if (!defined('WEB_URL')) {
    define('WEB_URL', (HTTPS ? 'https://' : $scheme).$host.$contextPrefix.str_replace($docRoot, '', ROOT_PATH));
}

/*
 * Token settings.
 * The limit and age of tokens.
 */
if (!defined('TOKEN_LIMIT')) {
    define('TOKEN_LIMIT', 10);
}
if (!defined('TOKEN_AGE')) {
    define('TOKEN_AGE', 3600);
}

/**
 * Create a new instance of a class.
 *
 * @param  string $className The class name
 *
 * @return object|null        An instance of the class or null if the class doesn't exist
 */
function createClass($className, $param = null)
{
    return new $className($param);
}

/**
 * Shutdown function to output debug messages to the browser console.
 */
function doShutdown()
{
    echo '<script>';
    foreach (\Kotchasan::$debugger as $item) {
        echo 'console.log('.$item.');';
    }
    echo '</script>';
}

/**
 * Output debug information to the browser console.
 *
 * @param  mixed $data The variable to be displayed in the console
 */
function debug($expression)
{
    if (\Kotchasan::$debugger === null) {
        \Kotchasan::$debugger = [];
        register_shutdown_function('doShutdown');
    }
    $debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    \Kotchasan::$debugger[] = '"'.$debug[0]['file'].' : '.$debug[0]['line'].'"';
    foreach (func_get_args() as $expression) {
        if (is_array($expression) || is_object($expression)) {
            \Kotchasan::$debugger[] = json_encode((array) $expression);
        } else {
            \Kotchasan::$debugger[] = '"'.str_replace(array('/', '"'), array('\/', '\"'), strval($expression)).'"';
        }
    }
}

/**
 * Custom error handler
 *
 * This code segment defines a custom error handler that handles PHP errors and exceptions.
 * If the application is not in debug mode (DEBUG != 2), errors are logged to a file. Otherwise, errors are displayed.
 */
if (DEBUG != 2) {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

        // Create a log entry with the error information
        \Kotchasan\Log\Logger::create()->error('<br>'.$type.' : <em>'.$errstr.'</em> in <b>'.$errfile.'</b> on line <b>'.$errline.'</b>');
    });

    set_exception_handler(function ($e) {
        $trace = $e->getTrace();
        if (empty($trace)) {
            $trace = array(
                'file' => $e->getFile(),
                'line' => $e->getLine()
            );
        } else {
            $trace = next($trace);
        }
        $error = '<br>Exception : <em>'.$e->getMessage().'</em>';
        if (isset($trace['file']) && isset($trace['line'])) {
            $error .= ' in <b>'.$trace['file'].'</b> on line <b>'.$trace['line'].'</b>';
        }

        // Create a log entry with the exception information
        \Kotchasan\Log\Logger::create()->error($error);
    });
}

/**
 * Get the file path of a class based on its name.
 *
 * @param  string $className The class name
 *
 * @return string            The file path of the class or an empty string if the class is not found
 */
function getClassPath($className)
{
    if (preg_match_all('/([\/\\])([a-zA-Z0-9]+)/', $className, $match)) {
        $className = implode(DIRECTORY_SEPARATOR, $match[1]).'.php';
        if (is_file(ROOT_PATH.$className)) {
            return ROOT_PATH.$className;
        } elseif (isset($match[1][2])) {
            if (isset($match[1][3])) {
                $className = strtolower('modules'.DIRECTORY_SEPARATOR.$match[1][0].DIRECTORY_SEPARATOR.$match[1][3].'s'.DIRECTORY_SEPARATOR.$match[1][1].DIRECTORY_SEPARATOR.$match[1][2].'.php');
            } else {
                $className = strtolower('modules'.DIRECTORY_SEPARATOR.$match[1][0].DIRECTORY_SEPARATOR.$match[1][2].'s'.DIRECTORY_SEPARATOR.$match[1][1].'.php');
            }
            if (is_file(APP_PATH.$className)) {
                return APP_PATH.$className;
            } elseif (is_file(ROOT_PATH.$className)) {
                return ROOT_PATH.$className;
            }
        }
    }
    return null;
}

/**
 * Class Autoloader
 *
 * This section defines an autoloader function that is responsible for dynamically loading classes.
 * It uses a predefined mapping array to match class names with their corresponding file paths.
 * If a class is found in the mapping array, the corresponding file is required, otherwise, the getClassPath() function is used to locate the file.
 * Once the file is found, it is required to load the class.
 */
spl_autoload_register(function ($className) {
    $files = array(
        'Kotchasan\Cache\ApcCache' => 'Cache/ApcCache.php',
        'Kotchasan\Cache\Cache' => 'Cache/Cache.php',
        'Kotchasan\Cache\CacheItem' => 'Cache/CacheItem.php',
        'Kotchasan\Cache\Exception' => 'Cache/Exception.php',
        'Kotchasan\Cache\FileCache' => 'Cache/FileCache.php',
        'Kotchasan\Database\Db' => 'Database/Db.php',
        'Kotchasan\Database\DbCache' => 'Database/DbCache.php',
        'Kotchasan\Database\Driver' => 'Database/Driver.php',
        'Kotchasan\Database\Exception' => 'Database/Exception.php',
        'Kotchasan\Database\PdoMysqlDriver' => 'Database/PdoMysqlDriver.php',
        'Kotchasan\Database\Query' => 'Database/Query.php',
        'Kotchasan\Database\QueryBuilder' => 'Database/QueryBuilder.php',
        'Kotchasan\Database\Schema' => 'Database/Schema.php',
        'Kotchasan\Database\Sql' => 'Database/Sql.php',
        'Kotchasan\Http\Message' => 'Http/Message.php',
        'Kotchasan\Http\NotFound' => 'Http/NotFound.php',
        'Kotchasan\Http\Response' => 'Http/Response.php',
        'Kotchasan\Http\Stream' => 'Http/Stream.php',
        'Kotchasan\Http\UploadedFile' => 'Http/UploadedFile.php',
        'Kotchasan\Log\AbstractLogger' => 'Log/AbstractLogger.php',
        'Kotchasan\Log\Logger' => 'Log/Logger.php',
        'Kotchasan\Orm\Field' => 'Orm/Field.php',
        'Kotchasan\Orm\Recordset' => 'Orm/Recordset.php',
        'Kotchasan\PHPMailer\class' => 'PHPMailer/class.php',
        'Kotchasan\PHPMailer\class.smtp' => 'PHPMailer/class.smtp.php',
        'Psr\Cache\CacheItemInterface' => 'Psr/Cache/CacheItemInterface.php',
        'Psr\Cache\CacheItemPoolInterface' => 'Psr/Cache/CacheItemPoolInterface.php',
        'Psr\Http\Message\ResponseInterface' => 'Psr/Http/Message/ResponseInterface.php',
        'Psr\Http\Message\ServerRequestInterface' => 'Psr/Http/Message/ServerRequestInterface.php',
        'Psr\Http\Message\StreamInterface' => 'Psr/Http/Message/StreamInterface.php',
        'Psr\Http\Message\UploadedFileInterface' => 'Psr/Http/Message/UploadedFileInterface.php',
        'Psr\Log\AbstractLogger' => 'Psr/Log/AbstractLogger.php',
        'Psr\Log\LogLevel' => 'Psr/Log/LogLevel.php',
        'Psr\Log\LoggerAwareInterface' => 'Psr/Log/LoggerAwareInterface.php',
        'Psr\Log\LoggerInterface' => 'Psr/Log/LoggerInterface.php',
        'Psr\Log\LoggerTrait' => 'Psr/Log/LoggerTrait.php',
        'Psr\Log\NullLogger' => 'Psr/Log/NullLogger.php',
        'Kotchasan\Accordion' => 'Accordion.php',
        'Kotchasan\ArrayTool' => 'ArrayTool.php',
        'Kotchasan\Barcode' => 'Barcode.php',
        'Kotchasan\CKEditor' => 'CKEditor.php',
        'Kotchasan\Collection' => 'Collection.php',
        'Kotchasan\Country' => 'Country.php',
        'Kotchasan\Csv' => 'Csv.php',
        'Kotchasan\Curl' => 'Curl.php',
        'Kotchasan\Currency' => 'Currency.php',
        'Kotchasan\DOMNode' => 'DOMNode.php',
        'Kotchasan\DOMParser' => 'DOMParser.php',
        'Kotchasan\DataTable' => 'DataTable.php',
        'Kotchasan\Database' => 'Database.php',
        'Kotchasan\Date' => 'Date.php',
        'Kotchasan\Email' => 'Email.php',
        'Kotchasan\File' => 'File.php',
        'Kotchasan\Files' => 'Files.php',
        'Kotchasan\Form' => 'Form.php',
        'Kotchasan\Grid' => 'Grid.php',
        'Kotchasan\Html' => 'Html.php',
        'Kotchasan\HtmlTable' => 'HtmlTable.php',
        'Kotchasan\Htmldoc' => 'Htmldoc.php',
        'Kotchasan\Image' => 'Image.php',
        'Kotchasan\InputItem' => 'InputItem.php',
        'Kotchasan\Inputs' => 'Inputs.php',
        'Kotchasan\Jwt' => 'Jwt.php',
        'Kotchasan\Language' => 'Language.php',
        'Kotchasan\ListItem' => 'ListItem.php',
        'Kotchasan\Login' => 'Login.php',
        'Kotchasan\Menu' => 'Menu.php',
        'Kotchasan\Mime' => 'Mime.php',
        'Kotchasan\Model' => 'Model.php',
        'Kotchasan\Number' => 'Number.php',
        'Kotchasan\Password' => 'Password.php',
        'Kotchasan\Pdf' => 'Pdf.php',
        'Kotchasan\Promptpay' => 'Promptpay.php',
        'Kotchasan\Province' => 'Province.php',
        'Kotchasan\Singleton' => 'Singleton.php',
        'Kotchasan\Tab' => 'Tab.php',
        'Kotchasan\Template' => 'Template.php',
        'Kotchasan\Text' => 'Text.php',
        'Kotchasan\Validator' => 'Validator.php',
        'Kotchasan\View' => 'View.php',
        'Kotchasan\load' => 'load.php',
        'Kotchasan\InputItemException' => 'InputItemException.php',
        'Kotchasan\Xls' => 'Xls.php'
    );

    if (isset($files[$className])) {
        $file = VENDOR_DIR.$files[$className];
    } else {
        $file = getClassPath($className);
    }

    if ($file !== null) {
        require $file;
    }
});

/**
 * Load initial classes
 */
require VENDOR_DIR.'KBase.php';
require VENDOR_DIR.'Config.php';
require VENDOR_DIR.'Psr/Http/Message/MessageInterface.php';
require VENDOR_DIR.'Psr/Http/Message/RequestInterface.php';
require VENDOR_DIR.'Psr/Http/Message/UriInterface.php';
require VENDOR_DIR.'Http/AbstractMessage.php';
require VENDOR_DIR.'Http/AbstractRequest.php';
require VENDOR_DIR.'Http/Request.php';
require VENDOR_DIR.'Http/Uri.php';
require VENDOR_DIR.'Router.php';
require VENDOR_DIR.'Kotchasan.php';
require VENDOR_DIR.'Controller.php';
