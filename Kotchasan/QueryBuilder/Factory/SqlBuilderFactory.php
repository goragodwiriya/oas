<?php

namespace Kotchasan\QueryBuilder\Factory;

use Kotchasan\Connection\ConnectionInterface;
use Kotchasan\QueryBuilder\SqlBuilder\MySqlSqlBuilder;
use Kotchasan\QueryBuilder\SqlBuilder\PostgreSqlSqlBuilder;
use Kotchasan\QueryBuilder\SqlBuilder\SqlBuilderInterface;
use Kotchasan\QueryBuilder\SqlBuilder\SqliteSqlBuilder;
use Kotchasan\QueryBuilder\SqlBuilder\SqlServerSqlBuilder;

/**
 * Class SqlBuilderFactory
 *
 * Factory for creating appropriate SQL builders based on database driver.
 *
 * @package Kotchasan\QueryBuilder\Factory
 */
class SqlBuilderFactory
{
    /**
     * Cache of created SQL builders.
     *
     * @var array<string, SqlBuilderInterface>
     */
    private static array $builders = [];

    /**
     * Default SQL builders mapped to driver names.
     *
     * @var array<string, string>
     */
    private static array $defaultBuilders = [
        'mysql' => MySqlSqlBuilder::class,
        'mysqli' => MySqlSqlBuilder::class,
        'pgsql' => PostgreSqlSqlBuilder::class,
        'postgres' => PostgreSqlSqlBuilder::class,
        'postgresql' => PostgreSqlSqlBuilder::class,
        'sqlite' => SqliteSqlBuilder::class,
        'sqlsrv' => SqlServerSqlBuilder::class,
        'mssql' => SqlServerSqlBuilder::class,
        'sqlserver' => SqlServerSqlBuilder::class,
        // Test drivers - use MySQL builder as fallback
        'dummy' => MySqlSqlBuilder::class,
        'dummy2' => MySqlSqlBuilder::class,
        'testdummy' => MySqlSqlBuilder::class
    ];

    /**
     * Create or get cached SQL builder for the given connection.
     *
     * @param ConnectionInterface $connection The database connection
     * @return SqlBuilderInterface The appropriate SQL builder
     * @throws \InvalidArgumentException If no suitable builder is found
     */
    public static function create(ConnectionInterface $connection): SqlBuilderInterface
    {
        $driverName = static::getDriverName($connection);

        // Return cached builder if available
        if (isset(static::$builders[$driverName])) {
            return static::$builders[$driverName];
        }

        // Create new builder
        $builder = static::createBuilder($driverName);

        // Cache the builder
        static::$builders[$driverName] = $builder;

        return $builder;
    }

    /**
     * Create SQL builder for specific driver name.
     *
     * @param string $driverName The driver name
     * @return SqlBuilderInterface The SQL builder
     * @throws \InvalidArgumentException If no suitable builder is found
     */
    public static function createForDriver(string $driverName): SqlBuilderInterface
    {
        $normalizedName = strtolower($driverName);

        // Return cached builder if available
        if (isset(static::$builders[$normalizedName])) {
            return static::$builders[$normalizedName];
        }

        // Create new builder
        $builder = static::createBuilder($normalizedName);

        // Cache the builder
        static::$builders[$normalizedName] = $builder;

        return $builder;
    }

    /**
     * Register a custom SQL builder for a driver.
     *
     * @param string $driverName The driver name
     * @param string $builderClass The SQL builder class name
     * @return void
     * @throws \InvalidArgumentException If the builder class is invalid
     */
    public static function register(string $driverName, string $builderClass): void
    {
        if (!class_exists($builderClass)) {
            throw new \InvalidArgumentException("SQL builder class '{$builderClass}' does not exist");
        }

        if (!is_subclass_of($builderClass, SqlBuilderInterface::class)) {
            throw new \InvalidArgumentException("SQL builder class '{$builderClass}' must implement SqlBuilderInterface");
        }

        $normalizedName = strtolower($driverName);
        static::$defaultBuilders[$normalizedName] = $builderClass;

        // Clear cached builder if exists
        unset(static::$builders[$normalizedName]);
    }

    /**
     * Get supported driver names.
     *
     * @return array<string> The supported driver names
     */
    public static function getSupportedDrivers(): array
    {
        return array_keys(static::$defaultBuilders);
    }

    /**
     * Check if a driver is supported.
     *
     * @param string $driverName The driver name
     * @return bool True if supported, false otherwise
     */
    public static function isSupported(string $driverName): bool
    {
        return isset(static::$defaultBuilders[strtolower($driverName)]);
    }

    /**
     * Clear all cached builders.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$builders = [];
    }

    /**
     * Get the driver name from connection.
     *
     * @param ConnectionInterface $connection The database connection
     * @return string The driver name
     */
    private static function getDriverName(ConnectionInterface $connection): string
    {
        try {
            $driver = $connection->getDriver();
            return strtolower($driver->getName());
        } catch (\Throwable $e) {
            // Fall back to default if driver detection fails
            return 'mysql';
        }
    }

    /**
     * Create a new SQL builder instance.
     *
     * @param string $driverName The normalized driver name
     * @return SqlBuilderInterface The SQL builder
     * @throws \InvalidArgumentException If no suitable builder is found
     */
    private static function createBuilder(string $driverName): SqlBuilderInterface
    {
        if (!isset(static::$defaultBuilders[$driverName])) {
            throw new \InvalidArgumentException("No SQL builder available for driver '{$driverName}'");
        }

        $builderClass = static::$defaultBuilders[$driverName];

        return new $builderClass();
    }
}
