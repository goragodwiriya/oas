<?php
namespace Kotchasan;

use Kotchasan\Cache\CacheInterface;
use Kotchasan\Cache\QueryCache;
use Kotchasan\Connection\ConnectionInterface;
use Kotchasan\Connection\ConnectionManager;
use Kotchasan\Exception\DatabaseException;
use Kotchasan\Logger\LoggerInterface;
use Kotchasan\Logger\QueryLogger;
use Kotchasan\Logger\SqlFileLogger;
use Kotchasan\Logger\SqlQueryLogger;
use Kotchasan\QueryBuilder\DeleteBuilder;
use Kotchasan\QueryBuilder\InsertBuilder;
use Kotchasan\QueryBuilder\QueryBuilderInterface;
use Kotchasan\QueryBuilder\SelectBuilder;
use Kotchasan\QueryBuilder\UpdateBuilder;

/**
 * Class Database
 *
 * Main entry point for database operations.
 *
 * @package Kotchasan
 */
class Database
{
    /**
     * The connection manager instance.
     *
     * @var ConnectionManager|null
     */
    protected static ?ConnectionManager $connectionManager = null;

    /**
     * The current connection.
     *
     * @var ConnectionInterface
     */
    protected ConnectionInterface $connection;

    /**
     * The current connection name.
     *
     * @var string
     */
    protected string $connectionName;

    /**
     * The last executed query.
     *
     * @var string|null
     */
    protected ?string $lastQuery = null;

    /**
     * The last error message.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * The query cache instance.
     *
     * @var QueryCache|null
     */
    protected static ?QueryCache $queryCache = null;

    /**
     * Flag to track if database config has been loaded from file
     *
     * @var bool
     */
    protected static bool $configLoaded = false;

    /**
     * Database constructor.
     *
     * @param ConnectionInterface $connection The database connection.
     * @param string $connectionName The connection name.
     */
    protected function __construct(ConnectionInterface $connection, string $connectionName = 'default')
    {
        $this->connection = $connection;
        $this->connectionName = $connectionName;
    }

    /**
     * Resets the Database static state.
     *
     * This is useful for testing when you need to reconfigure the database
     * with different settings without interference from previous configurations.
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$connectionManager = null;
        static::$queryCache = null;
        static::$configLoaded = false;
    }

    /**
     * Creates a new database instance.
     *
     * @param string $connectionName The name of the connection to use.
     * @return Database The database instance.
     * @throws DatabaseException If the connection manager is not initialized.
     */
    public static function create(string $connectionName = 'default'): self
    {
        // Auto-load config from settings/database.php if not already loaded
        if (!static::$configLoaded) {
            static::loadConfigFromFile();
        }

        if (static::$connectionManager === null) {
            throw new DatabaseException('Connection manager is not initialized. Call Database::config() first.');
        }

        $connection = static::$connectionManager->getConnection($connectionName);

        return new self($connection, $connectionName);
    }

    /**
     * Loads database configuration from settings/database.php file
     *
     * @return void
     */
    protected static function loadConfigFromFile(): void
    {
        $config = [];

        // Check if database config file exists in APP_PATH or ROOT_PATH
        if (defined('APP_PATH') && is_file(APP_PATH.'settings/database.php')) {
            $config = include APP_PATH.'settings/database.php';
        } elseif (defined('ROOT_PATH') && is_file(ROOT_PATH.'settings/database.php')) {
            $config = include ROOT_PATH.'settings/database.php';
        }

        if (!empty($config)) {
            // Validate and process the configuration
            $processedConfig = static::validateAndProcessConfig($config);
            static::config($processedConfig);
            static::$configLoaded = true;
        }
    }

