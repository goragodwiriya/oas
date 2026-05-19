<?php

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

/**
 * Log destination.
 * LOG_FILE   = Log to error_log.php file only
 * LOG_SYSTEM = Log to PHP/Apache error log only (error_log())
 * LOG_BOTH   = Log to both destinations
 *
 * @var string
 */
if (!defined('LOG_DESTINATION')) {
    define('LOG_DESTINATION', 'LOG_BOTH');
}

/* Display errors */
if (DEBUG > 0) {
    /* During development, display PHP errors and warnings */
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(-1);
} else {
    /* During production */
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

/*
 * Framework Version
 *
 * @var string
 */
define('VERSION', '7.0.0');

/*
 * Enable database query logging.
 * It should be set to false in production.
 *
 * @var bool
 */
if (!defined('DB_LOG')) {
    define('DB_LOG', false);
}

/*
 * Database query log file.
 * Used when DB_LOG is enabled.
 *
 * @var string
 */
if (!defined('DB_LOG_FILE')) {
    define('DB_LOG_FILE', defined('DATA_FOLDER') ? DATA_FOLDER.'logs/sql_log.php' : 'sql_log.php');
}

/*
 * Number of days to retain SQL query logs.
 *
 * @var int
 */
if (!defined('DB_LOG_RETENTION_DAYS')) {
    define('DB_LOG_RETENTION_DAYS', 7);
}

/**
 * Enable database query caching.
 * Set to true for production, false for development.
 *
 * @var bool
 */
if (!defined('DB_CACHE')) {
    define('DB_CACHE', false);
}

/**
 * Cache driver type.
 * file   = Store cache in files (default)
 * memory = Store cache in memory (lost when request ends)
 * redis  = Store cache in Redis server
 *
 * @var string
 */
if (!defined('CACHE_DRIVER')) {
    define('CACHE_DRIVER', 'file');
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
    if (PHP_SAPI === 'cli') {
        // Set a default host when running from CLI
        $host = 'localhost';
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])));
    } elseif (empty($_SERVER['HTTP_HOST'])) {
        $host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
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
    define('TOKEN_LIMIT', 100);
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
    // Check if debugger has data (may have been cleared by Response::send())
    if (empty(\Kotchasan::$debugger) || !is_array(\Kotchasan::$debugger)) {
        return;
    }
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
            \Kotchasan::$debugger[] = '"'.str_replace(['/', '"'], ['\/', '\"'], strval($expression)).'"';
        }
    }
}

