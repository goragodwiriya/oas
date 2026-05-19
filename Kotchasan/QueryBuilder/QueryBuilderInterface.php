<?php
namespace Kotchasan\QueryBuilder;

use Kotchasan\Result\ResultInterface;

/**
 * Interface QueryBuilderInterface
 *
 * Defines methods for building SQL queries.
 *
 * @package Kotchasan\QueryBuilder
 */
interface QueryBuilderInterface
{
    /**
     * Creates a SELECT query.
     *
     * @param mixed ...$columns The columns to select.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function select(...$columns): QueryBuilderInterface;

    /**
     * Creates a raw SELECT query.
     *
     * @param string $expression The raw SQL expression.
     * @param array $bindings The parameter bindings.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function selectRaw(string $expression, array $bindings = []): QueryBuilderInterface;

    /**
     * Creates a SELECT COUNT(*) query.
     *
     * @param string $column The column to count (default: '*').
     * @return QueryBuilderInterface The query builder instance.
     */
    public function selectCount(string $column = '*'): QueryBuilderInterface;

    /**
     * Creates an INSERT query.
     *
     * @param string $table The table to insert into.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function insert(string $table): QueryBuilderInterface;

    /**
     * Creates an UPDATE query.
     *
     * @param string $table The table to update.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function update(string $table): QueryBuilderInterface;

    /**
     * Creates a DELETE query.
     *
     * @param string $table The table to delete from.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function delete(string $table): QueryBuilderInterface;

    /**
     * Specifies the table to select from.
     *
     * @param string|array $table The table name.
     * @param string|null $alias The table alias.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function from($table, ?string $alias = null): QueryBuilderInterface;

    /**
     * Adds a WHERE clause to the query.
     *
     * Examples:
     *  - where(['U.username', null])
     *    => U.username IS NULL
     *
     *  - where(['U.username', 'admin'])
     *    => U.username = 'admin'
     *
     *  - where(['U.username', '=', 'admin'])
     *    => U.username = 'admin'
     *
     *  - where(['U.username', '!=', ['a','b','c']])
     *    => U.username NOT IN ('a','b','c')
     *
     *  - where([['U.username','=','admin'], ['U.phone','=','1234']])
     *    => U.username = 'admin' AND U.phone = '1234'
     *
     *  - where(function(QueryBuilderInterface $q) {
     *        $q->where([['U.status', 'active']], 'OR')
     *          ->where([['U.status', 'pending']]);
     *    })
     *    => ((U.status = 'active') OR (U.status = 'pending'))
     *
     *  - where("U.active = 1")
     *    => U.active = 1
     *
     * @param string|array|\Closure $where     The column name, raw condition string, array of conditions, or closure for nested conditions.
     * @param string                $condition Logical connector (AND/OR), default = "AND".
     *
     * @return QueryBuilderInterface The query builder instance.
     */
    public function where($where, string $condition = 'AND'): QueryBuilderInterface;