    /**
     * Validates and processes database configuration including table mappings.
     *
     * @param array $config The raw configuration array
     * @return array The processed configuration array
     * @throws DatabaseException If configuration validation fails
     */
    protected static function validateAndProcessConfig(array $config): array
    {
        $processedConfig = [];

        // Handle legacy config format where first connection becomes 'default'
        $hasDefaultConnection = isset($config['default']);
        $firstConnectionKey = null;

        foreach ($config as $key => $value) {
            if ($key === 'tables') {
                // Validate global tables configuration
                if (!is_array($value)) {
                    throw new DatabaseException("Global 'tables' configuration must be an array.");
                }

                // Validate each table mapping
                foreach ($value as $logicalName => $physicalName) {
                    if (!is_string($logicalName) || !is_string($physicalName)) {
                        throw new DatabaseException("Table mappings must be string to string mappings. Invalid mapping for '{$logicalName}'.");
                    }

                    if (empty($logicalName) || empty($physicalName)) {
                        throw new DatabaseException("Table names cannot be empty. Invalid mapping: '{$logicalName}' => '{$physicalName}'.");
                    }
                }

                $processedConfig[$key] = $value;
            } elseif (is_array($value) && static::isConnectionConfig($value)) {
                // Process connection configuration
                $processedConfig[$key] = static::validateConnectionConfig($key, $value);

                // Track first connection for legacy support
                if ($firstConnectionKey === null) {
                    $firstConnectionKey = $key;
                }
            } else {
                // Keep non-array values as-is (for backward compatibility)
                $processedConfig[$key] = $value;
            }
        }

        // For legacy support: if no 'default' connection exists,
        // make the first connection available as 'default'
        if (!$hasDefaultConnection && $firstConnectionKey !== null) {
            $processedConfig['default'] = $processedConfig[$firstConnectionKey];

            // Copy global tables to default connection if not already set
            if (isset($processedConfig['tables']) && !isset($processedConfig['default']['tables'])) {
                $processedConfig['default']['tables'] = $processedConfig['tables'];
            }
        }

        return $processedConfig;
    }

