<?php
namespace Kotchasan\QueryBuilder;

/**
 * Class SelectBuilder
 *
 * Builder for SELECT queries.
 *
 * @package Kotchasan\QueryBuilder
 */
class SelectBuilder extends QueryBuilder
{
    /**
     * Flag indicating whether to select distinct values.
     *
     * @var bool
     */
    protected bool $distinct = false;

    /**
     * Adds DISTINCT to the SELECT query.
     *
     * @return $this
     */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        // Return cached SQL if already built to avoid regenerating bindings
        if ($this->lastQuery !== null) {
            return $this->lastQuery;
        }

        $sqlBuilder = $this->getSqlBuilder();

        // Build SELECT part with proper handling for complex columns
        $query = 'SELECT ';

        // If EXPLAIN is requested, prepend the query
        if ($this->explain) {
            $query = 'EXPLAIN '.$query;
        }

        // Add DISTINCT if specified
        if ($this->distinct) {
            $query .= 'DISTINCT ';
        }

        // Add columns (handle complex subquery binding logic here)
        if (empty($this->columns)) {
            $query .= '*';
        } else {
            $columnParts = [];
            foreach ($this->columns as $column) {
                if (is_array($column) && count($column) === 2) {
                    // Format: [SQL, alias] -> SQL AS alias
                    $expr = $column[0];
                    // If expression is a QueryBuilder (subquery), convert to SQL and wrap in parentheses
                    if (is_object($expr) && method_exists($expr, 'toSql')) {
                        // Embed subquery SQL and merge its bindings into this builder
                        $exprSql = $expr->toSql();

                        // Collect all rename mappings first, then apply with strtr to avoid prefix collision
                        // (e.g. :p1 must not corrupt :p10, :p11, etc.)
                        $renameMap = [];

                        // First handle any named placeholders in the subquery
                        $subNamed = property_exists($expr, 'namedBindings') ? $expr->namedBindings : [];
                        if (!empty($subNamed)) {
                            foreach ($subNamed as $origName => $origVal) {
                                $newName = $this->placeholderPrefix.$this->paramCounter++;
                                // Ensure we replace the token form (with leading ':') regardless of how the key is stored
                                $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                                $renameMap[$origToken] = $newName;
                                // store in embeddedBindings without leading ':' so processNamedParameters works consistently
                                $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                                $this->embeddedBindings[$p] = $origVal;
                            }
                            $this->useNamedParameters = true;
                        }

                        // Also handle embeddedBindings from the subquery (e.g. from nested WHERE clauses)
                        $subEmbedded = property_exists($expr, 'embeddedBindings') ? $expr->embeddedBindings : [];
                        if (!empty($subEmbedded)) {
                            foreach ($subEmbedded as $origName => $origVal) {
                                $newName = $this->placeholderPrefix.$this->paramCounter++;
                                $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                                $renameMap[$origToken] = $newName;
                                $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                                $this->embeddedBindings[$p] = $origVal;
                            }
                            $this->useNamedParameters = true;
                        }

                        // Apply all renames at once using strtr (longest match first, no prefix collision)
                        if (!empty($renameMap)) {
                            $exprSql = strtr($exprSql, $renameMap);
                        }

                        // Then handle positional bindings (question marks)
                        $subBindings = [];
                        if (method_exists($expr, 'getBindings')) {
                            $subBindings = $expr->getBindings();
                        }
                        if (!empty($subBindings)) {
                            foreach ($subBindings as $bindVal) {
                                $paramName = $this->placeholderPrefix.$this->paramCounter++;
                                $exprSql = preg_replace('/\?/', $paramName, $exprSql, 1);
                                // store positional subquery bindings in embeddedBindings without leading ':'
                                $p = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                                $this->embeddedBindings[$p] = $bindVal;
                            }
                            $this->useNamedParameters = true;
                        }

                        $exprSql = '('.$exprSql.')';
                    } else {
                        $exprSql = (string) $expr;
                    }

                    $columnParts[] = $exprSql.' AS '.$sqlBuilder->quoteIdentifier($column[1]);
                } else {
                    // Format: string or QueryBuilder object
                    if (is_object($column) && method_exists($column, 'toSql')) {
                        // Handle different object types:
                        // - SqlFunction: use format() method with connection
                        // - Sql objects: don't need parentheses
                        // - QueryBuilder objects (subqueries): need parentheses
                        if ($column instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                            $columnParts[] = $column->format($this->connection);
                        } elseif ($column instanceof \Kotchasan\Database\Sql) {
                            $columnParts[] = $column->toSql();
                        } else {
                            // It's a QueryBuilder subquery, wrap in parentheses
                            $columnParts[] = '('.$column->toSql().')';
                        }
                    } else {
                        $colStr = (string) $column;
                        if (preg_match('/^[A-Za-z0-9_\.]+$/', $colStr)) {
                            $colStr = $sqlBuilder->quoteIdentifier($colStr);
                        }
                        $columnParts[] = $colStr;
                    }
                }
            }
            $query .= implode(', ', $columnParts);
        }

