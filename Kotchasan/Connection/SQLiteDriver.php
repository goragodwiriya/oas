<?php

namespace Kotchasan\Connection;

use Kotchasan\Exception\DatabaseException;
use Kotchasan\Execution\PDOStatement;
use Kotchasan\Execution\StatementInterface;

/**
 * Class SQLiteDriver
 *
 * SQLite-specific driver implementation.
 *
 * @package Kotchasan\Connection
 */
class SQLiteDriver implements DriverInterface
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

            $this->lastError = null;
            return true;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
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
            return false;
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
            return false;
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
            return false;
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
            return '';
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
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function emptyTable(string $tableName, array $options = []): bool
    {
        // SQLite doesn't support TRUNCATE, so we always use DELETE
        // Quote table name for SQLite
        $quotedTable = $this->quoteIdentifier($tableName);

        try {
            // Delete all rows
            $sql = "DELETE FROM {$quotedTable}";
            $statement = $this->prepare($sql);
            $result = $statement->execute();

            if ($result !== false) {
                // Reset auto-increment sequence if exists
                try {
                    $plainTableName = trim($tableName, '"\'`[]');
                    $resetSql = "DELETE FROM sqlite_sequence WHERE name = ?";
                    $stmt = $this->prepare($resetSql);
                    $stmt->execute([$plainTableName]);
                } catch (\Exception $e) {
                    // Ignore errors - sequence might not exist
                }
                return true;
            }
            return false;
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
        if (!$this->isConnected()) {
            return false;
        }

        try {
            // Run the VACUUM command to optimize the database
            $sql = "VACUUM";
            $statement = $this->prepare($sql);
            $result = $statement->execute();
            return $result !== false;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Translate a MySQL DATE_FORMAT() format string to SQLite strftime() tokens.
     * Single-pass (no re-translation): MySQL %i (minutes) -> %M, %s -> %S, etc.
     *
     * @param string $mysqlFormat
     * @return string
     */
    protected function convertDateFormat(string $mysqlFormat): string
    {
        $map = [
            '%Y' => '%Y', '%y' => '%Y', '%m' => '%m', '%d' => '%d',
            '%H' => '%H', '%k' => '%H', '%h' => '%H', '%I' => '%H',
            '%i' => '%M', '%s' => '%S', '%S' => '%S', '%M' => '%m',
            '%j' => '%j', '%w' => '%w', '%W' => '%w', '%%' => '%',
        ];
        $out = '';
        $len = strlen($mysqlFormat);
        for ($i = 0; $i < $len; $i++) {
            if ($mysqlFormat[$i] === '%' && $i + 1 < $len && isset($map['%'.$mysqlFormat[$i + 1]])) {
                $out .= $map['%'.$mysqlFormat[$i + 1]];
                $i++;
            } else {
                $out .= $mysqlFormat[$i];
            }
        }
        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public function formatSqlFunction(string $type, array $parameters, ?string $alias): string
    {
        $aliasStr = $alias ? ' AS '.$this->quoteIdentifier($alias) : '';

        switch (strtoupper($type)) {
            case 'YEAR':
                return "CAST(strftime('%Y', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'MONTH':
                return "CAST(strftime('%m', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'DAY':
                return "CAST(strftime('%d', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'HOUR':
                return "CAST(strftime('%H', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'MINUTE':
                return "CAST(strftime('%M', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'SECOND':
                return "CAST(strftime('%S', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'DATE':
                return 'DATE('.$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'NOW':
                // DATETIME() with no arg is invalid in SQLite; use local time to
                // match MySQL NOW() (server-local rather than UTC).
                return "DATETIME('now', 'localtime')".$aliasStr;

            case 'RAND':
                // MySQL RAND() returns a float in [0,1); SQLite RANDOM() returns a
                // signed 64-bit int. Normalize to [0,1).
                return '(ABS(RANDOM()) / 9223372036854775807.0)'.$aliasStr;

            case 'CONCAT':
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                if (!empty($parameters['separator'])) {
                    // SQLite doesn't have CONCAT_WS, simulate with || and separator
                    $sep = str_replace("'", "''", $parameters['separator']);
                    return '('.implode(" || '".$sep."' || ", $fields).')'.$aliasStr;
                }
                return '('.implode(' || ', $fields).')'.$aliasStr;

            case 'GROUP_CONCAT':
                $distinct = !empty($parameters['distinct']) ? 'DISTINCT ' : '';
                $fields = array_map([$this, 'formatFieldOrValue'], $parameters['fields']);
                $concatFields = implode(' || ', $fields);
                $separator = str_replace("'", "''", $parameters['separator'] ?? ',');

                // SQLite does NOT allow a custom separator together with DISTINCT
                // (GROUP_CONCAT(DISTINCT x, sep) is a syntax error). With DISTINCT
                // we must fall back to the default ',' separator.
                if ($distinct !== '') {
                    return 'GROUP_CONCAT(DISTINCT '.$concatFields.')'.$aliasStr;
                }
                return 'GROUP_CONCAT('.$concatFields.", '".$separator."')".$aliasStr;

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
                return 'IFNULL('.$this->quoteIdentifier($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'DATEDIFF':
                // MySQL DATEDIFF returns an INTEGER number of days.
                return '(CAST(JULIANDAY(DATE('.$this->formatFieldOrValue($parameters['column1']).')) - JULIANDAY(DATE('.$this->formatFieldOrValue($parameters['column2']).')) AS INTEGER))'.$aliasStr;

            case 'TIMEDIFF':
                return '(STRFTIME(\'%s\', '.$this->formatFieldOrValue($parameters['column1']).') - STRFTIME(\'%s\', '.$this->formatFieldOrValue($parameters['column2']).'))'.$aliasStr;

            case 'TIMESTAMPDIFF':
                // Honor the unit (MySQL returns an integer count in that unit).
                $unit = strtoupper((string) ($parameters['unit'] ?? 'DAY'));
                $factor = ['SECOND' => 86400, 'MINUTE' => 1440, 'HOUR' => 24, 'DAY' => 1, 'WEEK' => 1 / 7, 'MONTH' => 1 / 30, 'YEAR' => 1 / 365];
                $f = $factor[$unit] ?? 1;
                // ROUND before CAST: JULIANDAY float arithmetic makes an exact
                // 60s diff come out as 59.9999.. which would truncate to 59.
                return '(CAST(ROUND((JULIANDAY('.$this->formatFieldOrValue($parameters['column2']).') - JULIANDAY('.$this->formatFieldOrValue($parameters['column1']).')) * '.$f.') AS INTEGER))'.$aliasStr;

            case 'DATE_ADD':{
                    $unitMap = ['WEEK' => ['days', 7], 'YEAR' => ['years', 1], 'MONTH' => ['months', 1],
                        'DAY' => ['days', 1], 'HOUR' => ['hours', 1], 'MINUTE' => ['minutes', 1], 'SECOND' => ['seconds', 1]];
                    $u = $unitMap[$parameters['unit']] ?? [strtolower($parameters['unit']), 1];
                    $n = (int) $parameters['interval'] * $u[1];
                    return "DATETIME(".$this->formatFieldOrValue($parameters['column']).", '".$n." ".$u[0]."')".$aliasStr;
                }

            case 'DATE_SUB':{
                    $unitMap = ['WEEK' => ['days', 7], 'YEAR' => ['years', 1], 'MONTH' => ['months', 1],
                        'DAY' => ['days', 1], 'HOUR' => ['hours', 1], 'MINUTE' => ['minutes', 1], 'SECOND' => ['seconds', 1]];
                    $u = $unitMap[$parameters['unit']] ?? [strtolower($parameters['unit']), 1];
                    $n = (int) $parameters['interval'] * $u[1];
                    return "DATETIME(".$this->formatFieldOrValue($parameters['column']).", '-".$n." ".$u[0]."')".$aliasStr;
                }

            case 'FIND_IN_SET':
                $val = $this->formatFieldOrValue($parameters['value']);
                $col = $this->quoteIdentifier($parameters['column']);
                return "INSTR(',' || ".$col." || ',', ',' || ".$val." || ',')".$aliasStr;

            case 'LPAD':{
                    $len = (int) $parameters['length'];
                    $pad = str_replace("'", "''", $parameters['pad']);
                    $col = 'CAST('.$this->quoteIdentifier($parameters['column']).' AS TEXT)';
                    return "SUBSTR(REPLACE(PRINTF('%".$len."s', ''), ' ', '".$pad."') || ".$col.", -".$len.")".$aliasStr;
                }

            case 'NULLIF':
                return 'NULLIF('.$this->formatFieldOrValue($parameters['column1']).', '.$this->formatFieldOrValue($parameters['column2']).')'.$aliasStr;

            case 'QUARTER':
                return "((CAST(strftime('%m', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER) - 1) / 3 + 1)'.$aliasStr;

            case 'RPAD':{
                    $len = (int) $parameters['length'];
                    $pad = str_replace("'", "''", $parameters['pad']);
                    $col = 'CAST('.$this->quoteIdentifier($parameters['column']).' AS TEXT)';
                    return "SUBSTR(".$col." || REPLACE(PRINTF('%".$len."s', ''), ' ', '".$pad."'), 1, ".$len.")".$aliasStr;
                }

            case 'STR_TO_DATE':
                // SQLite cannot parse arbitrary formats; DATETIME() handles ISO
                // input. Non-ISO input yields NULL (documented limitation).
                return 'DATETIME('.$this->formatFieldOrValue($parameters['value']).')'.$aliasStr;

            case 'TIME':
                return "STRFTIME('%H:%M:%S', ".$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

            case 'WEEK':
                return "CAST(strftime('%W', ".$this->quoteIdentifier($parameters['column']).') AS INTEGER)'.$aliasStr;

            case 'DATE_FORMAT':
                $fmt = str_replace("'", "''", $this->convertDateFormat((string) $parameters['format']));
                return "strftime('".$fmt."', ".$this->quoteIdentifier($parameters['column']).')'.$aliasStr;

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
                    return 'SUBSTR('.$this->quoteIdentifier($parameters['column']).', '.$start.')'.$aliasStr;
                }
                return 'SUBSTR('.$this->quoteIdentifier($parameters['column']).', '.$start.', '.$length.')'.$aliasStr;

            case 'REPLACE':
                return 'REPLACE('.$this->quoteIdentifier($parameters['column']).', '.$this->formatLiteralOrColumn($parameters['search']).', '.$this->formatLiteralOrColumn($parameters['replace']).')'.$aliasStr;

            case 'CURDATE':
                return "DATE('now')".$aliasStr;

            case 'CURTIME':
                return "TIME('now')".$aliasStr;

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
                // SQLite has no CEIL without the math extension — emulate.
                $c = $this->quoteIdentifier($parameters['column']);
                return '(CASE WHEN '.$c.' = CAST('.$c.' AS INTEGER) THEN CAST('.$c.' AS INTEGER) WHEN '.$c.' > 0 THEN CAST('.$c.' AS INTEGER) + 1 ELSE CAST('.$c.' AS INTEGER) END)'.$aliasStr;

            case 'FLOOR':
                // SQLite has no FLOOR without the math extension — emulate.
                $c = $this->quoteIdentifier($parameters['column']);
                return '(CASE WHEN '.$c.' = CAST('.$c.' AS INTEGER) THEN CAST('.$c.' AS INTEGER) WHEN '.$c.' >= 0 THEN CAST('.$c.' AS INTEGER) ELSE CAST('.$c.' AS INTEGER) - 1 END)'.$aliasStr;

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
     * Quote identifier for SQLite using double quotes.
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

    /**
     * Builds the DSN for the SQLite connection.
     *
     * @param array $config The configuration parameters.
     * @return string The DSN.
     */
    protected function buildDsn(array $config): string
    {
        $database = $config['database'] ?? ':memory:';
        return "sqlite:{$database}";
    }
}
