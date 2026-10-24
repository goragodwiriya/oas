<?php

namespace Kotchasan\Execution;

use Kotchasan\Result\ResultInterface;

/**
 * Interface StatementInterface
 *
 * Defines methods for prepared statements.
 *
 * @package Kotchasan\Execution
 */
interface StatementInterface
{
    /**
     * Binds a parameter to the specified variable name.
     *
     * @param string|int $parameter The parameter identifier (name or position).
     * @param mixed &$variable The variable to bind.
     * @param int|null $type The data type for the parameter.
     * @param int|null $length The length of the data type.
     * @return bool True on success, false on failure.
     */
    public function bindParam($parameter, &$variable, $type = null, $length = null): bool;

    /**
     * Binds a value to a parameter.
     *
     * @param string|int $parameter The parameter identifier (name or position).
     * @param mixed $value The value to bind.
     * @param int|null $type The data type for the parameter.
     * @return bool True on success, false on failure.
     */
    public function bindValue($parameter, $value, $type = null): bool;

    /**
     * Executes the prepared statement.
     *
     * @param array|null $params An array of values to be bound to the parameters.
     * @param string|null $resultFormat Optional result format indicator (e.g., 'array' or 'object').
     * @return ResultInterface The result of the executed statement.
     */
    public function execute(?array $params = null, ?string $resultFormat = null): ResultInterface;

    /**
     * Returns the underlying statement resource or object.
     *
     * @return mixed The statement resource or object.
     */
    public function getStatement();

    /**
     * Returns the SQL query that was prepared.
     *
     * @return string The SQL query.
     */
    public function getQuery(): string;

    /**
     * Returns the last error that occurred.
     *
     * @return string|null The last error message or null if no error occurred.
     */
    public function getLastError(): ?string;
}
