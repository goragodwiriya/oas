<?php

namespace Kotchasan\Execution;

use Kotchasan\Exception\DatabaseException;
use Kotchasan\Result\PDOResult;
use Kotchasan\Result\ResultInterface;

/**
 * Class PDOStatement
 *
 * PDO-specific implementation of StatementInterface.
 *
 * @package Kotchasan\Execution
 */
class PDOStatement implements StatementInterface
{
    /**
     * The PDOStatement instance.
     *
     * @var \PDOStatement
     */
    protected \PDOStatement $statement;

    /**
     * The SQL query.
     *
     * @var string
     */
    protected string $query;

    /**
     * The last error message.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * PDOStatement constructor.
     *
     * @param \PDOStatement $statement The PDOStatement instance.
     */
    public function __construct(\PDOStatement $statement)
    {
        $this->statement = $statement;
        $this->query = $statement->queryString;
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($parameter, &$variable, $type = null, $length = null): bool
    {
        try {
            // Convert type if provided
            if ($type !== null) {
                return $this->statement->bindParam($parameter, $variable, $type, $length);
            }

            return $this->statement->bindParam($parameter, $variable);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($parameter, $value, $type = null): bool
    {
        try {
            // Convert type if provided
            if ($type !== null) {
                return $this->statement->bindValue($parameter, $value, $type);
            }

            return $this->statement->bindValue($parameter, $value);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute(?array $params = null, ?string $resultFormat = null): ResultInterface
    {
        try {
            // PDO may expect named parameter keys without the leading colon.
            // Normalize keys if an associative array with colon-prefixed keys is provided.
            $normalizedParams = null;
            if (is_array($params)) {
                $normalizedParams = [];
                foreach ($params as $k => $v) {
                    if (is_string($k) && strpos($k, ':') === 0) {
                        $normalizedParams[substr($k, 1)] = $v;
                    } else {
                        $normalizedParams[$k] = $v;
                    }
                }
            }

            $success = $this->statement->execute($normalizedParams ?? $params);

            if (!$success) {
                $error = $this->statement->errorInfo();
                $this->lastError = $error[2];
                throw new DatabaseException("Failed to execute statement: ".$this->lastError);
            }

            return new PDOResult($this->statement, $resultFormat);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new DatabaseException("Failed to execute statement: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatement()
    {
        return $this->statement;
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
