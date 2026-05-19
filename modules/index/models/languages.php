<?php
/**
 * @filesource modules/index/models/languages.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Languages;

/**
 * Language Model
 *
 * Handles language table operations
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * @var bool
     */
    private static $storageSynchronized = false;

    /**
     * @var array|null
     */
    private static $languageColumns = null;

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        self::synchronizeStorage();

        $query = static::createQuery()->from('language');

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $where = [
                ['key', 'LIKE', $search],
                ['th', 'LIKE', $search],
                ['en', 'LIKE', $search]
            ];

            $query->where($where, 'OR');
        }

        return $query;
    }

    /**
     * Delete translations and regenerate language files
     * Return number of deleted translations
     *
     * @param int|array $ids Translation ID or array of translation IDs
     *
     * @return int
     */
    public static function remove($ids)
    {
        self::synchronizeStorage();

        if (empty($ids)) {
            return 0;
        }
        $deleted = \Kotchasan\DB::create()->delete('language', ['id', $ids], 0);

        // Regenerate language files after deletion
        if ($deleted > 0) {
            self::exportToFile();
        }

        return $deleted;
    }

    /**
     * Import translations from JSON files to database.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function importFromJson()
    {
        self::synchronizeStorage();

        $basePath = ROOT_PATH.'language/';

        // Scan for all language files (2-letter codes)
        $languages = self::scanLanguageFiles($basePath, 'json');

        if (empty($languages)) {
            return [
                'success' => false,
                'message' => 'No JSON translation files found'
            ];
        }

        // Read all language translations
        $allTranslations = [];
        foreach ($languages as $lang) {
            $file = $basePath.$lang.'.json';
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }
            $allTranslations[$lang] = $data;
        }

        if (empty($allTranslations)) {
            return [
                'success' => false,
                'message' => 'No valid JSON translation files'
            ];
        }

        return self::processTranslations($allTranslations, $languages);
    }

    /**
     * Scan directory for language files (2-letter language codes)
     *
     * @param string $path      Directory path
     * @param string $extension File extension (json or php)
     *
     * @return array List of language codes found
     */
    private static function scanLanguageFiles($path, $extension)
    {
        $languages = [];

        if (!is_dir($path)) {
            return $languages;
        }

        $files = glob($path.'*.'.$extension);
        foreach ($files as $file) {
            $filename = basename($file, '.'.$extension);
            // Only 2-letter language codes
            if (preg_match('/^[a-z]{2}$/', $filename)) {
                $languages[] = $filename;
            }
        }

        return $languages;
    }

    /**
     * Import translations from JSON files to database and regenerate outputs.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function importFromFile()
    {
        $jsonResult = self::importFromJson();

        if (!$jsonResult['success']) {
            return $jsonResult;
        }

        $exportResult = self::exportToFile();

        return [
            'success' => $exportResult['success'],
            'message' => 'Import: '.$jsonResult['message'].' | Export: '.$exportResult['message']
        ];
    }

    /**
     * Merge legacy split rows and drop the deprecated js column.
     *
     * @return void
     */
    public static function synchronizeStorage()
    {
        if (self::$storageSynchronized) {
            return;
        }
        self::$storageSynchronized = true;

        $db = \Kotchasan\DB::create();
        $records = $db->select('language', [], ['orderBy' => 'id']);

        if (!empty($records)) {
            $languageColumns = self::detectLanguageColumns();
            $grouped = [];

            foreach ($records as $record) {
                $grouped[$record->key][] = $record;
            }

            foreach ($grouped as $items) {
                if (count($items) < 2) {
                    continue;
                }

                $canonical = self::selectCanonicalRecord($items, $languageColumns);
                $merged = self::mergeRecordData($items, $languageColumns);

                $db->update('language', ['id', $canonical->id], $merged);

                $deleteIds = [];
                foreach ($items as $item) {
                    if ($item->id != $canonical->id) {
                        $deleteIds[] = $item->id;
                    }
                }
                if (!empty($deleteIds)) {
                    $db->delete('language', ['id', $deleteIds], 0);
                }
            }
        }
    }

    /**
     * Get language columns from the language table schema.
     *
     * @return array
     */
    public static function getLanguageColumns()
    {
        self::synchronizeStorage();

        return self::detectLanguageColumns();
    }

    /**
     * Ensure language column exists in database
     *
     * @param string $lang Language code (2 letters)
     *
     * @return bool
     */
    private static function ensureLanguageColumn($lang)
    {
        if (in_array($lang, ['th', 'en'], true)) {
            return true;
        }

        $db = \Kotchasan\DB::create();

        try {
            if (!$db->fieldExists('language', $lang)) {
                $tableName = $db->getTableName('language');
                $db->raw("ALTER TABLE `{$tableName}` ADD COLUMN `{$lang}` TEXT NULL AFTER `en`");
                self::$languageColumns = null;
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Process translations and save to database
     *
     * @param array $allTranslations All language translations [lang => [key => value]]
     * @param array $languages       List of language codes
     * @return array ['success' => bool, 'message' => string]
     */
    private static function processTranslations($allTranslations, $languages)
    {
        $db = \Kotchasan\DB::create();
        $insertCount = 0;
        $updateCount = 0;
        $columnsAdded = [];
        $existingColumns = self::detectLanguageColumns();

        foreach ($languages as $lang) {
            $hadColumn = in_array($lang, $existingColumns, true);
            if (self::ensureLanguageColumn($lang)) {
                if (!$hadColumn) {
                    $columnsAdded[] = $lang;
                    $existingColumns[] = $lang;
                }
            }
        }

        // Collect all keys from all languages
        $allKeys = [];
        foreach ($allTranslations as $lang => $translations) {
            foreach (array_keys($translations) as $key) {
                $allKeys[$key] = true;
            }
        }

        foreach (array_keys($allKeys) as $key) {
            // Get values for all languages
            $langValues = [];
            $type = 'text';

            foreach ($languages as $lang) {
                $value = $allTranslations[$lang][$key] ?? null;

                // Detect type from first non-null value
                if ($value !== null && $type === 'text') {
                    $type = self::detectType($value);
                }

                // Prepare value for storage
                if ($lang === 'en') {
                    // Store empty if en value equals key or is missing
                    if ($value === null || $value === $key) {
                        $langValues[$lang] = '';
                    } else {
                        $langValues[$lang] = self::prepareValue($value);
                    }
                } else {
                    $langValues[$lang] = $value !== null ? self::prepareValue($value) : '';
                }
            }

            $existing = $db->first('language', ['key', $key]);

            if ($existing) {
                $updateData = ['type' => $type];
                foreach ($langValues as $lang => $val) {
                    $updateData[$lang] = $val;
                }
                $db->update('language', ['id', $existing->id], $updateData);
                $updateCount++;
            } else {
                $insertData = [
                    'key' => $key,
                    'type' => $type
                ];
                foreach ($langValues as $lang => $val) {
                    $insertData[$lang] = $val;
                }
                $db->insert('language', $insertData);
                $insertCount++;
            }
        }

        $message = sprintf('Imported %d new, Updated %d existing', $insertCount, $updateCount);
        if (!empty($columnsAdded)) {
            $message .= ' (Added columns: '.implode(', ', $columnsAdded).')';
        }

        return [
            'success' => true,
            'message' => $message
        ];
    }

    /**
     * Detect value type (text, int, array)
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function detectType($value)
    {
        if (is_array($value)) {
            return 'array';
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return 'int';
        }
        return 'text';
    }

    /**
     * Prepare value for storage (convert array to JSON)
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function prepareValue($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    /**
     * Export translations from database to JSON files.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function exportToJson()
    {
        self::synchronizeStorage();

        $basePath = ROOT_PATH.'language/';

        // Ensure directory exists
        if (!is_dir($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Cannot create translations directory'
                ];
            }
        }

        return self::exportTranslations($basePath, 'json');
    }

    /**
     * Export translations from database to PHP files.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function exportToPhp()
    {
        self::synchronizeStorage();

        $basePath = ROOT_PATH.'language/';

        // Ensure directory exists
        if (!is_dir($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Cannot create language directory'
                ];
            }
        }

        return self::exportTranslations($basePath, 'php');
    }

    /**
     * Export translations from database to both JSON and PHP files
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function exportToFile()
    {
        $jsonResult = self::exportToJson();
        $phpResult = self::exportToPhp();

        return [
            'success' => $jsonResult['success'] && $phpResult['success'],
            'message' => implode(' | ', ['JSON: '.$jsonResult['message'], 'PHP: '.$phpResult['message']])
        ];
    }

    /**
     * Export translations from database to files
     *
     * @param string $basePath  Base directory path
     * @param string $extension File extension (json or php)
     * @return array ['success' => bool, 'message' => string]
     */
    private static function exportTranslations($basePath, $extension)
    {
        $db = \Kotchasan\DB::create();

        $records = $db->select('language', [], ['orderBy' => 'key']);

        if (empty($records)) {
            $clearedCount = self::clearExportArtifacts($basePath, $extension);

            return [
                'success' => true,
                'message' => sprintf('No translations found in database (cleared %d stale file(s))', $clearedCount)
            ];
        }

        // Detect available language columns
        $languageColumns = self::getLanguageColumns();

        if (empty($languageColumns)) {
            return [
                'success' => false,
                'message' => 'No language columns found'
            ];
        }

        // Always rebuild from latest DB state by clearing old generated files first.
        self::clearExportArtifacts($basePath, $extension);

        // Group translations by language
        $translations = [];
        foreach ($languageColumns as $lang) {
            $translations[$lang] = [];
        }

        foreach ($records as $record) {
            $key = $record->key;
            $type = $record->type ?? 'text';

            foreach ($languageColumns as $lang) {
                $value = $record->$lang ?? '';

                // Skip empty values for non-en languages
                if ($value === '') {
                    continue;
                }

                // Convert from stored format based on type
                $translations[$lang][$key] = self::restoreValue($value, $type);
            }
        }

        // Write files
        $filesWritten = [];
        $errors = [];

        foreach ($translations as $lang => $data) {
            if (empty($data)) {
                continue;
            }

            $file = $basePath.$lang.'.'.$extension;

            if ($extension === 'json') {
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                // PHP format
                $content = self::generatePhpContent($lang, $data);
            }

            if (file_put_contents($file, $content) !== false) {
                $filesWritten[] = $lang.'.'.$extension;

                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            } else {
                $errors[] = $lang.'.'.$extension;
            }
        }

        if (!empty($errors)) {
            return [
                'success' => !empty($filesWritten),
                'message' => sprintf('Written: %d, Failed: %d (%s)',
                    count($filesWritten),
                    count($errors),
                    implode(', ', $errors))
            ];
        }

        return [
            'success' => true,
            'message' => sprintf('Exported %d files (%s)',
                count($filesWritten),
                implode(', ', $filesWritten))
        ];
    }

    /**
     * Remove all generated language artifacts before rebuilding from DB.
     *
     * @param string $basePath
     * @param string $extension
     *
     * @return int Number of deleted files
     */
    private static function clearExportArtifacts($basePath, $extension)
    {
        $deleted = 0;

        foreach (glob($basePath.'*.'.$extension) ?: [] as $file) {
            $language = basename($file, '.'.$extension);
            if (!preg_match('/^[a-z]{2}$/', $language)) {
                continue;
            }

            if (@unlink($file)) {
                ++$deleted;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            }
        }

        return $deleted;
    }

    /**
     * Get available language columns from database
     *
     * @return array List of language column names
     */
    private static function detectLanguageColumns()
    {
        if (self::$languageColumns !== null) {
            return self::$languageColumns;
        }

        $defaultColumns = ['th', 'en'];
        $languageColumns = [];

        foreach (self::getTableColumns() as $column) {
            if (!in_array($column, ['id', 'js'], true) && preg_match('/^[a-z]{2}$/', $column)) {
                $languageColumns[] = $column;
            }
        }

        if (empty($languageColumns)) {
            $languageColumns = $defaultColumns;
        }

        self::$languageColumns = array_values(array_unique(array_merge($defaultColumns, $languageColumns)));

        return self::$languageColumns;
    }

    /**
     * Read language table column names from the database schema.
     *
     * @return array
     */
    private static function getTableColumns()
    {
        $db = \Kotchasan\DB::create();
        $tableName = $db->getTableName('language');
        $result = $db->raw("SHOW COLUMNS FROM `{$tableName}`");

        if ($result === null) {
            return [];
        }

        $columns = [];
        foreach ($result->fetchAll() as $row) {
            $field = is_object($row) ? ($row->Field ?? $row->field ?? null) : ($row['Field'] ?? $row['field'] ?? null);
            if (is_string($field) && $field !== '') {
                $columns[] = $field;
            }
        }

        return $columns;
    }

    /**
     * Remove stale locale artifacts that are no longer produced by the current export.
     *
     * @param string $basePath
     * @param string $extension
     * @param array  $exportedLanguages
     *
     * @return void
     */
    private static function cleanupExportArtifacts($basePath, $extension, array $exportedLanguages)
    {
        foreach (glob($basePath.'*.'.$extension) ?: [] as $file) {
            $language = basename($file, '.'.$extension);
            if (!preg_match('/^[a-z]{2}$/', $language) || in_array($language, $exportedLanguages, true)) {
                continue;
            }

            if (@unlink($file) && function_exists('opcache_invalidate')) {
                opcache_invalidate($file);
            }
        }
    }

    /**
     * Restore value from stored format
     *
     * @param string $value Stored value
     * @param string $type  Value type (text, int, array)
     *
     * @return mixed Restored value
     */
    private static function restoreValue($value, $type)
    {
        if ($type === 'array') {
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        }
        if ($type === 'int') {
            return is_numeric($value) ? (int) $value : $value;
        }
        return $value;
    }

    /**
     * Generate PHP file content
     *
     * @param string $lang Language code
     * @param array  $data Translation data
     *
     * @return string PHP file content
     */
    private static function generatePhpContent($lang, $data)
    {
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Format array
                $arrayItems = [];
                foreach ($value as $k => $v) {
                    if (is_int($k)) {
                        $keyPart = $k.' => ';
                    } else {
                        $keyPart = "'".addslashes($k)."' => ";
                    }

                    if (is_string($v)) {
                        $arrayItems[] = $keyPart."'".addslashes($v)."'";
                    } elseif (is_int($v) || is_float($v)) {
                        $arrayItems[] = $keyPart.$v;
                    } else {
                        $arrayItems[] = $keyPart."'".addslashes((string) $v)."'";
                    }
                }
                $lines[] = "'".addslashes($key)."' => array(\n    ".implode(",\n    ", $arrayItems)."\n  )";
            } elseif (is_int($value)) {
                $lines[] = "'".addslashes($key)."' => ".$value;
            } else {
                $lines[] = "'".addslashes($key)."' => '".addslashes($value)."'";
            }
        }

        return "<?php\n/* language/{$lang}.php */\nreturn array(\n  ".implode(",\n  ", $lines)."\n);\n";
    }

    /**
     * @param array $records
     * @param array $languageColumns
     *
     * @return object
     */
    private static function selectCanonicalRecord(array $records, array $languageColumns)
    {
        usort($records, function ($a, $b) use ($languageColumns) {
            $scoreA = self::recordScore($a, $languageColumns);
            $scoreB = self::recordScore($b, $languageColumns);
            if ($scoreA === $scoreB) {
                return $a->id <=> $b->id;
            }
            return $scoreB <=> $scoreA;
        });

        return $records[0];
    }

    /**
     * @param array $records
     * @param array $languageColumns
     *
     * @return array
     */
    private static function mergeRecordData(array $records, array $languageColumns)
    {
        $canonical = self::selectCanonicalRecord($records, $languageColumns);

        usort($records, function ($a, $b) use ($languageColumns, $canonical) {
            if ($a->id == $canonical->id) {
                return -1;
            }
            if ($b->id == $canonical->id) {
                return 1;
            }

            $scoreA = self::recordScore($a, $languageColumns);
            $scoreB = self::recordScore($b, $languageColumns);
            if ($scoreA === $scoreB) {
                return $a->id <=> $b->id;
            }
            return $scoreB <=> $scoreA;
        });

        $data = [
            'type' => self::resolveMergedType($records)
        ];

        foreach ($languageColumns as $lang) {
            $data[$lang] = '';
            foreach ($records as $record) {
                $value = $record->$lang ?? '';
                if ($value !== null && $value !== '') {
                    $data[$lang] = $value;
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * @param object $record
     * @param array  $languageColumns
     *
     * @return int
     */
    private static function recordScore($record, array $languageColumns)
    {
        $filled = 0;
        foreach ($languageColumns as $lang) {
            $value = $record->$lang ?? '';
            if ($value !== null && $value !== '') {
                ++$filled;
            }
        }

        return ($filled * 10) + self::typePriority($record->type ?? 'text');
    }

    /**
     * @param array $records
     *
     * @return string
     */
    private static function resolveMergedType(array $records)
    {
        $type = 'text';
        foreach ($records as $record) {
            if (self::typePriority($record->type ?? 'text') > self::typePriority($type)) {
                $type = $record->type;
            }
        }

        return $type;
    }

    /**
     * @param string $type
     *
     * @return int
     */
    private static function typePriority($type)
    {
        switch ($type) {
        case 'array':
            return 3;
        case 'int':
            return 2;
        default:
            return 1;
        }
    }
}