        // Add FROM clause using SqlBuilder
        $query .= ' '.$sqlBuilder->buildFromClause($this->table, $this->alias);

        // Add JOIN clauses using SqlBuilder
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $query .= ' '.$sqlBuilder->buildJoinClause($join['type'], $join['table'], null, $join['condition']);
            }
        }

        // Add WHERE clauses (use existing logic for now due to complex binding handling)
        if (!empty($this->wheres)) {
            $whereSql = $this->buildWhereClauses();
            if ($whereSql !== '') {
                $query .= ' WHERE '.$whereSql;
            }
        }

        // Add GROUP BY clause using SqlBuilder
        if (!empty($this->groups)) {
            $query .= ' '.$sqlBuilder->buildGroupByClause($this->groups);
        }

        // Add HAVING clauses (use existing logic for now)
        if (!empty($this->havings)) {
            $query .= ' HAVING '.$this->buildHavingClauses();
        }

        // Add ORDER BY clause using SqlBuilder
        if (!empty($this->orders)) {
            $query .= ' '.$sqlBuilder->buildOrderByClause($this->orders);
        }

        // Add LIMIT/OFFSET clause using SqlBuilder
        if ($this->limit !== null || $this->offset !== null) {
            $query .= ' '.$sqlBuilder->buildLimitClause($this->limit, $this->offset);
        }

        // Append UNION / UNION ALL clauses
        foreach ($this->unions as $union) {
            $unionPart = $union['query'];
            $unionSql = $unionPart->toSql();
            // Merge bindings from the union member into this builder so that
            // parent queries (from/join subquery merging) see them all in one place.
            if ($unionPart instanceof QueryBuilder) {
                $unionSql = $this->mergeSubqueryBindings($unionPart, $unionSql);
            }
            $query .= ' '.($union['all'] ? 'UNION ALL' : 'UNION').' '.$unionSql;
        }

        $this->lastQuery = $query;
        return $query;
    }

    /**
     * Set values (interface requirement). For SELECT builder this just stores values for compatibility.
     *
     * @param array $values
     * @return QueryBuilderInterface
     */
    public function values(array $values): QueryBuilderInterface
    {
        foreach ($values as $k => $v) {
            $this->values[$k] = $v;
            if (!$this->useNamedParameters) {
                $this->addBinding($v, 'values');
            }
        }
        return $this;
    }

    /**
     * Override update to return a real UpdateBuilder populated with this builder's state.
     * This makes Model::createQuery()->update(...) behave the same as Database::update(...).
     *
     * @param string $table
     * @return QueryBuilderInterface
     */
    public function update(string $table): QueryBuilderInterface
    {
        $builder = new UpdateBuilder($this->connection);
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy relevant state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->groups = $this->groups;
        $builder->havings = $this->havings;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->values = $this->values;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    /**
     * Override insert to return a real InsertBuilder populated with this builder's state.
     * @param string $table
     * @return QueryBuilderInterface
     */
    public function insert(string $table): QueryBuilderInterface
    {
        $builder = new InsertBuilder($this->connection);
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy relevant state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->values = $this->values;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    /**
     * Override delete to return a real DeleteBuilder populated with this builder's state.
     * @param string $table
     * @return QueryBuilderInterface
     */
    public function delete(string $table): QueryBuilderInterface
    {
        $builder = new DeleteBuilder($this->connection);
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy relevant state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    // use parent's buildWhereClauses

    /**
     * Builds the HAVING clauses.
     *
     * @return string The HAVING clauses.
     */
    protected function buildHavingClauses(): string
    {
        $clauses = [];

        foreach ($this->havings as $having) {
            if ($this->useNamedParameters) {
                $paramName = $this->placeholderPrefix.$this->paramCounter++;
                $this->namedBindings[$paramName] = $having['value'];
                $placeholder = $paramName;
            } else {
                $placeholder = '?';
            }
            $clauses[] = ($clauses ? $having['boolean'].' ' : '').
                $having['column'].' '.$having['operator'].' '.$placeholder;
        }

        return implode(' ', $clauses);
    }
}
