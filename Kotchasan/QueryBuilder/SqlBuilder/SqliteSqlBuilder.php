<?php

namespace Kotchasan\QueryBuilder\SqlBuilder;

/**
 * Class SqliteSqlBuilder
 *
 * SQLite-specific SQL builder implementation.
 *
 * @package Kotchasan\QueryBuilder\SqlBuilder
 */
class SqliteSqlBuilder extends AbstractSqlBuilder
{
    /**
     * {@inheritdoc}
     */
    protected array $quoteChars = ['"', '"'];

    /**
     * {@inheritdoc}
     */
    protected array $supportedDrivers = ['sqlite'];

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function buildWhereClause(array $wheres, array &$bindings): string
    {
        if (empty($wheres)) {
            return '';
        }

        $conditions = [];
        foreach ($wheres as $where) {
            $boolean = $where['boolean'] ?? 'AND';
            $condition = '';

            if ($where['type'] === 'nested' && isset($where['query'])) {
                // Handle nested queries
                $nestedWhere = $this->buildWhereClause($where['query']->wheres, $bindings);
                if (!empty($nestedWhere)) {
                    $condition = '('.str_replace('WHERE ', '', $nestedWhere).')';
                }
            } elseif ($where['type'] === 'exists') {
                // Handle EXISTS / NOT EXISTS
                $not = !empty($where['not']) ? 'NOT ' : '';
                $existsSql = $this->buildExistsClause($where, $bindings);
                if (!empty($existsSql)) {
                    $condition = $not.'EXISTS ('.$existsSql.')';
                }
            } else {
                $condition = $this->processWhereCondition($where, $bindings);
            }

            if (!empty($condition)) {
                if (!empty($conditions)) {
                    $conditions[] = ' '.$boolean.' ';
                }
                $conditions[] = $condition;
            }
        }

        return empty($conditions) ? '' : 'WHERE '.implode('', $conditions);
    }

    /**
     * Build the EXISTS subquery SQL.
     *
     * @param array $where The where clause data containing 'table' and 'condition'.
     * @param array &$bindings Reference to bindings array.
     * @return string The EXISTS subquery SQL.
     */
    protected function buildExistsClause(array $where, array &$bindings): string
    {
        $table = $where['table'] ?? '';
        $condition = $where['condition'] ?? [];

        if (empty($table)) {
            return '';
        }

        // Handle multiple tables (array of tables for JOINs inside EXISTS)
        if (is_array($table)) {
            $tables = $table;
            $fromTable = array_shift($tables);
            $fromTableQuoted = $this->quoteIdentifier($fromTable);

            $sql = 'SELECT 1 FROM '.$fromTableQuoted;

            foreach ($tables as $joinTable) {
                $sql .= ', '.$this->quoteIdentifier($joinTable);
            }
        } else {
            $fromTableQuoted = $this->quoteIdentifier($table);
            $sql = 'SELECT 1 FROM '.$fromTableQuoted;
        }

        if (!empty($condition)) {
            $whereParts = [];
            foreach ($condition as $cond) {
                if (is_array($cond)) {
                    if (count($cond) === 2) {
                        $left = $this->quoteIdentifier($cond[0]);
                        $right = $this->processConditionValue($cond[1], $bindings);
                        $whereParts[] = $left.' = '.$right;
                    } elseif (count($cond) === 3) {
                        $left = $this->quoteIdentifier($cond[0]);
                        $operator = $cond[1];
                        $right = $this->processConditionValue($cond[2], $bindings);
                        $whereParts[] = $left.' '.$operator.' '.$right;
                    }
                } elseif (is_string($cond)) {
                    $whereParts[] = $cond;
                }
            }
            if (!empty($whereParts)) {
                $sql .= ' WHERE '.implode(' AND ', $whereParts);
            }
        }

        return $sql;
    }

    /**
     * Process a condition value - determine if it's a column reference or a literal value.
     *
     * @param mixed $value The value to process.
     * @param array &$bindings Reference to bindings array.
     * @return string The processed value for SQL.
     */
    protected function processConditionValue($value, array &$bindings): string
    {
        if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            return $this->quoteIdentifier($value);
        }

