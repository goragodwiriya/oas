<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Class MySQLFunctionBuilder
 *
 * Provides MySQL-specific SQL function implementations.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class MySQLFunctionBuilder extends AbstractSQLFunctionBuilder
{
    /**
     * {@inheritdoc}
     */
    public function concat(string ...$strings): string
    {
        return "CONCAT(".implode(', ', $strings).")";
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
        return "DATE_FORMAT({$date}, '{$format}')";
    }

    /**
     * {@inheritdoc}
     */
    public function dateAdd(string $date, int $value, string $unit): string
    {
        return "DATE_ADD({$date}, INTERVAL {$value} {$unit})";
    }

    /**
     * {@inheritdoc}
     */
    public function dateSub(string $date, int $value, string $unit): string
    {
        return "DATE_SUB({$date}, INTERVAL {$value} {$unit})";
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
        return "IF({$condition}, {$ifTrue}, {$ifFalse})";
    }

    /**
     * {@inheritdoc}
     */
    public function ifNull(string $expr1, string $expr2): string
    {
        return "IFNULL({$expr1}, {$expr2})";
    }

    // MySQL-specific functions

    /**
     * Creates an IF function (MySQL-specific alias for conditional).
     *
     * @param string $condition The condition to check.
     * @param string $ifTrue The value to return if true.
     * @param string $ifFalse The value to return if false.
     * @return string The IF function.
     */
    public function if(string $condition, string $ifTrue, string $ifFalse): string
    {
        return $this->conditional($condition, $ifTrue, $ifFalse);
    }

    /**
     * Creates a JSON_EXTRACT function.
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path.
     * @return string The JSON_EXTRACT function.
     */
    public function jsonExtract(string $column, string $path): string
    {
        return "JSON_EXTRACT({$column}, '{$path}')";
    }

    /**
     * Creates a JSON_CONTAINS function.
     *
     * @param string $column The JSON column.
     * @param string $value The value to check for.
     * @param string|null $path The JSON path (optional).
     * @return string The JSON_CONTAINS function.
     */
    public function jsonContains(string $column, string $value, ?string $path = null): string
    {
        if ($path === null) {
            return "JSON_CONTAINS({$column}, '{$value}')";
        }

        return "JSON_CONTAINS({$column}, '{$value}', '{$path}')";
    }

    /**
     * Creates a MATCH AGAINST function for full-text search.
     *
     * @param string|array $columns The columns to search in.
     * @param string $query The search query (already formatted boolean term, or raw query for natural language mode).
     * @param string $mode The search mode (IN NATURAL LANGUAGE MODE, IN BOOLEAN MODE, etc.).
     * @return string The MATCH AGAINST function.
     */
    public function match($columns, string $query, string $mode = 'IN NATURAL LANGUAGE MODE'): string
    {
        if (is_array($columns)) {
            $columns = implode(', ', $columns);
        }

        return "MATCH ({$columns}) AGAINST ('{$query}' {$mode})";
    }

    /**
     * Creates a MATCH AGAINST expression in BOOLEAN MODE from raw user input.
     *
     * Handles tokenization automatically — no need to call buildBooleanSearchTerm() separately.
     *
     * Examples:
     *   matchBoolean('`job_no`', 'สมชาย ใจดี')
     *     => "MATCH (`job_no`) AGAINST ('+สมชาย* +ใจดี*' IN BOOLEAN MODE)"
     *
     *   matchBoolean(['`name`', '`phone`'], 'บฉ-2662')
     *     => "MATCH (`name`, `phone`) AGAINST ('+บฉ* +2662*' IN BOOLEAN MODE)"
     *
     * @param string|array $columns The columns to search in.
     * @param string $search Raw user input (spaces / hyphens are handled automatically).
     * @return string The MATCH AGAINST expression ready for use in whereRaw().
     */
    public function matchBoolean($columns, string $search): string
    {
        return $this->match($columns, static::buildBooleanSearchTerm($search), 'IN BOOLEAN MODE');
    }

    /**
     * Build a FULLTEXT boolean mode search term from raw user input.
     *
     * Each token (split on non-letter/digit characters) becomes a required prefix term.
     * Safe to embed directly — only Unicode letters, marks, digits, '+', '*', and spaces are emitted.
     *
     * Examples:
     *   "สมชาย ใจดี" => "+สมชาย* +ใจดี*"
     *   "บฉ-2662"    => "+บฉ* +2662*"
     *   "62000001"   => "+62000001*"
     *
     * @param string $search Raw user search input.
     * @return string FULLTEXT boolean mode term.
     */
    public static function buildBooleanSearchTerm(string $search): string
    {
        $tokens = preg_split('/[^\p{L}\p{M}\p{N}]+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return '+*';
        }
        return implode(' ', array_map(static fn($t) => '+'.$t.'*', $tokens));
    }
}
