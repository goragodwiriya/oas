<?php

namespace Kotchasan\Exception;

/**
 * Class DatabaseException
 *
 * Base exception class for all database-related exceptions.
 *
 * @package Kotchasan\Exception
 */
class DatabaseException extends \Exception
{
    /**
     * The SQL query that caused the exception (if applicable).
     *
     * @var string|null
     */
    protected ?string $query = null;

    /**
     * The bindings for the query (if applicable).
     *
     * @var array|null
     */
    protected ?array $bindings = null;

    /**
     * Sets the SQL query that caused the exception.
     *
     * @param string $query The SQL query.
     * @return $this
     */
    public function setQuery(string $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Gets the SQL query that caused the exception.
     *
     * @return string|null The SQL query or null if not set.
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * Sets the bindings for the query.
     *
     * @param array $bindings The query bindings.
     * @return $this
     */
    public function setBindings(array $bindings): self
    {
        $this->bindings = $bindings;

        return $this;
    }

    /**
     * Gets the bindings for the query.
     *
     * @return array|null The query bindings or null if not set.
     */
    public function getBindings(): ?array
    {
        return $this->bindings;
    }
}
