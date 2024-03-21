<?php
/**
 * @filesource db.php
 *
 * @copyright 2018 Goragod.com
 * @license https://somtum.kotchasan.com/license/
 *
 * @see https://somtum.kotchasan.com/
 */

/*
 * PDO MySql Database Class (CRUD)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */

class Db
{
    /**
     * @var mixed
     */
    private $connection;
    /**
     * @var mixed
     */
    private $error;

    /**
     * create database connection
     *
     * @param array $db_config
     *
     * @return bool
     */
    public function __construct($db_config)
    {
        $dbdriver = empty($db_config['dbdriver']) ? 'mysql' : $db_config['dbdriver'];
        $hostname = empty($db_config['hostname']) ? 'localhost' : $db_config['hostname'];
        $port = empty($db_config['port']) ? 3306 : $db_config['port'];
        // pdo options
        $options = array(
            \PDO::ATTR_PERSISTENT => 1,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        );
        if ($dbdriver == 'mysql') {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8';
            $options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = 1;
        }
        // connection string
        $sql = $dbdriver.':host='.$hostname.';port='.$port.';dbname='.$db_config['dbname'];
        // connect to database
        $this->connection = new \PDO($sql, $db_config['username'], $db_config['password'], $options);
        $this->connection->query("SET SESSION sql_mode = ''");
    }

    /**
     * ตรวจสอบฐานข้อมูล
     * คืนค่า true ถ้ามีอยู่แล้ว
     *
     * @param string $database_name ชื่อฐานข้อมูล
     *
     * @return bool
     */
    public function databaseExists($database_name)
    {
        $result = $this->customQuery("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$database_name';");
        return empty($result) ? false : true;
    }

    /**
     * ตรวจสอบตาราง
     * คืนค่า true ถ้ามีอยู่แล้ว
     *
     * @param string $table_name ชื่อตาราง
     *
     * @return bool
     */
    public function tableExists($table_name)
    {
        $result = $this->customQuery("SHOW TABLES LIKE '$table_name'");
        return empty($result) ? false : true;
    }

    /**
     * ตรวจสอบฟิลด์
     * คืนค่า true ถ้ามีอยู่แล้ว
     *
     * @param string $table_name ชื่อตาราง
     * @param string $field ชื่อฟิลด์
     *
     * @return bool
     */
    public function fieldExists($table_name, $field)
    {
        $result = $this->customQuery("SHOW COLUMNS FROM `$table_name` LIKE '$field'");
        return empty($result) ? false : true;
    }

    /**
     * ตรวจสอบ index ซ้ำ
     * คืนค่า true ถ้ามีอยู่แล้ว
     *
     * @param string $table_name ชื่อตาราง
     * @param string $index ชื่อ Index
     *
     * @return bool
     */
    public function indexExists($table_name, $index)
    {
        $result = $this->customQuery("SELECT index_name FROM INFORMATION_SCHEMA.STATISTICS WHERE table_name='$table_name' AND index_name='$index'");
        return empty($result) ? false : true;
    }

    /**
     * ตรวจสอบชนิดคอลัมน์ และ ฟิลด์
     * คืนค่า true ถ้าเป็นชนิดที่ต้องการ
     *
     * @param string $table_name ชื่อตาราง
     * @param string $field ชื่อฟิลด์
     * @param string $type ชนิดของฟิลด์ เช่น VARCHAR FLOAT INT DATE
     *
     * @return bool
     */
    public function isColumnType($table_name, $field, $type)
    {
        $result = $this->customQuery("SHOW FIELDS FROM `$table_name` WHERE `Field`='$field' AND `Type` LIKE '$type%'");
        return empty($result) ? false : true;
    }

    /**
     * ค้นหาข้อมูลที่กำหนดเองเพียงรายการเดียว
     * พบคืนค่ารายการที่พบเพียงรายการเดียว ไม่พบหรือมีข้อผิดพลาดคืนค่า false
     *
     * @param string $table      ชื่อตาราง
     * @param array  $conditions ข้อความที่ต้องการค้นหา array(column => value, column => value)
     *
     * @return object|bool
     */
    public function first($table, $conditions)
    {
        $result = $this->search($table, $conditions, 1);
        if (is_array($result) && sizeof($result) == 1) {
            return $result[0];
        }
        return false;
    }

