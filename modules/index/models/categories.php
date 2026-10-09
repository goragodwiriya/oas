<?php
/**
 * @filesource modules/index/models/categories.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Categories;

use Kotchasan;
use Kotchasan\Language;

/**
 * API Category Model
 *
 * Handles category table operations
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Get all category for editing
     *
     * @param string $type
     *
     * @return array
     */
    public static function get($type)
    {
        $languages = \Index\Languages\Model::getLanguageColumns();
        $query = static::createQuery()
            ->select('category_id', 'topic', 'language')
            ->from('category')
            ->where(['type', $type]);
        $data = [];
        foreach ($query->fetchAll() as $item) {
            if (!isset($data[$item->category_id])) {
                // แถวแรกที่เจอเติมทุกภาษาไว้ก่อน หมวดหมู่ที่บันทึกแบบไม่ระบุภาษา
                // (language = '') จึงยังแสดงครบทุกช่อง
                $data[$item->category_id]['id'] = $item->category_id;
                foreach ($languages as $lng) {
                    $data[$item->category_id][$lng] = $item->topic;
                }
            }
            // แถวของภาษาไหนต้องได้ช่องของภาษานั้น — เดิมทุกช่องได้ข้อความของแถวแรก
            // หน้าแก้หมวดหมู่หลายภาษาจึงแสดงภาษาเดียวซ้ำทุกช่อง แล้วกดบันทึกทีเดียว
            // คำแปลภาษาอื่นถูกเขียนทับหายหมด
            if (in_array($item->language, $languages, true)) {
                $data[$item->category_id][$item->language] = $item->topic;
            }
        }
        if (empty($data)) {
            $data[0] = ['id' => 1];
            foreach ($languages as $lng) {
                $data[0][$lng] = '';
            }
        }
        return array_values($data);
    }

    /**
     * Save category data
     *
     * @param string $type
     * @param array $save
     * @param bool $multiLanguage
     *
     * @return void
     */
    public static function save($type, $save, $multiLanguage)
    {
        $db = Kotchasan\DB::create();

        $db->delete('category', ['type', $type], 0);

        foreach ($save as $item) {
            if (!$multiLanguage) {
                $item['language'] = '';
            }
            $db->insert('category', $item);
        }
    }

    /**
     * Get columns for table
     *
     * @param bool $multiLanguage
     *
     * @return array
     */
    public static function getColumns($multiLanguage)
    {
        $columns = [
            [
                'field' => 'id',
                'label' => 'ID',
                'cellElement' => 'text',
                'size' => 5
            ]
        ];

        if ($multiLanguage) {
            foreach (\Index\Languages\Model::getLanguageColumns() as $language) {
                $columns[] = [
                    'field' => $language,
                    'label' => ucfirst($language),
                    'cellElement' => 'text',
                    'size' => 20
                ];
            }
        } else {
            $language = Language::name();
            $columns[] = [
                'field' => $language,
                'label' => ucfirst($language),
                'cellElement' => 'text',
                'size' => 20
            ];
        }

        return $columns;
    }
}
