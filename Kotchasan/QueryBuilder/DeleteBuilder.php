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
        $sqlBuilder = $this->getSqlBuilder();

        // Build DELETE statement using SqlBuilder
        $query = 'DELETE FROM '.$sqlBuilder->quoteIdentifier($this->table);

        // Add WHERE clauses (use existing logic for now)
        if (!empty($this->wheres)) {
            $whereSql = $this->buildWhereClauses();
            if ($whereSql !== '') {
                $query .= ' WHERE '.$whereSql;
            }
        }

        // Add ORDER BY clause using SqlBuilder
        if (!empty($this->orders)) {
            $query .= ' '.$sqlBuilder->buildOrderByClause($this->orders);
        }

        // Add row LIMIT in the dialect-correct way (MySQL/SQLite LIMIT,
        // SQL Server TOP, PostgreSQL throws — DELETE..LIMIT is non-portable).
        if ($this->limit !== null) {
            $query = $sqlBuilder->applyUpdateDeleteLimit($query, 'DELETE', $this->limit);
        }

        return $query;
    }

    /**
     * Builds the WHERE clauses.
     *
     * @return string The WHERE clauses.
     */
    // use parent's buildWhereClauses
}
