<?php

namespace Kotchasan\Connection;

use Kotchasan\Cache\CacheFactory;
use Kotchasan\Cache\CacheInterface;
use Kotchasan\Cache\QueryCache;
use Kotchasan\Database\TableConfiguration;
use Kotchasan\Exception\ConfigurationException;
use Kotchasan\Exception\DatabaseException;
use Kotchasan\Logger\LoggerInterface;

/**
 * Class ConnectionManager
 *
 * Manages database connections.
 *
 * @package Kotchasan\Connection
 */
class ConnectionManager
{
    /**
     * The configuration array.
     *
     * @var array
     */
    protected array $config;

    /**
     * The active connections.
     *
     * @var array
     */
    protected array $connections = [];

    /**
     * The available drivers.
     *
     * @var array
     */
    protected array $drivers = [
        'mysql' => MySQLDriver::class,
        'sqlite' => SQLiteDriver::class,
        'pgsql' => PostgreSQLDriver::class,
        'sqlsrv' => MSSQLDriver::class
    ];

    /**
     * The query cache instance.
     *
     * @var QueryCache|null
     */
    protected ?QueryCache $queryCache = null;

    /**
     * The logger instance.
     *
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;

    /**
     * Table configurations per connection.
     *
     * @var array<string, TableConfiguration>
     */
    protected array $tableConfigurations = [];

    /**
     * ConnectionManager constructor.
     *
     * @param array $config The configuration array.
     * @param LoggerInterface|null $logger Optional logger instance.
     */
    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;

