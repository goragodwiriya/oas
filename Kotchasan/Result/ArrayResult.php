<?php

namespace Kotchasan\Result;

/**
 * Class ArrayResult
 *
 * Array-based implementation of ResultInterface.
 * Used for cached query results.
 *
 * @package Kotchasan\Result
 */
class ArrayResult implements ResultInterface
{
    /**
     * The result data.
     *
     * @var array
     */
    protected array $data;

    /**
     * Current position for iteration.
     *
     * @var int
     */
    protected int $position = 0;

    /**
     * The result format.
     *
     * @var string
     */
    protected string $resultFormat;

    /**
     * ArrayResult constructor.
     *
     * @param array $data The result data.
     * @param string $resultFormat The format of the result ('array' or 'object').
     */
    public function __construct(array $data, string $resultFormat = 'array')
    {
        $this->data = $data;
        $this->resultFormat = $resultFormat;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch()
    {
        if (!isset($this->data[$this->position])) {
            return null;
        }

        $row = $this->data[$this->position];
        $this->position++;

        if ($this->resultFormat === 'object' && is_array($row)) {
            return (object) $row;
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(): array
    {
        $rows = array_slice($this->data, $this->position);
        $this->position = count($this->data);

        if ($this->resultFormat === 'object') {
            return array_map(function ($row) {
                return is_array($row) ? (object) $row : $row;
            }, $rows);
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(int $columnNumber = 0)
    {
        if (!isset($this->data[$this->position])) {
            return null;
        }

        $row = $this->data[$this->position];
        $this->position++;

        if (is_array($row)) {
            $values = array_values($row);
            return $values[$columnNumber] ?? null;
        }

        if (is_object($row)) {
            $values = array_values((array) $row);
            return $values[$columnNumber] ?? null;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount(): int
    {
        return count($this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function first()
    {
        if (empty($this->data)) {
            return null;
        }

        $row = $this->data[0];

        if ($this->resultFormat === 'object' && is_array($row)) {
            return (object) $row;
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        if ($this->resultFormat === 'object') {
            return array_map(function ($row) {
                return is_array($row) ? (object) $row : $row;
            }, $this->data);
        }

        return $this->data;
    }

    // Iterator interface methods

    /**
     * {@inheritdoc}
     */
    public function current(): mixed
    {
        if (!isset($this->data[$this->position])) {
            return null;
        }

        $row = $this->data[$this->position];

        if ($this->resultFormat === 'object' && is_array($row)) {
            return (object) $row;
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function key(): mixed
    {
        return $this->position;
    }

    /**
     * {@inheritdoc}
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function valid(): bool
    {
        return isset($this->data[$this->position]);
    }

    /**
     * Get the raw data array.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the result format.
     *
     * @return string
     */
    public function getResultFormat(): string
    {
        return $this->resultFormat;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount(): int
    {
        if (empty($this->data)) {
            return 0;
        }

        $firstRow = $this->data[0];

        if (is_array($firstRow)) {
            return count($firstRow);
        }

        if (is_object($firstRow)) {
            return count((array) $firstRow);
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnMeta(int $column): array
    {
        if (empty($this->data)) {
            return [];
        }

        $firstRow = $this->data[0];

        if (is_array($firstRow)) {
            $keys = array_keys($firstRow);
            if (isset($keys[$column])) {
                return [
                    'name' => $keys[$column],
                    'native_type' => 'STRING'
                ];
            }
        }

        if (is_object($firstRow)) {
            $keys = array_keys((array) $firstRow);
            if (isset($keys[$column])) {
                return [
                    'name' => $keys[$column],
                    'native_type' => 'STRING'
                ];
            }
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function hasMore(): bool
    {
        return isset($this->data[$this->position]);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
        $this->position = 0;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        $this->data = [];
        $this->position = 0;
        return true;
    }
}
