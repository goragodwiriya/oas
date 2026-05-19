<?php

namespace Kotchasan\Connection;

use Kotchasan\Exception\DatabaseException;
use Kotchasan\Execution\PDOStatement;
use Kotchasan\Execution\StatementInterface;

/**
 * Class MSSQLDriver
 *
 * MSSQL-specific driver implementation.
 *
 * @package Kotchasan\Connection
 */
class MSSQLDriver implements DriverInterface
{
    /**
     * The PDO instance.
     *
     * @var \PDO|null
     */
    protected $pdo = null;

    /**
     * The last error message.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * {@inheritdoc}
     */
    public function connect(array $config): bool
    {
        $dsn = $this->buildDsn($config);

        try {
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ];

            $this->pdo = new \PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                $options
            );

            // Set specific MSSQL options
            if (isset($config['charset'])) {
                $this->pdo->exec("SET NAMES '{$config['charset']}'");
            }

            return true;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Builds the DSN string for MSSQL.
     *
     * @param array $config The configuration array.
     * @return string The DSN string.
     */
    protected function buildDsn(array $config): string
    {
        $dsn = 'sqlsrv:';

        // Add server
        if (isset($config['host'])) {
            $dsn .= 'Server='.$config['host'];
        } else {
            $dsn .= 'Server=localhost';
        }

        // Add port if specified
        if (isset($config['port'])) {
            $dsn .= ','.$config['port'];
        }

        // Add database name
        if (isset($config['database'])) {
            $dsn .= ';Database='.$config['database'];
        }

        // Add App name
        if (isset($config['appname'])) {
            $dsn .= ';APP='.$config['appname'];
        } else {
            $dsn .= ';APP=Kotchasan';
        }

        // Add connection timeout
        if (isset($config['timeout'])) {
            $dsn .= ';ConnectionTimeout='.$config['timeout'];
        }

        // Add connection pooling
        if (isset($config['pooling']) && $config['pooling'] === false) {
            $dsn .= ';ConnectionPooling=0';
        }

        // Add encrypt
        if (isset($config['encrypt']) && $config['encrypt'] === true) {
            $dsn .= ';Encrypt=1';
        }

        // Add trust server certificate
        if (isset($config['trust_server_certificate']) && $config['trust_server_certificate'] === true) {
            $dsn .= ';TrustServerCertificate=1';
        }

        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): bool
    {
        $this->pdo = null;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(string $query): StatementInterface
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot prepare statement: Not connected to database.");
        }

