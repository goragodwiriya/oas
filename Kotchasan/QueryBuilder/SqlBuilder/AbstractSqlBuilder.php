<?php

namespace Kotchasan\QueryBuilder\SqlBuilder;

/**
 * Abstract class AbstractSqlBuilder
 *
 * Provides common functionality for SQL builders.
 * Database-specific builders should extend this class.
 *
 * @package Kotchasan\QueryBuilder\SqlBuilder
 */
abstract class AbstractSqlBuilder implements SqlBuilderInterface
{
    /**
     * The quote characters for identifiers [start, end].
     *
     * @var array
     */
    protected array $quoteChars = ['`', '`'];

    /**
     * The driver names this builder supports.
     *
     * @var array
     */
    protected array $supportedDrivers = [];

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        [$quoteStart, $quoteEnd] = $this->quoteChars;

        // Handle AS alias within identifier (e.g. "table AS t") FIRST
        if (preg_match('/^(.+?)\s+(?:AS\s+)?([A-Za-z0-9_]+)$/i', $identifier, $m)) {
            $left = trim($m[1]);
            $alias = trim($m[2]);
            // Don't recursively call quoteIdentifier on left part if it's simple
            if (preg_match('/^[A-Za-z0-9_]+$/', $left)) {
                $quotedLeft = $quoteStart.$left.$quoteEnd;
            } else {
                $quotedLeft = $this->quoteIdentifier($left);
            }
            return $quotedLeft.' AS '.$quoteStart.$alias.$quoteEnd;
        }

        // If already quoted or looks like an expression (but not AS clause), don't modify
        if (preg_match('/[`"\[\]\(\)\*\+\-\/]/', $identifier)) {
            return $identifier;
        }

