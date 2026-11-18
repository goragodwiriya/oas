<?php
namespace Kotchasan\QueryBuilder;

/**
 * Class UpdateBuilder
 *
 * Builder for UPDATE queries.
 *
 * @package Kotchasan\QueryBuilder
 */
class UpdateBuilder extends QueryBuilder
{
    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        if (!empty($this->joins)) {
            // Dropping the join would run the update without its conditions
            throw new \LogicException('UPDATE does not support join(); use a where condition on a subquery instead');
        }

        $sqlBuilder = $this->getSqlBuilder();

        // Build UPDATE statement using SqlBuilder
        $query = 'UPDATE '.$sqlBuilder->quoteIdentifier($this->table).' SET ';

        // Add SET clause
        $sets = [];
        foreach ($this->values as $column => $value) {
            $quotedColumn = $this->writableColumn($column);

            // Check if value is a Sql object or SqlFunction (raw SQL expression)
            if ($value instanceof \Kotchasan\Database\Sql) {
                // Raw SQL expression; its bindings get new names, in the SQL too
                // (they used to be renamed in the bindings only)
                $sets[] = $quotedColumn.' = '.$this->extractExpressionSql($value);
            } elseif ($value instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                // Use SqlFunction's toSql method
                $sets[] = $quotedColumn.' = '.$value->toSql();
            } elseif ($value instanceof \Kotchasan\QueryBuilder\RawExpression) {
                // Use RawExpression's toSql method
                $sets[] = $quotedColumn.' = '.$value->toSql();
            } else {
                // Regular value - use placeholder. Numbered, not named after the
                // column: 'a-b' and 'a_b', or a Thai column name, made the same name
                $placeholder = $this->placeholderPrefix.'s'.$this->paramCounter++;
                $sets[] = $quotedColumn.' = '.$placeholder;
                $this->namedBindings[substr($placeholder, 1)] = $value;
            }
        }
        $query .= implode(', ', $sets);

        $whereSql = empty($this->wheres) ? '' : $this->buildWhereClauses();
        $orderSql = empty($this->orders) ? '' : $sqlBuilder->buildOrderByClause($this->orders);

        // PostgreSQL has no UPDATE ... LIMIT: the rows go by ctid, as in DeleteBuilder
        if ($this->limit !== null && $sqlBuilder->getDriverName() === 'pgsql') {
            return $query.' WHERE ctid IN ('.DeleteBuilder::limitedRows($sqlBuilder->quoteIdentifier($this->table), $whereSql, $orderSql, $this->limit).')';
        }
        // SQL Server has no ORDER BY in UPDATE: as in DeleteBuilder
        if ($sqlBuilder->getDriverName() === 'sqlsrv' && $orderSql !== '') {
            if ($this->limit === null) {
                $orderSql = '';
            } else {
                return DeleteBuilder::limitedCte($sqlBuilder->quoteIdentifier($this->table), $whereSql, $orderSql, $this->limit)
                    .' UPDATE [kotchasan_rows] SET '.implode(', ', $sets);
            }
        }

        // Add WHERE clauses (use existing logic for now)
        if ($whereSql !== '') {
            $query .= ' WHERE '.$whereSql;
        }

        // Add ORDER BY clause using SqlBuilder
        if ($orderSql !== '') {
            $query .= ' '.$orderSql;
        }

        // Add row LIMIT in the dialect-correct way (MySQL/SQLite LIMIT, SQL Server TOP)
        if ($this->limit !== null) {
            $query = $sqlBuilder->applyUpdateDeleteLimit($query, 'UPDATE', $this->limit);
        }

        return $query;
    }

    // use parent's buildWhereClauses
}
