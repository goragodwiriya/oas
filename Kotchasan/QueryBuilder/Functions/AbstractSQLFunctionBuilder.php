<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Abstract class AbstractSQLFunctionBuilder
 *
 * Provides common SQL standard functions that work across databases.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
abstract class AbstractSQLFunctionBuilder implements SQLFunctionBuilderInterface
{
    /**
     * {@inheritdoc}
     */
    public function count(string $column = '*'): string
    {
        return "COUNT({$column})";
    }

    /**
     * {@inheritdoc}
     */
    public function sum(string $column): string
    {
        return "SUM({$column})";
    }

    /**
     * {@inheritdoc}
     */
    public function avg(string $column): string
    {
        return "AVG({$column})";
    }

    /**
     * {@inheritdoc}
     */
    public function min(string $column): string
    {
        return "MIN({$column})";
    }

    /**
     * {@inheritdoc}
     */
    public function max(string $column): string
    {
        return "MAX({$column})";
    }

    /**
     * {@inheritdoc}
     */
    public function round(string $number, int $decimals = 0): string
    {
        return "ROUND({$number}, {$decimals})";
    }

    /**
     * {@inheritdoc}
     */
    public function ceil(string $number): string
    {
        return "CEIL({$number})";
    }

    /**
     * {@inheritdoc}
     */
    public function floor(string $number): string
    {
        return "FLOOR({$number})";
    }

    /**
     * {@inheritdoc}
     */
    public function abs(string $number): string
    {
        return "ABS({$number})";
    }

    /**
     * {@inheritdoc}
     */
    public function nullIf(string $expr1, string $expr2): string
    {
        return "NULLIF({$expr1}, {$expr2})";
    }

    // Abstract methods that need database-specific implementations

    /**
     * {@inheritdoc}
     */
    abstract public function concat(string ...$strings): string;

    /**
     * {@inheritdoc}
     */
    abstract public function substring(string $string, int $start, ?int $length = null): string;

    /**
     * {@inheritdoc}
     */
    abstract public function now(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function dateFormat(string $date, string $format): string;

    /**
     * {@inheritdoc}
     */
    abstract public function dateAdd(string $date, int $value, string $unit): string;

    /**
     * {@inheritdoc}
     */
    abstract public function dateSub(string $date, int $value, string $unit): string;

    /**
     * {@inheritdoc}
     */
    abstract public function rand(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function conditional(string $condition, string $ifTrue, string $ifFalse): string;

    /**
     * {@inheritdoc}
     */
    abstract public function ifNull(string $expr1, string $expr2): string;
}
