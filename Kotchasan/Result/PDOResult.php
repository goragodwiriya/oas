<?php

namespace Kotchasan\Result;

/**
 * Class PDOResult
 *
 * PDO-specific implementation of ResultInterface.
 *
 * @package Kotchasan\Result
 */
class PDOResult implements ResultInterface
{
    /**
     * The PDOStatement instance.
     *
     * @var \PDOStatement
     */
    protected \PDOStatement $statement;

    /**
     * Flag indicating whether the result set has been fully traversed.
     *
     * @var bool
     */
    protected bool $isEmpty = false;

    /**
     * Current row for iteration.
     *
     * @var array|null
     */
    protected ?array $currentRow = null;

    /**
     * Current position for iteration.
     *
     * @var int
     */
    protected int $position = 0;

    /**
     * Flag indicating if iteration has started.
     *
     * @var bool
     */
    protected bool $iterationStarted = false;

    /**
     * The result format for the PDOResult.
     *
     * @var string
     */
    protected string $resultFormat;

    /**
     * Rows read ahead to count a SELECT (see rowCount()), handed out by fetch() next.
     *
     * @var array|null
     */
    protected ?array $buffer = null;

    /**
     * Rows handed out so far.
     *
     * @var int
     */
    protected int $fetched = 0;

    /**
     * The row count of a SELECT once rowCount() worked it out.
     *
     * @var int|null
     */
    protected ?int $total = null;

    /**
     * false: PDOStatement::rowCount() of a SELECT is not its row count (SQLite gives
     * the rows changed by the last write, SQL Server -1), so rowCount() counts.
     *
     * @var bool
     */
    protected bool $countsRows = true;

    /**
     * PDOResult constructor.
     *
     * @param \PDOStatement $statement The PDOStatement instance.
     * @param string|null $resultFormat The format of the result (e.g., 'default', 'array', 'object').
     */
    public function __construct(\PDOStatement $statement, ?string $resultFormat = null, bool $countsRows = true)
    {
        $this->statement = $statement;
        $this->countsRows = $countsRows;
        // normalize null to 'default' to maintain backward compatibility
        $this->resultFormat = $resultFormat ?? 'default';
    }

    /**
     * Iterate the rows of the result set.
     *
     * Model code throughout the framework consumes a query with
     * `foreach ($query->execute() as $row)`. Without this the loop walks the
     * object's properties instead of its rows and silently yields nothing.
     *
     * @return \Traversable
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->fetchAll());
    }

    /**
     * {@inheritdoc}
     */
    public function fetch()
    {
        if ($this->buffer !== null) {
            if (empty($this->buffer)) {
                $this->isEmpty = true;
                return null;
            }
            $this->fetched++;

            return array_shift($this->buffer);
        }
        $fetchStyle = $this->resultFormat === 'array' ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;

        $row = $this->statement->fetch($fetchStyle);

        if ($row === false) {
            $this->isEmpty = true;
            return null;
        }
        $this->fetched++;

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(): array
    {
        if ($this->buffer !== null) {
            $rows = $this->buffer;
            $this->buffer = [];
        } else {
            $fetchStyle = $this->resultFormat === 'array' ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;
            $rows = $this->statement->fetchAll($fetchStyle);
        }
        $this->fetched += count($rows);
        $this->isEmpty = true;

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(int $columnNumber = 0)
    {
        if ($this->buffer !== null) {
            if (empty($this->buffer)) {
                $this->isEmpty = true;
                return false;
            }
            $this->fetched++;
            $row = array_values((array) array_shift($this->buffer));

            return $row[$columnNumber] ?? false;
        }
        $value = $this->statement->fetchColumn($columnNumber);

        if ($value === false && $this->statement->columnCount() === 0) {
            $this->isEmpty = true;
            return null;
        }
        if ($value === false) {
            // The end of the rows (a column value is never false)
            $this->isEmpty = true;
        } else {
            $this->fetched++;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount(): int
    {
        // A write, or a driver that counts the rows of a SELECT (MySQL, PostgreSQL)
        if ($this->countsRows || $this->statement->columnCount() === 0) {
            return $this->statement->rowCount();
        }
        // A SELECT on SQLite (rows changed by the last write) or SQL Server (-1):
        // count by reading the rest of the rows ahead, which fetch() and fetchAll()
        // then hand out
        if ($this->total === null) {
            $fetchStyle = $this->resultFormat === 'array' ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;
            // Rows already read to the end: nothing more to fetch (SQL Server throws)
            $rest = $this->isEmpty ? [] : $this->statement->fetchAll($fetchStyle);
            $this->buffer = array_merge($this->buffer ?? [], $rest);
            $this->total = $this->fetched + count($this->buffer);
        }

        return $this->total;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnMeta(int $column): array
    {
        $meta = $this->statement->getColumnMeta($column);

        if ($meta === false) {
            return [];
        }

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        return $this->statement;
    }

    /**
     * {@inheritdoc}
     */
    public function isEmpty(): bool
    {
        // No row at all (no row changed, for a write). It used to be true only after
        // reading to the end of a result that MySQL or PostgreSQL counted as empty
        return $this->rowCount() === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function hasMore(): bool
    {
        return $this->buffer !== null ? !empty($this->buffer) : !$this->isEmpty;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
        $this->buffer = null;
        $this->fetched = 0;
        $this->total = null;
        $this->isEmpty = false;

        return $this->statement->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        $this->statement->closeCursor();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return $this->rowCount();
    }
}
