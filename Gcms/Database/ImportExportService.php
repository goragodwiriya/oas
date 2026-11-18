<?php
/**
 * @filesource Gcms/Database/ImportExportService.php
 *
 * Database Import/Export service for prefixed tables.
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Database;

use Kotchasan\Database;

class ImportExportService
{
    /**
     * The session settings a dump needs; any other SET is left out, not run. SET GLOBAL and SET
     * PASSWORD reach past this site, and a charset such as gbk or the sql_mode
     * NO_BACKSLASH_ESCAPES makes the server end a string where the checks here do not.
     */
    private const SESSION_SET = '/^SET\s+(?:SESSION\s+)?(?:NAMES\s+[\'"]?(?:utf8|utf8mb3|utf8mb4|latin1|ascii)[\'"]?(?:\s+COLLATE\s+[\'"]?\w+[\'"]?)?|(?:FOREIGN_KEY_CHECKS|UNIQUE_CHECKS)\s*=\s*[01]|TIME_ZONE\s*=\s*[\'"][-+]\d\d:\d\d[\'"]|SQL_MODE\s*=\s*[\'"](?:NO_AUTO_VALUE_ON_ZERO)?[\'"])\s*;?\s*$/i';

    /**
     * What may follow the column list of a CREATE TABLE. Everything else there reads or reaches
     * outside the table: SELECT, TABLE, LIKE, UNION=, CONNECTION=, DATA DIRECTORY=, PARTITION BY.
     */
    private const TABLE_OPTIONS = '/^(?:[\s,]*(?:ENGINE\s*=?\s*\w+|(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET|COLLATE)\s*=?\s*\w+|(?:AUTO_INCREMENT|AVG_ROW_LENGTH|CHECKSUM|DELAY_KEY_WRITE|KEY_BLOCK_SIZE|MAX_ROWS|MIN_ROWS|PACK_KEYS|PAGE_CHECKSUM|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ROW_FORMAT|STATS_AUTO_RECALC|STATS_PERSISTENT|STATS_SAMPLE_PAGES|TRANSACTIONAL)\s*=?\s*\w+|COMMENT\s*=?\s*\'\'))*\s*$/i';

    /**
     * Words that have no place in a table definition of a backup, read with quoted names removed:
     * a subquery, a table or file elsewhere, or a function that blocks the server.
     */
    private const FOREIGN_REACH = '/\b(?:SELECT|LIKE|TABLE|RENAME|EXCHANGE|UNION|PARTITION|DIRECTORY|TABLESPACE|CONNECTION|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK)\b/i';

    /**
     * The last statement of a backup: the import takes a file without it as cut off.
     */
    private const BACKUP_LAST_LINE = 'SET FOREIGN_KEY_CHECKS=1;';

    /**
     * Holds the data of a table while an import runs.
     */
    private const COPY_SUFFIX = '__restore';

    /**
     * Records how to undo an import while it runs. Neither this nor a copy is offered for backup.
     */
    private const STATE_TABLE = 'import_state';

    /**
     * Marks a value in a JSON backup that is not UTF-8 text.
     */
    private const JSON_BINARY = '__base64';

    private const READ_CHUNK = 1048576;

    private const INSERT_ROWS = 1000;

    private const INSERT_BYTES = 1048576;

    private const BATCH_BYTES = 8388608;

    private const COMMIT_STATEMENTS = 1000;

    private const HEARTBEAT_SECONDS = 10;

    private Database $database;

    private string $prefix;

    /**
     * @var callable|null
     */
    private $heartbeat = null;

    private int $nextBeat = 0;

    /**
     * Statements since the last commit, -1 while the import must not commit as it goes.
     */
    private int $uncommitted = -1;

    /**
     * @param Database $database
     */
    public function __construct(?Database $database = null)
    {
        $this->database = $database ?: Database::create();
        $this->prefix = trim((string) $this->database->getPrefix());
    }

    /**
     * @return mixed
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @return mixed
     */
    public function getTablePrefixWithUnderscore(): string
    {
        return $this->prefix === '' ? '' : $this->prefix.'_';
    }

    /**
     * @param string $table
     */
    public function isValidPrefixedTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        $prefixWithUnderscore = $this->getTablePrefixWithUnderscore();
        if ($prefixWithUnderscore === '') {
            return true;
        }

        return strpos($table, $prefixWithUnderscore) === 0;
    }

    /**
     * @return array the tables of this site that can be backed up
     */
    public function detectTables(): array
    {
        $state = $this->stateTable();
        $suffix = self::COPY_SUFFIX;

        return array_values(array_filter($this->siteTables(), static function ($item) use ($state, $suffix) {
            return $item['name'] !== $state && substr($item['name'], -strlen($suffix)) !== $suffix;
        }));
    }

    /**
     * @return array every table of this site, including what an import is using
     */
    private function siteTables(): array
    {
        $tables = [];
        $prefixWithUnderscore = $this->getTablePrefixWithUnderscore();
        // Asked by name: a database shared by many sites has thousands of tables to report on.
        $like = $prefixWithUnderscore === '' ? '' : " LIKE '".str_replace(['\\', '_', '%', "'"], ['\\\\', '\\_', '\\%', ''], $prefixWithUnderscore)."%'";
        try {
            $result = $this->database->raw('SHOW TABLE STATUS'.$like);
            $rows = $result ? $result->fetchAll() : [];
            foreach ($rows as $row) {
                $name = (string) ($row->Name ?? '');
                if ($name === '') {
                    continue;
                }
                if ($prefixWithUnderscore !== '' && strpos($name, $prefixWithUnderscore) !== 0) {
                    continue;
                }
                // A view has no rows of its own to restore.
                if (strtoupper((string) ($row->Comment ?? '')) === 'VIEW') {
                    continue;
                }
                $tables[$name] = [
                    'name' => $name,
                    'rows' => isset($row->Rows) ? (int) $row->Rows : null,
                    'size' => (isset($row->Data_length) ? (int) $row->Data_length : 0) + (isset($row->Index_length) ? (int) $row->Index_length : 0),
                    'engine' => isset($row->Engine) ? (string) $row->Engine : ''
                ];
            }
        } catch (\Exception $e) {
            $tables = [];
        }

        if (empty($tables)) {
            $rows = $this->database->raw('SHOW TABLES'.$like);
            while ($rows && ($row = $rows->fetch())) {
                $name = (string) current((array) $row);
                if ($name === '') {
                    continue;
                }
                if ($prefixWithUnderscore !== '' && strpos($name, $prefixWithUnderscore) !== 0) {
                    continue;
                }
                $tables[$name] = [
                    'name' => $name,
                    'rows' => null,
                    'size' => null,
                    'engine' => ''
                ];
            }
        }

        ksort($tables);
        return array_values($tables);
    }

    /**
     * @param array $selected
     */
    public function sanitizeSelectedTables(array $selected): array
    {
        $detected = $this->detectTables();
        $allowed = [];
        foreach ($detected as $item) {
            $allowed[$item['name']] = true;
        }

        $result = [];
        foreach ($selected as $table) {
            $table = trim((string) $table);
            if ($table === '' || !isset($allowed[$table])) {
                continue;
            }
            $result[] = $table;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array $tableModes
     * @param array $allowedTables
     * @return mixed
     */
    public function sanitizeTableModes(array $tableModes, array $allowedTables = []): array
    {
        if (empty($allowedTables)) {
            $allowedTables = array_map(static function ($item) {
                return $item['name'];
            }, $this->detectTables());
        }
        $allowedMap = [];
        foreach ($allowedTables as $table) {
            $allowedMap[(string) $table] = true;
        }

        $result = [];
        foreach ($tableModes as $table => $mode) {
            $table = trim((string) $table);
            if ($table === '' || !isset($allowedMap[$table]) || !$this->isValidPrefixedTable($table)) {
                continue;
            }
            if (!is_array($mode)) {
                continue;
            }
            $structure = !empty($mode['structure']);
            $data = !empty($mode['data']);
            if (!$structure && !$data) {
                continue;
            }
            $result[$table] = [
                'structure' => $structure,
                'data' => $data
            ];
        }

        return $result;
    }

    /**
     * Write a backup of the given tables. Nothing is held in memory but a batch of rows, so the size
     * of the database does not matter. Pass php://output to send it as it is produced.
     *
     * @param string $filePath
     * @param array $tableModes
     * @param int $batchSize
     */
    public function exportSqlByTableModes(string $filePath, array $tableModes, int $batchSize = 500): array
    {
        $batchSize = max(100, min(5000, $batchSize));
        $fp = fopen($filePath, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to create backup file');
        }
        @set_time_limit(0);
        $streaming = strpos($filePath, 'php://') === 0;

        $summary = [
            'tables' => [],
            'rows_exported' => 0
        ];

        // One view of the data from the first table to the last, like mysqldump --single-transaction.
        $this->database->beginTransaction();
        try {
            fwrite($fp, "-- GCMS Database Backup\n");
            fwrite($fp, "-- Generated at: ".date('Y-m-d H:i:s')."\n");
            fwrite($fp, "SET NAMES utf8mb4;\n");
            fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tableModes as $table => $mode) {
                $table = (string) $table;
                if (!$this->isValidPrefixedTable($table)) {
                    continue;
                }

                $includeStructure = !empty($mode['structure']);
                $includeData = !empty($mode['data']);
                if (!$includeStructure && !$includeData) {
                    continue;
                }

                $tableInfo = [
                    'table' => $table,
                    'structure' => false,
                    'rows' => 0
                ];

                fwrite($fp, "-- --------------------------------------------------------\n");
                fwrite($fp, "-- Table: `{$table}`\n\n");

                if ($includeStructure) {
                    $createStmt = $this->getCreateTableSql($table);
                    if ($createStmt !== '') {
                        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                        fwrite($fp, $createStmt.";\n\n");
                        $tableInfo['structure'] = true;
                    }
                }

                if ($includeData) {
                    $tableInfo['rows'] = $this->writeInserts($fp, $table, $batchSize, $streaming);
                    $summary['rows_exported'] += $tableInfo['rows'];
                    fwrite($fp, "\n");
                }

                $summary['tables'][] = $tableInfo;
            }

            // The import checks for this line: a file without it was cut off.
            fwrite($fp, self::BACKUP_LAST_LINE."\n");
        } finally {
            $this->database->rollback();
            fclose($fp);
        }

        return $summary;
    }

    /**
     * @param string $filePath
     * @param array $tables
     * @param string $mode
     * @param int $batchSize
     */
    public function exportJson(string $filePath, array $tables, string $mode = 'both', int $batchSize = 500): array
    {
        $mode = $this->normalizeMode($mode);
        $batchSize = max(100, min(5000, $batchSize));
        $fp = fopen($filePath, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to create backup file');
        }
        @set_time_limit(0);

        $summary = [
            'tables' => [],
            'mode' => $mode,
            'rows_exported' => 0
        ];

        $this->database->beginTransaction();
        try {
            fwrite($fp, '{"format":"gcms-db-backup-v1","generated_at":'.json_encode(date('c')).',"mode":'.json_encode($mode).',"tables":{');
            $firstTable = true;
            foreach ($tables as $table) {
                if (!$this->isValidPrefixedTable($table)) {
                    continue;
                }
                if (!$firstTable) {
                    fwrite($fp, ',');
                }
                $firstTable = false;

                fwrite($fp, json_encode($table).':{');
                $firstPart = true;
                $tableInfo = [
                    'table' => $table,
                    'structure' => false,
                    'rows' => 0
                ];

                if ($mode !== 'data') {
                    $createStmt = $this->getCreateTableSql($table);
                    fwrite($fp, '"create":'.json_encode($createStmt));
                    $firstPart = false;
                    $tableInfo['structure'] = $createStmt !== '';
                }

                if ($mode !== 'structure') {
                    if (!$firstPart) {
                        fwrite($fp, ',');
                    }
                    fwrite($fp, '"rows":[');
                    $firstRow = true;
                    foreach ($this->tableRows($table, $batchSize) as $row) {
                        if (!$firstRow) {
                            fwrite($fp, ',');
                        }
                        fwrite($fp, json_encode($this->jsonSafeRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $firstRow = false;
                        $tableInfo['rows']++;
                        $summary['rows_exported']++;
                    }
                    fwrite($fp, ']');
                }

                fwrite($fp, '}');
                $summary['tables'][] = $tableInfo;
            }
            fwrite($fp, '}}');
        } finally {
            $this->database->rollback();
            fclose($fp);
        }

        return $summary;
    }

    /**
     * @param resource $fp
     * @param string $table
     * @param int $batchSize
     * @param bool $streaming send each statement on at once
     * @return int rows written
     */
    private function writeInserts($fp, string $table, int $batchSize, bool $streaming): int
    {
        $head = '';
        $values = '';
        $inStatement = 0;
        $written = 0;
        $flush = function () use ($fp, &$head, &$values, &$inStatement, $streaming) {
            if ($inStatement > 0) {
                fwrite($fp, $head.$values.";\n");
                if ($streaming) {
                    fflush($fp);
                    flush();
                }
            }
            $values = '';
            $inStatement = 0;
        };
        foreach ($this->tableRows($table, $batchSize) as $row) {
            if ($head === '') {
                $head = 'INSERT INTO `'.$table.'` ('.$this->quoteNames(array_keys($row)).") VALUES\n";
            }
            $values .= ($inStatement > 0 ? ",\n" : '').'('.implode(',', array_map([$this, 'escapeSqlValue'], $row)).')';
            $inStatement++;
            $written++;
            if ($inStatement >= self::INSERT_ROWS || strlen($values) >= self::INSERT_BYTES) {
                $flush();
            }
        }
        $flush();

        return $written;
    }

    /**
     * Every stored row of a table, a batch at a time. It reads on from the last key rather than with
     * OFFSET: the server walks past every skipped row, so OFFSET gets slower with each batch, and
     * without an ORDER BY it may also repeat or miss rows.
     *
     * @param string $table
     * @param int $batchSize
     * @return \Generator
     */
    private function tableRows(string $table, int $batchSize): \Generator
    {
        $columns = $this->storedColumns($table);
        $key = $this->orderingKey($table, $columns);
        $select = 'SELECT '.$this->quoteNames(array_keys($columns))." FROM `{$table}`";
        $order = empty($key) ? '' : ' ORDER BY '.$this->quoteNames($key);
        // Only these can make a row too large to judge from the rows before it.
        $large = array_keys(array_filter($columns, static function ($type) {
            return preg_match('/blob|text|json/i', $type);
        }));
        $limit = min(100, $batchSize);
        $after = '';
        $params = [];
        $offset = 0;
        while (true) {
            $page = empty($key) ? " LIMIT {$limit} OFFSET {$offset}" : " LIMIT {$limit}";
            if (!empty($large)) {
                [$limit, $page] = $this->fittingPage($table, $large, $after.$order, $params, $limit, empty($key) ? $offset : null);
            }
            $result = $this->database->raw($select.$after.$order.$page, $params);
            $fetched = 0;
            $bytes = 0;
            $row = [];
            while ($result && ($next = $result->fetch())) {
                $row = (array) $next;
                foreach ($row as $value) {
                    $bytes += is_string($value) ? strlen($value) : 8;
                }
                $fetched++;
                yield $row;
            }
            if ($fetched < $limit) {
                return;
            }
            $offset += $fetched;
            if (!empty($key)) {
                [$after, $params] = $this->afterKey($key, $row);
            }
            // Keep a batch small enough for memory_limit however wide the rows are.
            $limit = max(1, min($batchSize, $limit * 2, (int) (self::BATCH_BYTES / max(1, $bytes / $fetched))));
        }
    }

    /**
     * Ask the server how large the next batch is before fetching it: the whole batch is held in
     * memory, and one too large for memory_limit ends the request with no way to catch it.
     *
     * @param string $table
     * @param array $large the columns that can be large
     * @param string $filter the WHERE and ORDER BY of the batch
     * @param array $params
     * @param int $limit
     * @param int|null $offset null when paging by key
     * @return array the limit that fits, and its LIMIT clause
     */
    private function fittingPage(string $table, array $large, string $filter, array $params, int $limit, ?int $offset): array
    {
        $sizes = implode('+', array_map(function ($column) {
            return 'COALESCE(LENGTH('.$this->quoteNames([$column]).'),0)';
        }, $large));
        while (true) {
            $page = $offset === null ? " LIMIT {$limit}" : " LIMIT {$limit} OFFSET {$offset}";
            if ($limit <= 1) {
                return [$limit, $page];
            }
            $result = $this->database->raw("SELECT SUM(`size`) AS `total` FROM (SELECT {$sizes} AS `size` FROM `{$table}`{$filter}{$page}) AS `batch`", $params);
            if ((int) (((array) $result->fetch())['total'] ?? 0) <= self::BATCH_BYTES) {
                return [$limit, $page];
            }
            $limit = (int) ($limit / 2);
        }
    }

    /**
     * @param string $table
     * @return array column name => type, for the columns that hold a value: the server works out a
     * generated one and refuses a value for it
     */
    private function storedColumns(string $table): array
    {
        $columns = [];
        $result = $this->database->raw("SHOW COLUMNS FROM `{$table}`");
        while ($result && ($row = $result->fetch())) {
            $row = (array) $row;
            // Not the word GENERATED: MySQL 8 calls a plain DEFAULT CURRENT_TIMESTAMP DEFAULT_GENERATED.
            if (!preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT)\b/i', (string) ($row['Extra'] ?? ''))) {
                $columns[(string) $row['Field']] = (string) $row['Type'];
            }
        }

        return $columns;
    }

    /**
     * @param string $table
     * @param array $columns from storedColumns()
     * @return array the columns of a key that orders the rows exactly, empty if the table has none:
     * no NULLs, no index on part of a column, and no float, which does not survive being sent back
     */
    private function orderingKey(string $table, array $columns): array
    {
        $keys = [];
        $result = $this->database->raw("SHOW KEYS FROM `{$table}`");
        while ($result && ($row = $result->fetch())) {
            $row = (array) $row;
            if ((int) $row['Non_unique'] !== 0) {
                continue;
            }
            $name = (string) $row['Key_name'];
            $column = (string) $row['Column_name'];
            $usable = ($row['Null'] ?? '') !== 'YES' && ($row['Sub_part'] ?? null) === null
            && isset($columns[$column]) && !preg_match('/float|double/i', $columns[$column]);
            $keys[$name]['usable'] = ($keys[$name]['usable'] ?? true) && $usable;
            $keys[$name]['columns'][(int) $row['Seq_in_index']] = $column;
        }
        if (isset($keys['PRIMARY'])) {
            $keys = ['PRIMARY' => $keys['PRIMARY']] + $keys;
        }
        foreach ($keys as $candidate) {
            if ($candidate['usable']) {
                ksort($candidate['columns']);
                return array_values($candidate['columns']);
            }
        }

        return [];
    }

    /**
     * Written out, not as (a, b) > (?, ?): not every server uses the index for that form.
     *
     * @param array $key
     * @param array $row the last row read
     * @return array the WHERE clause and its values
     */
    private function afterKey(array $key, array $row): array
    {
        $any = [];
        $params = [];
        foreach ($key as $position => $column) {
            $all = [];
            for ($i = 0; $i < $position; $i++) {
                $all[] = $this->quoteNames([$key[$i]]).' = ?';
                $params[] = $row[$key[$i]];
            }
            $all[] = $this->quoteNames([$column]).' > ?';
            $params[] = $row[$column];
            $any[] = '('.implode(' AND ', $all).')';
        }

        return [' WHERE '.implode(' OR ', $any), $params];
    }

    /**
     * @param array $names
     * @return string quoted and comma separated
     */
    private function quoteNames(array $names): string
    {
        return implode(',', array_map(static function ($name) {
            return '`'.str_replace('`', '``', (string) $name).'`';
        }, $names));
    }

    /**
     * JSON can only hold UTF-8, and json_encode() of anything else writes nothing at all.
     *
     * @param array $row
     */
    private function jsonSafeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && !preg_match('//u', $value)) {
                $row[$column] = [self::JSON_BINARY => base64_encode($value)];
            }
        }

        return $row;
    }

    /**
     * @param string $filePath a .sql file, plain or gzip
     * @param array $tableModes
     * @param array $selectedTables
     * @param bool $overwrite
     */
    public function importSql(string $filePath, array $tableModes, array $selectedTables, bool $overwrite = false): array
    {
        $tableModes = $this->sanitizeTableModes($tableModes, $selectedTables);
        $logs = [];
        $stats = [
            'executed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'tables_touched' => []
        ];

        $allowed = [];
        foreach ($selectedTables as $table) {
            $allowed[$table] = true;
        }
        $restrictTables = !empty($allowed);

        $plan = $this->readSqlPlan($filePath, $tableModes, $allowed);

        $toTruncate = [];
        if ($overwrite) {
            foreach ($tableModes as $table => $mode) {
                if (!empty($mode['data']) && (!$restrictTables || isset($allowed[$table]))) {
                    $toTruncate[] = (string) $table;
                    $plan['tables'][(string) $table] = 'replace';
                    $plan['ddl'] = true;
                }
            }
        }

        $this->runRestorable($plan['tables'], $plan['ddl'], function () use ($filePath, $toTruncate, &$logs, &$stats, $allowed, $tableModes, $restrictTables) {
            foreach ($toTruncate as $table) {
                $this->database->raw("TRUNCATE TABLE `{$table}`");
                $logs[] = "Truncated table {$table}";
            }

            $this->iterateSqlStatements($filePath, function ($statement) use (&$logs, &$stats, $allowed, $tableModes, $restrictTables) {
                $meta = $this->parseStatementMeta($statement);
                if ($meta['type'] === 'unknown') {
                    $stats['skipped']++;
                    return;
                }

                if ($meta['table'] !== '' && !$this->isValidPrefixedTable($meta['table'])) {
                    $stats['skipped']++;
                    $logs[] = "Skipped non-prefixed table statement: {$meta['table']}";
                    return;
                }
                if ($restrictTables && $meta['table'] !== '' && !isset($allowed[$meta['table']])) {
                    $stats['skipped']++;
                    return;
                }

                if (!$this->isStatementAllowedByTableModes($meta['type'], $meta['table'], $tableModes, $restrictTables)) {
                    $stats['skipped']++;
                    return;
                }

                $this->database->raw($meta['sql']);
                $this->statementDone();
                $stats['executed']++;
                if ($meta['table'] !== '') {
                    $stats['tables_touched'][$meta['table']] = true;
                }
            });
        });

        $stats['tables_touched'] = array_keys($stats['tables_touched']);

        return [
            'logs' => $logs,
            'stats' => $stats
        ];
    }

    /**
     * @param string $filePath a .json file, plain or gzip
     * @param array $tableModes
     * @param array $selectedTables
     * @param bool $overwrite
     */
    public function importJson(string $filePath, array $tableModes, array $selectedTables, bool $overwrite = false): array
    {
        $tableModes = $this->sanitizeTableModes($tableModes, $selectedTables);
        $decoded = $this->readJsonBackup($filePath);

        $allowed = [];
        foreach ($selectedTables as $table) {
            $allowed[$table] = true;
        }
        $restrictTables = !empty($allowed);

        $logs = [];
        $stats = [
            'executed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'tables_touched' => []
        ];

        $creates = [];
        foreach ($decoded['tables'] as $table => $payload) {
            $table = (string) $table;
            if (empty($tableModes[$table]['structure']) || !is_array($payload) || empty($payload['create'])) {
                continue;
            }
            $meta = $this->parseStatementMeta((string) $payload['create']);
            if ($meta['type'] !== 'structure' || $meta['table'] !== $table || !preg_match('/^CREATE\b/i', $meta['sql'])) {
                throw new \RuntimeException("Import refused: the CREATE TABLE of {$table} is not allowed in a backup", 422);
            }
            $creates[$table] = $meta['sql'];
        }

        $tables = [];
        $hasDdl = false;
        foreach ($decoded['tables'] as $table => $payload) {
            $table = (string) $table;
            if (!isset($tableModes[$table]) || !$this->isValidPrefixedTable($table) || ($restrictTables && !isset($allowed[$table]))) {
                continue;
            }
            $replaces = isset($creates[$table]) || ($overwrite && !empty($tableModes[$table]['data']) && isset($payload['rows']));
            $tables[$table] = $replaces ? 'replace' : 'change';
            $hasDdl = $hasDdl || $replaces;
        }

        $this->runRestorable($tables, $hasDdl, function () use ($decoded, $creates, $overwrite, &$stats, $allowed, $tableModes, $restrictTables) {
            foreach ($decoded['tables'] as $table => $payload) {
                if (!$this->isValidPrefixedTable((string) $table)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($restrictTables && !isset($allowed[$table])) {
                    $stats['skipped']++;
                    continue;
                }
                if (!isset($tableModes[$table])) {
                    $stats['skipped']++;
                    continue;
                }

                $importStructure = !empty($tableModes[$table]['structure']);
                $importData = !empty($tableModes[$table]['data']);

                if ($importStructure && isset($creates[$table])) {
                    $this->database->raw("DROP TABLE IF EXISTS `{$table}`");
                    $this->database->raw($creates[$table]);
                    $stats['executed'] += 2;
                    $stats['tables_touched'][$table] = true;
                }

                if ($importData && isset($payload['rows']) && is_array($payload['rows'])) {
                    if ($overwrite) {
                        $this->database->raw("TRUNCATE TABLE `{$table}`");
                        $stats['executed']++;
                    }
                    foreach ($payload['rows'] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $this->database->raw($this->buildInsertSql($table, $row));
                        $this->statementDone();
                        $stats['executed']++;
                        $stats['tables_touched'][$table] = true;
                    }
                }
            }
        });

        $stats['tables_touched'] = array_keys($stats['tables_touched']);
        return [
            'logs' => $logs,
            'stats' => $stats
        ];
    }

    /**
     * Called every few seconds while an import runs, so the caller can keep its connection alive.
     *
     * @param callable $beat
     */
    public function setHeartbeat(callable $beat): void
    {
        $this->heartbeat = $beat;
        $this->nextBeat = time() + self::HEARTBEAT_SECONDS;
    }

    /**
     * Put back the tables of an import that was killed: a fatal error, an out-of-memory kill, or a
     * time limit that cannot be lifted end the request with no chance to do it then.
     *
     * @return array the tables put back
     */
    public function recoverInterruptedImport(): array
    {
        if (!$this->tableExists($this->stateTable()) || !$this->lockImport()) {
            return [];
        }
        $session = [];
        try {
            $session = $this->sessionSettings();
            $this->prepareSession($session);

            return $this->finishInterruptedImport();
        } finally {
            $this->restoreSession($session);
            $this->unlockImport();
        }
    }

    /**
     * @return array the tables put back
     */
    private function finishInterruptedImport(): array
    {
        if (!$this->tableExists($this->stateTable())) {
            return [];
        }
        $tables = array_column($this->stateRows(), 'tbl');
        $failed = $this->putTablesBack();
        if (!empty($failed)) {
            throw new \RuntimeException('An earlier import did not finish and these tables could not be put back: '.implode(', ', $failed), 409);
        }

        return $tables;
    }

    /**
     * Run an import so that a failure leaves the tables as they were. A transaction alone cannot
     * promise that: DROP, CREATE and TRUNCATE end it, and MyISAM ignores it.
     *
     * @param array $tables the tables it will change: name => 'replace' if it starts by emptying the
     * table, else 'change'
     * @param bool $hasDdl it will run a statement that ends a transaction
     * @param callable $import
     */
    private function runRestorable(array $tables, bool $hasDdl, callable $import): void
    {
        // Neither a time limit nor a browser that gives up may stop it halfway.
        @set_time_limit(0);
        ignore_user_abort(true);
        if (!$this->lockImport()) {
            throw new \RuntimeException('Another import is running on this database. Please wait until it has finished', 409);
        }
        $session = [];
        try {
            $session = $this->sessionSettings();
            $this->prepareSession($session);
            $this->finishInterruptedImport();
            $this->saveTables($tables, $hasDdl);

            $this->database->raw('SET SESSION autocommit = 0');
            // Committing as it goes is much faster than a commit for every statement, which is what
            // follows a DDL otherwise. Only an import that relies on ROLLBACK must not do it.
            $this->uncommitted = $hasDdl ? 0 : -1;
            try {
                $import();
                $this->database->raw('COMMIT');
            } catch (\Throwable $e) {
                $this->failImport($e, array_keys($tables), $session);
            }
            $this->uncommitted = -1;
            // So that marking the import as done takes effect at once.
            $this->database->raw('SET SESSION autocommit = 1');
            $this->dropCopies();
        } finally {
            $this->restoreSession($session);
            $this->unlockImport();
        }
    }

    /**
     * @param \Throwable $error
     * @param array $tables
     * @param array $session
     */
    private function failImport(\Throwable $error, array $tables, array $session): void
    {
        $failed = $tables;
        try {
            $this->database->raw('ROLLBACK');
            // The file may have changed these settings before it failed.
            $this->prepareSession($session);
            $failed = $this->tableExists($this->stateTable()) ? $this->putTablesBack() : [];
        } catch (\Exception $lost) {
            // The connection is gone. Every copy stays until the next visit puts it back.
        }
        throw new \RuntimeException('Import failed: '.$error->getMessage().'. '.(empty($failed)
            ? 'The tables were put back as they were before the import.'
            : 'These tables could not be put back yet: '.implode(', ', $failed).'. Their earlier data is safe, and is put back when this page is next opened.'));
    }

    /**
     * After one statement of the import.
     */
    private function statementDone(): void
    {
        $this->beat();
        if ($this->uncommitted >= 0 && ++$this->uncommitted >= self::COMMIT_STATEMENTS) {
            $this->database->raw('COMMIT');
            $this->uncommitted = 0;
        }
    }

    private function beat(): void
    {
        if ($this->heartbeat !== null && time() >= $this->nextBeat) {
            $this->nextBeat = time() + self::HEARTBEAT_SECONDS;
            ($this->heartbeat)();
        }
    }

    /**
     * @return bool false if another import holds it. The server frees it if the connection dies, so
     * an import that was killed cannot leave it held.
     */
    private function lockImport(): bool
    {
        $result = $this->database->raw('SELECT GET_LOCK(?, 0) AS `locked`', [$this->lockName()]);

        return (int) (((array) $result->fetch())['locked'] ?? 0) === 1;
    }

    private function unlockImport(): void
    {
        try {
            $this->database->raw('SELECT RELEASE_LOCK(?)', [$this->lockName()])->fetch();
        } catch (\Throwable $e) {
            // The lock went with the connection.
        }
    }

    private function lockName(): string
    {
        $result = $this->database->raw('SELECT DATABASE() AS `name`');

        return 'gcms_import_'.md5(((array) $result->fetch())['name'].'|'.$this->prefix);
    }

    /**
     * @return array the session settings an import changes
     */
    private function sessionSettings(): array
    {
        $result = $this->database->raw('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS foreign_key_checks, @@SESSION.unique_checks AS unique_checks, @@SESSION.autocommit AS autocommit, @@SESSION.character_set_client AS charset, @@SESSION.time_zone AS time_zone');

        return (array) $result->fetch();
    }

    /**
     * A restore must accept what the backup holds, so the session runs the way mysqldump does: no
     * strict mode (an ENUM that an earlier non-strict insert left empty), a zero in an
     * AUTO_INCREMENT column stays zero, and the tables may arrive in any order. It also keeps the
     * checks in parseStatementMeta() true whatever the server's own sql_mode is: with
     * NO_BACKSLASH_ESCAPES or ANSI_QUOTES it would end a string where they do not.
     *
     * @param array $session
     */
    private function prepareSession(array $session): void
    {
        $this->database->raw('SET NAMES '.$session['charset']);
        $this->database->raw('SET SESSION autocommit = 1');
        $this->database->raw("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $this->database->raw('SET SESSION foreign_key_checks = 0');
        $this->database->raw('SET SESSION unique_checks = 1');
    }

    /**
     * @param array $session
     */
    private function restoreSession(array $session): void
    {
        if (empty($session)) {
            return;
        }
        try {
            $this->database->raw('SET NAMES '.$session['charset']);
            $this->database->raw('SET SESSION autocommit = '.(int) $session['autocommit']);
            $this->database->raw('SET SESSION sql_mode = ?', [(string) $session['sql_mode']]);
            $this->database->raw('SET SESSION time_zone = ?', [(string) $session['time_zone']]);
            $this->database->raw('SET SESSION foreign_key_checks = '.(int) $session['foreign_key_checks']);
            $this->database->raw('SET SESSION unique_checks = '.(int) $session['unique_checks']);
        } catch (\Throwable $e) {
            // The session ends with the connection that was lost; the import has its own result.
        }
    }

    /**
     * Make sure each table can be put back, the cheapest way that works for it, and record how in the
     * state table first, so a killed import can be undone later:
     *
     * - rollback: only InnoDB tables and no statement that ends the transaction. Nothing to save.
     * - rename: the import starts by emptying the table, so its data moves aside, instantly and with
     *   no extra disk. Not with a foreign key: that would follow the table to its new name.
     * - copy: everything else.
     *
     * @param array $tables name => 'replace' or 'change'
     * @param bool $hasDdl
     */
    private function saveTables(array $tables, bool $hasDdl): void
    {
        $existing = [];
        foreach ($this->siteTables() as $item) {
            $existing[$item['name']] = strtolower((string) $item['engine']);
        }
        foreach (array_keys($tables) as $table) {
            $copy = $this->copyName((string) $table);
            if (isset($existing[$copy])) {
                // No state says how it came to be, so it may be the only good copy of the data.
                throw new \RuntimeException("Import refused: table {$copy} may hold the data from before an earlier import. Check that table and remove it first", 409);
            }
        }

        try {
            foreach ($tables as $table => $use) {
                $table = (string) $table;
                $this->beat();
                if (!isset($existing[$table])) {
                    $this->writeState($table, '', 'new', 1, '');
                } elseif (!$hasDdl && $existing[$table] === 'innodb') {
                    continue;
                } elseif ($use === 'replace' && !$this->hasForeignKeys($table)) {
                    $copy = $this->copyName($table);
                    $this->writeState($table, $copy, 'rename', 1, '');
                    $this->database->raw("RENAME TABLE `{$table}` TO `{$copy}`");
                    $this->database->raw("CREATE TABLE `{$table}` LIKE `{$copy}`");
                } else {
                    $copy = $this->copyName($table);
                    $columns = $this->quoteNames(array_keys($this->storedColumns($table)));
                    $this->writeState($table, $copy, 'copy', 0, $this->getCreateTableSql($table));
                    $this->database->raw("CREATE TABLE `{$copy}` LIKE `{$table}`");
                    $this->database->raw("INSERT INTO `{$copy}` ({$columns}) SELECT {$columns} FROM `{$table}`");
                    $this->database->raw('UPDATE `'.$this->stateTable().'` SET `ready` = 1 WHERE `tbl` = ?', [$table]);
                }
            }
        } catch (\Exception $e) {
            if ($this->tableExists($this->stateTable())) {
                $this->putTablesBack();
            }
            throw new \RuntimeException('Import failed before any table was changed: '.$e->getMessage());
        }
    }

    /**
     * Undo what the state table records.
     *
     * @return array the tables that could not be put back; their copies and state are kept
     */
    private function putTablesBack(): array
    {
        $failed = [];
        foreach ($this->stateRows() as $state) {
            try {
                $this->undoState($state);
                $this->database->raw('DELETE FROM `'.$this->stateTable().'` WHERE `tbl` = ?', [$state['tbl']]);
            } catch (\Exception $e) {
                $failed[] = (string) $state['tbl'];
            }
        }
        if (empty($failed)) {
            $this->database->raw('DROP TABLE IF EXISTS `'.$this->stateTable().'`');
        }

        return $failed;
    }

    /**
     * @param array $state a row of the state table
     */
    private function undoState(array $state): void
    {
        $table = (string) $state['tbl'];
        $copy = (string) $state['copy_name'];
        switch ($state['how']) {
        case 'done':
            // The import had finished, only its copies were still there.
            $this->database->raw("DROP TABLE IF EXISTS `{$copy}`");
            break;
        case 'new':
            $this->database->raw("DROP TABLE IF EXISTS `{$table}`");
            break;
        case 'rename':
            if ($this->tableExists($copy)) {
                $this->database->raw("DROP TABLE IF EXISTS `{$table}`");
                $this->database->raw("RENAME TABLE `{$copy}` TO `{$table}`");
            }
            break;
        case 'copy':
            // Not ready means the import had not started: the table is as it was, the copy is partial.
            if ((int) $state['ready'] === 1 && $this->tableExists($copy)) {
                $columns = $this->quoteNames(array_keys($this->storedColumns($copy)));
                $this->database->raw("DROP TABLE IF EXISTS `{$table}`");
                // Its definition as it was: a copy made with LIKE has no foreign keys.
                $this->database->raw((string) $state['definition']);
                $this->database->raw("INSERT INTO `{$table}` ({$columns}) SELECT {$columns} FROM `{$copy}`");
            }
            $this->database->raw("DROP TABLE IF EXISTS `{$copy}`");
            break;
        }
    }

    /**
     * The import is done. One statement marks every table, so a crash from here on cannot undo some
     * of the tables and leave others.
     */
    private function dropCopies(): void
    {
        if (!$this->tableExists($this->stateTable())) {
            return;
        }
        try {
            $this->database->raw('UPDATE `'.$this->stateTable()."` SET `how` = 'done' WHERE `how` <> 'new'");
            $this->database->raw('DELETE FROM `'.$this->stateTable()."` WHERE `how` = 'new'");
            foreach ($this->stateRows() as $state) {
                $this->database->raw('DROP TABLE IF EXISTS `'.$state['copy_name'].'`');
            }
            $this->database->raw('DROP TABLE IF EXISTS `'.$this->stateTable().'`');
        } catch (\Exception $e) {
            // The import is done. The next visit finishes this.
        }
    }

    private function stateTable(): string
    {
        return $this->getTablePrefixWithUnderscore().self::STATE_TABLE;
    }

    /**
     * @param string $table
     * @param string $copy
     * @param string $how
     * @param int $ready
     * @param string $definition
     */
    private function writeState(string $table, string $copy, string $how, int $ready, string $definition): void
    {
        $this->database->raw('CREATE TABLE IF NOT EXISTS `'.$this->stateTable().'` (`tbl` VARCHAR(64) NOT NULL PRIMARY KEY, `copy_name` VARCHAR(64) NOT NULL, `how` VARCHAR(8) NOT NULL, `ready` TINYINT NOT NULL, `definition` LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->database->raw('INSERT INTO `'.$this->stateTable().'` VALUES (?, ?, ?, ?, ?)', [$table, $copy, $how, $ready, $definition]);
    }

    private function stateRows(): array
    {
        $rows = [];
        $result = $this->database->raw('SELECT `tbl`, `copy_name`, `how`, `ready`, `definition` FROM `'.$this->stateTable().'`');
        while ($result && ($row = $result->fetch())) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /**
     * @param string $table
     */
    private function tableExists(string $table): bool
    {
        $result = $this->database->raw("SHOW TABLES LIKE '".str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table)."'");

        return $result && (bool) $result->fetch();
    }

    /**
     * @param string $table
     */
    private function hasForeignKeys(string $table): bool
    {
        $result = $this->database->raw('SELECT COUNT(*) AS `found` FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND (TABLE_NAME = ? OR REFERENCED_TABLE_NAME = ?)', [$table, $table]);

        return (int) (((array) $result->fetch())['found'] ?? 0) > 0;
    }

    /**
     * @param string $table
     * @return string a name that keeps the site prefix and fits the 64 characters of a table name
     */
    private function copyName(string $table): string
    {
        $room = 64 - strlen(self::COPY_SUFFIX);

        return (strlen($table) <= $room ? $table : substr($table, 0, $room - 9).substr(md5($table), 0, 9)).self::COPY_SUFFIX;
    }

    /**
     * @param string $filePath
     * @return array the decoded backup
     */
    private function readJsonBackup(string $filePath): array
    {
        // Decoding takes several times the size of the text, and running out of memory cannot be caught.
        $limit = (int) (self::iniBytes((string) ini_get('memory_limit')) / 8);
        $source = function_exists('gzopen') ? 'compress.zlib://'.$filePath : $filePath;
        $content = @file_get_contents($source, false, null, 0, $limit === PHP_INT_MAX ? null : $limit + 1);
        if ($content === false) {
            throw new \RuntimeException('Unable to read uploaded file');
        }
        if (strlen($content) > $limit) {
            throw new \RuntimeException('This JSON backup is too large to read within memory_limit. Please use a SQL backup', 422);
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
            throw new \RuntimeException('Invalid JSON backup format');
        }

        return $decoded;
    }

    /**
     * @param string $value a php.ini size such as 128M
     * @return int bytes, PHP_INT_MAX for no limit
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        if ($number <= 0) {
            return PHP_INT_MAX;
        }
        $unit = strtoupper(substr($value, -1));
        $factor = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];

        return $number * ($factor[$unit] ?? 1);
    }

    /**
     * @param string $mode
     * @return mixed
     */
    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['structure', 'data', 'both'], true)) {
            return 'both';
        }

        return $mode;
    }

    /**
     * @param string $table
     */
    private function getCreateTableSql(string $table): string
    {
        $result = $this->database->raw("SHOW CREATE TABLE `{$table}`");
        $row = $result ? $result->fetch() : null;
        if (!$row) {
            return '';
        }
        foreach ((array) $row as $key => $value) {
            if (stripos((string) $key, 'create table') !== false) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param string $table
     * @param array $row
     */
    private function buildInsertSql(string $table, array $row): string
    {
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = '`'.str_replace('`', '``', (string) $column).'`';
            $values[] = $this->escapeSqlValue($value);
        }

        return 'INSERT INTO `'.$table.'` ('.implode(',', $columns).') VALUES ('.implode(',', $values).");\n";
    }

    /**
     * @param $value
     * @return mixed
     */
    private function escapeSqlValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_array($value)) {
            if (!is_string($value[self::JSON_BINARY] ?? null)) {
                throw new \RuntimeException('Invalid JSON backup format');
            }
            $binary = bin2hex((string) base64_decode($value[self::JSON_BINARY]));

            return $binary === '' ? "''" : '0x'.$binary;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace(["\\", "'", "\0", "\n", "\r", "\x1a"], ["\\\\", "\\'", "\\0", "\\n", "\\r", "\\Z"], (string) $value)."'";
    }

    /**
     * Each statement of a backup, plain or gzip. It reads a piece at a time, so neither the size of
     * the file nor the length of a line matters.
     *
     * @param string $filePath
     * @param callable $callback
     */
    private function iterateSqlStatements(string $filePath, callable $callback): void
    {
        $handle = $this->openBackup($filePath);
        $limit = $this->largestStatement();

        $statement = '';
        $inSingle = false;
        $inDouble = false;
        $escaped = false;
        $atLineStart = true;
        $inComment = false;
        $inBlockComment = false;

        try {
            while (($line = $this->readBackup($handle)) !== false) {
                $this->beat();
                $endsLine = substr($line, -1) === "\n";
                if ($inComment) {
                    $inComment = !$endsLine;
                    $atLineStart = $endsLine;
                    continue;
                }
                $i = 0;
                if ($inBlockComment) {
                    $close = strpos($line, '*/');
                    if ($close === false) {
                        continue;
                    }
                    $inBlockComment = false;
                    $i = $close + 2;
                }
                if (strspn($statement, " \t\r\n\0\x0B") === strlen($statement)) {
                    $statement = '';
                    $inSingle = false;
                    $inDouble = false;
                    $escaped = false;
                }
                if ($statement === '' && $atLineStart) {
                    if (trim($line) === '') {
                        continue;
                    }
                    if (preg_match('/^\s*(--|#)/', $line)) {
                        $inComment = !$endsLine;
                        $atLineStart = $endsLine;
                        continue;
                    }
                }

                $length = strlen($line);
                $start = $i;
                while ($i < $length) {
                    if ($escaped) {
                        $escaped = false;
                        $i++;
                        continue;
                    }
                    // A comment before a statement never reaches the server, and its apostrophe is
                    // not the start of a string. A slash-star-bang is not a comment to the server.
                    if ($statement === '' && $i === $start) {
                        $code = $i + strspn($line, " \t\r\n", $i);
                        if (substr($line, $code, 2) === '/*' && !preg_match('/\G\/\*M?!/', $line, $bang, 0, $code)) {
                            $close = strpos($line, '*/', $code + 2);
                            if ($close === false) {
                                $inBlockComment = true;
                                $start = $length;
                                break;
                            }
                            $i = $start = $close + 2;
                            continue;
                        }
                    }
                    // Straight to the next character that matters: most of a dump is plain text.
                    $i += strcspn($line, "\\'\";", $i);
                    if ($i >= $length) {
                        break;
                    }
                    $ch = $line[$i];
                    if ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === "'") {
                        $inSingle = $inDouble ? $inSingle : !$inSingle;
                    } elseif ($ch === '"') {
                        $inDouble = $inSingle ? $inDouble : !$inDouble;
                    } elseif (!$inSingle && !$inDouble) {
                        $statement .= substr($line, $start, $i + 1 - $start);
                        $start = $i + 1;
                        $trimmed = trim($statement);
                        if ($trimmed !== '' && $trimmed !== ';') {
                            $callback($trimmed);
                        }
                        $statement = '';
                        $inSingle = false;
                        $inDouble = false;
                        $escaped = false;
                        // The rest of the line is the next statement, unless it is only a comment.
                        if (preg_match('/\G\s*(?:$|#|--(?:\s|$))/', $line, $rest, 0, $start)) {
                            $start = $length;
                            $inComment = !$endsLine;
                            break;
                        }
                    }
                    $i++;
                }
                if ($start < $length) {
                    $statement .= substr($line, $start);
                }
                if (strlen($statement) > $limit) {
                    throw new \RuntimeException('Import refused: a statement in this file is larger than this server can take ('.ceil($limit / 1048576).' MB)', 422);
                }
                $atLineStart = $endsLine;
            }

            if (trim($statement) !== '') {
                $callback(trim($statement));
            }
        } finally {
            $this->closeBackup($handle);
        }
    }

    /**
     * @param string $filePath
     * @return resource
     */
    private function openBackup(string $filePath)
    {
        // gzopen() also reads a file that is not compressed, as it is.
        $handle = function_exists('gzopen') ? @gzopen($filePath, 'rb') : @fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read SQL backup file');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return string|false up to READ_CHUNK bytes, ending at the end of a line if that comes first
     */
    private function readBackup($handle)
    {
        return function_exists('gzopen') ? gzgets($handle, self::READ_CHUNK) : fgets($handle, self::READ_CHUNK);
    }

    /**
     * @param resource $handle
     */
    private function closeBackup($handle): void
    {
        function_exists('gzopen') ? gzclose($handle) : fclose($handle);
    }

    /**
     * @return int bytes: what the server accepts, and what memory_limit leaves room to check
     */
    private function largestStatement(): int
    {
        $result = $this->database->raw('SELECT @@max_allowed_packet AS `packet`');
        $packet = (int) (((array) $result->fetch())['packet'] ?? 0);

        return min($packet > 0 ? $packet : PHP_INT_MAX, (int) (self::iniBytes((string) ini_get('memory_limit')) / 4));
    }

    /**
     * Read the whole file before anything runs: a statement refused halfway would leave the
     * tables that the statements before it already dropped or emptied.
     *
     * @param string $filePath
     * @param array $tableModes
     * @param array $allowed table name => true, empty for no restriction
     * @return array tables: the tables the file will change, name => 'replace' if it starts by
     * emptying that table, else 'change'. ddl: whether it holds a statement that ends a transaction.
     */
    private function readSqlPlan(string $filePath, array $tableModes, array $allowed): array
    {
        $number = 0;
        $last = '';
        $tables = [];
        $ddl = false;
        $this->iterateSqlStatements($filePath, function ($statement) use (&$number, &$last, &$tables, &$ddl, $tableModes, $allowed) {
            $number++;
            $last = $statement;
            $meta = $this->parseStatementMeta($statement);
            if ($meta['type'] === 'forbidden') {
                throw new \RuntimeException("Import refused: statement {$number} is not allowed in a backup", 422);
            }
            $table = $meta['table'];
            if ($table !== '' && $this->isValidPrefixedTable($table) && (empty($allowed) || isset($allowed[$table]))
                && $this->isStatementAllowedByTableModes($meta['type'], $table, $tableModes, !empty($allowed))) {
                if (!isset($tables[$table])) {
                    $tables[$table] = preg_match('/^(?:DROP|TRUNCATE|DELETE)\b/i', $meta['sql']) ? 'replace' : 'change';
                }
                $ddl = $ddl || $meta['type'] === 'structure';
            }
        });

        // A backup of ours ends with this line. Restoring one that was cut off would lose the rest.
        $handle = $this->openBackup($filePath);
        $first = (string) $this->readBackup($handle);
        $this->closeBackup($handle);
        if (strpos($first, '-- GCMS Database Backup') === 0 && $last !== self::BACKUP_LAST_LINE) {
            throw new \RuntimeException('Import refused: this backup file is incomplete, it was cut off before its last line', 422);
        }

        return ['tables' => $tables, 'ddl' => $ddl];
    }

    /**
     * The file is user input, and on a multi-site install the MySQL user can reach every site. So
     * a statement runs only if it is exactly the shape a dump uses: the verbs below on one table
     * of this site, with nothing that reads or writes anywhere else.
     *
     * type: structure, data, meta (session SET), unknown (not part of a restore, never runs) or
     * forbidden (one of those verbs in any other shape, the whole file is refused).
     *
     * @param string $statement
     * @return array type, table, and sql: the text that was checked, which is the text to run
     */
    private function parseStatementMeta(string $statement): array
    {
        $sql = $this->stripLeadingSqlComments($statement);
        $meta = ['type' => 'unknown', 'table' => '', 'sql' => $sql];

        if (preg_match(self::SESSION_SET, $sql)) {
            $meta['type'] = 'meta';
            return $meta;
        }
        // Any INSERT or REPLACE: skipping one in a shape not known here would lose its rows and still
        // report success, so it is refused below instead.
        if (!preg_match('/^(?:CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE\s+TABLE|INSERT|REPLACE|DELETE\s+FROM)\b/i', $sql)) {
            return $meta;
        }

        $table = $this->tableOfAllowedStatement($sql);
        if ($table === null) {
            $meta['type'] = 'forbidden';
            return $meta;
        }
        $meta['table'] = $table;
        $meta['type'] = preg_match('/^(?:INSERT|REPLACE|DELETE)\b/i', $sql) ? 'data' : 'structure';

        return $meta;
    }

    /**
     * @param string $sql
     * @return string|null the table the statement works on, null if its shape is not allowed
     */
    private function tableOfAllowedStatement(string $sql): ?string
    {
        $skeleton = $this->sqlSkeleton($sql);
        if ($skeleton === null) {
            return null;
        }
        $skeleton = rtrim($skeleton, "; \t\r\n");
        if (strpos($skeleton, ';') !== false) {
            return null;
        }

        // Not `_`, what sqlSkeleton() leaves in place of an unusual quoted name: that hides which table it is.
        $name = '`?((?!_\b)\w+)`?';
        if (preg_match('/^(?:DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|TRUNCATE\s+TABLE\s+|DELETE\s+FROM\s+)'.$name.'$/i', $skeleton, $m)) {
            return $m[1];
        }
        if (preg_match('/^(?:INSERT|REPLACE)(?:\s+(?:IGNORE|LOW_PRIORITY|HIGH_PRIORITY|DELAYED))*\s+INTO\s+'.$name.'\s*(?:\([\s,`\w]*\)\s*)?VALUES?\b/i', $skeleton, $m)) {
            return $this->isOnlyLiterals(substr($skeleton, strlen($m[0]))) ? $m[1] : null;
        }
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$name.'\s*\(/i', $skeleton, $m)) {
            $close = $this->closingParenthesis($skeleton, strlen($m[0]) - 1);
            if ($close === null || !preg_match(self::TABLE_OPTIONS, substr($skeleton, $close + 1))) {
                return null;
            }
            return $this->isDefinitionWithinSite(substr($skeleton, strlen($m[0]))) ? $m[1] : null;
        }
        if (preg_match('/^ALTER\s+TABLE\s+'.$name.'\s/i', $skeleton, $m)) {
            return $this->isDefinitionWithinSite(substr($skeleton, strlen($m[0]))) ? $m[1] : null;
        }

        return null;
    }

    /**
     * The statement with each string emptied and each unusual quoted name replaced, so the checks
     * read its structure and cannot be fooled by what a value holds. Null when it holds a comment
     * (the server runs the inside of a comment like this one's cousin, slash-star-bang), an
     * unterminated quote, or anything but plain ASCII outside its quotes.
     *
     * @param string $sql
     */
    private function sqlSkeleton(string $sql): ?string
    {
        $skeleton = '';
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $plain = strcspn($sql, "'\"`#-/\\", $i);
            $skeleton .= substr($sql, $i, $plain);
            $i += $plain;
            if ($i >= $length) {
                break;
            }
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($ch === '#' || $ch === '\\' || ($ch === '-' && $next === '-') || ($ch === '/' && $next === '*')) {
                return null;
            }
            if ($ch === '-' || $ch === '/') {
                $skeleton .= $ch;
                $i++;
                continue;
            }
            $end = $this->closingQuote($sql, $i);
            if ($end === null) {
                return null;
            }
            if ($ch === '`') {
                $quoted = substr($sql, $i + 1, $end - $i - 1);
                $skeleton .= preg_match('/^\w+$/', $quoted) ? "`{$quoted}`" : '`_`';
            } else {
                $skeleton .= "''";
            }
            $i = $end + 1;
        }

        return preg_match('/[^\x09-\x0d\x20-\x7e]/', $skeleton) ? null : $skeleton;
    }

    /**
     * @param string $sql
     * @param int $start position of the opening quote
     * @return int|null position of the closing quote
     */
    private function closingQuote(string $sql, int $start): ?int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $i = $start + 1;
        while ($i < $length) {
            $i += strcspn($sql, $quote.'\\', $i);
            if ($i >= $length) {
                return null;
            }
            if ($sql[$i] === '\\') {
                // No escapes inside a quoted name.
                $i += $quote === '`' ? 1 : 2;
            } elseif (($sql[$i + 1] ?? '') === $quote) {
                $i += 2;
            } else {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param string $text
     * @param int $open position of the opening parenthesis
     * @return int|null position of the one that closes it
     */
    private function closingParenthesis(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = $open; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * After VALUES, a dump holds only literals. No word is allowed through, so no function and no
     * subquery can be either.
     *
     * @param string $values
     */
    private function isOnlyLiterals(string $values): bool
    {
        $rest = preg_replace('/(?:_(?:binary|utf8|utf8mb3|utf8mb4|latin1|ascii)\s*|[nxb])?\'\'|\b(?:NULL|TRUE|FALSE|DEFAULT)\b|0x[0-9a-f]+|(?<![\w$.])[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?(?![\w$.])/i', '', $values);

        return $rest !== null && trim($rest, " \t\r\n(),") === '';
    }

    /**
     * The column list and options of a CREATE or ALTER TABLE must stay on tables of this site.
     *
     * @param string $definition
     */
    private function isDefinitionWithinSite(string $definition): bool
    {
        // Only a number may hold a dot: a name that has one is in another database.
        $withoutNumbers = preg_replace('/(?<![\w$`.])\d*\.\d+(?:e[-+]?\d+)?(?![\w$`.])/i', '', $definition);
        if ($withoutNumbers === null || strpos($withoutNumbers, '.') !== false) {
            return false;
        }
        // Without quoted names, so that a column called `like` is not read as the keyword.
        $bare = preg_replace('/`[^`]*`/', '``', $definition);
        if ($bare === null || strpos($bare, '@') !== false || preg_match(self::FOREIGN_REACH, $bare)) {
            return false;
        }
        preg_match_all('/\bENGINE\b\s*=?\s*(\w*)/i', $bare, $engines);
        foreach ($engines[1] as $engine) {
            if (!in_array(strtolower($engine), ['innodb', 'myisam', 'aria', 'memory', 'heap'], true)) {
                return false;
            }
        }
        preg_match_all('/\bREFERENCES\s*`?(\w+)`?\s*\(/i', $definition, $references);
        foreach ($references[1] as $table) {
            if (!$this->isValidPrefixedTable($table)) {
                return false;
            }
        }

        return preg_match_all('/\bREFERENCES\b/i', $bare) === count($references[1]);
    }

    /**
     * Only the comments before the statement: a line inside a string that merely looks like a
     * comment is data, and removing it made the checks read something other than what runs. A
     * slash-star-bang is not a comment to the server, so it stays and the statement is not run.
     *
     * @param string $statement
     */
    private function stripLeadingSqlComments(string $statement): string
    {
        return ltrim((string) preg_replace('/^(?:\s*(?:(?:#|--(?=\s))[^\n]*(?:\n|$)|\/\*(?!!|M!)[\s\S]*?\*\/))+/', '', $statement));
    }

    /**
     * @param string $type
     * @param string $table
     * @param array $tableModes
     * @param bool $restrictTables
     * @return mixed
     */
    private function isStatementAllowedByTableModes(string $type, string $table, array $tableModes, bool $restrictTables): bool
    {
        if ($type === 'meta') {
            return true;
        }
        if ($table === '') {
            return false;
        }
        if ($restrictTables && !isset($tableModes[$table])) {
            return false;
        }
        $mode = $tableModes[$table] ?? ['structure' => false, 'data' => false];
        if ($type === 'structure') {
            return !empty($mode['structure']);
        }
        if ($type === 'data') {
            return !empty($mode['data']);
        }

        return false;
    }
}