        $bindings[] = $value;
        return '?';
    }

    /**
     * {@inheritdoc}
     */
    public function buildHavingClause(array $havings, array &$bindings): string
    {
        if (empty($havings)) {
            return '';
        }

        $conditions = [];
        foreach ($havings as $having) {
            $boolean = $having['boolean'] ?? 'AND';
            $condition = $this->processWhereCondition($having, $bindings);

            if (!empty($condition)) {
                if (!empty($conditions)) {
                    $conditions[] = ' '.$boolean.' ';
                }
                $conditions[] = $condition;
            }
        }

        return empty($conditions) ? '' : 'HAVING '.implode('', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function buildLimitClause(?int $limit, ?int $offset = null): string
    {
        if ($limit === null) {
            return '';
        }

        $clause = 'LIMIT '.$limit;

        if ($offset !== null && $offset > 0) {
            $clause .= ' OFFSET '.$offset;
        }

        return $clause;
    }

    /**
     * {@inheritdoc}
     */
    public function buildInsertStatement(string $table, array $data, array &$bindings, bool $ignore = false): string
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Insert data cannot be empty');
        }

        $quotedTable = $this->quoteIdentifier($table);

        $insertClause = $ignore ? 'INSERT OR IGNORE INTO ' : 'INSERT INTO ';

        // Handle batch insert (array of arrays)
        if (isset($data[0]) && is_array($data[0])) {
            $columns = array_keys($data[0]);
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);

            $values = [];
            foreach ($data as $row) {
                $rowValues = [];
                foreach ($columns as $column) {
                    $rowValues[] = '?';
                    $bindings[] = $row[$column] ?? null;
                }
                $values[] = '('.implode(', ', $rowValues).')';
            }

            return $insertClause.$quotedTable.' ('.implode(', ', $quotedColumns).') VALUES '.implode(', ', $values);
        }

        // Single row insert
        $columns = array_keys($data);
        $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);

        $placeholders = [];
        foreach ($data as $value) {
            $placeholders[] = '?';
            $bindings[] = $value;
        }

        return $insertClause.$quotedTable.' ('.implode(', ', $quotedColumns).') VALUES ('.implode(', ', $placeholders).')';
    }

    /**
     * {@inheritdoc}
     */
    public function buildUpdateStatement(string $table, array $data, array $wheres, array &$bindings): string
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Update data cannot be empty');
        }

        $quotedTable = $this->quoteIdentifier($table);

        // Build SET clause
        $setParts = [];
        foreach ($data as $column => $value) {
            $quotedColumn = $this->quoteIdentifier($column);

            // Check if value is a Sql object or SqlFunction (raw SQL expression)
            if ($value instanceof \Kotchasan\Database\Sql) {
                // Use raw SQL expression directly
                $setParts[] = $quotedColumn.' = '.$value->toSql();
                // Merge any bindings from the Sql object
                foreach ($value->getValues([]) as $v) {
                    $bindings[] = $v;
                }
            } elseif ($value instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                // Use SqlFunction's toSql method
                $setParts[] = $quotedColumn.' = '.$value->toSql();
            } elseif ($value instanceof \Kotchasan\QueryBuilder\RawExpression) {
                // Use RawExpression's toSql method
                $setParts[] = $quotedColumn.' = '.$value->toSql();
            } else {
                // Regular value - use placeholder
                $setParts[] = $quotedColumn.' = ?';
                $bindings[] = $value;
            }
        }

        $sql = 'UPDATE '.$quotedTable.' SET '.implode(', ', $setParts);

        // Add WHERE clause
        $whereClause = $this->buildWhereClause($wheres, $bindings);
        if (!empty($whereClause)) {
            $sql .= ' '.$whereClause;
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function buildDeleteStatement(string $table, array $wheres, array &$bindings): string
    {
        $quotedTable = $this->quoteIdentifier($table);

        $sql = 'DELETE FROM '.$quotedTable;

        // Add WHERE clause
        $whereClause = $this->buildWhereClause($wheres, $bindings);
        if (!empty($whereClause)) {
            $sql .= ' '.$whereClause;
        }

        return $sql;
    }
}