    /**
     * Adds a WHERE EXISTS clause to the query.
     *
     * Usage similar to join():
     *  ->whereExists('product_details D', [['D.product_id', 'P.id']])
     *  ->whereExists('product_details D', [['D.product_id', 'P.id'], ['D.status', 1]])
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions (similar to join condition format).
     * @param string $boolean The boolean connector (AND/OR).
     * @param bool $not Whether to use NOT EXISTS instead of EXISTS.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereExists($table, array $condition = [], string $boolean = 'AND', bool $not = false): QueryBuilderInterface;

    /**
     * Adds a WHERE NOT EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @param string $boolean The boolean connector (AND/OR).
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereNotExists($table, array $condition = [], string $boolean = 'AND'): QueryBuilderInterface;

    /**
     * Adds an OR WHERE EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereExists($table, array $condition = []): QueryBuilderInterface;

    /**
     * Adds an OR WHERE NOT EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereNotExists($table, array $condition = []): QueryBuilderInterface;

    /**
     * Adds a raw WHERE clause with optional bindings.
     *
     * @param string|object $sql Raw SQL string or object exposing toSql()/getValues().
     * @param string $condition Logical connector (AND/OR), default = "AND".
     * @param array $bindings Optional positional or named bindings.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereRaw($sql, string $condition = 'AND', array $bindings = []): QueryBuilderInterface;

    /**
     * Adds an OR WHERE clause to the query.
     *
     * @param string|array|\Closure $where The column name, an array of conditions, or a closure.
     * @param string|null $operator The operator.
     * @param mixed $value The value.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhere($where, string $condition = 'OR'): QueryBuilderInterface;

    /**
     * Adds a raw OR WHERE clause with optional bindings.
     *
     * @param string|object $sql Raw SQL string or object exposing toSql()/getValues().
     * @param array $bindings Optional positional or named bindings.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereRaw($sql, array $bindings = []): QueryBuilderInterface;

    /**
     * Adds a JOIN clause to the query.
     *
     * @param string|array $table The table to join.
     * @param string|array $condition The join condition. Can be string or array format:
     *                                - String: 'u.id = p.user_id'
     *                                - Simple array: ['u.id', 'p.user_id']
     *                                - Complex array: [['u.id', 'p.user_id'], ['u.status', 1], ['p.active', '=', 1]]
     * @param string $type The join type (INNER, LEFT, RIGHT, etc.).
     * @return QueryBuilderInterface The query builder instance.
     */
    public function join($table, $condition, string $type = 'INNER'): QueryBuilderInterface;

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param string|array $table The table to join.
     * @param string|array $condition The join condition. Can be string or array format:
     *                                - String: 'u.id = p.user_id'
     *                                - Simple array: ['u.id', 'p.user_id']
     *                                - Complex array: [['u.id', 'p.user_id'], ['u.status', 1], ['p.active', '=', 1]]
     * @return QueryBuilderInterface The query builder instance.
     */
    public function leftJoin($table, $condition): QueryBuilderInterface;

    /**
     * Adds a RIGHT JOIN clause to the query.
     *
     * @param string|array $table The table to join.
     * @param string|array $condition The join condition. Can be string or array format:
     *                                - String: 'u.id = p.user_id'
     *                                - Simple array: ['u.id', 'p.user_id']
     *                                - Complex array: [['u.id', 'p.user_id'], ['u.status', 1], ['p.active', '=', 1]]
     * @return QueryBuilderInterface The query builder instance.
     */
    public function rightJoin($table, $condition): QueryBuilderInterface;

    /**
     * Appends a UNION clause to this SELECT query.
     *
     * Can be chained multiple times. The resulting object can be used as a
     * derived table in from() or join():
     *
     *   $union = Model::createQuery()->select('id')->from('a')
     *       ->union(Model::createQuery()->select('id')->from('b'));
     *   // Use standalone:
     *   $union->execute();
     *   // Or as derived table in join:
     *   $query->join([$union, 'U'], ['U.id', 'J.id'], 'INNER');
     *
     * @param QueryBuilderInterface $query The query to union with.
     * @param bool $all Use UNION ALL instead of UNION (keeps duplicates).
     * @return QueryBuilderInterface The query builder instance.
     */
    public function union(QueryBuilderInterface $query, bool $all = false): QueryBuilderInterface;

    /**
     * Appends a UNION ALL clause (alias for union($query, true)).
     *
     * @param QueryBuilderInterface $query The query to union with.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function unionAll(QueryBuilderInterface $query): QueryBuilderInterface;

    /**
     * Adds an ORDER BY clause to the query.
     *
     * @param string $column The column to order by.
     * @param string $direction The sort direction (ASC or DESC).
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilderInterface;

    /**
     * Adds a GROUP BY clause to the query.
     *
     * @param string|array $columns The columns to group by.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function groupBy($columns): QueryBuilderInterface;

    /**
     * Adds a HAVING clause to the query.
     *
     * @param string $column The column name.
     * @param string|null $operator The operator.
     * @param mixed $value The value.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function having($column, $operator = null, $value = null): QueryBuilderInterface;

    /**
     * Adds a LIMIT clause to the query.
     *
     * @param int $limit The limit value.
     * @param int $offset The offset value.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function limit(int $limit, int $offset = 0): QueryBuilderInterface;

    /**
     * Sets the values for an INSERT query.
     *
     * @param array $values The values to insert.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function values(array $values): QueryBuilderInterface;

    /**
     * Sets values for an UPDATE query.
     *
     * @param array $values The values to update.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function set(array $values): QueryBuilderInterface;

    /**
     * Returns the SQL query as a string.
     *
     * @return string The SQL query.
     */
    public function toSql(): string;

