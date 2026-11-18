<?php

namespace Kotchasan\QueryBuilder;

/**
 * Represents a SQL function that can be formatted based on database connection
 */
class SqlFunction
{
    private string $type;
    private array $parameters;
    private ?string $alias;

    /**
     * @param string $type The SQL function type (YEAR, CONCAT, NOW, etc.)
     * @param array $parameters Parameters for the function
     * @param string|null $alias Optional alias for the function result
     */
    public function __construct(string $type, array $parameters, ?string $alias = null)
    {
        $this->type = $type;
        $this->parameters = $parameters;
        $this->alias = $alias;
    }

    /**
     * Format the SQL function using the connection's database-specific logic
     *
     * @param \Kotchasan\Connection\ConnectionInterface $connection
     * @return string
     */
    public function format(\Kotchasan\Connection\ConnectionInterface $connection): string
    {
        return $connection->formatSqlFunction($this->type, $this->parameters, $this->alias);
    }

    /**
     * For compatibility with existing code that doesn't have connection available
     * Always requires a database connection for proper SQL generation
     *
     * @return string
     * @throws \RuntimeException when no database connection is available
     */
    public function toSql(): string
    {
        $connection = \Kotchasan\Database::getConnection();

        if ($connection === null) {
            throw new \RuntimeException(
                'Database connection is required for SQL function generation. '.
                'Ensure database is properly configured before using SQL functions.'
            );
        }

        // Use the connection's driver for database-specific formatting
        return $this->format($connection);
    }

    /**
     * Get the function type
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the function parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the alias
     *
     * @return string|null
     */
    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /**
     * String conversion method to provide basic compatibility
     * Falls back to toSql() for cases where connection is not available
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toSql();
    }
}
