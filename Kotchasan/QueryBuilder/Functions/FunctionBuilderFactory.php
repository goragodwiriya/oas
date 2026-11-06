<?php

namespace Kotchasan\QueryBuilder\Functions;

use Kotchasan\Connection\ConnectionInterface;

/**
 * Class FunctionBuilderFactory
 *
 * Factory for creating database-specific function builders.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class FunctionBuilderFactory
{
    /**
     * Cache for function builders.
     *
     * @var array
     */
    protected static array $builders = [];

    /**
     * Default function builders by driver name.
     *
     * @var array
     */
    protected static array $defaultBuilders = [
        'mysql' => MySQLFunctionBuilder::class,
        'mysqli' => MySQLFunctionBuilder::class,
        'pgsql' => PostgreSQLFunctionBuilder::class,
        'postgres' => PostgreSQLFunctionBuilder::class,
        'postgresql' => PostgreSQLFunctionBuilder::class,
        'sqlite' => SQLiteFunctionBuilder::class,
        'sqlite3' => SQLiteFunctionBuilder::class,
        'sqlsrv' => SQLServerFunctionBuilder::class,
        'mssql' => SQLServerFunctionBuilder::class,
        'sqlserver' => SQLServerFunctionBuilder::class,
        'dblib' => SQLServerFunctionBuilder::class,
        // Test drivers
        'test' => MySQLFunctionBuilder::class,
        'mock' => MySQLFunctionBuilder::class,
        'dummy' => MySQLFunctionBuilder::class
    ];

    /**
     * Creates a function builder for the given connection.
     *
     * @param ConnectionInterface $connection The database connection
     * @return SQLFunctionBuilderInterface The function builder instance
     */
    public static function create(ConnectionInterface $connection): SQLFunctionBuilderInterface
    {
        $driverName = static::getDriverName($connection);
        $cacheKey = $driverName;

        if (!isset(static::$builders[$cacheKey])) {
            static::$builders[$cacheKey] = static::createBuilder($driverName);
        }

        return static::$builders[$cacheKey];
    }

    /**
     * Creates a function builder instance for the given driver.
     *
     * @param string $driverName The driver name
     * @return SQLFunctionBuilderInterface The function builder instance
     */
    protected static function createBuilder(string $driverName): SQLFunctionBuilderInterface
    {
        $builderClass = static::$defaultBuilders[$driverName] ?? MySQLFunctionBuilder::class;
        return new $builderClass();
    }

    /**
     * Gets the driver name from the connection.
     *
     * @param ConnectionInterface $connection The database connection
     * @return string The driver name
     */
    protected static function getDriverName(ConnectionInterface $connection): string
    {
        try {
            return strtolower($connection->getDriver()->getName());
        } catch (\Exception $e) {
            return 'mysql'; // fallback
        }
    }

    /**
     * Registers a custom function builder for a driver.
     *
     * @param string $driverName The driver name
     * @param string $builderClass The function builder class name
     * @return void
     */
    public static function register(string $driverName, string $builderClass): void
    {
        static::$defaultBuilders[strtolower($driverName)] = $builderClass;
        // Clear cache for this driver
        unset(static::$builders[strtolower($driverName)]);
    }

    /**
     * Clears the function builder cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$builders = [];
    }
}