        // Quote dotted identifiers
        $parts = explode('.', $identifier);
        foreach ($parts as &$p) {
            $p = trim($p);
            if ($p === '*') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]+$/', $p)) {
                $p = $quoteStart.$p.$quoteEnd;
            }
            // otherwise leave it as-is (likely an expression)
        }

        return implode('.', $parts);
    }

    /**
     * {@inheritdoc}
     */
    public function buildSelectClause(array $columns, bool $distinct = false, bool $explain = false): string
    {
        $selectClause = 'SELECT ';

        if ($explain) {
            $selectClause = 'EXPLAIN '.$selectClause;
        }

        if ($distinct) {
            $selectClause .= 'DISTINCT ';
        }

        if (empty($columns)) {
            return $selectClause.'*';
        }

        $quotedColumns = [];
        foreach ($columns as $column) {
            if (is_array($column) && count($column) === 2) {
                // Format: [SQL, alias] -> SQL AS alias
                $expr = $column[0];
                $alias = $column[1];

                // If expression is a QueryBuilder (subquery), convert to SQL and wrap in parentheses
                if (is_object($expr) && method_exists($expr, 'toSql')) {
                    $exprSql = '('.$expr->toSql().')';
                } else {
                    $exprSql = (string) $expr;
                }

                $quotedColumns[] = $exprSql.' AS '.$this->quoteIdentifier($alias);
            } else if (is_string($column)) {
                // Handle aliases (e.g., "column AS alias")
                if (preg_match('/^(.+?)\s+(?:AS\s+)?([A-Za-z0-9_]+)$/i', $column, $matches)) {
                    $col = trim($matches[1]);
                    $alias = trim($matches[2]);

                    // Don't quote if it's already an expression or function
                    if (preg_match('/[()\s\*\+\-\/]/', $col) || preg_match('/[`"\[\]]/', $col)) {
                        $quotedColumns[] = $col.' AS '.$this->quoteIdentifier($alias);
                    } else {
                        $quotedColumns[] = $this->quoteIdentifier($col).' AS '.$this->quoteIdentifier($alias);
                    }
                } else {
                    // Simple column
                    if (preg_match('/[()\s\*\+\-\/]/', $column) || preg_match('/[`"\[\]]/', $column)) {
                        // Expression or function - don't quote
                        $quotedColumns[] = $column;
                    } else {
                        $quotedColumns[] = $this->quoteIdentifier($column);
                    }
                }
            } else if (is_object($column) && method_exists($column, 'toSql')) {
                // Sql objects (functions) don't need parentheses
                // QueryBuilder objects (subqueries) need parentheses
                $sqlString = $column->toSql();
                if ($column instanceof \Kotchasan\Database\Sql) {
                    $quotedColumns[] = $sqlString;
                } else {
                    // It's a QueryBuilder subquery, wrap in parentheses
                    $quotedColumns[] = '('.$sqlString.')';
                }
            } else {
                $quotedColumns[] = (string) $column;
            }
        }

        return $selectClause.implode(', ', $quotedColumns);
    }

    /**
     * {@inheritdoc}
     */
    public function buildFromClause(string $table, ?string $alias = null): string
    {
        $quotedTable = $this->quoteIdentifier($table);

        if ($alias) {
            return 'FROM '.$quotedTable.' AS '.$this->quoteIdentifier($alias);
        }

        return 'FROM '.$quotedTable;
    }

    /**
     * {@inheritdoc}
     */
    public function buildJoinClause(string $type, string $table, ?string $alias, string $condition): string
    {
        $quotedTable = $this->quoteIdentifier($table);

        $join = strtoupper($type);
        if (in_array($join, ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
            $join = $join.' JOIN '.$quotedTable;
        } else {
            $join = $join.' '.$quotedTable;
        }

        if ($alias) {
            $join .= ' AS '.$this->quoteIdentifier($alias);
        }

        $join .= ' ON '.$this->processJoinCondition($condition);

        return $join;
    }

    /**
     * Process join condition to quote identifiers where appropriate.
     *
     * @param string $condition The join condition
     * @return string The processed condition
     */
    protected function processJoinCondition(string $condition): string
    {
        // Simple implementation - can be enhanced in specific builders
        return $condition;
    }

    /**
     * Process WHERE condition to handle arrays and build condition string.
     *
     * @param array $where The WHERE condition array
     * @param array &$bindings Reference to bindings array
     * @return string The processed condition
     */
    protected function processWhereCondition(array $where, array &$bindings): string
    {
        if (!isset($where['column'], $where['operator'], $where['value'])) {
            throw new \InvalidArgumentException('WHERE condition must have column, operator, and value');
        }

        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        // Quote column identifier if it's a simple string
        if (is_string($column) && !preg_match('/[()\s\*\+\-\/`"\[\]]/', $column)) {
            $column = $this->quoteIdentifier($column);
        }

        // Handle different value types
        if (is_array($value) && in_array($operator, ['IN', 'NOT IN'])) {
            $placeholders = [];
            foreach ($value as $v) {
                $placeholders[] = '?';
                $bindings[] = $v;
            }
            return $column.' '.$operator.' ('.implode(', ', $placeholders).')';
        } elseif ($value === null && in_array($operator, ['=', '!=', '<>'])) {
            return $column.' '.($operator === '=' ? 'IS NULL' : 'IS NOT NULL');
        } else {
            $bindings[] = $value;
            return $column.' '.$operator.' ?';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function buildOrderByClause(array $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        $orderParts = [];
        foreach ($orders as $order) {
            if (is_array($order) && isset($order['column'], $order['direction'])) {
                // QueryBuilder format: ['column' => 'name', 'direction' => 'ASC']
                $column = $order['column'];
                $direction = strtoupper($order['direction']);

                if (preg_match('/[()\s\*\+\-\/]/', $column)) {
                    // Expression - don't quote
                    $orderParts[] = $column.' '.$direction;
                } else {
                    $orderParts[] = $this->quoteIdentifier($column).' '.$direction;
                }
            } elseif (is_string($order)) {
                // Handle "column ASC/DESC" format
                if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', $order, $matches)) {
                    $column = trim($matches[1]);
                    $direction = strtoupper($matches[2]);

                    if (preg_match('/[()\s\*\+\-\/]/', $column)) {
                        // Expression - don't quote
                        $orderParts[] = $column.' '.$direction;
                    } else {
                        $orderParts[] = $this->quoteIdentifier($column).' '.$direction;
                    }
                } else {
                    // Just column name
                    if (preg_match('/[()\s\*\+\-\/]/', $order)) {
                        $orderParts[] = $order;
                    } else {
                        $orderParts[] = $this->quoteIdentifier($order);
                    }
                }
            } else {
                $orderParts[] = (string) $order;
            }
        }

        return 'ORDER BY '.implode(', ', $orderParts);
    }

    /**
     * {@inheritdoc}
     */
    public function buildGroupByClause(array $groups): string
    {
        if (empty($groups)) {
            return '';
        }

        $groupParts = [];
        foreach ($groups as $group) {
            if (is_string($group)) {
                if (preg_match('/[()\s\*\+\-\/]/', $group)) {
                    // Expression - don't quote
                    $groupParts[] = $group;
                } else {
                    $groupParts[] = $this->quoteIdentifier($group);
                }
            } else {
                $groupParts[] = (string) $group;
            }
        }

        return 'GROUP BY '.implode(', ', $groupParts);
    }

    /**
     * {@inheritdoc}
     */
    public function buildInsertStatement(string $table, array $data, array &$bindings, bool $ignore = false): string
    {
        $query = 'INSERT ';

        if ($ignore) {
            $query .= 'IGNORE ';
        }

        $query .= 'INTO '.$this->quoteIdentifier($table).' ';

        if (!empty($data)) {
            $columns = array_keys($data);
            $quotedColumns = [];
            foreach ($columns as $column) {
                $quotedColumns[] = $this->quoteIdentifier($column);
            }

            $query .= '('.implode(', ', $quotedColumns).') VALUES (';

            $placeholders = [];
            foreach ($data as $value) {
                $placeholders[] = '?';
                $bindings[] = $value;
            }

            $query .= implode(', ', $placeholders).')';
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function buildUpdateStatement(string $table, array $data, array $wheres, array &$bindings): string
    {
        $query = 'UPDATE '.$this->quoteIdentifier($table).' SET ';

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

        $query .= implode(', ', $setParts);

        if (!empty($wheres)) {
            $whereParts = [];
            foreach ($wheres as $where) {
                $whereParts[] = $this->processWhereCondition($where, $bindings);
            }
            $query .= ' WHERE '.implode(' AND ', $whereParts);
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function buildDeleteStatement(string $table, array $wheres, array &$bindings): string
    {
        $query = 'DELETE FROM '.$this->quoteIdentifier($table);

        if (!empty($wheres)) {
            $whereParts = [];
            foreach ($wheres as $where) {
                $whereParts[] = $this->processWhereCondition($where, $bindings);
            }
            $query .= ' WHERE '.implode(' AND ', $whereParts);
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDriver(string $driverName): bool
    {
        return in_array(strtolower($driverName), $this->supportedDrivers, true);
    }
}
