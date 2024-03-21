<?php
/**
 * @filesource Gcms/Category.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

use Kotchasan\Database\Sql;

/**
 * คลาสสำหรับอ่านข้อมูลหมวดหมู่
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Category
{
    /**
     * ชื่อตารางหมวดหมู่
     *
     * @var string
     */
    protected $table = 'category';
    /**
     * @var array
     */
    private $datas = [];
    /**
     * @var array
     */
    protected $categories = [];

    /**
     * อ่านรายชื่อประเภทหมวดหมู่ที่สามารถใช้งานได้
     *
     * @return array
     */
    public function typies()
    {
        return empty($this->categories) ? [] : array_keys($this->categories);
    }

    /**
     * คืนค่าประเภทหมวดหมู่
     *
     * @return array
     */
    public function items()
    {
        return $this->categories;
    }

    /**
     * คืนค่าชื่อหมวดหมู่
     * ไม่พบคืนค่าว่าง
     *
     * @param string $type
     *
     * @return string
     */
    public function name($type)
    {
        return isset($this->categories[$type]) ? $this->categories[$type] : '';
    }

    /**
     * อ่านรายชื่อหมวดหมู่จากฐานข้อมูลตามภาษาปัจจุบัน
     * สำหรับการแสดงผล
     *
     * @param bool $sortById true เรียงลำดับตาม category_id, false เรียงลำดับตาม topic
     * @param bool $all true คืนค่าทั้งหมด, false คืนค่าเฉพาะรายการที่เผยแพร่
     * @param bool $cache true มีการแคชข้อมูล, false ไม่แคช
     *
     * @return static
     */
    public static function init($sortById = true, $all = true, $cache = true)
    {
        // create object
        $obj = new static;
        $typies = $obj->typies();
        if (!empty($typies)) {
            $where = array(
                array('type', $typies)
            );
            if (!$all) {
                $where[] = array('published', 1);
            }
            // Query
            $query = \Kotchasan\Model::createQuery()
                ->select('category_id', 'topic', 'type')
                ->from($obj->table)
                ->where($where)
                ->order($sortById ? 'category_id' : 'topic');
            if ($cache) {
                $query->cacheOn();
            }
            foreach ($query->execute() as $item) {
                $obj->datas[$item->type][$item->category_id] = $item->topic;
            }
        }
        return $obj;
    }

    /**
     * ลิสต์รายการหมวดหมู่
     * สำหรับใส่ลงใน select
     *
     * @param string $type
     * @param bool $by_id
     *
     * @return array
     */
    public function toSelect($type, $by_id = true)
    {
        if (empty($this->datas[$type])) {
            return [];
        } elseif ($by_id) {
            return $this->datas[$type];
        } else {
            $result = [];
            foreach ($this->datas[$type] as $key => $value) {
                $result[$value] = $value;
            }
            return $result;
        }
    }

    /**
     * คืนค่า true ถ้าไม่มีข้อมูลใน $type ที่เลือก
     *
     * @param string $type
     *
     * @return bool
     */
    public function isEmpty($type)
    {
        return empty($this->datas[$type]);
    }

    /**
     * อ่านหมวดหมู่จาก $category_id
     * ไม่พบ คืนค่าว่าง
     *
     * @param string $type
     * @param string|int $category_id
     * @param string $default
     *
     * @return string
     */
    public function get($type, $category_id, $default = '')
    {
        return empty($this->datas[$type][$category_id]) ? $default : $this->datas[$type][$category_id];
    }

    /**
     * คืนค่าคีย์รายการแรกสุด
     * ไม่พบคืนค่า NULL
     *
     * @param string $type
     *
     * @return int|null
     */
    public function getFirstKey($type)
    {
        if (isset($this->datas[$type])) {
            reset($this->datas[$type]);
            return key($this->datas[$type]);
        }
        return null;
    }

    /**
     * ตรวจสอบ $category_id ว่ามีหรือไม่
     * คืนค่า true ถ้ามี ไม่มีคืนค่า false
     *
     * @param string $type
     * @param string|int $category_id
     *
     * @return bool
     */
    public function exists($type, $category_id)
    {
        return isset($this->datas[$type][$category_id]);
    }

    /**
     * ฟังก์ชั่นอ่านหมวดหมู่ หรือ บันทึก ถ้าไม่มีหมวดหมู่
     * คืนค่า category_id
     *
     * @param string $type
     * @param string $topic
     *
     * @return int
     */
    public static function save($type, $topic)
    {
        $topic = trim($topic);
        if ($topic == '') {
            return 0;
        } else {
            $obj = new static;
            // Model
            $model = new \Kotchasan\Model;
            // Database
            $db = $model->db();
            // table
            $table = $model->getTableName($obj->table);
            // ตรวจสอบรายการที่มีอยู่แล้ว
            $search = $db->first($table, array(
                array('type', $type),
                array('topic', $topic)
            ));
            if ($search) {
                // มีหมวดหมู่อยู่แล้ว
                return $search->category_id;
            } else {
                // ไม่มีหมวดหมู่ ตรวจสอบ category_id ใหม่
                $search = $model->createQuery()
                    ->from($obj->table)
                    ->where(array('type', $type))
                    ->first(Sql::create('MAX(CAST(`category_id` AS INT)) AS `category_id`'));
                $category_id = empty($search->category_id) ? 1 : (1 + (int) $search->category_id);
                // save
                $db->insert($table, array(
                    'type' => $type,
                    'category_id' => $category_id,
                    'topic' => $topic
                ));
                return $category_id;
            }
        }
    }
}
