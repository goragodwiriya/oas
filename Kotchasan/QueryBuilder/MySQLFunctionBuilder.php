<?php

namespace Kotchasan\QueryBuilder;

use Kotchasan\Connection\ConnectionInterface;
use Kotchasan\QueryBuilder\Functions\FunctionBuilderFactory;
use Kotchasan\QueryBuilder\Functions\SQLFunctionBuilderInterface;

/**
 * Class MySQLFunctionBuilder
 *
 * Provides support for database-specific SQL functions.
 * This class acts as a facade to the new function builder architecture.
 *
 * @package Kotchasan\QueryBuilder
 * @deprecated Use FunctionBuilderFactory::create() instead
 */
class MySQLFunctionBuilder
{
    /**
     * The actual function builder instance.
     *
     * @var SQLFunctionBuilderInterface
     */
    protected SQLFunctionBuilderInterface $builder;

    /**
     * MySQLFunctionBuilder constructor.
     *
     * @param ConnectionInterface|null $connection The database connection (optional)
     */
    public function __construct(?ConnectionInterface $connection = null)
    {
        if ($connection) {
            $this->builder = FunctionBuilderFactory::create($connection);
        } else {
            // Fallback to MySQL builder for backward compatibility
            $this->builder = new \Kotchasan\QueryBuilder\Functions\MySQLFunctionBuilder();
        }
    }

    /**
     * Creates a COUNT function.
     *
     * @param string $column The column to count.
     * @return string The COUNT function.
     */
    public function count(string $column = '*'): string
    {
        return $this->builder->count($column);
    }

    /**
     * Creates a SUM function.
     *
     * @param string $column The column to sum.
     * @return string The SUM function.
     */
    public function sum(string $column): string
    {
        return $this->builder->sum($column);
    }

    /**
     * Creates an AVG function.
     *
     * @param string $column The column to average.
     * @return string The AVG function.
     */
    public function avg(string $column): string
    {
        return $this->builder->avg($column);
    }

    /**
     * Creates a MIN function.
     *
     * @param string $column The column to get the minimum value from.
     * @return string The MIN function.
     */
    public function min(string $column): string
    {
        return $this->builder->min($column);
    }

    /**
     * Creates a MAX function.
     *
     * @param string $column The column to get the maximum value from.
     * @return string The MAX function.
     */
    public function max(string $column): string
    {
        return $this->builder->max($column);
    }

    /**
     * Creates a CONCAT function.
     *
     * @param string ...$strings The strings to concatenate.
     * @return string The CONCAT function.
     */
    public function concat(string ...$strings): string
    {
        return $this->builder->concat(...$strings);
    }

    /**
     * Creates a SUBSTRING function.
     *
     * @param string $string The string to extract from.
     * @param int $start The start position (1-indexed).
     * @param int|null $length The length to extract. If null, extracts to the end.
     * @return string The SUBSTRING function.
     */
    public function substring(string $string, int $start, ?int $length = null): string
    {
        return $this->builder->substring($string, $start, $length);
    }

    /**
     * Creates a NOW function.
     *
     * @return string The NOW function.
     */
    public function now(): string
    {
        return $this->builder->now();
    }

    /**
     * Creates a DATE_FORMAT function.
     *
     * @param string $date The date to format.
     * @param string $format The format string.
     * @return string The DATE_FORMAT function.
     */
    public function dateFormat(string $date, string $format): string
    {
        return $this->builder->dateFormat($date, $format);
    }

    /**
     * Creates a DATE_ADD function.
     *
     * @param string $date The date to add to.
     * @param int $value The value to add.
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.).
     * @return string The DATE_ADD function.
     */
    public function dateAdd(string $date, int $value, string $unit): string
    {
        return $this->builder->dateAdd($date, $value, $unit);
    }

    /**
     * Creates a DATE_SUB function.
     *
     * @param string $date The date to subtract from.
     * @param int $value The value to subtract.
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.).
     * @return string The DATE_SUB function.
     */
    public function dateSub(string $date, int $value, string $unit): string
    {
        return $this->builder->dateSub($date, $value, $unit);
    }

