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
    private Database $database;

    private string $prefix;

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

    public function detectTables(): array
    {
        $tables = [];
        $prefixWithUnderscore = $this->getTablePrefixWithUnderscore();
        try {
            $result = $this->database->raw('SHOW TABLE STATUS');
            $rows = $result ? $result->fetchAll() : [];
            foreach ($rows as $row) {
                $name = (string) ($row->Name ?? '');
                if ($name === '') {
                    continue;
                }
                if ($prefixWithUnderscore !== '' && strpos($name, $prefixWithUnderscore) !== 0) {
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
            $rows = $this->database->raw('SHOW TABLES');
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

        $summary = [
            'tables' => [],
            'rows_exported' => 0
        ];

        fwrite($fp, "-- GCMS Database Backup\n");
        fwrite($fp, "-- Generated at: ".date('Y-m-d H:i:s')."\n");
        fwrite($fp, "SET NAMES utf8mb4;\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tableModes as $table => $mode) {
            if (!$this->isValidPrefixedTable((string) $table)) {
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
                $createStmt = $this->getCreateTableSql((string) $table);
                if ($createStmt !== '') {
                    fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fp, $createStmt.";\n\n");
                    $tableInfo['structure'] = true;
                }
            }

            if ($includeData) {
                $offset = 0;
                while (true) {
                    $sql = "SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}";
                    $result = $this->database->raw($sql);
                    $fetched = 0;
                    while ($result && ($row = $result->fetch())) {
                        $rowArray = (array) $row;
                        fwrite($fp, $this->buildInsertSql((string) $table, $rowArray));
                        $fetched++;
                        $tableInfo['rows']++;
                        $summary['rows_exported']++;
                    }
                    if ($fetched < $batchSize) {
                        break;
                    }
                    $offset += $batchSize;
                }
                fwrite($fp, "\n");
            }

            $summary['tables'][] = $tableInfo;
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

        return $summary;
    }

    /**
     * @param string $filePath
     * @param array $tables
     * @param string $mode
     * @param int $batchSize
     */
    public function exportSql(string $filePath, array $tables, string $mode = 'both', int $batchSize = 500): array
    {
        $mode = $this->normalizeMode($mode);
        $batchSize = max(100, min(5000, $batchSize));
        $fp = fopen($filePath, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to create backup file');
        }

        $summary = [
            'tables' => [],
            'mode' => $mode,
            'rows_exported' => 0
        ];

        fwrite($fp, "-- GCMS Database Backup\n");
        fwrite($fp, "-- Generated at: ".date('Y-m-d H:i:s')."\n");
        fwrite($fp, "SET NAMES utf8mb4;\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            if (!$this->isValidPrefixedTable($table)) {
                continue;
            }

            $tableInfo = [
                'table' => $table,
                'structure' => false,
                'rows' => 0
            ];
            fwrite($fp, "-- --------------------------------------------------------\n");
            fwrite($fp, "-- Table: `{$table}`\n\n");

            if ($mode !== 'data') {
                $createStmt = $this->getCreateTableSql($table);
                if ($createStmt !== '') {
                    fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fp, $createStmt.";\n\n");
                    $tableInfo['structure'] = true;
                }
            }

            if ($mode !== 'structure') {
                $offset = 0;
                while (true) {
                    $sql = "SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}";
                    $result = $this->database->raw($sql);
                    $fetched = 0;
                    while ($result && ($row = $result->fetch())) {
                        $rowArray = (array) $row;
                        fwrite($fp, $this->buildInsertSql($table, $rowArray));
                        $fetched++;
                        $tableInfo['rows']++;
                        $summary['rows_exported']++;
                    }
                    if ($fetched < $batchSize) {
                        break;
                    }
                    $offset += $batchSize;
                }
                fwrite($fp, "\n");
            }

            $summary['tables'][] = $tableInfo;
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

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

        $summary = [
            'tables' => [],
            'mode' => $mode,
            'rows_exported' => 0
        ];

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
                $offset = 0;
                $firstRow = true;
                while (true) {
                    $sql = "SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}";
                    $result = $this->database->raw($sql);
                    $fetched = 0;
                    while ($result && ($row = $result->fetch())) {
                        $rowArray = (array) $row;
                        if (!$firstRow) {
                            fwrite($fp, ',');
                        }
                        fwrite($fp, json_encode($rowArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $firstRow = false;
                        $fetched++;
                        $tableInfo['rows']++;
                        $summary['rows_exported']++;
                    }
                    if ($fetched < $batchSize) {
                        break;
                    }
                    $offset += $batchSize;
                }
                fwrite($fp, ']');
            }

            fwrite($fp, '}');
            $summary['tables'][] = $tableInfo;
        }
        fwrite($fp, '}}');
        fclose($fp);

        return $summary;
    }

    /**
     * @param string $filePath
     * @param string $mode
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

        $importsData = false;
        foreach ($tableModes as $mode) {
            if (!empty($mode['data'])) {
                $importsData = true;
                break;
            }
        }

        $transactionStarted = false;
        if ($importsData) {
            $this->database->beginTransaction();
            $transactionStarted = true;
        }

        try {
            if ($overwrite) {
                foreach ($tableModes as $table => $mode) {
                    if (empty($mode['data']) || ($restrictTables && !isset($allowed[$table]))) {
                        continue;
                    }
                    $this->database->raw("TRUNCATE TABLE `{$table}`");
                    $logs[] = "Truncated table {$table}";
                }
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

                $this->database->raw($statement);
                $stats['executed']++;
                if ($meta['table'] !== '') {
                    $stats['tables_touched'][$meta['table']] = true;
                }
            });

            if ($transactionStarted) {
                $this->database->commit();
            }
        } catch (\Exception $e) {
            if ($transactionStarted) {
                $this->database->rollback();
            }
            $stats['errors']++;
            $logs[] = 'Import failed: '.$e->getMessage();
            throw new \RuntimeException('Import failed: '.$e->getMessage());
        }

        $stats['tables_touched'] = array_keys($stats['tables_touched']);

        return [
            'logs' => $logs,
            'stats' => $stats
        ];
    }

    /**
     * @param string $filePath
     * @param string $mode
     * @param array $selectedTables
     * @param bool $overwrite
     */
    public function importJson(string $filePath, array $tableModes, array $selectedTables, bool $overwrite = false): array
    {
        $tableModes = $this->sanitizeTableModes($tableModes, $selectedTables);
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Unable to read uploaded file');
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
            throw new \RuntimeException('Invalid JSON backup format');
        }

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

        $importsData = false;
        foreach ($tableModes as $mode) {
            if (!empty($mode['data'])) {
                $importsData = true;
                break;
            }
        }

        $transactionStarted = false;
        if ($importsData) {
            $this->database->beginTransaction();
            $transactionStarted = true;
        }

        try {
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

                if ($importStructure && !empty($payload['create'])) {
                    $this->database->raw("DROP TABLE IF EXISTS `{$table}`");
                    $this->database->raw((string) $payload['create']);
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
                        $stats['executed']++;
                        $stats['tables_touched'][$table] = true;
                    }
                }
            }

            if ($transactionStarted) {
                $this->database->commit();
            }
        } catch (\Exception $e) {
            if ($transactionStarted) {
                $this->database->rollback();
            }
            $stats['errors']++;
            $logs[] = 'Import failed: '.$e->getMessage();
            throw new \RuntimeException('Import failed: '.$e->getMessage());
        }

        $stats['tables_touched'] = array_keys($stats['tables_touched']);
        return [
            'logs' => $logs,
            'stats' => $stats
        ];
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
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace(["\\", "'", "\0", "\n", "\r", "\x1a"], ["\\\\", "\\'", "\\0", "\\n", "\\r", "\\Z"], (string) $value)."'";
    }

    /**
     * @param string $filePath
     * @param $callback
     */
    private function iterateSqlStatements(string $filePath, callable $callback): void
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read SQL backup file');
        }

        $statement = '';
        $inSingle = false;
        $inDouble = false;
        $escaped = false;

        while (($line = fgets($handle)) !== false) {
            if (trim($statement) === '') {
                $statement = '';
                $inSingle = false;
                $inDouble = false;
                $escaped = false;
            }
            if (trim($line) === '' && $statement === '') {
                continue;
            }
            if ($statement === '' && preg_match('/^\s*(--|#)/', $line)) {
                continue;
            }

            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $ch = $line[$i];
                $statement .= $ch;

                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === "'" && !$inDouble) {
                    $inSingle = !$inSingle;
                    continue;
                }
                if ($ch === '"' && !$inSingle) {
                    $inDouble = !$inDouble;
                    continue;
                }
                if ($ch === ';' && !$inSingle && !$inDouble) {
                    $trimmed = trim($statement);
                    if ($trimmed !== '' && $trimmed !== ';') {
                        $callback($trimmed);
                    }
                    $statement = '';
                    $inSingle = false;
                    $inDouble = false;
                    $escaped = false;
                    break;
                }
            }
        }

        if (trim($statement) !== '') {
            $callback(trim($statement));
        }

        fclose($handle);
    }

    /**
     * @param string $statement
     * @return mixed
     */
    private function parseStatementMeta(string $statement): array
    {
        $sql = $this->stripLeadingSqlComments($statement);
        $upper = strtoupper($sql);
        $meta = ['type' => 'unknown', 'table' => ''];

        if (strpos($upper, 'CREATE TABLE') === 0) {
            $meta['type'] = 'structure';
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                $meta['table'] = $m[1];
            }
            return $meta;
        }
        if (strpos($upper, 'DROP TABLE') === 0 || strpos($upper, 'ALTER TABLE') === 0 || strpos($upper, 'TRUNCATE TABLE') === 0) {
            $meta['type'] = 'structure';
            if (preg_match('/^(?:DROP|ALTER|TRUNCATE)\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                $meta['table'] = $m[1];
            }
            return $meta;
        }
        if (strpos($upper, 'INSERT INTO') === 0 || strpos($upper, 'REPLACE INTO') === 0 || strpos($upper, 'DELETE FROM') === 0) {
            $meta['type'] = 'data';
            if (preg_match('/^(?:INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                $meta['table'] = $m[1];
            }
            return $meta;
        }
        if (strpos($upper, 'SET ') === 0 || strpos($upper, 'LOCK TABLES') === 0 || strpos($upper, 'UNLOCK TABLES') === 0) {
            $meta['type'] = 'meta';
            return $meta;
        }

        return $meta;
    }

    /**
     * @param string $statement
     */
    private function stripLeadingSqlComments(string $statement): string
    {
        $lines = preg_split('/\R/', $statement) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(--|#)/', $line)) {
                continue;
            }
            $parts[] = $line;
        }

        return ltrim(implode("\n", $parts));
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
