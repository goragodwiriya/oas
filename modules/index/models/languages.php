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
        $query = self::createQuery()
            ->from('language');

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
        if (empty($ids)) {
            return 0;
        }
        $deleted = self::createDB()->delete('language', ['id', $ids], 0);

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
        $translations = self::readTranslationFiles(ROOT_PATH.'language/', 'json');

        if (empty($translations)) {
            return [
                'success' => false,
                'message' => 'No valid JSON translation files'
            ];
        }

        return self::processTranslations($translations, array_keys($translations));
    }

    /**
     * Import translations from PHP files to database.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function importFromPhp()
    {
        $translations = self::readTranslationFiles(ROOT_PATH.'language/', 'php');

        if (empty($translations)) {
            return [
                'success' => false,
                'message' => 'No valid PHP translation files'
            ];
        }

        return self::processTranslations($translations, array_keys($translations));
    }

    /**
     * Import translations from both JSON and PHP files to database.
     *
     * The export step writes both file formats, so importing from one of them
     * alone silently deletes every translation that exists only in the other:
     * it never reaches the database, and the export then overwrites the file
     * that held it. Both sides must come in before anything goes back out.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function importFromFiles()
    {
        $basePath = ROOT_PATH.'language/';
        $json = self::readTranslationFiles($basePath, 'json');
        $php = self::readTranslationFiles($basePath, 'php');

        $languages = array_values(array_unique(array_merge(array_keys($json), array_keys($php))));
        if (empty($languages)) {
            return [
                'success' => false,
                'message' => 'No translation files found'
            ];
        }

        $allTranslations = [];
        foreach ($languages as $lang) {
            $allTranslations[$lang] = self::mergeTranslations(
                $basePath,
                $lang,
                isset($json[$lang]) ? $json[$lang] : [],
                isset($php[$lang]) ? $php[$lang] : []
            );
        }

        return self::processTranslations($allTranslations, $languages);
    }

    /**
     * Read every language file of one format.
     *
     * @param string $basePath  Language directory
     * @param string $extension json or php
     *
     * @return array [lang => [key => value]] languages with no readable file are skipped
     */
    private static function readTranslationFiles($basePath, $extension)
    {
        $result = [];
        foreach (self::scanLanguageFiles($basePath, $extension) as $lang) {
            $file = $basePath.$lang.'.'.$extension;
            if ($extension === 'json') {
                $data = json_decode(file_get_contents($file), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
            } else {
                // include (not include_once) — the file is already loaded by
                // Kotchasan\Language and must still return its array here
                $data = @include $file;
            }
            if (is_array($data) && !empty($data)) {
                $result[$lang] = $data;
            }
        }

        return $result;
    }

    /**
     * Merge the JSON and PHP dictionaries of one language.
     *
     * The file edited last wins on keys that carry different values in both,
     * which is what "whoever touched it last meant it" comes down to in
     * practice. Keys held by only one file always survive, and an untranslated
     * entry never overwrites a translated one.
     *
     * @param string $basePath Language directory
     * @param string $lang     Language code
     * @param array  $json     Translations read from <lang>.json
     * @param array  $php      Translations read from <lang>.php
     *
     * @return array
     */
    private static function mergeTranslations($basePath, $lang, array $json, array $php)
    {
        $json = self::translatedOnly($json);
        $php = self::translatedOnly($php);

        $jsonFile = $basePath.$lang.'.json';
        $phpFile = $basePath.$lang.'.php';
        $jsonTime = is_file($jsonFile) ? filemtime($jsonFile) : 0;
        $phpTime = is_file($phpFile) ? filemtime($phpFile) : 0;

        // The + operator keeps the left operand's value on colliding keys
        return $phpTime >= $jsonTime ? $php + $json : $json + $php;
    }

    /**
     * Drop entries that hold no translation at all.
     *
     * Only null, '' and [] count as empty — 0 and false are legitimate values
     * (YEAR_OFFSET is 0 in English) and must not be filtered out.
     *
     * @param array $items
     *
     * @return array
     */
    private static function translatedOnly(array $items)
    {
        return array_filter($items, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
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
        $jsonResult = self::importFromFiles();

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
     * Get language columns from the language table schema.
     *
     * @return array
     */
    public static function getLanguageColumns()
    {
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

        $db = self::createDB();

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
        $db = self::createDB();
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
        $db = self::createDB();

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
        $db = self::createDB();
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
            if ($decoded !== null) {
                return $decoded;
            }

            // แถวที่ย้ายมาจาก Gcms รุ่นเก่าเก็บ array ด้วย serialize() ไม่ใช่ JSON
            // ถ้าไม่แปลงกลับ ค่าจะถูกส่งออกเป็นสตริงดิบลง .json/.php แล้วโค้ดที่
            // foreach ผลของ Language::get() จะพัง
            $unserialized = self::unserializeLegacyArray($value);
            if ($unserialized !== null) {
                return $unserialized;
            }

            return $value;
        }
        if ($type === 'int') {
            return is_numeric($value) ? (int) $value : $value;
        }
        return $value;
    }

    /**
     * แปลงค่า array ที่เก็บด้วย serialize() ของระบบเดิมกลับเป็น array
     * คืนค่า null ถ้าไม่ใช่รูปแบบนั้น หรือแปลงไม่สำเร็จ
     *
     * @param string $value
     *
     * @return array|null
     */
    private static function unserializeLegacyArray($value)
    {
        if (!is_string($value) || !preg_match('/^a:\d+:\{/', $value)) {
            return null;
        }

        // ปิด object ทั้งหมด ยอมรับเฉพาะ scalar กับ array เท่านั้น
        $restored = @unserialize($value, ['allowed_classes' => false]);

        return is_array($restored) ? $restored : null;
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
                        $keyPart = "'".self::escapeSingleQuoted($k)."' => ";
                    }

                    if (is_int($v) || is_float($v)) {
                        $arrayItems[] = $keyPart.$v;
                    } else {
                        $arrayItems[] = $keyPart."'".self::escapeSingleQuoted((string) $v)."'";
                    }
                }
                $lines[] = "'".self::escapeSingleQuoted($key)."' => array(\n    ".implode(",\n    ", $arrayItems)."\n  )";
            } elseif (is_int($value)) {
                $lines[] = "'".self::escapeSingleQuoted($key)."' => ".$value;
            } else {
                $lines[] = "'".self::escapeSingleQuoted($key)."' => '".self::escapeSingleQuoted($value)."'";
            }
        }

        return "<?php\n/* language/{$lang}.php */\nreturn array(\n  ".implode(",\n  ", $lines)."\n);\n";
    }

    /**
     * Escape a value for a single-quoted PHP string literal.
     *
     * addslashes() also escapes double quotes, which are literal inside single
     * quotes — that turned keys such as `Target directory "%s" is not writable`
     * into `Target directory \"%s\" is not writable` and made the PHP file drift
     * away from the JSON file on every export.
     *
     * @param string $value
     *
     * @return string
     */
    private static function escapeSingleQuoted($value)
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);
    }
}
