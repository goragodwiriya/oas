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
        $sqlBuilder = $this->getSqlBuilder();

        // Build UPDATE statement using SqlBuilder
        $query = 'UPDATE '.$sqlBuilder->quoteIdentifier($this->table).' SET ';

        // Add SET clause
        $sets = [];
        foreach ($this->values as $column => $value) {
            $quotedColumn = $sqlBuilder->quoteIdentifier($column);

            // Check if value is a Sql object or SqlFunction (raw SQL expression)
            if ($value instanceof \Kotchasan\Database\Sql) {
                // Use raw SQL expression directly
                $sets[] = $quotedColumn.' = '.$value->toSql();
                // Merge any bindings from the Sql object
                foreach ($value->getValues([]) as $v) {
                    $this->namedBindings[':'.$column.'_'.$this->paramCounter++] = $v;
                }
            } elseif ($value instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                // Use SqlFunction's toSql method
                $sets[] = $quotedColumn.' = '.$value->toSql();
            } elseif ($value instanceof \Kotchasan\QueryBuilder\RawExpression) {
                // Use RawExpression's toSql method
                $sets[] = $quotedColumn.' = '.$value->toSql();
            } else {
                // Regular value - use placeholder
                $sets[] = $quotedColumn.' = :'.$column;
                $this->namedBindings[':'.$column] = $value;
            }
        }
        $query .= implode(', ', $sets);

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

        // Add LIMIT clause using SqlBuilder
        if ($this->limit !== null) {
            $query .= ' '.$sqlBuilder->buildLimitClause($this->limit, $this->offset);
        }

        return $query;
    }

    // use parent's buildWhereClauses
}
