<?php

namespace Kotchasan\Result;

/**
 * Interface ResultInterface
 *
 * Defines methods for query results.
 *
 * @package Kotchasan\Result
 */
interface ResultInterface
{
    /**
     * Fetches the next row from the result set.
     *
     * @return array|object|null The next row, or null if there are no more rows.
     */
    public function fetch();

    /**
     * Fetches all rows from the result set.
     *
     * @return array An array of all rows.
     */
    public function fetchAll(): array;

    /**
     * Fetches a single column from the next row of the result set.
     *
     * @param int $columnNumber The column number (0-indexed).
     * @return mixed The column value, or null if there are no more rows.
     */
    public function fetchColumn(int $columnNumber = 0);

    /**
     * Returns the number of rows affected by the last operation.
     *
     * @return int The number of rows.
     */
    public function rowCount(): int;

    /**
     * Returns the number of columns in the result set.
     *
     * @return int The number of columns.
     */
    public function columnCount(): int;

    /**
     * Returns metadata for a column in the result set.
     *
     * @param int $column The column number (0-indexed).
     * @return array An array of metadata for the column.
     */
    public function getColumnMeta(int $column): array;

    /**
     * Returns the underlying result resource or object.
     *
     * @return mixed The result resource or object.
     */
    public function getResult();

    /**
     * Checks if the result set is empty.
     *
     * @return bool True if the result set is empty, false otherwise.
     */
    public function isEmpty(): bool;

    /**
     * Checks if the result has more rows.
     *
     * @return bool True if there are more rows, false otherwise.
     */
    public function hasMore(): bool;

    /**
     * Resets the result pointer to the beginning.
     *
     * @return bool True on success, false on failure.
     */
    public function reset(): bool;

    /**
     * Closes the cursor, freeing the database connection.
     *
     * @return bool True on success, false on failure.
     */
    public function close(): bool;

    /**
     * Returns the count of rows in the result set.
     * This is an alias for rowCount() for compatibility with Countable interface.
     *
     * @return int The number of rows.
     */
    public function count(): int;
}