    /**
     * Creates a ROUND function.
     *
     * @param string $number The number to round.
     * @param int $decimals The number of decimal places.
     * @return string The ROUND function.
     */
    public function round(string $number, int $decimals = 0): string
    {
        return $this->builder->round($number, $decimals);
    }

    /**
     * Creates a CEIL function.
     *
     * @param string $number The number to round up.
     * @return string The CEIL function.
     */
    public function ceil(string $number): string
    {
        return $this->builder->ceil($number);
    }

    /**
     * Creates a FLOOR function.
     *
     * @param string $number The number to round down.
     * @return string The FLOOR function.
     */
    public function floor(string $number): string
    {
        return $this->builder->floor($number);
    }

    /**
     * Creates an ABS function.
     *
     * @param string $number The number to get the absolute value of.
     * @return string The ABS function.
     */
    public function abs(string $number): string
    {
        return $this->builder->abs($number);
    }

    /**
     * Creates a RAND function.
     *
     * @return string The RAND function.
     */
    public function rand(): string
    {
        return $this->builder->rand();
    }

    /**
     * Creates an IF function.
     *
     * @param string $condition The condition to check.
     * @param string $ifTrue The value to return if true.
     * @param string $ifFalse The value to return if false.
     * @return string The IF function.
     */
    public function if(string $condition, string $ifTrue, string $ifFalse): string
    {
        return $this->builder->conditional($condition, $ifTrue, $ifFalse);
    }

    /**
     * Creates an IFNULL function.
     *
     * @param string $expr1 The expression to check for NULL.
     * @param string $expr2 The value to return if expr1 is NULL.
     * @return string The IFNULL function.
     */
    public function ifNull(string $expr1, string $expr2): string
    {
        return $this->builder->ifNull($expr1, $expr2);
    }

    /**
     * Creates a NULLIF function.
     *
     * @param string $expr1 The first expression.
     * @param string $expr2 The second expression.
     * @return string The NULLIF function.
     */
    public function nullIf(string $expr1, string $expr2): string
    {
        return $this->builder->nullIf($expr1, $expr2);
    }

    /**
     * Creates a JSON_EXTRACT function (MySQL-specific methods).
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path.
     * @return string The JSON_EXTRACT function.
     */
    public function jsonExtract(string $column, string $path): string
    {
        // Check if builder has MySQL-specific methods
        if ($this->builder instanceof \Kotchasan\QueryBuilder\Functions\MySQLFunctionBuilder) {
            return $this->builder->jsonExtract($column, $path);
        }

        // Fallback for other databases
        return "JSON_EXTRACT({$column}, '{$path}')";
    }

    /**
     * Creates a JSON_CONTAINS function (MySQL-specific methods).
     *
     * @param string $column The JSON column.
     * @param string $value The value to check for.
     * @param string|null $path The JSON path (optional).
     * @return string The JSON_CONTAINS function.
     */
    public function jsonContains(string $column, string $value, ?string $path = null): string
    {
        // Check if builder has MySQL-specific methods
        if ($this->builder instanceof \Kotchasan\QueryBuilder\Functions\MySQLFunctionBuilder) {
            return $this->builder->jsonContains($column, $value, $path);
        }

        // Fallback for other databases
        if ($path === null) {
            return "JSON_CONTAINS({$column}, '{$value}')";
        }
        return "JSON_CONTAINS({$column}, '{$value}', '{$path}')";
    }

    /**
     * Creates a MATCH AGAINST function for full-text search (MySQL-specific).
     *
     * @param string|array $columns The columns to search in.
     * @param string $query The search query.
     * @param string $mode The search mode (IN NATURAL LANGUAGE MODE, IN BOOLEAN MODE, etc.).
     * @return string The MATCH AGAINST function.
     */
    public function match($columns, string $query, string $mode = 'IN NATURAL LANGUAGE MODE'): string
    {
        // Check if builder has MySQL-specific methods
        if ($this->builder instanceof \Kotchasan\QueryBuilder\Functions\MySQLFunctionBuilder) {
            return $this->builder->match($columns, $query, $mode);
        }

        // Fallback implementation
        if (is_array($columns)) {
            $columns = implode(', ', $columns);
        }
        return "MATCH ({$columns}) AGAINST ('{$query}' {$mode})";
    }
}