        // Extract and store table configurations for each connection
        foreach ($config as $connectionName => $connectionConfig) {
            if (is_array($connectionConfig)) {
                $this->tableConfigurations[$connectionName] = $this->loadTableConfiguration($connectionConfig);
            }
        }
    }

    /**
     * Gets a connection by name.
     *
     * @param string $name The connection name.
     * @return ConnectionInterface The connection.
     * @throws DatabaseException If the connection cannot be established.
     * @throws ConfigurationException If the connection configuration is invalid.
     */
    public function getConnection(string $name): ConnectionInterface
    {
        // Return existing connection if already established
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        // Check if configuration exists
        if (!isset($this->config[$name])) {
            throw new ConfigurationException("Connection configuration '{$name}' not found.");
        }

        $connectionConfig = $this->config[$name];

        // Check for required configuration parameters
        if (!isset($connectionConfig['driver'])) {
            throw new ConfigurationException("Driver not specified in connection configuration '{$name}'.");
        }

        $driver = $connectionConfig['driver'];

        // Check if driver is supported
        if (!isset($this->drivers[$driver])) {
            throw new ConfigurationException("Driver '{$driver}' is not supported.");
        }

        $driverClass = $this->drivers[$driver];

        // Create driver instance
        $driverInstance = new $driverClass();

        // Create connection instance
        $connection = new Connection($driverInstance);

        // Set logger if available
        if ($this->logger !== null) {
            $connection->setLogger($this->logger);
        }

        // Set query cache if available
        if ($this->queryCache !== null) {
            $connection->setQueryCache($this->queryCache);
        }

        // Establish connection
        if (!$connection->connectWithConfig($connectionConfig)) {
            throw new DatabaseException("Failed to connect to database: ".$connection->getLastError());
        }

        // Store connection for reuse
        $this->connections[$name] = $connection;

        return $connection;
    }

    /**
     * Closes a connection by name.
     *
     * @param string $name The connection name.
     * @return bool True if the connection was successfully closed, false otherwise.
     */
    public function closeConnection(string $name): bool
    {
        if (isset($this->connections[$name])) {
            $result = $this->connections[$name]->disconnect();
            unset($this->connections[$name]);
            return $result;
        }

        return false;
    }

    /**
     * Closes all connections.
     *
     * @return bool True if all connections were successfully closed, false otherwise.
     */
    public function closeAllConnections(): bool
    {
        $result = true;

        foreach (array_keys($this->connections) as $name) {
            $result = $this->closeConnection($name) && $result;
        }

        return $result;
    }

    /**
     * Registers a custom driver.
     *
     * @param string $name The driver name.
     * @param string $class The driver class name (must implement DriverInterface).
     * @return void
     * @throws ConfigurationException If the driver class does not implement DriverInterface.
     */
    public function registerDriver(string $name, string $class): void
    {
        // Check if class implements DriverInterface
        if (!is_subclass_of($class, DriverInterface::class)) {
            throw new ConfigurationException("Driver class '{$class}' must implement DriverInterface.");
        }

        $this->drivers[$name] = $class;
    }

    /**
     * Sets a logger for all connections.
     *
     * @param LoggerInterface $logger The logger instance.
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Gets the logger instance.
     *
     * @return LoggerInterface|null The logger instance.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Configures query caching.
     *
     * @param array $config The cache configuration.
     * @param int|null $defaultTtl The default TTL for cache entries.
     * @return QueryCache The query cache instance.
     */
    public function configureCache(array $config, ?int $defaultTtl = 3600): QueryCache
    {
        $cache = CacheFactory::create($config);
        $this->queryCache = new QueryCache($cache, $defaultTtl);

        // Set cache for existing connections
        foreach ($this->connections as $connection) {
            $connection->setQueryCache($this->queryCache);
        }

        return $this->queryCache;
    }

    /**
     * Sets a cache instance directly.
     *
     * @param CacheInterface $cache The cache implementation.
     * @param int|null $defaultTtl The default TTL for cache entries.
     * @return QueryCache The query cache instance.
     */
    public function setCache(CacheInterface $cache, ?int $defaultTtl = 3600): QueryCache
    {
        $this->queryCache = new QueryCache($cache, $defaultTtl);

        // Set cache for existing connections
        foreach ($this->connections as $connection) {
            $connection->setQueryCache($this->queryCache);
        }

        return $this->queryCache;
    }

    /**
     * Gets the current query cache.
     *
     * @return QueryCache|null The query cache instance.
     */
    public function getQueryCache(): ?QueryCache
    {
        return $this->queryCache;
    }

    /**
     * Loads table configuration from database settings.
     *
     * @param array $config The database configuration array
     * @return TableConfiguration The table configuration instance
     */
    public function loadTableConfiguration(array $config): TableConfiguration
    {
        try {
            // Extract table configuration from the config array
            $tableConfig = [];

            // Check for prefix in connection config
            if (isset($config['prefix'])) {
                $tableConfig['prefix'] = $config['prefix'];
            }

            // Check for tables in connection config
            if (isset($config['tables'])) {
                $tableConfig['tables'] = $config['tables'];
            }

            // Also check for global tables configuration in the main config
            if (isset($this->config['tables'])) {
                $tableConfig['tables'] = $this->config['tables'];
            }

            // Create TableConfiguration with logger if available
            $tableConfiguration = new TableConfiguration($tableConfig, $this->logger);

            // Log successful configuration loading
            if ($this->logger !== null) {
                $this->logger->info("[ConnectionManager] Table configuration loaded", [
                    'has_prefix' => !empty($tableConfig['prefix']),
                    'table_count' => isset($tableConfig['tables']) ? count($tableConfig['tables']) : 0,
                    'is_valid' => $tableConfiguration->isValid()
                ]);
            }

            return $tableConfiguration;

        } catch (\Exception $e) {
            // Log configuration loading error
            if ($this->logger !== null) {
                $this->logger->error("[ConnectionManager] Failed to load table configuration", [
                    'error' => $e->getMessage(),
                    'config_keys' => array_keys($config),
                    'recovery_action' => 'Creating fallback configuration'
                ]);
            }

            // Return fallback configuration to maintain system stability
            return new TableConfiguration([], $this->logger);
        }
    }

    /**
     * Gets the table configuration for a specific connection.
     *
     * @param string $connectionName The connection name
     * @return TableConfiguration|null The table configuration instance or null if not found
     */
    public function getTableConfiguration(string $connectionName): ?TableConfiguration
    {
        return $this->tableConfigurations[$connectionName] ?? null;
    }
}
