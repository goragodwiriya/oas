<?php
/**
 * @filesource Kotchasan/Database/QueryBuilder.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan\Database;

use Kotchasan\ArrayTool;

/**
 * SQL Query builder
 *
 * @setup $driver = new PdoMysqlDriver;
 * @setup $this = $driver->createQuery();
 *
 * @see https://www.kotchasan.com/
 */
class QueryBuilder extends \Kotchasan\Database\Query
{
    /**
     * ส่งออกผลลัพท์เป็น Array
     *
     * @var bool
     */
    protected $toArray = false;
    /**
     * ตัวแปรเก็บพารามิเตอร์สำหรับการ bind
     *
     * @var array
     */
    protected $values;

    /**
     * Class constructor
     *
     * @param object $db database driver
     */
    public function __construct(Driver $db)
    {
        $this->db = $db;
        $this->values = [];
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง WHERE ถ้ามีข้อมูล Where ก่อนหน้าจะ AND กับข้อมูลก่อนหน้า
     *
     * @assert where(array('U.id', 1))->andWhere([])->text() [==] " WHERE U.`id` = 1"
     * @assert where(array('U.id', 1))->andWhere(array('U.id', 2))->text() [==] " WHERE (U.`id` = 1) AND (U.`id` = 2)"
     * @assert where(array('U.id', 1))->andWhere(array(Sql::BETWEEN('id', 0, 1), Sql::BETWEEN('id', 0, 1)), 'OR')->text() [==] " WHERE (U.`id` = 1) AND (`id` BETWEEN 0 AND 1 OR `id` BETWEEN 0 AND 1)"
     *
     * @param mixed  $condition query string หรือ array
     * @param string $oprator   defaul AND
     * @param string $id        Primary Key เช่น id (default)
     *
     * @return static
     */
    public function andWhere($condition, $oprator = 'AND', $id = 'id')
    {
        if (!empty($condition)) {
            $ret = $this->buildWhere($condition, $oprator, $id);
            if (is_array($ret)) {
                $this->sqls['where'] = empty($this->sqls['where']) ? $ret[0] : '('.$this->sqls['where'].') AND ('.$ret[0].')';
                $this->values = ArrayTool::replace($this->values, $ret[1]);
            } else {
                $this->sqls['where'] = empty($this->sqls['where']) ? $ret : '('.$this->sqls['where'].') AND ('.$ret.')';
            }
        }
        return $this;
    }

    /**
     * นำเข้า property จาก Class อื่น
     *
     * @param \Kotchasan\Orm\Recordset $src
     *
     * @return static
     */
    public function assignment($src)
    {
        $this->sqls = array(
            'function' => 'customQuery',
            'select' => '*'
        );
        if ($src instanceof \Kotchasan\Orm\Recordset) {
            $this->sqls['from'] = $src->getField()->getTableWithAlias();
        }
        foreach ($src->sqls as $k => $v) {
            $this->sqls[$k] = $v;
        }
        $this->values = $src->getValues();
        return $this;
    }

    /**
     * เปิดการใช้งานแคช
     * จะมีการตรวจสอบจากแคชก่อนการสอบถามข้อมูล
     *
     * @param bool $auto_save (options) true (default) บันทึกผลลัพท์อัตโนมัติ, false ต้องบันทึกแคชเอง
     *
     * @return static
     */
    public function cacheOn($auto_save = true)
    {
        $this->db()->cacheOn($auto_save);
        return $this;
    }

    /**
     * สำเนา Class เป็นอันใหม่
     *
     * @return static
     */
    public function copy()
    {
        return clone $this;
    }

    /**
     * ประมวลผลคำสั่ง SQL และคืนค่าจำนวนแถวของผลลัพท์
     * คืนค่า จำนวนแถว
     *
     * @return int
     */
    public function count()
    {
        if (!isset($this->sqls['select'])) {
            $this->selectCount('* count');
        }
        $result = $this->toArray()->execute();
        return count($result) == 1 ? (int) $result[0]['count'] : 0;
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง DELETE
     *
     * @assert delete('user', array(array('id', 1), array('name', 'test')))->text() [==] "DELETE FROM `user` WHERE `id` = 1 AND `name` = 'test'"
     *
     * @param string $table
     * @param mixed  $condition query string หรือ array
     *
     * @return static
     */
    public function delete($table, $condition = [])
    {
        $this->sqls['function'] = 'query';
        $this->sqls['delete'] = $this->quoteTableName($table);
        $this->where($condition);
        return $this;
    }

    /**
     * ประมวลผลคำสั่ง SQL
     * สำเร็จ คืนค่าแอเรย์ของผลลัพท์
     *
     * @return mixed
     */
    public function execute()
    {
        $result = $this->db->execQuery($this->sqls, $this->values, $this->debugger);
        if ($this->toArray) {
            $this->toArray = false;
        } elseif (is_array($result)) {
            foreach ($result as $i => $items) {
                $result[$i] = (object) $items;
            }
        }
        return $result;
    }

    /**
     * ฟังก์ชั่นสร้าง SQL EXISTS
     *
     * @param string $table     ชื่อตาราง
     * @param mixed  $condition query WHERE
     *
     * @assert select()->from('user U')->exists('useronline', array('member_id', 'U.id'))->text() [==] 'SELECT * FROM `user` AS U WHERE EXISTS (SELECT 1 FROM `useronline` WHERE `member_id` = U.`id`)'
     * @assert select()->from('user U')->where(array('U.id', 1))->exists('useronline', array('member_id', 'U.id'))->text() [==] 'SELECT * FROM `user` AS U WHERE U.`id` = 1 AND EXISTS (SELECT 1 FROM `useronline` WHERE `member_id` = U.`id`)'
     *
     * @return static
     */
    public function exists($table, $condition)
    {
        $ret = $this->buildWhere($condition);
        if (is_array($ret)) {
            $this->values = ArrayTool::replace($this->values, $ret[1]);
            $ret = $ret[0];
        }
        if (!isset($this->sqls['exists'])) {
            $this->sqls['exists'] = [];
        }
        $this->sqls['exists'][] = 'EXISTS (SELECT 1 FROM '.$this->quoteTableName($table).' WHERE '.$ret.')';
        return $this;
    }

    /**
     * คำสั่งสำหรับดูรายละเอียดการ Query
     *
     * @return static
     */
    public function explain()
    {
        $this->sqls['explain'] = true;
        return $this;
    }

    /**
     * คำสั่งสร้าง View
     *
     * @param string $table     ชื่อตาราง
     *
     * @return static
     */
    public function createView($table)
    {
        $this->sqls['view'] = $this->quoteTableName($table);
        return $this;
    }

    /**
     * คำสั่งสร้างตารางชั่วคราว
     *
     * @param string $table     ชื่อตาราง
     *
     * @return static
     */
    public function createTmpTable($table)
    {
        $this->sqls['tmptable'] = $this->quoteTableName($table);
        return $this;
    }

    /**
     * ฟังก์ชั่นประมวลผลคำสั่ง SQL ข้อมูลต้องการผลลัพท์เพียงรายการเดียว
     * คืนค่าผลลัพท์ที่พบเพียงรายการเดียว ไม่พบข้อมูลคืนค่า false
     *
     * @param string $fields (option) รายชื่อฟิลด์ field1, field2, field3, ...
     *
     * @return mixed
     */
    public function first($fields = '*')
    {
        if (func_num_args() > 1) {
            $fields = func_get_args();
        }
        if (!empty($fields)) {
            // ถ้ามีการระบุฟิลด์มา
            call_user_func(array($this, 'select'), $fields);
        }
        if (empty($this->sqls['select'])) {
            // เลือกทุกฟิลด์ ถ้ายังไม่มีฟิลด์ที่ถูกเลือก
            call_user_func(array($this, 'select'), '*');
        }
        $this->sqls['limit'] = 1;
        $result = $this->execute();
        return empty($result) ? false : $result[0];
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง FROM
     *
     * @assert select()->from('user')->text() [==] "SELECT * FROM `user`"
     * @assert select()->from('user a', 'user b')->text() [==] "SELECT * FROM `user` AS `a`, `user` AS `b`"
     *
     * @param string $tables ชื่อตาราง table1, table2, table3, ...
     *
     * @return static
     */
    public function from($tables)
    {
        $qs = [];
        foreach (func_get_args() as $table) {
            $qs[] = $this->quoteTableName($table);
        }
        if (count($qs) > 0) {
            $this->sqls['from'] = implode(', ', $qs);
        }
        return $this;
    }

    /**
     * คืนค่าแอเร์ยเก็บพารามิเตอร์สำหรับการ bind รวมกับ $values
     *
     * @param array $values
     *
     * @return array
     */
    public function getValues($values = [])
    {
        if (empty($values)) {
            return $this->values;
        }
        foreach ($this->values as $key => $value) {
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * GROUP BY
     *
     * @assert select()->from('user')->groupBy('SQL(MONTH(`date`))', 'SQL(YEAR(`date`))')->text() [==] 'SELECT * FROM `user` GROUP BY MONTH(`date`), YEAR(`date`)'
     * @assert select()->from('user')->groupBy('U.id')->text() [==] 'SELECT * FROM `user` GROUP BY U.`id`'
     * @assert select()->from('user')->groupBy(array('id', 'username'))->text() [==] 'SELECT * FROM `user` GROUP BY `id`, `username`'
     *
     * @param string $fields รายชื่อฟิล์ด เช่น field1, field2, ..
     *
     * @return static
     */
    public function groupBy($fields)
    {
        $args = is_array($fields) ? $fields : func_get_args();
        $sqls = [];
        foreach ($args as $item) {
            if ($item instanceof Sql) {
                $sqls[] = $item->text();
            } elseif (preg_match('/^SQL\((.+)\)$/', $item, $match)) {
                // SQL()
                $sqls[] = $match[1];
            } elseif (preg_match('/^(([a-z0-9]+)\.)?([a-z0-9_]+)?$/i', $item, $match)) {
                // column.alias
                $sqls[] = "$match[1]`$match[3]`";
            }
        }
        if (count($sqls) > 0) {
            $this->sqls['group'] = implode(', ', $sqls);
        }
        return $this;
    }

    /**
     * HAVING
     *
     * @param mixed  $condition query string หรือ array
     * @param string $oprator   defaul AND
     *
     * @return static
     */
    public function having($condition, $oprator = 'AND')
    {
        if (!empty($condition)) {
            $ret = $this->buildWhere($condition, $oprator);
            if (is_array($ret)) {
                $this->sqls['having'] = $ret[0];
                $this->values = ArrayTool::replace($this->values, $ret[1]);
            } else {
                $this->sqls['having'] = $ret;
            }
        }
        return $this;
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง INSERT INTO
     * สามารถกำหนดค่า value เป็น query string ได้
     *
     * @assert insert('user', array('id' => 1, 'name' => 'test'))->text() [==] "INSERT INTO `user` (`id`, `name`) VALUES (1, 'test')"
     * @assert insert('user', array('id' => 1, 'name' => 'SQL(SELECT username FROM user WHERE id=1)'))->text() [==] "INSERT INTO `user` (`id`, `name`) VALUES (1, (SELECT username FROM user WHERE id=1))"
     * @assert insert('user', array('id' => 1, 'name' => 'SQL()'))->text() [==] "INSERT INTO `user` (`id`, `name`) VALUES (1, 'SQL()')"
     *
     * @param string $table ชื่อตาราง
     * @param mixed  $datas รูปแบบ array(key1=>value1, key2=>value2)
     * @param array  $fields ระบุคอลัมน์ที่ต้องการ หาก $datas เป็น QueryBuilder
     *
     * @return static
     */
    public function insert($table, $datas, $fields = [])
    {
        $this->sqls['function'] = 'query';
        $this->sqls['insert'] = $this->getFullTableName($table);
        if ($datas instanceof QueryBuilder || $datas instanceof Sql) {
            $this->sqls['keys'] = $fields;
            $this->sqls['select'] = $datas->text();
        } elseif (is_array($datas)) {
            foreach ($datas as $key => $value) {
                if (preg_match('/^SQL\((.+)\)$/', $value, $match)) {
                    // SQL()
                    $this->sqls['keys'][$key] = '('.$match[1].')';
                } else {
                    $this->sqls['keys'][$key] = ':'.$key;
                    $this->values[':'.$key] = $value;
                }
            }
        }
        return $this;
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง INSERT INTO
     * โดยทำการตรวจสอบ KEY ถ้ามีอยู่แล้วจะเป็นการ UPDATE ข้อมูล
     *
     * @assert insertOrUpdate('user', array('id' => 1, 'name' => 'test'))->text() [==] "INSERT INTO `user` (`id`, `name`) VALUES (1, 'test') ON DUPLICATE KEY UPDATE `id`=VALUES(`id`), `name`=VALUES(`name`)"
     *
     * @param string $table ชื่อตาราง
     * @param array  $datas รูปแบบ array(key1=>value1, key2=>value2)
     *
     * @return static
     */
    public function insertOrUpdate($table, $datas)
    {
        $this->insert($table, $datas);
        $this->sqls['orupdate'] = [];
        foreach ($datas as $key => $value) {
            $this->sqls['orupdate'][] = "`$key`=VALUES(`$key`)";
        }
        return $this;
    }

    /**
     * สร้างคำสั่ง JOIN
     *
     * @assert join('user U', 'INNER', 1)->text() [==] " INNER JOIN `user` AS U ON `id` = 1"
     * @assert join('user U', 'INNER', array('U.id', 'A.id'))->text() [==] " INNER JOIN `user` AS U ON U.`id` = A.`id`"
     * @assert join('user U', 'INNER', array('U.id', '=', 'A.id'))->text() [==] " INNER JOIN `user` AS U ON U.`id` = A.`id`"
     * @assert join('user U', 'INNER', array('id', '=', 1))->text() [==] " INNER JOIN `user` AS U ON `id` = 1"
     * @assert join('user U', 'INNER', array(array('U.id', 'A.id'), array('U.id', 'A.id')))->text() [==] " INNER JOIN `user` AS U ON U.`id` = A.`id` AND U.`id` = A.`id`"
     *
     * @param string       $table ชื่อตารางที่ต้องการ join เช่น table alias
     * @param string|array $table ชื่อตารางที่ต้องการ join เช่น table alias หรือ (QueryBuilder, alias)
     * @param string       $type  เข่น INNER OUTER LEFT RIGHT
     * @param mixed        $on    query string หรือ array
     *
     * @return static
     */
    public function join($table, $type, $on)
    {
        $ret = $this->buildJoin($table, $type, $on);
        if (is_array($ret)) {
            $this->sqls['join'][] = $ret[0];
            $this->values = ArrayTool::replace($this->values, $ret[1]);
        } else {
            $this->sqls['join'][] = $ret;
        }
        return $this;
    }

    /**
     * จำกัดผลลัพท์ และกำหนดรายการเริ่มต้น
     *
     * @assert limit(10)->text() [==] " LIMIT 10"
     * @assert limit(10, 1)->text() [==] " LIMIT 1,10"
     *
     * @param int $count จำนวนผลลัท์ที่ต้องการ
     * @param int $start รายการเริ่มต้น
     *
     * @return static
     */
    public function limit($count, $start = 0)
    {
        if (!empty($start)) {
            $this->sqls['start'] = (int) $start;
        }
        if (!empty($count)) {
            $this->sqls['limit'] = (int) $count;
        }
        return $this;
    }

    /**
     * ฟังก์ชั่นสร้าง SQL NOT EXISTS
     *
     * @assert select()->from('user U')->notExists('useronline', array('member_id', 'U.id'))->text() [==] 'SELECT * FROM `user` AS U WHERE NOT EXISTS (SELECT 1 FROM `useronline` WHERE `member_id` = U.`id`)'
     * @assert select()->from('user U')->where(array('U.id', 1))->notExists('useronline', array('member_id', 'U.id'))->text() [==] 'SELECT 1 FROM `user` AS U WHERE U.`id` = 1 AND NOT EXISTS (SELECT * FROM `useronline` WHERE `member_id` = U.`id`)'
     *
     * @param string $table     ชื่อตาราง
     * @param mixed  $condition query WHERE
     * @param string $operator  (optional) เช่น AND หรือ OR
     *
     * @return static
     */
    public function notExists($table, $condition, $operator = 'AND')
    {
        $ret = $this->buildWhere($condition, $operator);
        if (is_array($ret)) {
            $this->values = ArrayTool::replace($this->values, $ret[1]);
            $ret = $ret[0];
        }
        if (!isset($this->sqls['exists'])) {
            $this->sqls['exists'] = [];
        }
        $this->sqls['exists'][] = 'NOT EXISTS (SELECT 1 FROM '.$this->quoteTableName($table).' WHERE '.$ret.')';
        return $this;
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง WHERE ถ้ามีข้อมูล Where ก่อนหน้าจะ OR กับข้อมูลก่อนหน้า
     *
     * @assert where(array('U.id', 1))->orWhere([])->text() [==] " WHERE U.`id` = 1"
     * @assert where(array('U.id', 1))->orWhere(array('U.id', 2))->text() [==] " WHERE (U.`id` = 1) OR (U.`id` = 2)"
     * @assert where(array('U.id', 1))->orWhere(array(Sql::BETWEEN('id', 0, 1), Sql::BETWEEN('id', 0, 1)), 'OR')->text() [==] " WHERE (U.`id` = 1) OR (`id` BETWEEN 0 AND 1 OR `id` BETWEEN 0 AND 1)"
     *
     * @param mixed  $condition query string หรือ array
     * @param string $oprator   defaul AND
     * @param string $id        Primary Key เช่น id (default)
     *
     * @return static
     */
    public function orWhere($condition, $oprator = 'AND', $id = 'id')
    {
        if (!empty($condition)) {
            $ret = $this->buildWhere($condition, $oprator, $id);
            if (is_array($ret)) {
                $this->sqls['where'] = empty($this->sqls['where']) ? $ret[0] : '('.$this->sqls['where'].') OR ('.$ret[0].')';
                $this->values = ArrayTool::replace($this->values, $ret[1]);
            } else {
                $this->sqls['where'] = empty($this->sqls['where']) ? $ret : '('.$this->sqls['where'].') OR ('.$ret.')';
            }
        }
        return $this;
    }

    /**
     * สร้าง query เรียงลำดับ
     *
     * @assert order('id', 'id ASC')->text() [==] " ORDER BY `id`, `id` ASC"
     * @assert order('id ASC')->text() [==] " ORDER BY `id` ASC"
     * @assert order('user.id DESC')->text() [==] " ORDER BY `user`.`id` DESC"
     * @assert order('id ASCD')->text() [==] ""
     *
     * @param mixed $columns array('field ASC','field DESC') หรือ 'field ASC', 'field DESC', ...
     *
     * @return static
     */
    public function order($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $ret = $this->buildOrder($columns);
        if (!empty($ret)) {
            $this->sqls['order'] = $ret;
        }
        return $this;
    }

    /**
     * SELECT `field1`, `field2`, `field3`, ...
     *
     * @assert select('U.id', 'email name', 'module')->text() [==] "SELECT U.`id`,`email` AS `name`,`module`"
     * @assert select('"email" name', '0 id', '0 `ไอดี`')->text() [==] "SELECT 'email' AS `name`,0 AS `id`,0 AS `ไอดี`"
     * @assert select("'email' name", '0 AS id', '0 AS ไอดี')->text() [==] "SELECT 'email' AS `name`,0 AS `id`,0 AS `ไอดี`"
     * @assert select()->text()  [==] "SELECT *"
     * @assert select()->where(array('domain', Sql::strValue('kotchasan.com')))->text() [==] "SELECT * WHERE `domain` = 'kotchasan.com'"
     * @assert select('name `ชื่อ นามสกุล`', 'U.`idcard` AS `เลขประชาชน`')->text() [==] "SELECT `name` AS `ชื่อ นามสกุล`,U.`idcard` AS `เลขประชาชน`"
     * @assert select('table.field', '`table`.`field`')->text() [==] "SELECT `table`.`field`,`table`.`field`"
     * @assert select('table.field field', '`table`.`field` `field`')->text() [==] "SELECT `table`.`field` AS `field`,`table`.`field` AS `field`"
     * @assert select('table.field AS field', '`table`.`field` AS `field`')->text() [==] "SELECT `table`.`field` AS `field`,`table`.`field` AS `field`"
     * @assert select('U.field', 'U1.`field`', 'NULL id')->text() [==] "SELECT U.`field`,U1.`field`,NULL AS `id`"
     * @assert select('U.field field', 'U1.`field` `field`')->text() [==] "SELECT U.`field` AS `field`,U1.`field` AS `field`"
     * @assert select('U.field AS field', 'U1.`field` AS `field`')->text() [==] "SELECT U.`field` AS `field`,U1.`field` AS `field`"
     * @assert select(Sql::YEAR('create_date', 'year'), Sql::MONTH('create_date', 'month'))->text() [==] "SELECT YEAR(`create_date`) AS `year`,MONTH(`create_date`) AS `month`"
     * @assert select(array(Sql::YEAR('create_date', 'year'), Sql::MONTH('create_date', 'month')))->text() [==] "SELECT YEAR(`create_date`) AS `year`,MONTH(`create_date`) AS `month`"
     *
     * @param string $fields (option) รายชื่อฟิลด์ field1, field2, field3, ...
     *
     * @return static
     */
    public function select($fields = '*')
    {
        $qs = [];
        if ($fields == '*') {
            $qs[] = '*';
        } else {
            foreach (func_get_args() as $item) {
                if (!empty($item)) {
                    $qs[] = $this->buildSelect($item);
                }
            }
        }
        if (count($qs) > 0) {
            $this->sqls['function'] = 'customQuery';
            $this->sqls['select'] = implode(',', $qs);
        }
        return $this;
    }

    /**
     * สร้าง query สำหรับการนับจำนวน record
     *
     * @assert selectCount()->from('user')->text() [==] "SELECT COUNT(*) AS `count` FROM `user`"
     * @assert selectCount('id ids')->from('user')->text() [==] "SELECT COUNT(`id`) AS `ids` FROM `user`"
     * @assert selectCount('id ids', 'field alias')->from('user')->text() [==] "SELECT COUNT(`id`) AS `ids`, COUNT(`field`) AS `alias` FROM `user`"
     *
     * @param mixed $fileds (option) 'field alias'
     *
     * @return static
     */
    public function selectCount($fileds = '* count')
    {
        $args = func_num_args() == 0 ? array($fileds) : func_get_args();
        $sqls = [];
        foreach ($args as $item) {
            if (preg_match('/^([a-z0-9_\*]+)([\s]+([a-z0-9_]+))?$/', trim($item), $match)) {
                $sqls[] = 'COUNT('.($match[1] == '*' ? '*' : '`'.$match[1].'`').')'.(isset($match[3]) ? ' AS `'.$match[3].'`' : '');
            }
        }
        if (count($sqls) > 0) {
            $this->sqls['function'] = 'customQuery';
            $this->sqls['select'] = implode(', ', $sqls);
        }
        return $this;
    }

    /**
     * SELECT DISTINCT `field1`, `field2`, `field3`, ...
     *
     * @assert selectDistinct('id')->from('user')->text() [==] "SELECT DISTINCT `id` FROM `user`"
     *
     * @param string $fields (option) รายชื่อฟิลด์ field1, field2, field3, ...
     *
     * @return static
     */
    public function selectDistinct($fields = '*')
    {
        call_user_func(array($this, 'select'), func_get_args());
        $this->sqls['select'] = 'DISTINCT '.$this->sqls['select'];
        return $this;
    }

    /**
     * UPDATE ..... SET
     *
     * @assert update('user')->set(array('key1' => 'value1', 'key2' => 2))->where(1)->text() [==] "UPDATE `user` SET `key1`=:Skey1, `key2`=:Skey2 WHERE `id` = 1"
     * @assert update('user U')->set(array('U.key1' => 'value1', 'U.key2' => 2))->where(array('U.id', 1))->text() [==] "UPDATE `user` AS U SET U.`key1`=:SUkey1, U.`key2`=:SUkey2 WHERE U.`id` = 1"
     * @assert update('user')->set(array('key1' => '(...)'))->text() [==] "UPDATE `user` SET `key1`=(...)"
     * @assert update('user')->set(array('key1' => 'test (...)'))->text() [==] "UPDATE `user` SET `key1`=:Skey1"
     * @assert update('user')->set('`reply`=`reply`+1')->text() [==] "UPDATE `user` SET `reply`=`reply`+1"
     * @assert update('user')->set(array('id' => 1, '`reply`=`reply`+1'))->text() [==] "UPDATE `user` SET `id`=:Sid, `reply`=`reply`+1"
     * @assert update('user')->set(array('create_date' => Sql::NOW()))->text() [==] "UPDATE `user` SET `create_date`=NOW()"
     * @assert update('user')->set(array('create_date' => Sql::create('SELECT * FROM `a`')))->text() [==] "UPDATE `user` SET `create_date`=SELECT * FROM `a`"
     * @assert update('user')->set(array('create_date' => 'U.id'))->text() [==] "UPDATE `user` SET `create_date`=U.`id`"
     * @assert update('user')->set(array('create_date' => '111.11'))->text() [==] "UPDATE `user` SET `create_date`=:Screatedate"
     * @assert update('user')->set(array('create_date' => 'user.user'))->text() [==] "UPDATE `user` SET `create_date`=:Screatedate"
     *
     * @param array|string $datas รูปแบบ array(key1 => value1, query_string) หรือ query_string
     *
     * @return static
     */
    public function set($datas)
    {
        if (is_array($datas) || is_object($datas)) {
            foreach ($datas as $key => $value) {
                if (is_int($key)) {
                    $this->sqls['set'][$value] = $value;
                } else {
                    $field = $this->fieldName($key);
                    $key = $this->aliasName($key, 'S');
                    if ($value instanceof QueryBuilder) {
                        $this->sqls['set'][$key] = $field.'=('.$value->text().')';
                    } elseif ($value instanceof Sql) {
                        $this->sqls['set'][$key] = $field.'='.$value->text();
                    } elseif (is_string($value)) {
                        if (preg_match('/^([A-Z][0-9]{0,2})\.`?([A-Za-z0-9_]+)`?$/', $value, $match)) {
                            $this->sqls['set'][$key] = $field.'='.$match[1].'.`'.$match[2].'`';
                        } elseif (mb_strlen($value) > 2 && $value[0] === '(' && $value[mb_strlen($value) - 1] === ')') {
                            $this->sqls['set'][$key] = $field.'='.$value;
                        } else {
                            $this->sqls['set'][$key] = $field.'='.$key;
                            $this->sqls['values'][$key] = $value;
                        }
                    } else {
                        $this->sqls['set'][$key] = $field.'='.$key;
                        $this->sqls['values'][$key] = $value;
                    }
                }
            }
        } else {
            $this->sqls['set'][$datas] = $datas;
        }
        return $this;
    }

    /**
     * คืนค่าข้อมูลเป็น Array
     * ฟังก์ชั่นนี้ใช้เรียกก่อนการสอบถามข้อมูล
     *
     * @return static
     */
    public function toArray()
    {
        $this->toArray = true;
        return $this;
    }

    /**
     * UNION
     *
     * @assert (Sql::create('SELECT * FROM `a`'), Sql::create('SELECT * FROM `b`'))->text() [==] "(SELECT * FROM `a`) UNION (SELECT * FROM `b`)"
     * @assert (array(Sql::create('SELECT * FROM `a`'), Sql::create('SELECT * FROM `b`')))->text() [==] "(SELECT * FROM `a`) UNION (SELECT * FROM `b`)"
     *
     * @param array $querys แอเรย์ของ QueryBuilder หรือ Query String ที่จะนำม่า UNION
     *
     * @return static
     */
    public function union($querys)
    {
        $this->sqls['union'] = [];
        $querys = is_array($querys) ? $querys : func_get_args();
        foreach ($querys as $item) {
            if ($item instanceof QueryBuilder || $item instanceof Sql) {
                $this->sqls['union'][] = $item->text();
            } elseif (is_string($item)) {
                $this->sqls['union'][] = $item;
            } else {
                throw new \InvalidArgumentException('Invalid arguments in union');
            }
        }
        $this->sqls['function'] = 'customQuery';
        return $this;
    }

    /**
     * UNION ALL
     *
     * @assert (Sql::create('SELECT * FROM `a`'), Sql::create('SELECT * FROM `b`'))->text() [==] "(SELECT * FROM `a`) UNION ALL (SELECT * FROM `b`)"
     * @assert (array(Sql::create('SELECT * FROM `a`'), Sql::create('SELECT * FROM `b`')))->text() [==] "(SELECT * FROM `a`) UNION ALL (SELECT * FROM `b`)"
     *
     * @param array $querys แอเรย์ของ QueryBuilder หรือ Query String ที่จะนำม่า UNION ALL
     *
     * @return static
     */
    public function unionAll($querys)
    {
        $this->sqls['unionAll'] = [];
        $querys = is_array($querys) ? $querys : func_get_args();
        foreach ($querys as $item) {
            if ($item instanceof QueryBuilder || $item instanceof Sql) {
                $this->sqls['unionAll'][] = $item->text();
            } elseif (is_string($item)) {
                $this->sqls['unionAll'][] = $item;
            } else {
                throw new \InvalidArgumentException('Invalid arguments in unionAll');
            }
        }
        $this->sqls['function'] = 'customQuery';
        return $this;
    }

    /**
     * UPDATE
     *
     * @assert update('user')->set(array('key1'=>'value1', 'key2'=>2))->where(array(array('id', 1), array('id', 1)))->text() [==] "UPDATE `user` SET `key1`=:Skey1, `key2`=:Skey2 WHERE `id` = 1 AND `id` = 1"
     *
     * @param string $table [$table1, $table2, ....] ชื่อตาราง
     *
     * @return static
     */
    public function update($table)
    {
        $this->sqls['function'] = 'query';
        $updates = [];
        foreach (func_get_args() as $tbl) {
            $updates[] = $this->quoteTableName($tbl);
        }
        $this->sqls['update'] = implode(',', $updates);
        return $this;
    }

    /**
     * ล้างตาราง
     *
     * @param string $table ชื่อตาราง
     *
     * @return static
     */
    public function emptyTable($table)
    {
        $this->db->emptyTable($this->quoteTableName($table));
        return $this;
    }

    /**
     * ฟังก์ชั่นสร้างคำสั่ง WHERE
     *
     * @assert where([])->text() [==] ""
     * @assert where(1)->text() [==] " WHERE `id` = 1"
     * @assert where(array('id', 1))->text() [==] " WHERE `id` = 1"
     * @assert where(array('id', '1'))->text() [==] " WHERE `id` = '1'"
     * @assert where(array('domain', 'domain.tld'))->text() [==] " WHERE `domain` = 'domain.tld'"
     * @assert where(array(1, 1))->text() [==] " WHERE 1 = 1"
     * @assert where(array('U.id', 'G.id'))->text() [==] " WHERE U.`id` = G.`id`"
     * @assert where(array('date', '2016-1-1 30:30'))->text() [==] " WHERE `date` = '2016-1-1 30:30'"
     * @assert where(array('id', '=', 1))->text() [==] " WHERE `id` = 1"
     * @assert where(Sql::create('`id`=1 OR (SELECT ....)'))->text() [==] " WHERE `id`=1 OR (SELECT ....)"
     * @assert where(array('id', '=', 1))->text() [==] " WHERE `id` = 1"
     * @assert where(array('id', 'IN', array(1, 2, '3')))->text() [==] " WHERE `id` IN (1, 2, '3')"
     * @assert where(array(array('social', '0'), Sql::create('(...)')))->text() [==] " WHERE `social` = '0' AND (...)"
     * @assert where(array(array(Sql::MONTH('create_date'), 1), array(Sql::YEAR('create_date'), 1)))->text() [==] " WHERE MONTH(`create_date`) = 1 AND YEAR(`create_date`) = 1"
     * @assert where(array(array('id', array(1, 'a')), array('id', array('G.id', 'G.`id2`'))))->text() [==] " WHERE `id` IN (1, 'a') AND `id` IN (G.`id`, G.`id2`)"
     * @assert where(array(array('id', array(1, 'a')), array('id', array('', 'th'))))->text() [==] " WHERE `id` IN (1, 'a') AND `id` IN ('', 'th')"
     * @assert where(array('ip', 'NOT IN', array('', '192.168.1.104')))->text() [==] " WHERE `ip` NOT IN ('', '192.168.1.104')"
     * @assert where(array('U.id', '(SELECT CASE END)'))->text() [==] " WHERE U.`id` = '(SELECT CASE END)'"
     * @assert where(array(array(Sql::YEAR('create_date'), Sql::YEAR('S.`create_date`'))))->text() [==] " WHERE YEAR(`create_date`) = YEAR(S.`create_date`)"
     * @assert where(array('U.id', Sql::strValue('G.id')))->text() [==] " WHERE U.`id` = 'G.id'"
     * @assert where(Sql::ISNULL('U.id'))->text() [==] " WHERE U.`id` IS NULL"
     * @assert where(array(array('create_date', 'A'), Sql::BETWEEN('id', 'ทดสอบ', 'ทดสอบ')))->text() [==] " WHERE `create_date` = 'A' AND `id` BETWEEN 'ทดสอบ' AND 'ทดสอบ'"
     * @assert where(array(array(Sql::BETWEEN('id', 0, 1), 'OR', Sql::BETWEEN('id', 0, 1)), array(Sql::BETWEEN('id', 0, 1), 'OR', Sql::BETWEEN('id', 0, 1))), 'OR')->text() [==] " WHERE `id` BETWEEN 0 AND 1 OR `id` BETWEEN 0 AND 1 OR `id` BETWEEN 0 AND 1 OR `id` BETWEEN 0 AND 1"
     * @assert where(array(array('username', NULL), array('username', '=', NULL), array('username', '!=', NULL)))->text() [==] " WHERE `username` IS NULL AND `username` IS NULL AND `username` IS NOT NULL"
     *
     * @param mixed  $condition query string หรือ array
     * @param string $oprator   defaul AND
     * @param string $id        Primary Key เช่น id (default)
     *
     * @return static
     */
    public function where($condition, $oprator = 'AND', $id = 'id')
    {
        if (!empty($condition)) {
            $sql = Sql::WHERE($condition, $oprator, $id);
            $this->sqls['where'] = $sql->text();
            $this->values = $sql->getValues($this->values);
        }
        return $this;
    }
}
