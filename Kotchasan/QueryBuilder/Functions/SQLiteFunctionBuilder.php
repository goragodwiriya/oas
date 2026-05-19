<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Class SQLiteFunctionBuilder
 *
 * Provides SQLite-specific SQL function implementations.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class SQLiteFunctionBuilder extends AbstractSQLFunctionBuilder
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
            return "SUBSTR({$string}, {$start})";
        }

        return "SUBSTR({$string}, {$start}, {$length})";
    }

    /**
     * {@inheritdoc}
     */
    public function now(): string
    {
        return "DATETIME('now')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateFormat(string $date, string $format): string
    {
        // Convert MySQL format to SQLite strftime format
        $sqliteFormat = $this->convertMySQLFormatToSQLite($format);
        return "STRFTIME('{$sqliteFormat}', {$date})";
    }

    /**
     * {@inheritdoc}
     */
    public function dateAdd(string $date, int $value, string $unit): string
    {
        $modifier = $this->convertUnitToSQLiteModifier($value, $unit);
        return "DATETIME({$date}, '{$modifier}')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateSub(string $date, int $value, string $unit): string
    {
        $modifier = $this->convertUnitToSQLiteModifier(-$value, $unit);
        return "DATETIME({$date}, '{$modifier}')";
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
        return "IFNULL({$expr1}, {$expr2})";
    }

    // SQLite-specific functions

    /**
     * Creates a JSON extract function using JSON_EXTRACT (SQLite 3.38+).
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path.
     * @return string The JSON extract expression.
     */
    public function jsonExtract(string $column, string $path): string
    {
        return "JSON_EXTRACT({$column}, '\$.{$path}')";
    }

    /**
     * Creates a simple text search using LIKE.
     *
     * @param string|array $columns The columns to search in.
     * @param string $query The search query.
     * @return string The search expression.
     */
    public function textSearch($columns, string $query): string
    {
        if (is_array($columns)) {
            $conditions = [];
            foreach ($columns as $column) {
                $conditions[] = "{$column} LIKE '%{$query}%'";
            }
            return "(".implode(' OR ', $conditions).")";
        }

        return "{$columns} LIKE '%{$query}%'";
    }

    /**
     * Convert MySQL date format to SQLite strftime format.
     *
     * @param string $mysqlFormat MySQL date format string
     * @return string SQLite compatible format string
     */
    protected function convertMySQLFormatToSQLite(string $mysqlFormat): string
    {
        $conversions = [
            '%Y' => '%Y', // 4-digit year
            '%y' => '%y', // 2-digit year
            '%m' => '%m', // Month (01-12)
            '%d' => '%d', // Day (01-31)
            '%H' => '%H', // Hour (00-23)
            '%i' => '%M', // Minutes (00-59) - SQLite uses %M
            '%s' => '%S', // Seconds (00-59)
            '%M' => '%B', // Full month name - SQLite uses %B
            '%b' => '%b', // Abbreviated month name
            '%W' => '%A', // Full weekday name - SQLite uses %A
            '%a' => '%a' // Abbreviated weekday name
        ];

        return str_replace(array_keys($conversions), array_values($conversions), $mysqlFormat);
    }

    /**
     * Convert unit to SQLite date modifier.
     *
     * @param int $value The value to add/subtract
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.)
     * @return string SQLite modifier string
     */
    protected function convertUnitToSQLiteModifier(int $value, string $unit): string
    {
        $unit = strtolower($unit);

        $unitMap = [
            'day' => 'days',
            'days' => 'days',
            'month' => 'months',
            'months' => 'months',
            'year' => 'years',
            'years' => 'years',
            'hour' => 'hours',
            'hours' => 'hours',
            'minute' => 'minutes',
            'minutes' => 'minutes',
            'second' => 'seconds',
            'seconds' => 'seconds'
        ];

        $sqliteUnit = $unitMap[$unit] ?? 'days';

        if ($value >= 0) {
            return "+{$value} {$sqliteUnit}";
        } else {
            return "{$value} {$sqliteUnit}";
        }
    }
}
