<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Class SQLServerFunctionBuilder
 *
 * Provides SQL Server-specific SQL function implementations.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class SQLServerFunctionBuilder extends AbstractSQLFunctionBuilder
{
    /**
     * {@inheritdoc}
     */
    public function concat(string ...$strings): string
    {
        return implode(' + ', $strings);
    }

    /**
     * {@inheritdoc}
     */
    public function substring(string $string, int $start, ?int $length = null): string
    {
        if ($length === null) {
            return "SUBSTRING({$string}, {$start}, LEN({$string}))";
        }

        return "SUBSTRING({$string}, {$start}, {$length})";
    }

    /**
     * {@inheritdoc}
     */
    public function now(): string
    {
        return "GETDATE()";
    }

    /**
     * {@inheritdoc}
     */
    public function dateFormat(string $date, string $format): string
    {
        // Convert MySQL format to SQL Server FORMAT style
        $sqlServerFormat = $this->convertMySQLFormatToSQLServer($format);
        return "FORMAT({$date}, '{$sqlServerFormat}')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateAdd(string $date, int $value, string $unit): string
    {
        $sqlServerUnit = $this->convertUnitToSQLServer($unit);
        return "DATEADD({$sqlServerUnit}, {$value}, {$date})";
    }

    /**
     * {@inheritdoc}
     */
    public function dateSub(string $date, int $value, string $unit): string
    {
        $sqlServerUnit = $this->convertUnitToSQLServer($unit);
        return "DATEADD({$sqlServerUnit}, -{$value}, {$date})";
    }

    /**
     * {@inheritdoc}
     */
    public function rand(): string
    {
        return "RAND()";
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
        return "ISNULL({$expr1}, {$expr2})";
    }

    // SQL Server-specific functions

    /**
     * Creates a JSON_VALUE function (SQL Server 2016+).
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path.
     * @return string The JSON_VALUE function.
     */
    public function jsonExtract(string $column, string $path): string
    {
        return "JSON_VALUE({$column}, '\$.{$path}')";
    }

    /**
     * Creates a full-text search using CONTAINS.
     *
     * @param string|array $columns The columns to search in.
     * @param string $query The search query.
     * @return string The CONTAINS function.
     */
    public function fullTextSearch($columns, string $query): string
    {
        if (is_array($columns)) {
            $columns = '('.implode(', ', $columns).')';
        }

        return "CONTAINS({$columns}, '{$query}')";
    }

    /**
     * Creates a NEWID function for generating UUIDs.
     *
     * @return string The NEWID function.
     */
    public function newId(): string
    {
        return "NEWID()";
    }

    /**
     * Convert MySQL date format to SQL Server FORMAT style.
     *
     * @param string $mysqlFormat MySQL date format string
     * @return string SQL Server compatible format string
     */
    protected function convertMySQLFormatToSQLServer(string $mysqlFormat): string
    {
        $conversions = [
            '%Y' => 'yyyy', // 4-digit year
            '%y' => 'yy', // 2-digit year
            '%m' => 'MM', // Month (01-12)
            '%d' => 'dd', // Day (01-31)
            '%H' => 'HH', // Hour (00-23)
            '%i' => 'mm', // Minutes (00-59)
            '%s' => 'ss', // Seconds (00-59)
            '%M' => 'MMMM', // Full month name
            '%b' => 'MMM', // Abbreviated month name
            '%W' => 'dddd', // Full weekday name
            '%a' => 'ddd' // Abbreviated weekday name
        ];

        return str_replace(array_keys($conversions), array_values($conversions), $mysqlFormat);
    }

    /**
     * Convert unit to SQL Server DATEADD unit.
     *
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.)
     * @return string SQL Server DATEADD unit
     */
    protected function convertUnitToSQLServer(string $unit): string
    {
        $unit = strtoupper($unit);

        $unitMap = [
            'DAY' => 'DAY',
            'DAYS' => 'DAY',
            'MONTH' => 'MONTH',
            'MONTHS' => 'MONTH',
            'YEAR' => 'YEAR',
            'YEARS' => 'YEAR',
            'HOUR' => 'HOUR',
            'HOURS' => 'HOUR',
            'MINUTE' => 'MINUTE',
            'MINUTES' => 'MINUTE',
            'SECOND' => 'SECOND',
            'SECONDS' => 'SECOND',
            'WEEK' => 'WEEK',
            'WEEKS' => 'WEEK',
            'QUARTER' => 'QUARTER',
            'QUARTERS' => 'QUARTER'
        ];

        return $unitMap[$unit] ?? 'DAY';
    }
}