    /**
     * ค้นหาข้อมูลที่กำหนดเอง
     * คืนค่ารายการที่พบ (array) มีข้อผิดพลาดคืนค่า false
     *
     * @param string $table      ชื่อตาราง
     * @param array  $conditions ข้อความที่ต้องการค้นหา array(column => value, column => value)
     * @param int    $limit      จำนวนผลลัพท์ที่ต้องการ ค่าเริ่มต้น หมายถึงคืนค่าทุกรายการ
     * @param int    $start      ข้อมูลเริ่มต้นที่ต้องการ ค่าเริ่มต้น หมายถึงคืนค่าตั้งแต่รายการแรก
     * @param string $sort       คอลัมน์เรียงลำดับ เช่น id DESC,name ASC
     *
     * @return array|bool
     */
    public function search($table, $conditions, $limit = 0, $start = 0, $sort = null)
    {
        $keys = [];
        $datas = [];
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $keys[] = "`$field` IN :$field";
                $datas[":$field"] = $value;
            } else {
                $keys[] = "`$field`=:$field";
                $datas[":$field"] = $value;
            }
        }
        try {
            $sql = 'SELECT * FROM `'.$table.'` WHERE '.implode(' AND ', $keys);
            if (!empty($sort)) {
                $sql .= ' ORDER BY '.$sort;
            }
            if ($start > 0 && $limit > 0) {
                $sql .= ' LIMIT '.$start.','.$limit;
            } elseif ($limit > 0) {
                $sql .= ' LIMIT '.$limit;
            }
            $query = $this->connection->prepare($sql);
            $query->execute($datas);
            $result = [];
            if ($query) {
                while ($row = $query->fetch(\PDO::FETCH_OBJ)) {
                    $result[] = $row;
                }
            }
        } catch (\PDOException $e) {
            $this->error = $e->getMessage();
            $result = false;
        }
        return $result;
    }

    /**
     * ฟังก์ชั่นเพิ่มข้อมูลใหม่ลงในตาราง
     * สำเร็จ คืนค่า id ที่เพิ่ม, ผิดพลาด คืนค่า false
     *
     * @param string $table ชื่อตาราง
     * @param array  $save  ข้อมูลที่ต้องการบันทึก array(column => value, column => value)
     *
     * @return int|bool
     */
    public function insert($table, $save)
    {
        $keys = [];
        $values = [];
        foreach ($save as $key => $value) {
            $keys[] = $key;
            $values[":$key"] = $value;
        }
        $sql = 'INSERT INTO `'.$table.'` (`'.implode('`,`', $keys);
        $sql .= '`) VALUES (:'.implode(',:', $keys).');';
        $query = $this->connection->prepare($sql);
        $query->execute($values);
        return $this->connection->lastInsertId();
    }

    /**
     * แก้ไขข้อมูล
     * สำเร็จ คืนค่า true
     *
     * @param string    $table     ชื่อตาราง
     * @param array|int $condition id ที่ต้องการแก้ไข หรือข้อความค้นหารูปแอเรย์ [filed=>value]
     * @param array     $save      ข้อมูลที่ต้องการบันทึก
     *
     * @return bool
     */
    public function update($table, $condition, $save)
    {
        $keys = [];
        $values = [];
        foreach ($save as $key => $value) {
            $keys[] = "`$key`=:$key";
            $values[":$key"] = $value;
        }
        $where = $this->createWhere($condition, $values);
        if ($where == '' || sizeof($keys) == 0) {
            return false;
        } else {
            $sql = 'UPDATE `'.$table.'` SET '.implode(',', $keys).' WHERE '.$where.' LIMIT 1';
            $query = $this->connection->prepare($sql);
            $query->execute($values);
            return true;
        }
    }

    /**
     * ลบข้อมูล
     * สำเร็จ คืนค่า true
     *
     * @param string    $table     ชื่อตาราง
     * @param array|int $condition id ที่ต้องการ หรือข้อความค้นหารูปแอเรย์ [filed=>value]
     * @param int       $limit     จำนวนรายการที่ต้องการลบ ลบทุกลายการที่พบ , มากกว่า (ค่าเริ่มต้น 1) ลบตามจำนวนที่เลือก
     *
     * @return bool
     */
    public function delete($table, $condition, $limit = 1)
    {
        $values = [];
        $where = $this->createWhere($condition, $values);
        if ($where == '') {
            return false;
        } else {
            $sql = 'DELETE FROM `'.$table.'` WHERE '.$where;
            if ($limit > 0) {
                $sql .= ' LIMIT '.$limit;
            }
            $query = $this->connection->prepare($sql);
            $query->execute($values);
            return true;
        }
    }

    /**
     * คืนค่า query where
     *
     * @param array|int $condition id ที่ต้องการ หรือข้อความค้นหารูปแอเรย์ [filed=>value]
     * @param array     $values    ตัวแปรสำหรับรับค่าสำหรับการ prepare กลับ
     *
     * @return string
     */
    private function createWhere($condition, &$values)
    {
        if (is_array($condition)) {
            $datas = [];
            foreach ($condition as $key => $value) {
                if (is_array($value)) {
                    $ks = [];
                    $n = 1;
                    foreach ($value as $k => $v) {
                        $_key = ':'.$key.$n;
                        $ks[] = $_key;
                        $values[$_key] = $v;
                        ++$n;
                    }
                    $datas[] = "`$key` IN (".implode(',', $ks).')';
                } else {
                    $datas[] = "`$key`=:$key";
                    $values[":$key"] = $value;
                }
            }
            $where = sizeof($datas) == 0 ? '' : implode(' AND ', $datas);
        } else {
            $id = (int) $condition;
            $where = $id == 0 ? '' : '`id`=:id';
            $values[':id'] = $id;
        }
        return $where;
    }

    /**
     * ประมวลผลคำสั่ง SQL ที่ไม่ต้องการผลลัพท์ เช่น CREATE INSERT UPDATE
     * สำเร็จ คืนค่าจำนวนแถวที่ทำรายการ มีข้อผิดพลาดคืนค่า false
     *
     * @param string $sql
     *
     * @return int|bool
     */
    public function query($sql)
    {
        $this->error = '';
        $query = $this->connection->query($sql);
        return $query->rowCount();
    }

    /**
     * ประมวลผลคำสั่ง SQL สำหรับสอบถามข้อมูล คืนค่าผลลัพท์เป็นแอรย์ของข้อมูลที่ตรงตามเงื่อนไข
     * คืนค่าผลการทำงานเป็น record ของข้อมูลทั้งหมดที่ตรงตามเงื่อนไข ไม่พบข้อมูลคืนค่าเป็น array ว่างๆ ผิดพลาดคืนค่า false
     *
     * @param string $sql    query string
     * @param bool   $array  false (default) คืนค่าเป็น object, true คืนค่าเป็นแอเรย์
     * @param array  $values สำหรับ query ที่ใช้ prepare
     *
     * @return array|bool
     */
    public function customQuery($sql, $array = false, $values = null)
    {
        $this->error = '';
        if (empty($values)) {
            $query = $this->connection->query($sql);
        } else {
            $query = $this->connection->prepare($sql);
            $query->execute($values);
        }
        $result = [];
        if ($query) {
            if ($array) {
                while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                    $result[] = $row;
                }
            } else {
                while ($row = $query->fetch(\PDO::FETCH_OBJ)) {
                    $result[] = $row;
                }
            }
        }
        return $result;
    }

    /**
     * คืนค่าข้อความผิดพลาด
     * ไม่มี คืนค่าว่าง
     *
     * @return string
     */
    public function error()
    {
        return $this->error;
    }
}
