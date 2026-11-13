<?php
/**
 * @filesource modules/index/models/category.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Category;

use Kotchasan;

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
        $query = static::createQuery()
            ->select('category_id', 'topic')
            ->from('category')
            ->where(['type', $type]);
        $data = [];
        foreach ($query->fetchAll() as $item) {
            $data[] = [
                'id' => $item->category_id,
                'topic' => $item->topic
            ];
        }
        if (empty($data)) {
            $data[] = [
                'id' => '1',
                'topic' => ''
            ];
        }
        return $data;
    }

    /**
     * Save category data
     *
     * @param string $type
     * @param array $save
     *
     * @return void
     */
    public static function save($type, $save)
    {
        $db = Kotchasan\DB::create();

        $db->delete('category', ['type', $type], 0);

        foreach ($save as $item) {
            $db->insert('category', [
                'type' => $type,
                'category_id' => $item['category_id'],
                'topic' => $item['topic'],
                'language' => ''
            ]);
        }
    }
}