        try {
            $pdoStatement = $this->pdo->prepare($query);

            return new PDOStatement($pdoStatement);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new DatabaseException("Failed to prepare statement: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): bool
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot begin transaction: Not connected to database.");
        }

        try {
            return $this->pdo->beginTransaction();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot commit transaction: Not connected to database.");
        }

        try {
            return $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(): bool
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot rollback transaction: Not connected to database.");
        }

        try {
            return $this->pdo->rollBack();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function inTransaction(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        return $this->pdo->inTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(?string $name = null): string
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot get last insert ID: Not connected to database.");
        }

        return $this->pdo->lastInsertId($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * {@inheritdoc}
     */
    public function escape(string $value): string
    {
        if (!$this->isConnected()) {
            throw new DatabaseException("Cannot escape value: Not connected to database.");
        }

        return $this->pdo->quote($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'sqlsrv';
    }

    /**
     * {@inheritdoc}
     */
    public function emptyTable(string $tableName, array $options = []): bool
    {
        $options = array_merge([
            'use_truncate' => true
        ], $options);

        // Quote table name for SQL Server
        $quotedTable = $this->quoteIdentifier($tableName);

        // Try TRUNCATE first if enabled
        if ($options['use_truncate']) {
            try {
                $sql = "TRUNCATE TABLE {$quotedTable}";
                $statement = $this->prepare($sql);
                $result = $statement->execute();
                return $result !== false;
            } catch (\Exception $e) {
                $this->lastError = $e->getMessage();
                // Fall through to DELETE fallback
            }
        }

        // Fallback to DELETE
        try {
            $sql = "DELETE FROM {$quotedTable}";
            $statement = $this->prepare($sql);
            $result = $statement->execute();
            return $result !== false;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function optimizeTable(string $tableName): bool
    {
        // SQL Server does not have a direct OPTIMIZE TABLE command.
        // We can use DBCC commands to achieve similar results.

        // Quote table name for SQL Server
        $quotedTable = $this->quoteIdentifier($tableName);

        try {
            // Rebuild indexes to optimize the table
            $sql = "ALTER INDEX ALL ON {$quotedTable} REBUILD";
            $statement = $this->prepare($sql);
            $result = $statement->execute();
            return $result !== false;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function formatSqlFunction(string $type, array $parameters, ?string $alias): string
    {
        $aliasStr = $alias ? ' AS '.$this->quoteIdentifier($alias) : '';

        switch (strtoupper($type)) {
            case 'YEAR':
                return 'YEAR('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MONTH':
                return 'MONTH('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DAY':
                return 'DAY('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'HOUR':
                return 'DATEPART(HOUR, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MINUTE':
                return 'DATEPART(MINUTE, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'SECOND':
                return 'DATEPART(SECOND, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DATE':
                return 'CAST('.$this->quoteIdentifier($parameters['column']).' AS DATE)'.$aliasStr;

            case 'NOW':
                return 'GETDATE()'.$aliasStr;

            case 'RAND':
                return 'NEWID()'.$aliasStr;

            case 'CONCAT':
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                if (!empty($parameters['separator'])) {
                    // MSSQL doesn't have CONCAT_WS, simulate with + and separator
                    return '('.implode(" + '".$parameters['separator']."' + ", $fields).')'.$aliasStr;
                }
                return '('.implode(' + ', $fields).')'.$aliasStr;

            case 'GROUP_CONCAT':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                $concatFields = implode(' + ', $fields);
                $separator = $parameters['separator'] ?? ',';
                $orderClause = '';

                if (!empty($parameters['order'])) {
                    $orders = is_array($parameters['order']) ? $parameters['order'] : [$parameters['order']];
                    $orderClause = ' WITHIN GROUP (ORDER BY '.implode(', ', array_map([$this, 'quoteIdentifier'], $orders)).')';
                }

                return 'STRING_AGG('.$distinct.$concatFields.", '".$separator."')".$orderClause.$aliasStr;

            case 'COUNT':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                $column = $parameters['column'] === '*' ? '*' : $this->quoteIdentifier($parameters['column']);
                return 'COUNT('.$distinct.$column.')'.$aliasStr;

            case 'SUM':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                return 'SUM('.$distinct.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'AVG':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                return 'AVG('.$distinct.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MAX':
                return 'MAX('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MIN':
                return 'MIN('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DISTINCT':
                return 'DISTINCT '.$this->quoteIdentifier($parameters['column']).$aliasStr;

            case 'IFNULL':
                return 'ISNULL('.$this->quoteIdentifier($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'DATEDIFF':
                return 'DATEDIFF(DAY, '.$this->formatFieldOrValue($parameters['column2']).', '.$this->formatFieldOrValue($parameters['column1']).')'.$aliasStr;

            case 'TIMEDIFF':
                return 'DATEDIFF(SECOND, '.$this->formatFieldOrValue($parameters['column2']).', '.$this->formatFieldOrValue($parameters['column1']).')'.$aliasStr;

            case 'TIMESTAMPDIFF':
                return 'DATEDIFF('.$parameters['unit'].', '.$this->formatFieldOrValue($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'DATE_ADD':
                return 'DATEADD('.strtolower($parameters['unit']).', '.(int) $parameters['interval'].', '.$this->formatFieldOrValue($parameters['column']).')'.$aliasStr;

            case 'DATE_SUB':
                return 'DATEADD('.strtolower($parameters['unit']).', -'.(int) $parameters['interval'].', '.$this->formatFieldOrValue($parameters['column']).')'.$aliasStr;

            case 'FIND_IN_SET':
                $val = $this->formatFieldOrValue($parameters['value']);
                $col = $this->quoteIdentifier($parameters['column']);
                return 'CHARINDEX(\',\' + '.$val.' + \',\', \',\' + CAST('.$col.' AS VARCHAR) + \',\')'.$aliasStr;

            case 'LPAD':
                $len = (int) $parameters['length'];
                $pad = str_replace("'", "''", $parameters['pad']);
                return 'RIGHT(REPLICATE('."\'".$pad."\'".", ".$len.') + CAST('.$this->quoteIdentifier($parameters['column']).' AS VARCHAR), '.$len.')'.$aliasStr;

            case 'NULLIF':
                return 'NULLIF('.$this->formatFieldOrValue($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'QUARTER':
                return 'DATEPART(QUARTER, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'RPAD':
                $len = (int) $parameters['length'];
                $pad = str_replace("'", "''", $parameters['pad']);
                return 'LEFT(CAST('.$this->quoteIdentifier($parameters['column']).' AS VARCHAR) + REPLICATE('."\'".$pad."\'".", ".$len.'), '.$len.')'.$aliasStr;

            case 'STR_TO_DATE':
                return 'TRY_CONVERT(DATETIME, '.$this->formatFieldOrValue($parameters['value']).')'.$aliasStr;

            case 'TIME':
                return 'CONVERT(TIME, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'WEEK':
                return 'DATEPART(WEEK, '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DATE_FORMAT':
                return 'FORMAT('.$this->quoteIdentifier($parameters['column']).", '".$parameters['format']."')".$aliasStr;

            case 'LENGTH':
                return 'LEN('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'UPPER':
                return 'UPPER('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'LOWER':
                return 'LOWER('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'TRIM':
                return 'LTRIM(RTRIM('.$this->quoteIdentifier($parameters['column']).'))'.$aliasStr;

            case 'LTRIM':
                return 'LTRIM('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'RTRIM':
                return 'RTRIM('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'SUBSTRING':
                $start = $parameters['start'];
                $length = $parameters['length'] ?? null;
                if ($length === null) {
                    return 'SUBSTRING('.$this->quoteIdentifier($parameters['column']).', '.$start.', LEN('.$this->quoteIdentifier($parameters['column']).'))'.$aliasStr;
                }
                return 'SUBSTRING('.$this->quoteIdentifier($parameters['column']).', '.$start.', '.$length.')'.$aliasStr;

            case 'REPLACE':
                return 'REPLACE('.$this->quoteIdentifier($parameters['column']).', '.$this->formatLiteralOrColumn($parameters['search']).', '.$this->formatLiteralOrColumn($parameters['replace']).')'.$aliasStr;

            case 'CURDATE':
                return 'CAST(GETDATE() AS DATE)'.$aliasStr;

            case 'CURTIME':
                return 'CAST(GETDATE() AS TIME)'.$aliasStr;

            case 'IF_EXPR':
                return 'CASE WHEN '.$parameters['condition'].' THEN '.$this->formatFieldOrValue($parameters['value_true']).' ELSE '.$this->formatFieldOrValue($parameters['value_false']).' END'.$aliasStr;

            case 'CASE_WHEN':
                $cases = $parameters['cases'] ?? [];
                $sql = 'CASE';
                foreach ($cases as $case) {
                    $condition = $case[0] ?? '';
                    $result = $case[1] ?? null;
                    $sql .= ' WHEN '.$condition.' THEN '.$this->formatFieldOrValue($result);
                }
                if (array_key_exists('else', $parameters)) {
                    $sql .= ' ELSE '.$this->formatFieldOrValue($parameters['else']);
                }
                return $sql.' END'.$aliasStr;

            case 'COALESCE':
                $values = array_map([$this, 'formatFieldOrValue'], $parameters['values']);
                return 'COALESCE('.implode(', ', $values).')'.$aliasStr;

            case 'ABS':
                return 'ABS('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'CEIL':
                return 'CEILING('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'FLOOR':
                return 'FLOOR('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'ROUND':
                $precision = $parameters['precision'] ?? null;
                if ($precision === null) {
                    return 'ROUND('.$this->quoteIdentifier($parameters['column']).', 0)'.$aliasStr;
                }
                return 'ROUND('.$this->quoteIdentifier($parameters['column']).', '.$precision.')'.$aliasStr;

            case 'COLUMN':
                return $this->quoteIdentifier($parameters['column']);

            default:
                throw new \InvalidArgumentException("Unsupported SQL function: {$type}");
        }
    }

    /**
     * Format field name or value for SQL usage
     *
     * @param mixed $value
     * @return string
     */
    protected function formatFieldOrValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof \Kotchasan\QueryBuilder\RawExpression) {
            return $value->toSql();
        }
        if ($value instanceof \Kotchasan\QueryBuilder\SqlFunction  && strtoupper($value->getType()) === 'COLUMN') {
            $params = $value->getParameters();
            return $this->quoteIdentifier($params['column']);
        }

        if (is_string($value) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $value)) {
            // Looks like a field name
            return $this->quoteIdentifier($value);
        }

        // Treat as literal value
        return is_string($value) ? "'".str_replace("'", "''", $value)."'" : (string) $value;
    }

    /**
     * Format literal value or explicit column reference.
     * Strings are treated as literals by default.
     *
     * @param mixed $value
     * @return string
     */
    protected function formatLiteralOrColumn($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($value instanceof \Kotchasan\QueryBuilder\RawExpression) {
            return $value->toSql();
        }
        if ($value instanceof \Kotchasan\QueryBuilder\SqlFunction  && strtoupper($value->getType()) === 'COLUMN') {
            $params = $value->getParameters();
            return $this->quoteIdentifier($params['column']);
        }

        return is_string($value) ? "'".str_replace("'", "''", $value)."'" : (string) $value;
    }

    /**
     * Quote identifier for SQL Server using square brackets.
     *
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as &$part) {
            $part = '['.str_replace(']', ']]', $part).']';
        }
        return implode('.', $parts);
    }
}
