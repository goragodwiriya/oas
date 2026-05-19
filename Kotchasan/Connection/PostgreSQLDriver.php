<?php

namespace Kotchasan\Connection;

use Kotchasan\Exception\DatabaseException;
use Kotchasan\Execution\PDOStatement;
use Kotchasan\Execution\StatementInterface;

/**
 * Class PostgreSQLDriver
 *
 * PostgreSQL-specific driver implementation.
 *
 * @package Kotchasan\Connection
 */
class PostgreSQLDriver implements DriverInterface
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

            return true;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Builds the DSN string for PostgreSQL.
     *
     * @param array $config The configuration array.
     * @return string The DSN string.
     */
    protected function buildDsn(array $config): string
    {
        $dsn = 'pgsql:';

        // Add host
        if (isset($config['host'])) {
            $dsn .= 'host='.$config['host'];
        } else {
            $dsn .= 'host=localhost';
        }

        // Add port if specified
        if (isset($config['port'])) {
            $dsn .= ';port='.$config['port'];
        }

        // Add database name
        if (isset($config['database'])) {
            $dsn .= ';dbname='.$config['database'];
        }

        // Add schema if specified
        if (isset($config['schema'])) {
            $dsn .= ';options=--search_path='.$config['schema'];
        }

        // Add application name
        if (isset($config['application_name'])) {
            $dsn .= ';application_name='.$config['application_name'];
        } else {
            $dsn .= ';application_name=Kotchasan';
        }

        // Add SSL mode
        if (isset($config['sslmode'])) {
            $dsn .= ';sslmode='.$config['sslmode'];
        }

        // Add SSL certificate
        if (isset($config['sslcert'])) {
            $dsn .= ';sslcert='.$config['sslcert'];
        }

        // Add SSL key
        if (isset($config['sslkey'])) {
            $dsn .= ';sslkey='.$config['sslkey'];
        }

        // Add SSL root certificate
        if (isset($config['sslrootcert'])) {
            $dsn .= ';sslrootcert='.$config['sslrootcert'];
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

        // In PostgreSQL, sequences are typically named with pattern: table_column_seq
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
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     */
    public function emptyTable(string $tableName, array $options = []): bool
    {
        $options = array_merge([
            'use_truncate' => true,
            'restart_identity' => true,
            'cascade' => false
        ], $options);

        // Quote table name for PostgreSQL
        $quotedTable = $this->quoteIdentifier($tableName);

        // Try TRUNCATE first if enabled
        if ($options['use_truncate']) {
            try {
                $sql = "TRUNCATE TABLE {$quotedTable}";

                if ($options['restart_identity']) {
                    $sql .= ' RESTART IDENTITY';
                }

                if ($options['cascade']) {
                    $sql .= ' CASCADE';
                }

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
        // PostgreSQL does not have a direct OPTIMIZE TABLE command.
        // Instead, we can use VACUUM to clean up the table.
        $quotedTable = $this->quoteIdentifier($tableName);
        $sql = "VACUUM FULL {$quotedTable}";

        try {
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
                return 'EXTRACT(YEAR FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MONTH':
                return 'EXTRACT(MONTH FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DAY':
                return 'EXTRACT(DAY FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'HOUR':
                return 'EXTRACT(HOUR FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'MINUTE':
                return 'EXTRACT(MINUTE FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'SECOND':
                return 'EXTRACT(SECOND FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DATE':
                return $this->quoteIdentifier($parameters['column']).'::DATE'.$aliasStr;

            case 'NOW':
                return 'NOW()'.$aliasStr;

            case 'RAND':
                return 'RANDOM()'.$aliasStr;

            case 'CONCAT':
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                if (!empty($parameters['separator'])) {
                    return "CONCAT_WS('".$parameters['separator']."', ".implode(', ', $fields).')'.$aliasStr;
                }
                return 'CONCAT('.implode(', ', $fields).')'.$aliasStr;

            case 'GROUP_CONCAT':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                $concatFields = implode(', ', $fields);
                $separator = $parameters['separator'] ?? ',';
                $orderClause = '';

                if (!empty($parameters['order'])) {
                    $orders = is_array($parameters['order']) ? $parameters['order'] : [$parameters['order']];
                    $orderClause = ' ORDER BY '.implode(', ', array_map([$this, 'quoteIdentifier'], $orders));
                }

                return 'STRING_AGG('.$distinct.$concatFields.", '".$separator."'".$orderClause.')'.$aliasStr;

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
                return 'COALESCE('.$this->quoteIdentifier($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'DATEDIFF':
                return '('.$this->formatFieldOrValue($parameters['column1']).' - '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'TIMEDIFF':
                return '('.$this->formatFieldOrValue($parameters['column1']).' - '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'TIMESTAMPDIFF':
                return 'EXTRACT('.$parameters['unit'].' FROM ('.$this->formatFieldOrValue($parameters['column2']).' - '.$this->formatFieldOrValue($parameters['column1']).'))'.$aliasStr;

            case 'DATE_ADD':
                $interval = (int) $parameters['interval'];
                $unit = strtolower($parameters['unit']);
                return '('.$this->formatFieldOrValue($parameters['column']).' + INTERVAL \''.$interval.' '.$unit.'\')'.$aliasStr;

            case 'DATE_SUB':
                $interval = (int) $parameters['interval'];
                $unit = strtolower($parameters['unit']);
                return '('.$this->formatFieldOrValue($parameters['column']).' - INTERVAL \''.$interval.' '.$unit.'\')'.$aliasStr;

            case 'FIND_IN_SET':
                $val = $this->formatFieldOrValue($parameters['value']);
                $col = $this->quoteIdentifier($parameters['column']);
                return 'COALESCE(ARRAY_POSITION(string_to_array('.$col.', \',\'), '.$val.'), 0)'.$aliasStr;

            case 'LPAD':
                $len = (int) $parameters['length'];
                $pad = str_replace("'", "''", $parameters['pad']);
                return 'LPAD(CAST('.$this->quoteIdentifier($parameters['column']).' AS TEXT), '.$len.", '".$pad."')".$aliasStr;

            case 'NULLIF':
                return 'NULLIF('.$this->formatFieldOrValue($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'QUARTER':
                return 'EXTRACT(QUARTER FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'RPAD':
                $len = (int) $parameters['length'];
                $pad = str_replace("'", "''", $parameters['pad']);
                return 'RPAD(CAST('.$this->quoteIdentifier($parameters['column']).' AS TEXT), '.$len.", '".$pad."')".$aliasStr;

            case 'STR_TO_DATE':
                $fmt = str_replace("'", "''", $parameters['format']);
                return 'TO_TIMESTAMP('.$this->formatFieldOrValue($parameters['value']).", '".$fmt."')".$aliasStr;

            case 'TIME':
                return 'CAST('.$this->quoteIdentifier($parameters['column']).' AS TIME)'.$aliasStr;

            case 'WEEK':
                return 'EXTRACT(WEEK FROM '.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'DATE_FORMAT':
                return 'TO_CHAR('.$this->quoteIdentifier($parameters['column']).", '".$parameters['format']."')".$aliasStr;

            case 'LENGTH':
                return 'LENGTH('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'UPPER':
                return 'UPPER('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'LOWER':
                return 'LOWER('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'TRIM':
                return 'TRIM('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'LTRIM':
                return 'LTRIM('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'RTRIM':
                return 'RTRIM('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'SUBSTRING':
                $start = $parameters['start'];
                $length = $parameters['length'] ?? null;
                if ($length === null) {
                    return 'SUBSTRING('.$this->quoteIdentifier($parameters['column']).' FROM '.$start.')'.$aliasStr;
                }
                return 'SUBSTRING('.$this->quoteIdentifier($parameters['column']).' FROM '.$start.' FOR '.$length.')'.$aliasStr;

            case 'REPLACE':
                return 'REPLACE('.$this->quoteIdentifier($parameters['column']).', '.$this->formatLiteralOrColumn($parameters['search']).', '.$this->formatLiteralOrColumn($parameters['replace']).')'.$aliasStr;

            case 'CURDATE':
                return 'CURRENT_DATE'.$aliasStr;

            case 'CURTIME':
                return 'CURRENT_TIME'.$aliasStr;

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
                return 'CEIL('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'FLOOR':
                return 'FLOOR('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'ROUND':
                $precision = $parameters['precision'] ?? null;
                if ($precision === null) {
                    return 'ROUND('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;
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
     * Quote identifier for PostgreSQL using double quotes.
     *
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as &$part) {
            $part = '"'.str_replace('"', '""', $part).'"';
        }
        return implode('.', $parts);
    }
}