    /**
     * Gets the parameter bindings for the query.
     *
     * @return array The parameter bindings.
     */
    public function getBindings(): array;

    /**
     * Gets embedded bindings for the query (e.g., from EXISTS subqueries).
     *
     * @return array The embedded bindings.
     */
    public function getEmbeddedBindings(): array;

    /**
     * Enables caching for this query.
     *
     * @param int|null $ttl The cache TTL in seconds.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function cache(?int $ttl = null): QueryBuilderInterface;

    /**
     * Disables caching for this query.
     *
     * @return QueryBuilderInterface The query builder instance.
     */
    public function noCache(): QueryBuilderInterface;

    /**
     * Enable caching with default TTL (convenience method)
     *
     * @param bool $auto_save Whether to automatically save cache results (default: true)
     * @param int $ttl Cache time-to-live in seconds (default: 3600)
     * @return QueryBuilderInterface The query builder instance.
     */
    public function cacheOn(bool $auto_save = true, int $ttl = 3600): QueryBuilderInterface;

    /**
     * Disable caching (convenience method)
     *
     * @return QueryBuilderInterface The query builder instance.
     */
    public function cacheOff(): QueryBuilderInterface;

    /**
     * Manually save query result to cache
     *
     * This method is used when cacheOn(false) is called to enable manual cache saving.
     * Call this method after executing a query to save the result to cache.
     *
     * @param mixed $result The query result to save to cache
     * @param array|null $params The parameter bindings used in the query
     * @return bool True if cache was saved successfully, false otherwise
     */
    public function saveCache($result, ?array $params = null): bool;

    /**
     * Executes the query.
     *
     * @param array|null $params The parameter bindings.
     * @param string|null $resultFormat Optional desired result format ('array' or 'object').
     * @return ResultInterface The query result.
     */
    public function execute(?array $params = null, ?string $resultFormat = null): ResultInterface;

    /**
     * Execute query and return first result
     *
     * Backwards compatible: accepts either the legacy signature first(?array $params = null)
     * or the new signature first(bool $toArray = false, ?array $params = null).
     *
     * If the first argument is a boolean it controls whether the returned row is an array
     * (true => associative array) or an object (false => stdClass / PDO::FETCH_OBJ).
     * If the first argument is an array or null it is treated as the legacy $params binding.
     *
     * @param mixed $paramsOrToArray Either params array (legacy) or boolean $toArray flag
     * @return mixed The first result or null if not found
     */
    public function first($paramsOrToArray = null);

    /**
     * Execute query and return all results
     *
     * @param array|null $params Optional parameters for execution
     * @return array The result set
     */
    public function fetchAll(bool $toArray = false, ?array $params = null): array;

    /**
     * Debug the current query
     *
     * @param bool $return Whether to return the SQL instead of echoing
     * @return QueryBuilderInterface|string
     */
    public function debug(bool $return = false);

    /**
     * Enable query explanation
     *
     * This method sets the explain flag to true, which can be used to get query execution plans.
     *
     * @return static The query builder instance.
     */
    public function explain(): QueryBuilderInterface;

    /**
     * Clone the model instance.
     *
     * This method allows for cloning the model instance, which can be useful for creating copies of the model
     * with the same database connection and configuration.
     *
     * @return static A new instance of the model with the same properties.
     */
    public function copy(): QueryBuilderInterface;
}