    /**
     * Check if an array looks like a connection configuration
     *
     * @param array $config
     * @return bool
     */
    protected static function isConnectionConfig(array $config): bool
    {
        // Check for common database connection keys
        $connectionKeys = ['dbdriver', 'hostname', 'username', 'password', 'dbname', 'host', 'driver'];

        foreach ($connectionKeys as $key) {
            if (isset($config[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates a single connection configuration.
     *
     * @param string $connectionName The connection name
     * @param array $connectionConfig The connection configuration
     * @return array The validated connection configuration
     * @throws DatabaseException If connection configuration is invalid
     */
    protected static function validateConnectionConfig(string $connectionName, array $connectionConfig): array
    {
        // Normalize legacy field names
        $connectionConfig = static::normalizeLegacyConfig($connectionConfig);

        // Validate prefix if present
        if (isset($connectionConfig['prefix'])) {
            if (!is_string($connectionConfig['prefix'])) {
                throw new DatabaseException("Connection '{$connectionName}': 'prefix' must be a string.");
            }
        }

        // Validate tables configuration if present
        if (isset($connectionConfig['tables'])) {
            if (!is_array($connectionConfig['tables'])) {
                throw new DatabaseException("Connection '{$connectionName}': 'tables' configuration must be an array.");
            }

            // Validate each table mapping
            foreach ($connectionConfig['tables'] as $logicalName => $physicalName) {
                if (!is_string($logicalName) || !is_string($physicalName)) {
                    throw new DatabaseException("Connection '{$connectionName}': Table mappings must be string to string mappings. Invalid mapping for '{$logicalName}'.");
                }

                if (empty($logicalName) || empty($physicalName)) {
                    throw new DatabaseException("Connection '{$connectionName}': Table names cannot be empty. Invalid mapping: '{$logicalName}' => '{$physicalName}'.");
                }
            }
        }

        return $connectionConfig;
    }

    /**
     * Normalize legacy configuration field names
     *
     * @param array $config
     * @return array
     */
    protected static function normalizeLegacyConfig(array $config): array
    {
        // Map legacy field names to current ones
        $fieldMappings = [
            'dbdriver' => 'driver',
            'hostname' => 'host',
            'dbname' => 'database'
        ];

        foreach ($fieldMappings as $oldKey => $newKey) {
            if (isset($config[$oldKey]) && !isset($config[$newKey])) {
                $config[$newKey] = $config[$oldKey];
                unset($config[$oldKey]);
            }
        }

        return $config;
    }

    /**
     * Configures database connections.
     *
     * @param array $config The configuration array.
     * @return void
     */
    public static function config(array $config): void
    {
        // Preserve existing query cache if we're reinitializing
        $existingQueryCache = static::$queryCache;

        static::$connectionManager = new ConnectionManager($config);

        if (defined('DB_LOG') && DB_LOG) {
            $sqlLogger = new SqlFileLogger(DB_LOG_FILE, (int) DB_LOG_RETENTION_DAYS);
            $sqlLogger->setDebugEnabled(true);
            static::setLogger(new SqlQueryLogger($sqlLogger));
        }

        // Mark config as loaded to prevent auto-loading from file
        static::$configLoaded = true;

        // Restore query cache to new connection manager
        if ($existingQueryCache !== null) {
            static::$connectionManager->setCache(
                $existingQueryCache->getCache(),
                $existingQueryCache->getDefaultTtl()
            );
            static::$queryCache = static::$connectionManager->getQueryCache();
        }
    }

    /**
     * Configures query caching.
     *
     * @param array $config The cache configuration.
     * @param int|null $defaultTtl The default TTL for cache entries.
     * @return QueryCache The query cache instance.
     */
    public static function configureCache(array $config, ?int $defaultTtl = 3600): QueryCache
    {
        // Auto-initialize connection manager if not already done
        if (static::$connectionManager === null) {
            // Try to load config from file first
            if (!static::$configLoaded) {
                static::loadConfigFromFile();
            }

            // If still null after loading, create empty manager for cache-only usage
            if (static::$connectionManager === null) {
                static::$connectionManager = new ConnectionManager([]);
                static::$configLoaded = true;
            }
        }

        static::$queryCache = static::$connectionManager->configureCache($config, $defaultTtl);
        return static::$queryCache;
    }

    /**
     * Sets a cache instance directly.
     *
     * @param CacheInterface $cache The cache implementation.
     * @param int|null $defaultTtl The default TTL for cache entries.
     * @return QueryCache The query cache instance.
     */
    public static function setCache(CacheInterface $cache, ?int $defaultTtl = 3600): QueryCache
    {
        if (static::$connectionManager === null) {
            throw new DatabaseException('Connection manager is not initialized. Call Database::config() first.');
        }

        static::$queryCache = static::$connectionManager->setCache($cache, $defaultTtl);
        return static::$queryCache;
    }

    /**
     * Gets the current query cache.
     *
     * @return QueryCache|null The query cache instance.
     */
    public static function getQueryCache(): ?QueryCache
    {
        if (static::$connectionManager === null) {
            return null;
        }

        return static::$connectionManager->getQueryCache();
    }

    /**
     * Sets a logger for database operations.
     *
     * @param LoggerInterface $logger The logger instance.
     * @return void
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        if (static::$connectionManager === null) {
            throw new DatabaseException('Connection manager is not initialized. Call Database::config() first.');
        }

        static::$connectionManager->setLogger($logger);
    }

    /**
     * Gets the logger instance.
     *
     * @return LoggerInterface|null The logger instance.
     */
    public static function getLogger(): ?LoggerInterface
    {
        if (static::$connectionManager === null) {
            return null;
        }

        return static::$connectionManager->getLogger();
    }

    /**
     * Gets a connection by name for SqlFunction usage.
     *
     * @param string $connectionName The connection name.
     * @return ConnectionInterface|null The connection instance.
     */
    public static function getConnection(string $connectionName = 'default'): ?ConnectionInterface
    {
        if (static::$connectionManager === null) {
            return null;
        }

        try {
            return static::$connectionManager->getConnection($connectionName);
        } catch (DatabaseException $e) {
            return null;
        }
    }

    /**
     * Creates a query logger and registers it with the database.
     *
     * @param LoggerInterface $logger The underlying logger.
     * @return QueryLogger The query logger.
     */
    public static function createQueryLogger(LoggerInterface $logger): QueryLogger
    {
        $queryLogger = new QueryLogger($logger);
        static::setLogger($queryLogger);
        return $queryLogger;
    }

    /**
     * Creates a SELECT query.
     *
     * @param mixed ...$columns The columns to select.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function select(...$columns): QueryBuilderInterface
    {
        $builder = new SelectBuilder($this->connection);

        if (empty($columns)) {
            return $builder->select('*');
        }

        return $builder->select(...$columns);
    }

    /**
     * Creates a new query builder (convenience method)
     *
     * @return QueryBuilderInterface The query builder instance.
     */
    public function createQuery(): QueryBuilderInterface
    {
        return new SelectBuilder($this->connection);
    }

    /**
     * Gets the first record from a table.
     *
     * @param string $table The table name.
     * @param string|array $columns The columns to select.
     * @return mixed The first record or null if not found.
     */
    public function first(string $table, $columns = '*')
    {
        $builder = new SelectBuilder($this->connection);

        // Use QueryBuilder::first for consistency and to leverage new API
        $qb = $builder->select($columns)
            ->from($table);

        // Delegate to QueryBuilder::first which will execute the query
        return $qb->first();
    }

    /**
     * Gets all records from a table.
     *
     * @param string $table The table name.
     * @param string|array $columns The columns to select.
     * @return array All records.
     */
    public function all(string $table, $columns = '*'): array
    {
        $builder = new SelectBuilder($this->connection);

        $qb = $builder->select($columns)
            ->from($table);

        return $qb->fetchAll();
    }

    /**
     * Gets records from a table with conditions.
     *
     * @param string $table The table name.
     * @param array $where The where conditions.
     * @param string|array $columns The columns to select.
     * @return array The records.
     */
    public function get(string $table, array $where = [], $columns = '*'): array
    {
        $builder = new SelectBuilder($this->connection);

        $query = $builder->select($columns)->from($table);

        if (!empty($where)) {
            $query->where($where);
        }

        return $query->fetchAll();
    }

    /**
     * Creates a raw SELECT query.
     *
     * @param string $expression The raw SQL expression.
     * @param array $bindings The parameter bindings.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function selectRaw(string $expression, array $bindings = []): QueryBuilderInterface
    {
        $builder = new SelectBuilder($this->connection);

        return $builder->selectRaw($expression, $bindings);
    }

    /**
     * Creates an INSERT query.
     *
     * @param string $table The table to insert into.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function insert(string $table): QueryBuilderInterface
    {
        $builder = new InsertBuilder($this->connection);

        return $builder->insert($table);
    }

    /**
     * Creates an UPDATE query.
     *
     * @param string $table The table to update.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function update(string $table): QueryBuilderInterface
    {
        $builder = new UpdateBuilder($this->connection);

        return $builder->update($table);
    }

    /**
     * Creates a DELETE query.
     *
     * @param string $table The table to delete from.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function delete(string $table): QueryBuilderInterface
    {
        $builder = new DeleteBuilder($this->connection);

        return $builder->delete($table);
    }

    /**
     * Executes a raw SQL query.
     *
     * @param string $sql The SQL query.
     * @param array $params The parameter bindings.
     * @return mixed The query result.
     */
    public function raw(string $sql, array $params = [])
    {
        $this->lastQuery = $sql;

        $statement = $this->connection->getDriver()->prepare($sql);

        try {
            return $statement->execute($params);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            throw new DatabaseException('Error executing raw query: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Begins a transaction.
     *
     * @return bool True if the transaction was successfully started, false otherwise.
     */
    public function beginTransaction(): bool
    {
        return $this->connection->getDriver()->beginTransaction();
    }

    /**
     * Commits a transaction.
     *
     * @return bool True if the transaction was successfully committed, false otherwise.
     */
    public function commit(): bool
    {
        return $this->connection->getDriver()->commit();
    }

    /**
     * Rolls back a transaction.
     *
     * @return bool True if the transaction was successfully rolled back, false otherwise.
     */
    public function rollback(): bool
    {
        return $this->connection->getDriver()->rollback();
    }

    /**
     * Executes a callback within a transaction.
     *
     * @param callable $callback The callback to execute.
     * @return mixed The callback result.
     * @throws \Exception If an error occurs.
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Gets the last inserted ID.
     *
     * @param string|null $name The name of the sequence object (if applicable).
     * @return string The last inserted ID.
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->connection->getDriver()->lastInsertId($name);
    }

    /**
     * Gets the last executed query.
     *
     * @return string|null The last executed query or null if no query has been executed.
     */
    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    /**
     * Gets the last error message.
     *
     * @return string|null The last error message or null if no error has occurred.
     */
    public function getLastError(): ?string
    {
        return $this->lastError ?? $this->connection->getLastError();
    }

    /**
     * Switches to a different connection.
     *
     * @param string $connectionName The name of the connection to use.
     * @return Database The database instance.
     * @throws DatabaseException If the connection manager is not initialized.
     */
    public function connection(string $connectionName): self
    {
        if (static::$connectionManager === null) {
            throw new DatabaseException('Connection manager is not initialized. Call Database::config() first.');
        }

        $this->connection = static::$connectionManager->getConnection($connectionName);
        $this->connectionName = $connectionName;

        return $this;
    }

    /**
     * Gets the configured table name with prefix applied.
     *
     * @param string $table The logical table name
     * @return string The physical table name with prefix
     */
    public function getTableName(string $table): string
    {
        try {
            if (static::$connectionManager === null) {
                // Log warning about missing connection manager
                $this->logTableNameWarning("Connection manager not initialized, using fallback table name", [
                    'table' => $table,
                    'fallback' => $table
                ]);
                return $table;
            }

            // Get the connection name from the current connection
            $connectionName = $this->getConnectionName();

            // Get table configuration for this connection
            $tableConfig = static::$connectionManager->getTableConfiguration($connectionName);

            if ($tableConfig === null) {
                // Log warning about missing table configuration
                $this->logTableNameWarning("No table configuration found for connection, using fallback table name", [
                    'connection' => $connectionName,
                    'table' => $table,
                    'fallback' => $table
                ]);
                return $table;
            }

            // Check if table configuration is valid
            if (!$tableConfig->isValid()) {
                $this->logTableNameWarning("Table configuration is invalid, results may be unreliable", [
                    'connection' => $connectionName,
                    'table' => $table
                ]);
            }

            return $tableConfig->getTableName($table);
        } catch (\Exception $e) {
            // Log error and return fallback
            $this->logTableNameError("Error resolving table name", [
                'table' => $table,
                'error' => $e->getMessage(),
                'fallback' => $table
            ]);
            return $table;
        }
    }

    /**
     * Gets the configured table prefix.
     *
     * @return string The table prefix (without underscore)
     */
    public function getPrefix(): string
    {
        try {
            if (static::$connectionManager === null) {
                return '';
            }

            $connectionName = $this->getConnectionName();
            $tableConfig = static::$connectionManager->getTableConfiguration($connectionName);

            if ($tableConfig === null) {
                return '';
            }

            return $tableConfig->getPrefix();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if a column/field exists in a table.
     *
     * @param string $table The logical table name or physical table name
     * @param string $field The column name to check
     * @return bool True if field exists, false otherwise
     */
    public function _fieldExists(string $table, string $field): bool
    {
        try {
            $tableName = $this->getTableName($table);
            $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table AND COLUMN_NAME = :field LIMIT 1";
            $stmt = $this->connection->getDriver()->prepare($sql);
            $res = $stmt->execute([':table' => $tableName, ':field' => $field]);
            return $res && method_exists($res, 'fetch') && $res->fetch() !== null;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Quick insert helper: insert associative array into table and return last insert id.
     *
     * @param string $table Logical or physical table name
     * @param array $data  Associative array of column => value
     * @return string Last insert id (string) or empty string on failure
     */
    public function _insertRow(string $table, array $data): string
    {
        try {
            $tableName = $this->getTableName($table);
            $cols = array_keys($data);
            $placeholders = array_map(function ($c) {return ':'.$c;}, $cols);
            $sql = 'INSERT INTO `'.$tableName.'` (`'.implode('`,`', $cols).'`) VALUES ('.implode(',', $placeholders).')';
            $statement = $this->connection->getDriver()->prepare($sql);
            // normalize params: remove leading : in keys for PDO driver implementation
            $params = [];
            foreach ($data as $k => $v) {
                $params[':'.$k] = $v;
            }
            $res = $statement->execute($params);
            if ($res) {
                return $this->lastInsertId();
            }
            return '';
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return '';
        }
    }

    /**
     * Empty (truncate) a table using database-specific optimized method.
     *
     * @param string $table Logical or physical table name
     * @param array $options Driver-specific options
     * @return bool True on success, false on failure
     */
    public function emptyTable(string $table, array $options = []): bool
    {
        try {
            // Resolve logical table name to physical table name
            $tableName = $this->getTableName($table);

            // Delegate to driver for database-specific implementation
            $result = $this->connection->getDriver()->emptyTable($tableName, $options);

            if ($result) {
                // Update last query for debugging/logging
                $this->lastQuery = "emptyTable({$tableName})";
                return true;
            }

            // Get error from driver if available
            $this->lastError = $this->connection->getDriver()->getLastError();
            return false;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Gets the current connection name.
     *
     * @return string The connection name
     */
    protected function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Logs a table name warning.
     *
     * @param string $message The warning message
     * @param array $context Additional context
     * @return void
     */
    private function logTableNameWarning(string $message, array $context = []): void
    {
        $logger = static::getLogger();
        if ($logger !== null) {
            $logger->warning("[Database] {$message}", $context);
        }
    }

    /**
     * Optimize a database table.
     *
     * TODO: This should be implemented in individual connection drivers
     * for proper database-specific optimization commands.
     *
     * @param string $table The table name to optimize
     * @return bool True on success, false on failure
     */
    public function optimizeTable(string $table, array $options = []): bool
    {
        try {
            $tableName = $this->getTableName($table);

            return $this->connection->getDriver()->optimizeTable($tableName, $options);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Logs a table name error.
     *
     * @param string $message The error message
     * @param array $context Additional context
     * @return void
     */
    private function logTableNameError(string $message, array $context = []): void
    {
        $logger = static::getLogger();
        if ($logger !== null) {
            $logger->error("[Database] {$message}", $context);
        }
    }
}
