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
        return $this->from($table); // ใช้ from() ที่มีการแปลงชื่อตารางอัตโนมัติ
    }

    /**
     * Marks the insert as INSERT IGNORE.
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

        // Start building the query
        $query = 'INSERT ';

        // Add IGNORE if specified
        if ($this->ignore) {
            $query .= 'IGNORE ';
        }

        // Add INTO clause
        $query .= 'INTO '.$sqlBuilder->quoteIdentifier($this->table).' ';

        // Handle single row insert
        if (!empty($this->values)) {
            $columns = array_keys($this->values);
            $quoted = [];
            foreach ($columns as $c) {
                $quoted[] = $sqlBuilder->quoteIdentifier($c);
            }
            $query .= '('.implode(', ', $quoted).') VALUES (';

            $placeholders = [];
            foreach ($this->values as $column => $val) {
                // Check if value is a Sql object or SqlFunction (raw SQL expression)
                if ($val instanceof \Kotchasan\Database\Sql) {
                    // Use raw SQL expression directly
                    $placeholders[] = $val->toSql();
                    // Merge any bindings from the Sql object
                    foreach ($val->getValues([]) as $v) {
                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                        $this->namedBindings[$paramName] = $v;
                    }
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
                $quoted[] = $sqlBuilder->quoteIdentifier($c);
            }
            $query .= '('.implode(', ', $quoted).') VALUES ';

            $rowPlaceholders = [];
            foreach ($this->rows as $row) {
                $placeholders = [];
                foreach ($row as $val) {
                    // Check if value is a Sql object or SqlFunction (raw SQL expression)
                    if ($val instanceof \Kotchasan\Database\Sql) {
                        // Use raw SQL expression directly
                        $placeholders[] = $val->toSql();
                        // Merge any bindings from the Sql object
                        foreach ($val->getValues([]) as $v) {
                            $paramName = $this->placeholderPrefix.$this->paramCounter++;
                            $this->namedBindings[$paramName] = $v;
                        }
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

        $this->lastQuery = $query;
        return $query;
    }
}
