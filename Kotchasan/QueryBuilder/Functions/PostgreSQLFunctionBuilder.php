<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Class PostgreSQLFunctionBuilder
 *
 * Provides PostgreSQL-specific SQL function implementations.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class PostgreSQLFunctionBuilder extends AbstractSQLFunctionBuilder
{
    /**
     * {@inheritdoc}
     */
    public function concat(string ...$strings): string
    {
        return implode(' || ', $strings);
    }

    /**
     * {@inheritdoc}
     */
    public function substring(string $string, int $start, ?int $length = null): string
    {
        if ($length === null) {
            return "SUBSTRING({$string} FROM {$start})";
        }

        return "SUBSTRING({$string} FROM {$start} FOR {$length})";
    }

    /**
     * {@inheritdoc}
     */
    public function now(): string
    {
        return "NOW()";
    }

    /**
     * {@inheritdoc}
     */
    public function dateFormat(string $date, string $format): string
    {
        // Convert MySQL format to PostgreSQL TO_CHAR format
        $pgFormat = $this->convertMySQLFormatToPostgreSQL($format);
        return "TO_CHAR({$date}, '{$pgFormat}')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateAdd(string $date, int $value, string $unit): string
    {
        $unit = strtoupper($unit);
        return "({$date} + INTERVAL '{$value} {$unit}')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateSub(string $date, int $value, string $unit): string
    {
        $unit = strtoupper($unit);
        return "({$date} - INTERVAL '{$value} {$unit}')";
    }

    /**
     * {@inheritdoc}
     */
    public function rand(): string
    {
        return "RANDOM()";
    }

    /**
     * {@inheritdoc}
     */
    public function conditional(string $condition, string $ifTrue, string $ifFalse): string
    {
        return "CASE WHEN {$condition} THEN {$ifTrue} ELSE {$ifFalse} END";
    }

    /**
     * {@inheritdoc}
     */
    public function ifNull(string $expr1, string $expr2): string
    {
        return "COALESCE({$expr1}, {$expr2})";
    }

    // PostgreSQL-specific functions

    /**
     * Creates a JSON extract function using -> operator.
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path.
     * @return string The JSON extract expression.
     */
    public function jsonExtract(string $column, string $path): string
    {
        return "{$column}->>'{$path}'";
    }

    /**
     * Creates a JSON contains function using @> operator.
     *
     * @param string $column The JSON column.
     * @param string $value The value to check for.
     * @param string|null $path The JSON path (optional).
     * @return string The JSON contains expression.
     */
    public function jsonContains(string $column, string $value, ?string $path = null): string
    {
        if ($path === null) {
            return "{$column} @> '{$value}'";
        }

        return "{$column}->'{$path}' @> '{$value}'";
    }

    /**
     * Creates a full-text search function using tsvector.
     *
     * @param string|array $columns The columns to search in.
     * @param string $query The search query.
     * @param string $language The language configuration (default: 'english').
     * @return string The full-text search expression.
     */
    public function fullTextSearch($columns, string $query, string $language = 'english'): string
    {
        if (is_array($columns)) {
            $columns = implode(' || \' \' || ', $columns);
        }

        return "to_tsvector('{$language}', {$columns}) @@ plainto_tsquery('{$language}', '{$query}')";
    }

    /**
     * Convert MySQL date format to PostgreSQL format.
     *
     * @param string $mysqlFormat MySQL date format string
     * @return string PostgreSQL compatible format string
     */
    protected function convertMySQLFormatToPostgreSQL(string $mysqlFormat): string
    {
        $conversions = [
            '%Y' => 'YYYY', // 4-digit year
            '%y' => 'YY', // 2-digit year
            '%m' => 'MM', // Month (01-12)
            '%d' => 'DD', // Day (01-31)
            '%H' => 'HH24', // Hour (00-23)
            '%i' => 'MI', // Minutes (00-59)
            '%s' => 'SS', // Seconds (00-59)
            '%M' => 'Month', // Full month name
            '%b' => 'Mon', // Abbreviated month name
            '%W' => 'Day', // Full weekday name
            '%a' => 'Dy' // Abbreviated weekday name
        ];

        return str_replace(array_keys($conversions), array_values($conversions), $mysqlFormat);
    }
}
