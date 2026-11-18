<?php
namespace Kotchasan\QueryBuilder;

/**
 * Class DeleteBuilder
 *
 * Builder for DELETE queries.
 *
 * @package Kotchasan\QueryBuilder
 */
class DeleteBuilder extends QueryBuilder
{
    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        if (!empty($this->joins)) {
            // Dropping the join would run the delete without its conditions
            throw new \LogicException('DELETE does not support join(); use a where condition on a subquery instead');
        }

        $sqlBuilder = $this->getSqlBuilder();
        $table = $sqlBuilder->quoteIdentifier($this->table);
        $whereSql = empty($this->wheres) ? '' : $this->buildWhereClauses();
        $orderSql = empty($this->orders) ? '' : $sqlBuilder->buildOrderByClause($this->orders);

        // PostgreSQL has no DELETE ... LIMIT: the rows go by ctid, picked by a
        // subquery with the conditions, order and limit (DB::delete() limits to one
        // row by default, so it used to throw on every PostgreSQL connection)
        if ($this->limit !== null && $sqlBuilder->getDriverName() === 'pgsql') {
            return 'DELETE FROM '.$table.' WHERE ctid IN ('.self::limitedRows($table, $whereSql, $orderSql, $this->limit).')';
        }
        // SQL Server has no ORDER BY in DELETE: TOP (n) with an order goes through a
        // CTE, and an order without a limit changes nothing, so it is left out
        if ($sqlBuilder->getDriverName() === 'sqlsrv' && $orderSql !== '') {
            if ($this->limit === null) {
                $orderSql = '';
            } else {
                return self::limitedCte($table, $whereSql, $orderSql, $this->limit).' DELETE FROM [kotchasan_rows]';
            }
        }

        // Build DELETE statement using SqlBuilder
        $query = 'DELETE FROM '.$table.($whereSql === '' ? '' : ' WHERE '.$whereSql);

        // Add ORDER BY clause using SqlBuilder
        if ($orderSql !== '') {
            $query .= ' '.$orderSql;
        }

        // Add row LIMIT in the dialect-correct way (MySQL/SQLite LIMIT, SQL Server TOP)
        if ($this->limit !== null) {
            $query = $sqlBuilder->applyUpdateDeleteLimit($query, 'DELETE', $this->limit);
        }

        return $query;
    }

    /**
     * The PostgreSQL subquery that picks the rows of a limited UPDATE or DELETE
     *
     * @param string $table    Quoted table name
     * @param string $whereSql Conditions, without WHERE
     * @param string $orderSql ORDER BY clause, or ''
     * @param int    $limit    Number of rows
     *
     * @return string
     */
    public static function limitedRows(string $table, string $whereSql, string $orderSql, int $limit): string
    {
        return 'SELECT ctid FROM '.$table
            .($whereSql === '' ? '' : ' WHERE '.$whereSql)
            .($orderSql === '' ? '' : ' '.$orderSql)
            .' LIMIT '.max(0, $limit);
    }

    /**
     * The SQL Server CTE [kotchasan_rows] that picks the rows of a limited UPDATE or
     * DELETE in order (UPDATE/DELETE TOP (n) takes no ORDER BY)
     *
     * @param string $table    Quoted table name
     * @param string $whereSql Conditions, without WHERE
     * @param string $orderSql ORDER BY clause
     * @param int    $limit    Number of rows
     *
     * @return string
     */
    public static function limitedCte(string $table, string $whereSql, string $orderSql, int $limit): string
    {
        return 'WITH [kotchasan_rows] AS (SELECT TOP ('.max(0, $limit).') * FROM '.$table
            .($whereSql === '' ? '' : ' WHERE '.$whereSql).' '.$orderSql.')';
    }

    /**
     * Builds the WHERE clauses.
     *
     * @return string The WHERE clauses.
     */
    // use parent's buildWhereClauses
}