/**
 * Custom error handler
 *
 * This code segment defines a custom error handler that handles PHP errors and exceptions.
 * If the application is not in debug mode (DEBUG != 2), errors are logged based on LOG_DESTINATION.
 * Supports logging to file, system (Apache/PHP error log), or both.
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

        // Format error message for file logging (with HTML)
        $htmlMessage = '<br>'.$type.' : <em>'.$errstr.'</em> in <b>'.$errfile.'</b> on line <b>'.$errline.'</b>';

        // Format error message for system log (plain text)
        $plainMessage = $type.' : '.$errstr.' in '.$errfile.' on line '.$errline;

        // Log based on LOG_DESTINATION
        $destination = defined('LOG_DESTINATION') ? LOG_DESTINATION : 'LOG_BOTH';

        if ($destination === 'LOG_SYSTEM' || $destination === 'LOG_BOTH') {
            // Log to PHP/Apache error log
            error_log($plainMessage);
        }

        if ($destination === 'LOG_FILE' || $destination === 'LOG_BOTH') {
            // Log to file
            \Kotchasan\Logger::create()->error($htmlMessage);
        }
    });

    set_exception_handler(function ($e) {
        $trace = $e->getTrace();
        if (empty($trace)) {
            $trace = [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        } else {
            $trace = next($trace);
        }

        // Format error message for file logging (with HTML)
        $htmlMessage = '<br>Exception : <em>'.$e->getMessage().'</em>';
        if (isset($trace['file']) && isset($trace['line'])) {
            $htmlMessage .= ' in <b>'.$trace['file'].'</b> on line <b>'.$trace['line'].'</b>';
        }

        // Format error message for system log (plain text)
        $plainMessage = 'Exception : '.$e->getMessage();
        if (isset($trace['file']) && isset($trace['line'])) {
            $plainMessage .= ' in '.$trace['file'].' on line '.$trace['line'];
        }
        // Add stack trace for system log
        $plainMessage .= "\nStack trace:\n".$e->getTraceAsString();

        // Log based on LOG_DESTINATION
        $destination = defined('LOG_DESTINATION') ? LOG_DESTINATION : 'LOG_BOTH';

        if ($destination === 'LOG_SYSTEM' || $destination === 'LOG_BOTH') {
            // Log to PHP/Apache error log
            error_log($plainMessage);
        }

        if ($destination === 'LOG_FILE' || $destination === 'LOG_BOTH') {
            // Log to file
            \Kotchasan\Logger::create()->error($htmlMessage);
        }
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
    // Security: reject null bytes and empty/non-string input.
    // Path traversal is already prevented by preg_match_all below, which only
    // extracts separator and alphanumeric characters before any is_file() call.
    if (!is_string($className) || $className === '' || strpos($className, "\0") !== false) {
        return null;
    }
    if (preg_match_all('/([\/\\])([a-zA-Z0-9]+)/', $className, $match)) {
        $className = implode(DIRECTORY_SEPARATOR, $match[1]).'.php';
        if (is_file(ROOT_PATH.$className)) {
            return ROOT_PATH.$className;
        } elseif (is_file(ROOT_PATH.strtolower($className))) {
            return ROOT_PATH.strtolower($className);
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
    $files = [
        'Kotchasan\Cache\CacheFactory' => 'Cache/CacheFactory.php',
        'Kotchasan\Cache\CacheInterface' => 'Cache/CacheInterface.php',
        'Kotchasan\Cache\FileCache' => 'Cache/FileCache.php',
        'Kotchasan\Cache\MemoryCache' => 'Cache/MemoryCache.php',
        'Kotchasan\Cache\QueryCache' => 'Cache/QueryCache.php',
        'Kotchasan\Cache\RedisCache' => 'Cache/RedisCache.php',
        'Kotchasan\Connection\Connection' => 'Connection/Connection.php',
        'Kotchasan\Connection\ConnectionInterface' => 'Connection/ConnectionInterface.php',
        'Kotchasan\Connection\ConnectionManager' => 'Connection/ConnectionManager.php',
        'Kotchasan\Connection\DriverInterface' => 'Connection/DriverInterface.php',
        'Kotchasan\Connection\MSSQLDriver' => 'Connection/MSSQLDriver.php',
        'Kotchasan\Connection\MySQLDriver' => 'Connection/MySQLDriver.php',
        'Kotchasan\Connection\PostgreSQLDriver' => 'Connection/PostgreSQLDriver.php',
        'Kotchasan\Connection\SQLiteDriver' => 'Connection/SQLiteDriver.php',
        'Kotchasan\Database\TableConfiguration' => 'Database/TableConfiguration.php',
        'Kotchasan\Database\Sql' => 'Database/Sql.php',
        'Kotchasan\Exception\ConfigurationException' => 'Exception/ConfigurationException.php',
        'Kotchasan\Exception\DatabaseException' => 'Exception/DatabaseException.php',
        'Kotchasan\Execution\PDOStatement' => 'Execution/PDOStatement.php',
        'Kotchasan\Execution\StatementInterface' => 'Execution/StatementInterface.php',
        'Kotchasan\Http\Application' => 'Http/Application.php',
        'Kotchasan\Http\Auth\AuthFactory' => 'Http/Auth/AuthFactory.php',
        'Kotchasan\Http\InputItem' => 'Http/InputItem.php',
        'Kotchasan\Http\Inputs' => 'Http/Inputs.php',
        'Kotchasan\Http\Request' => 'Http/Request.php',
        'Kotchasan\Http\Response' => 'Http/Response.php',
        'Kotchasan\Http\Router' => 'Http/Router.php',
        'Kotchasan\Http\Stream' => 'Http/Stream.php',
        'Kotchasan\Http\UploadedFile' => 'Http/UploadedFile.php',
        'Kotchasan\Http\Uri' => 'Http/Uri.php',
        'Kotchasan\Http\Middleware\AuthorizationMiddleware' => 'Http/Middleware/AuthorizationMiddleware.php',
        'Kotchasan\Http\Middleware\BasicAuthMiddleware' => 'Http/Middleware/BasicAuthMiddleware.php',
        'Kotchasan\Http\Middleware\BearerTokenAuthMiddleware' => 'Http/Middleware/BearerTokenAuthMiddleware.php',
        'Kotchasan\Http\Middleware\DigestAuthMiddleware' => 'Http/Middleware/DigestAuthMiddleware.php',
        'Kotchasan\Http\Middleware\JwtMiddleware' => 'Http/Middleware/JwtMiddleware.php',
        'Kotchasan\Http\Middleware\MiddlewareInterface' => 'Http/Middleware/MiddlewareInterface.php',
        'Kotchasan\Http\Middleware\BaseMiddleware' => 'Http/Middleware/BaseMiddleware.php',
        'Kotchasan\Http\Middleware\SecurityMiddleware' => 'Http/Middleware/SecurityMiddleware.php',
        'Kotchasan\Logger\ConsoleLogger' => 'Logger/ConsoleLogger.php',
        'Kotchasan\Logger\FileLogger' => 'Logger/FileLogger.php',
        'Kotchasan\Logger\Logger' => 'Logger/Logger.php',
        'Kotchasan\Logger\LoggerInterface' => 'Logger/LoggerInterface.php',
        'Kotchasan\Logger\QueryLogger' => 'Logger/QueryLogger.php',
        'Kotchasan\Logger\QueryLoggerInterface' => 'Logger/QueryLoggerInterface.php',
        'Kotchasan\Logger\SqlFileLogger' => 'Logger/SqlFileLogger.php',
        'Kotchasan\Logger\SqlQueryLogger' => 'Logger/SqlQueryLogger.php',
        'Kotchasan\Logger\SystemLogger' => 'Logger/SystemLogger.php',
        'Kotchasan\Psr\Http\Message\UploadedFileInterface' => 'Psr/Http/Message/UploadedFileInterface.php',
        'Kotchasan\Psr\Http\Message\ResponseInterface' => 'Psr/Http/Message/ResponseInterface.php',
        'Kotchasan\Psr\Http\Message\UploadedFileInterface' => 'Psr/Http/Message/UploadedFileInterface.php',
        'Kotchasan\QueryBuilder\DeleteBuilder' => 'QueryBuilder/DeleteBuilder.php',
        'Kotchasan\QueryBuilder\Factory\SqlBuilderFactory' => 'QueryBuilder/Factory/SqlBuilderFactory.php',
        'Kotchasan\QueryBuilder\Functions\AbstractSQLFunctionBuilder' => 'QueryBuilder/Functions/AbstractSQLFunctionBuilder.php',
        'Kotchasan\QueryBuilder\Functions\FunctionBuilderFactory' => 'QueryBuilder/Functions/FunctionBuilderFactory.php',
        'Kotchasan\QueryBuilder\Functions\JSONFunctions' => 'QueryBuilder/Functions/JSONFunctions.php',
        'Kotchasan\QueryBuilder\Functions\MySQLFunctionBuilder' => 'QueryBuilder/Functions/MySQLFunctionBuilder.php',
        'Kotchasan\QueryBuilder\Functions\PostgreSQLFunctionBuilder' => 'QueryBuilder/Functions/PostgreSQLFunctionBuilder.php',
        'Kotchasan\QueryBuilder\Functions\SQLFunctionBuilderInterface' => 'QueryBuilder/Functions/SQLFunctionBuilderInterface.php',
        'Kotchasan\QueryBuilder\Functions\SQLiteFunctionBuilder' => 'QueryBuilder/Functions/SQLiteFunctionBuilder.php',
        'Kotchasan\QueryBuilder\Functions\SQLServerFunctionBuilder' => 'QueryBuilder/Functions/SQLServerFunctionBuilder.php',
        'Kotchasan\QueryBuilder\SqlBuilder\AbstractSqlBuilder' => 'QueryBuilder/SqlBuilder/AbstractSqlBuilder.php',
        'Kotchasan\QueryBuilder\SqlBuilder\MySqlSqlBuilder' => 'QueryBuilder/SqlBuilder/MySqlSqlBuilder.php',
        'Kotchasan\QueryBuilder\SqlBuilder\PostgreSqlSqlBuilder' => 'QueryBuilder/SqlBuilder/PostgreSqlSqlBuilder.php',
        'Kotchasan\QueryBuilder\SqlBuilder\SqlBuilderInterface' => 'QueryBuilder/SqlBuilder/SqlBuilderInterface.php',
        'Kotchasan\QueryBuilder\SqlBuilder\SqliteSqlBuilder' => 'QueryBuilder/SqlBuilder/SqliteSqlBuilder.php',
        'Kotchasan\QueryBuilder\SqlBuilder\SqlServerSqlBuilder' => 'QueryBuilder/SqlBuilder/SqlServerSqlBuilder.php',
        'Kotchasan\QueryBuilder\InsertBuilder' => 'QueryBuilder/InsertBuilder.php',
        'Kotchasan\QueryBuilder\MySQLFunctionBuilder' => 'QueryBuilder/MySQLFunctionBuilder.php',
        'Kotchasan\QueryBuilder\QueryBuilderInterface' => 'QueryBuilder/QueryBuilderInterface.php',
        'Kotchasan\QueryBuilder\QueryBuilder' => 'QueryBuilder/QueryBuilder.php',
        'Kotchasan\QueryBuilder\RawExpression' => 'QueryBuilder/RawExpression.php',
        'Kotchasan\QueryBuilder\SelectBuilder' => 'QueryBuilder/SelectBuilder.php',
        'Kotchasan\QueryBuilder\UpdateBuilder' => 'QueryBuilder/UpdateBuilder.php',
        'Kotchasan\Result\ArrayResult' => 'Result/ArrayResult.php',
        'Kotchasan\Result\PDOResult' => 'Result/PDOResult.php',
        'Kotchasan\Result\ResultInterface' => 'Result/ResultInterface.php',
        'Kotchasan\Accordion' => 'Accordion.php',
        'Kotchasan\ApiController' => 'ApiController.php',
        'Kotchasan\ApiException' => 'ApiException.php',
        'Kotchasan\ArrayTool' => 'ArrayTool.php',
        'Kotchasan\Barcode' => 'Barcode.php',
        'Kotchasan\CKEditor' => 'CKEditor.php',
        'Kotchasan\Collection' => 'Collection.php',
        'Kotchasan\Country' => 'Country.php',
        'Kotchasan\Csv' => 'Csv.php',
        'Kotchasan\Curl' => 'Curl.php',
        'Kotchasan\Currency' => 'Currency.php',
        'Kotchasan\Database' => 'Database.php',
        'Kotchasan\DataTable' => 'DataTable.php',
        'Kotchasan\Date' => 'Date.php',
        'Kotchasan\DOMNode' => 'DOMNode.php',
        'Kotchasan\DOMParser' => 'DOMParser.php',
        'Kotchasan\Email' => 'Email.php',
        'Kotchasan\File' => 'File.php',
        'Kotchasan\Files' => 'Files.php',
        'Kotchasan\Form' => 'Form.php',
        'Kotchasan\Grid' => 'Grid.php',
        'Kotchasan\Htmldoc' => 'Htmldoc.php',
        'Kotchasan\Html' => 'Html.php',
        'Kotchasan\HtmlTable' => 'HtmlTable.php',
        'Kotchasan\Image' => 'Image.php',
        'Kotchasan\Input' => 'Input.php',
        'Kotchasan\JwtMiddleware' => 'JwtMiddleware.php',
        'Kotchasan\Jwt' => 'Jwt.php',
        'Kotchasan\Language' => 'Language.php',
        'Kotchasan\ListItem' => 'ListItem.php',
        'Kotchasan\Logger' => 'Logger.php',
        'Kotchasan\Login' => 'Login.php',
        'Kotchasan\Menu' => 'Menu.php',
        'Kotchasan\Mime' => 'Mime.php',
        'Kotchasan\Model' => 'Model.php',
        'Kotchasan\Number' => 'Number.php',
        'Kotchasan\Password' => 'Password.php',
        'Kotchasan\Pdf' => 'Pdf.php',
        'Kotchasan\Promptpay' => 'Promptpay.php',
        'Kotchasan\Province' => 'Province.php',
        'Kotchasan\Session' => 'Session.php',
        'Kotchasan\Singleton' => 'Singleton.php',
        'Kotchasan\Tab' => 'Tab.php',
        'Kotchasan\Template' => 'Template.php',
        'Kotchasan\Text' => 'Text.php',
        'Kotchasan\Validator' => 'Validator.php',
        'Kotchasan\View' => 'View.php',
        'Kotchasan\Xls' => 'Xls.php'
    ];

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
require_once VENDOR_DIR.'KBase.php';
require_once VENDOR_DIR.'Config.php';
require_once VENDOR_DIR.'Http/Traits/RequestParametersTrait.php';
require_once VENDOR_DIR.'Http/Traits/RequestSecurityTrait.php';
require_once VENDOR_DIR.'Http/Traits/RequestMethodTrait.php';
require_once VENDOR_DIR.'Http/Traits/RequestInfoTrait.php';
require_once VENDOR_DIR.'Http/Traits/RequestCookieTrait.php';
require_once VENDOR_DIR.'Psr/Http/Message/MessageInterface.php';
require_once VENDOR_DIR.'Psr/Http/Message/RequestInterface.php';
require_once VENDOR_DIR.'Psr/Http/Message/UriInterface.php';
require_once VENDOR_DIR.'Psr/Http/Message/ServerRequestInterface.php';
require_once VENDOR_DIR.'Psr/Http/Message/StreamInterface.php';
require_once VENDOR_DIR.'Http/AbstractMessage.php';
require_once VENDOR_DIR.'Http/AbstractRequest.php';
require_once VENDOR_DIR.'Http/Request.php';
require_once VENDOR_DIR.'Http/Uri.php';
require_once VENDOR_DIR.'Http/Stream.php';
require_once VENDOR_DIR.'Router.php';
require_once VENDOR_DIR.'Kotchasan.php';
require_once VENDOR_DIR.'Controller.php';
