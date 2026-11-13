<?php

namespace Kotchasan\QueryBuilder;

/**
 * Class InsertBuilder
 *
 * Builder for INSERT queries.
 *
 * @package Kotchasan\QueryBuilder
 */
class InsertBuilder extends QueryBuilder
{
    /**
     * Flag indicating whether to use INSERT IGNORE.
     *
     * @var bool
     */
    protected bool $ignore = false;

    /**
     * Multiple rows for batch insert.
     *
     * @var array
     */
    protected array $rows = [];

    /**
     * Sets the table to insert into.
     *
     * @param string $table The table name
     * @return $this
     */
    public function insert(string $table): self
    {
        return $this->from($table); // from() applies automatic table-name resolution
    }

    /**
     * Skips rows that would break a unique key: INSERT IGNORE (MySQL), INSERT OR
     * IGNORE (SQLite), ON CONFLICT DO NOTHING (PostgreSQL). SQL Server throws.
     *
     * @return $this
     */
    public function ignore(): self
    {
        $this->ignore = true;

        return $this;
    }

    /**
     * Adds multiple rows for batch insert.
     *
     * @param array $rows Array of rows to insert.
     * @return $this
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;

        // Add values to bindings
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $this->addBinding($value, 'values');
            }
        }

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
        $driver = $sqlBuilder->getDriverName();

        // ignore() in each database's own words: INSERT IGNORE (MySQL), INSERT OR
        // IGNORE (SQLite), ON CONFLICT DO NOTHING (PostgreSQL, after the values).
        // INSERT IGNORE used to go to every driver, a syntax error on all but MySQL
        if ($this->ignore && $driver === 'sqlsrv') {
            throw new \RuntimeException('INSERT ... ignore() is not supported on SQL Server: check for the row first, or catch the duplicate key error');
        }
        $query = 'INSERT ';
        if ($this->ignore && $driver === 'sqlite') {
            $query .= 'OR IGNORE ';
        } elseif ($this->ignore && $driver !== 'pgsql') {
            $query .= 'IGNORE ';
        }

        // Add INTO clause
        $query .= 'INTO '.$sqlBuilder->quoteIdentifier($this->table).' ';

        // Handle single row insert
        if (!empty($this->values)) {
            $columns = array_keys($this->values);
            $quoted = [];
            foreach ($columns as $c) {
                $quoted[] = $this->writableColumn($c);
            }
            $query .= '('.implode(', ', $quoted).') VALUES (';

            $placeholders = [];
            foreach ($this->values as $column => $val) {
                // Check if value is a Sql object or SqlFunction (raw SQL expression)
                if ($val instanceof \Kotchasan\Database\Sql) {
                    // Raw SQL expression; its bindings get new names, in the SQL too
                    $placeholders[] = $this->extractExpressionSql($val);
                } elseif ($val instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                    // Use SqlFunction's toSql method
                    $placeholders[] = $val->toSql();
                } elseif ($val instanceof \Kotchasan\QueryBuilder\RawExpression) {
                    // Use RawExpression's toSql method
                    $placeholders[] = $val->toSql();
                } else {
                    // Regular value - use placeholder
                    if ($this->useNamedParameters) {
                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                        $this->namedBindings[$paramName] = $val;
                        $placeholders[] = $paramName;
                    } else {
                        $placeholders[] = '?';
                    }
                }
            }
            $query .= implode(', ', $placeholders).')';
        }
        // Handle batch insert
        elseif (!empty($this->rows)) {
            // Get column names from first row
            $columns = array_keys($this->rows[0]);
            $quoted = [];
            foreach ($columns as $c) {
                $quoted[] = $this->writableColumn($c);
            }
            $query .= '('.implode(', ', $quoted).') VALUES ';

            $rowPlaceholders = [];
            foreach ($this->rows as $row) {
                $placeholders = [];
                // In the order of the first row's columns: a row written with its keys
                // in another order used to put its values in the wrong columns
                foreach ($columns as $column) {
                    $val = $row[$column] ?? null;
                    // Check if value is a Sql object or SqlFunction (raw SQL expression)
                    if ($val instanceof \Kotchasan\Database\Sql) {
                        // Raw SQL expression; its bindings get new names, in the SQL too
                        $placeholders[] = $this->extractExpressionSql($val);
                    } elseif ($val instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                        // Use SqlFunction's toSql method
                        $placeholders[] = $val->toSql();
                    } elseif ($val instanceof \Kotchasan\QueryBuilder\RawExpression) {
                        // Use RawExpression's toSql method
                        $placeholders[] = $val->toSql();
                    } else {
                        // Regular value - use placeholder
                        if ($this->useNamedParameters) {
                            $paramName = $this->placeholderPrefix.$this->paramCounter++;
                            $this->namedBindings[$paramName] = $val;
                            $placeholders[] = $paramName;
                        } else {
                            $placeholders[] = '?';
                        }
                    }
                }
                $rowPlaceholders[] = '('.implode(', ', $placeholders).')';
            }

            $query .= implode(', ', $rowPlaceholders);
        } else {
            // No data to insert - return a basic INSERT statement
            $query .= '() VALUES ()';
        }
        if ($this->ignore && $driver === 'pgsql') {
            $query .= ' ON CONFLICT DO NOTHING';
        }

        $this->lastQuery = $query;
        return $query;
    }
}
