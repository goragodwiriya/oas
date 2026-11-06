<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Interface SQLFunctionBuilderInterface
 *
 * Defines methods for database-specific SQL function builders.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
interface SQLFunctionBuilderInterface
{
    /**
     * Creates a COUNT function.
     *
     * @param string $column The column to count.
     * @return string The COUNT function.
     */
    public function count(string $column = '*'): string;

    /**
     * Creates a SUM function.
     *
     * @param string $column The column to sum.
     * @return string The SUM function.
     */
    public function sum(string $column): string;

    /**
     * Creates an AVG function.
     *
     * @param string $column The column to average.
     * @return string The AVG function.
     */
    public function avg(string $column): string;

    /**
     * Creates a MIN function.
     *
     * @param string $column The column to get the minimum value from.
     * @return string The MIN function.
     */
    public function min(string $column): string;

    /**
     * Creates a MAX function.
     *
     * @param string $column The column to get the maximum value from.
     * @return string The MAX function.
     */
    public function max(string $column): string;

    /**
     * Creates a CONCAT function.
     *
     * @param string ...$strings The strings to concatenate.
     * @return string The CONCAT function.
     */
    public function concat(string ...$strings): string;

    /**
     * Creates a SUBSTRING function.
     *
     * @param string $string The string to extract from.
     * @param int $start The start position (1-indexed).
     * @param int|null $length The length to extract. If null, extracts to the end.
     * @return string The SUBSTRING function.
     */
    public function substring(string $string, int $start, ?int $length = null): string;

    /**
     * Creates a NOW function.
     *
     * @return string The NOW function.
     */
    public function now(): string;

    /**
     * Creates a date format function.
     *
     * @param string $date The date to format.
     * @param string $format The format string.
     * @return string The date format function.
     */
    public function dateFormat(string $date, string $format): string;

    /**
     * Creates a date add function.
     *
     * @param string $date The date to add to.
     * @param int $value The value to add.
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.).
     * @return string The date add function.
     */
    public function dateAdd(string $date, int $value, string $unit): string;

    /**
     * Creates a date subtract function.
     *
     * @param string $date The date to subtract from.
     * @param int $value The value to subtract.
     * @param string $unit The unit (DAY, MONTH, YEAR, etc.).
     * @return string The date subtract function.
     */
    public function dateSub(string $date, int $value, string $unit): string;

    /**
     * Creates a ROUND function.
     *
     * @param string $number The number to round.
     * @param int $decimals The number of decimal places.
     * @return string The ROUND function.
     */
    public function round(string $number, int $decimals = 0): string;

    /**
     * Creates a CEIL function.
     *
     * @param string $number The number to round up.
     * @return string The CEIL function.
     */
    public function ceil(string $number): string;

    /**
     * Creates a FLOOR function.
     *
     * @param string $number The number to round down.
     * @return string The FLOOR function.
     */
    public function floor(string $number): string;

    /**
     * Creates an ABS function.
     *
     * @param string $number The number to get the absolute value of.
     * @return string The ABS function.
     */
    public function abs(string $number): string;

    /**
     * Creates a random function.
     *
     * @return string The random function.
     */
    public function rand(): string;

    /**
     * Creates a conditional function (IF/CASE).
     *
     * @param string $condition The condition to check.
     * @param string $ifTrue The value to return if true.
     * @param string $ifFalse The value to return if false.
     * @return string The conditional function.
     */
    public function conditional(string $condition, string $ifTrue, string $ifFalse): string;

    /**
     * Creates a null handling function (IFNULL/COALESCE).
     *
     * @param string $expr1 The expression to check for NULL.
     * @param string $expr2 The value to return if expr1 is NULL.
     * @return string The null handling function.
     */
    public function ifNull(string $expr1, string $expr2): string;

    /**
     * Creates a NULLIF function.
     *
     * @param string $expr1 The first expression.
     * @param string $expr2 The second expression.
     * @return string The NULLIF function.
     */
    public function nullIf(string $expr1, string $expr2): string;
}
