<?php
/**
 * @filesource modules/inventory/models/warehouse.php
 *
 * คลังเก็บของ
 *
 * ⚠️ ยอดคงเหลือ · สมุดบัญชี · ชั้นต้นทุน ทุกแถวผูกกับคลังเสมอ ไม่มีแถวไหน
 * "ไม่มีคลัง" ได้ — ของต้องอยู่ที่ไหนสักที่จริง ๆ ถ้ายอมให้ว่างได้ วันหนึ่งจะมี
 * ของกองหนึ่งที่ไม่มีใครรู้ว่าอยู่คลังไหน แล้วยอดรวมกับยอดรายคลังจะไม่ตรงกัน
 *
 * ไซต์ที่มีคลังเดียวไม่ต้องรู้เรื่องนี้เลย — ตัวติดตั้งสร้าง "คลังหลัก" ให้ และ
 * Posting API เติมคลังให้เองเมื่อผู้เรียกไม่ได้ระบุ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Warehouse;

use Inventory\Base\Model as Base;

/**
 * Model คลังเก็บของ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * id ของคลังตั้งต้น จำไว้ทั้งคำขอ ไม่ต้องถามฐานซ้ำทุกบรรทัดเอกสาร
     *
     * @var int|null
     */
    protected static $defaultId = null;

    /**
     * คลังหนึ่งแห่ง
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function get($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $row = static::createDB()->first(Base::table('inventory_warehouse'), ['id' => $id]);

        return $row ? (array) $row : null;
    }

    /**
     * คลังตั้งต้นของไซต์ — คลังที่ตั้งธงไว้ ถ้าไม่มีก็คลังแรกที่ยังเปิดใช้งาน
     *
     * @return array|null
     */
    public static function getDefault()
    {
        $rows = static::createQuery()
            ->select()
            ->from('inventory_warehouse')
            ->where(['is_active', 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : $rows[0];
    }

    /**
     * id ของคลังตั้งต้น
     *
     * ⚠️ โยนข้อผิดพลาดเมื่อไม่มีคลังเลย ไม่ใช่คืน 0 — การปล่อยให้บันทึกของลง
     * "คลังหมายเลข 0" คือการสร้างกองของที่ไม่มีใครหาเจอ และซ่อมทีหลังยากมาก
     * เพราะแยกไม่ออกว่าแถวไหนตั้งใจและแถวไหนหลุดมา
     *
     * @throws \Exception เมื่อไซต์ไม่มีคลังที่ใช้งานได้เลย
     *
     * @return int
     */
    public static function defaultId()
    {
        if (static::$defaultId === null) {
            $warehouse = self::getDefault();
            if ($warehouse === null) {
                throw new \Exception(
                    'ยังไม่มีคลังเก็บของในระบบ กรุณาเพิ่มคลังอย่างน้อยหนึ่งแห่งที่'
                    .' ตั้งค่า → คลังเก็บของ ก่อนบันทึกการเคลื่อนไหวของสต๊อก'
                );
            }
            static::$defaultId = (int) $warehouse['id'];
        }

        return static::$defaultId;
    }

    /**
     * ล้างค่าที่จำไว้ — ชุดทดสอบและตัวปรับรุ่นเปลี่ยนคลังตั้งต้นระหว่างทางได้
     */
    public static function clearCache()
    {
        static::$defaultId = null;
    }

    /**
     * คลังที่ใช้ได้จริง จากค่าที่ผู้เรียกส่งมา
     *
     * ค่าว่าง / 0 / คลังที่ปิดใช้งานแล้ว → ใช้คลังตั้งต้น
     *
     * @param mixed $warehouseId
     *
     * @return int
     */
    public static function resolve($warehouseId)
    {
        $warehouseId = (int) $warehouseId;
        if ($warehouseId > 0) {
            $row = self::get($warehouseId);
            if ($row !== null) {
                return $warehouseId;
            }
        }

        return self::defaultId();
    }

    /**
     * ตัวเลือกสำหรับกล่องเลือกคลัง
     *
     * @param array $beforeItems รายการที่ต้องการให้อยู่บนสุด
     *
     * @return array
     */
    public static function toOptions(array $beforeItems = [])
    {
        $options = $beforeItems;
        foreach (static::createQuery()
            ->select('id', 'code', 'name')
            ->from('inventory_warehouse')
            ->where(['is_active', 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->execute(null, 'array')
            ->fetchAll() as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'text' => $row['name']
            ];
        }

        return $options;
    }

    /**
     * ไซต์นี้ใช้หลายคลังอยู่หรือไม่ — หน้าจอใช้ตัดสินใจว่าจะโชว์กล่องเลือกคลังไหม
     *
     * ร้านที่มีคลังเดียวไม่ควรต้องเห็นกล่องที่มีตัวเลือกเดียวทุกหน้า
     *
     * @return bool
     */
    public static function isMultiple()
    {
        return static::createDB()->count(Base::table('inventory_warehouse'), ['is_active', 1]) > 1;
    }

    /**
     * Query ของตารางรายการ
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params = [])
    {
        $query = static::createQuery()
            ->select('id', 'code', 'name', 'description', 'phone', 'is_default', 'is_active', 'sort_order')
            ->from('inventory_warehouse');

        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->whereNested(function ($q) use ($needle) {
                $q->where([['code', 'LIKE', $needle]])
                    ->orWhere([['name', 'LIKE', $needle]])
                    ->orWhere([['description', 'LIKE', $needle]]);
            });
        }

        return $query;
    }

    /**
     * บันทึกคลัง
     *
     * @param int   $id 0 = เพิ่มใหม่
     * @param array $data
     *
     * @throws \Exception เมื่อข้อมูลไม่ครบหรือรหัสซ้ำ
     *
     * @return int id ของคลัง
     */
    public static function save($id, array $data)
    {
        $id = (int) $id;
        $db = static::createDB();
        $table = Base::table('inventory_warehouse');

        $code = strtoupper(trim((string) (isset($data['code']) ? $data['code'] : '')));
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($code === '') {
            throw new \Exception('กรุณากรอกรหัสคลัง');
        }
        if ($name === '') {
            throw new \Exception('กรุณากรอกชื่อคลัง');
        }
        $exists = $db->first($table, ['code' => $code]);
        if ($exists && (int) $exists->id !== $id) {
            throw new \Exception('รหัสคลัง '.$code.' ถูกใช้ไปแล้ว');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'code' => $code,
            'name' => $name,
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'address' => isset($data['address']) ? (string) $data['address'] : null,
            'phone' => isset($data['phone']) ? (string) $data['phone'] : null,
            'is_active' => empty($data['is_active']) ? 0 : 1,
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
            'updated_at' => $now
        ];

        if ($id > 0) {
            $db->update($table, ['id', $id], $row);
        } else {
            $row['created_at'] = $now;
            $row['is_default'] = 0;
            $id = (int) $db->insert($table, $row);
        }

        // ⚠️ คลังตั้งต้นต้องมีแห่งเดียวเสมอ และต้องเปิดใช้งานอยู่ — ตั้งธงให้ตัวนี้
        // แล้วปลดของตัวอื่นในคำสั่งเดียวกัน ไม่งั้นเผลอมีสองตัวแล้ว getDefault()
        // จะคืนตัวไหนก็ได้ตามลำดับ id ซึ่งเปลี่ยนไปมาได้
        if (!empty($data['is_default']) && !empty($row['is_active'])) {
            self::setDefault($id);
        }
        self::ensureDefaultExists();
        self::clearCache();

        return $id;
    }

    /**
     * ลบคลัง
     *
     * ⚠️ ลบคลังที่ยังมีของหรือเคยมีรายการไม่ได้ — สมุดบัญชีจะเหลือแถวที่ชี้ไป
     * คลังที่ไม่มีอยู่ ยอดรายคลังกับยอดรวมจะไม่ตรงกันตลอดไป ให้ปิดใช้งานแทน
     *
     * @param int $id
     *
     * @throws \Exception
     *
     * @return bool
     */
    public static function remove($id)
    {
        $id = (int) $id;
        $db = static::createDB();
        $warehouse = self::get($id);
        if ($warehouse === null) {
            return false;
        }
        if ($db->exists(Base::table('inventory_stock_movement'), ['warehouse_id' => $id])) {
            throw new \Exception(
                'คลัง "'.$warehouse['name'].'" เคยมีการเคลื่อนไหวของสต๊อกแล้ว ลบไม่ได้'
                .' — ถ้าเลิกใช้แล้วให้ปิดการใช้งานแทน ประวัติจะได้ยังตรวจย้อนหลังได้'
            );
        }
        if ($db->count(Base::table('inventory_warehouse')) < 2) {
            throw new \Exception('ต้องมีคลังอย่างน้อยหนึ่งแห่งเสมอ ลบคลังสุดท้ายไม่ได้');
        }

        $db->delete(Base::table('inventory_stock'), ['warehouse_id', $id], 0);
        $db->delete(Base::table('inventory_warehouse'), ['id', $id], 1);
        self::ensureDefaultExists();
        self::clearCache();

        return true;
    }

    /**
     * ต้องมีคลังตั้งต้นที่เปิดใช้งานอยู่เสมอ
     *
     * ปิดใช้งานหรือลบคลังที่เป็นตัวตั้งต้นไปแล้ว ต้องมีตัวอื่นรับช่วงทันที
     * ไม่ใช่ปล่อยให้ไซต์ไม่มีคลังตั้งต้นจนบันทึกอะไรไม่ได้
     */
    protected static function ensureDefaultExists()
    {
        $db = static::createDB();
        $table = Base::table('inventory_warehouse');
        if ($db->exists($table, ['is_default' => 1, 'is_active' => 1])) {
            return;
        }
        $rows = static::createQuery()
            ->select('id')
            ->from('inventory_warehouse')
            ->where(['is_active', 1])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(1)
            ->execute(null, 'array')
            ->fetchAll();
        if (!empty($rows)) {
            self::setDefault((int) $rows[0]['id']);
        }
    }

    /**
     * ตั้งคลังหนึ่งแห่งเป็นตั้งต้น และปลดธงของที่เหลือ
     *
     * @param int $id
     */
    protected static function setDefault($id)
    {
        $db = static::createDB();
        $table = Base::table('inventory_warehouse');
        $db->update($table, ['is_default', 1], ['is_default' => 0]);
        $db->update($table, ['id', (int) $id], ['is_default' => 1]);
    }
}
