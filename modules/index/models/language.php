<?php
/**
 * @filesource modules/index/models/language.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Language;

/**
 * API Language Model
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
     * Get configured language columns.
     *
     * @return array
     */
    public static function getLanguages()
    {
        return \Index\Languages\Model::getLanguageColumns();
    }

    /**
     * Get language by ID
     * $id = 0 return new language (for Register)
     *
     * @param int $id
     * @param string|null $key
     *
     * @return object|null
     */
    public static function get($id, $key = null)
    {
        $languages = self::getLanguages();

        if ($id === 0 && ($key === null || $key === '')) {
            $language = [
                'id' => 0,
                'key' => '',
                'type' => 'text',
                'translate' => [self::createTranslateRow($languages)]
            ];
            return (object) $language;
        } else {
            // Edit
            $byKey = $key !== null && $key !== '';
            $data = null;
            $rows = static::createQuery()
                ->select()
                ->from('language')
                ->where($byKey ? ['key', $key] : ['id', $id])
                ->fetchAll();
            foreach ($rows as $row) {
                // The key column is case-insensitive — ?key=Line must not open the "LINE" row
                if (!$byKey || $row->key === $key) {
                    $data = $row;
                    break;
                }
            }
            if ($data) {
                if ($data->type === 'array') {
                    foreach ($languages as $lng) {
                        $ds = json_decode($data->$lng, true);
                        if (is_array($ds)) {
                            foreach ($ds as $k => $value) {
                                $data->translate[$k]['key'] = $k;
                                $data->translate[$k][$lng] = $value ?? '';
                            }
                        } else {
                            $data->translate[0]['key'] = '';
                            $data->translate[0][$lng] = $data->$lng ?? '';
                        }
                        unset($data->$lng);
                    }
                    // Make sure information is available in all languages.
                    foreach ($data->translate as $k => $values) {
                        foreach ($languages as $lng) {
                            if (!isset($data->translate[$k][$lng])) {
                                $data->translate[$k][$lng] = '';
                            }
                        }
                    }
                    $data->translate = array_values($data->translate);
                } else {
                    $data->translate[0]['key'] = '';
                    foreach ($languages as $lng) {
                        $data->translate[0][$lng] = $data->$lng ?? '';
                        unset($data->$lng);
                    }
                }
            }

            return $data;
        }
    }

    /**
     * The row that already holds a key, regardless of case.
     *
     * "Save", "save" and "SAVE" are one entry when saved. Translation lookups are
     * exact, so a second spelling would be a second key for the same text.
     * Compared in PHP: the SQL comparison follows the column's collation and the
     * driver (case-insensitive on MySQL, case-sensitive on PostgreSQL).
     *
     * @param string $key
     * @param int    $exceptId The row being edited (0 = none)
     *
     * @return object|null id, key
     */
    public static function findKey($key, $exceptId = 0)
    {
        $lower = mb_strtolower($key);
        $rows = static::createQuery()
            ->select('id', 'key')
            ->from('language')
            ->orderBy('id')
            ->fetchAll();
        foreach ($rows as $row) {
            if ((int) $row->id !== (int) $exceptId && mb_strtolower($row->key) === $lower) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Save language data and regenerate language files
     * Return language ID
     *
     * @param \Kotchasan\DB $db Database connection
     * @param int $id Language ID (0 for new language)
     * @param array $save Data to save
     *
     * @return int Language ID
     */
    public static function save($db, $id, $save)
    {
        if ($id === 0) {
            $id = $db->insert('language', $save);
        } else {
            $db->update('language', ['id', $id], $save);
        }

        // Regenerate language files after save
        \Index\Languages\Model::exportToFile();

        return $id;
    }

    /**
     * @return mixed
     */
    public static function getColumns()
    {
        $languages = self::getLanguages();

        $columns = [
            [
                'field' => 'key',
                'label' => 'Key',
                'i18n' => true,
                'cellElement' => 'text'
            ]
        ];

        foreach ($languages as $language) {
            $columns[] = [
                'field' => $language,
                'label' => ucfirst($language),
                'cellElement' => 'textarea',
                'i18n' => true
            ];
        }

        return $columns;
    }

    /**
     * Prepare translate data from language object
     *
     * @param object $language
     *
     * @return array
     */
    public static function prepareTranslateData($language)
    {
        $languages = self::getLanguages();

        if (isset($language->translate) && is_array($language->translate)) {
            return $language->translate;
        }

        $row = self::createTranslateRow($languages, $language->key ?? '');
        foreach ($languages as $lng) {
            $row[$lng] = $language->$lng ?? '';
        }

        return [$row];
    }

    /**
     * Create a blank translate row for the editor table.
     *
     * @param array $languages
     * @param string $key
     *
     * @return array
     */
    private static function createTranslateRow(array $languages, $key = '')
    {
        $row = ['key' => $key];
        foreach ($languages as $language) {
            $row[$language] = '';
        }

        return $row;
    }
}
