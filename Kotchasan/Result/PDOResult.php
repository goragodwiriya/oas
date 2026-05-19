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
     * PDOResult constructor.
     *
     * @param \PDOStatement $statement The PDOStatement instance.
     * @param string|null $resultFormat The format of the result (e.g., 'default', 'array', 'object').
     */
    public function __construct(\PDOStatement $statement, ?string $resultFormat = null)
    {
        $this->statement = $statement;
        // normalize null to 'default' to maintain backward compatibility
        $this->resultFormat = $resultFormat ?? 'default';
    }

    /**
     * {@inheritdoc}
     */
    public function fetch()
    {
        $fetchStyle = $this->resultFormat === 'array' ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;

        $row = $this->statement->fetch($fetchStyle);

        if ($row === false) {
            $this->isEmpty = true;
            return null;
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(): array
    {
        $fetchStyle = $this->resultFormat === 'array' ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;

        $rows = $this->statement->fetchAll($fetchStyle);
        $this->isEmpty = true;

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(int $columnNumber = 0)
    {
        $value = $this->statement->fetchColumn($columnNumber);

        if ($value === false && $this->statement->columnCount() === 0) {
            $this->isEmpty = true;
            return null;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount(): int
    {
        return $this->statement->rowCount();
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
        return $this->isEmpty && $this->statement->rowCount() === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function hasMore(): bool
    {
        return !$this->isEmpty;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
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
