<?php

namespace Kotchasan\QueryBuilder\SqlBuilder;

/**
 * Interface SqlBuilderInterface
 *
 * Defines the contract for database-specific SQL builders.
 * Each database driver should implement this interface to provide
 * driver-specific SQL generation capabilities.
 *
 * @package Kotchasan\QueryBuilder\SqlBuilder
 */
interface SqlBuilderInterface
{
    /**
     * Quote an identifier (table or column name) for this database.
     *
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Build a SELECT clause with columns, handling expressions and aliases.
     *
     * @param array $columns The columns to select
     * @param bool $distinct Whether to add DISTINCT
     * @param bool $explain Whether to add EXPLAIN
     * @return string The SELECT clause
     */
    public function buildSelectClause(array $columns, bool $distinct = false, bool $explain = false): string;

    /**
     * Build a FROM clause with optional alias.
     *
     * @param string $table The table name
     * @param string|null $alias The table alias
     * @return string The FROM clause
     */
    public function buildFromClause(string $table, ?string $alias = null): string;

    /**
     * Build a WHERE clause from conditions array.
     *
     * @param array $wheres The where conditions
     * @param array $bindings Reference to bindings array
     * @return string The WHERE clause
     */
    public function buildWhereClause(array $wheres, array &$bindings): string;

    /**
     * Build a JOIN clause.
     *
     * @param string $type The join type (INNER, LEFT, RIGHT, etc.)
     * @param string $table The table to join
     * @param string|null $alias The table alias
     * @param string $condition The join condition
     * @return string The JOIN clause
     */
    public function buildJoinClause(string $type, string $table, ?string $alias, string $condition): string;

    /**
     * Build an ORDER BY clause.
     *
     * @param array $orders The order by specifications
     * @return string The ORDER BY clause
     */
    public function buildOrderByClause(array $orders): string;

    /**
     * Build a GROUP BY clause.
     *
     * @param array $groups The group by columns
     * @return string The GROUP BY clause
     */
    public function buildGroupByClause(array $groups): string;

    /**
     * Build a HAVING clause.
     *
     * @param array $havings The having conditions
     * @param array $bindings Reference to bindings array
     * @return string The HAVING clause
     */
    public function buildHavingClause(array $havings, array &$bindings): string;

    /**
     * Build a LIMIT clause.
     *
     * @param int|null $limit The limit value
     * @param int|null $offset The offset value
     * @return string The LIMIT clause
     */
    public function buildLimitClause(?int $limit, ?int $offset = null): string;

    /**
     * Build an INSERT statement.
     *
     * @param string $table The table name
     * @param array $data The data to insert
     * @param array $bindings Reference to bindings array
     * @param bool $ignore Whether to use INSERT IGNORE
     * @return string The INSERT statement
     */
    public function buildInsertStatement(string $table, array $data, array &$bindings, bool $ignore = false): string;

    /**
     * Build an UPDATE statement.
     *
     * @param string $table The table name
     * @param array $data The data to update
     * @param array $wheres The where conditions
     * @param array $bindings Reference to bindings array
     * @return string The UPDATE statement
     */
    public function buildUpdateStatement(string $table, array $data, array $wheres, array &$bindings): string;

    /**
     * Build a DELETE statement.
     *
     * @param string $table The table name
     * @param array $wheres The where conditions
     * @param array $bindings Reference to bindings array
     * @return string The DELETE statement
     */
    public function buildDeleteStatement(string $table, array $wheres, array &$bindings): string;

    /**
     * Get the driver name this builder supports.
     *
     * @return string The driver name
     */
    public function getDriverName(): string;

    /**
     * Check if this builder supports the given driver.
     *
     * @param string $driverName The driver name to check
     * @return bool True if supported, false otherwise
     */
    public function supportsDriver(string $driverName): bool;
}
