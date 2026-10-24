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
 * PDO Database Class (CRUD) ของตัวติดตั้ง รองรับทุก driver ที่ Kotchasan รองรับ
 * mysql, pgsql, sqlite, sqlsrv (ชื่อเดียวกับ dbdriver ใน settings/database.php)
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
     * @var string mysql, pgsql, sqlite หรือ sqlsrv
     */
    private $driver;

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
        $port = empty($db_config['port']) ? self::defaultPort($dbdriver) : $db_config['port'];
        $this->driver = $dbdriver;
        // pdo options
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];
        if ($dbdriver == 'mysql') {
            $options[\PDO::ATTR_PERSISTENT] = 1;
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';
            $options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = 1;
        }
        // connection string — รูปแบบเดียวกับ Kotchasan\Connection\*Driver::buildDsn()
        // เพื่อให้สิ่งที่ตัวติดตั้งเชื่อมต่อได้ คือสิ่งที่ระบบเชื่อมต่อได้จริงตอนใช้งาน
        if ($dbdriver == 'sqlite') {
            // SQLite: dbname คือที่อยู่ของไฟล์ฐานข้อมูล ไม่มีโฮสต์ ผู้ใช้ หรือรหัสผ่าน
            $sql = 'sqlite:'.$db_config['dbname'];
        } elseif ($dbdriver == 'sqlsrv') {
            $sql = 'sqlsrv:Server='.$hostname.','.$port;
            if (!empty($db_config['dbname'])) {
                $sql .= ';Database='.$db_config['dbname'];
            }
            if (!empty($db_config['trust_server_certificate'])) {
                // ODBC Driver 18 เข้ารหัสการเชื่อมต่อเสมอ เซิร์ฟเวอร์ที่ใช้ใบรับรองที่ลงนามเอง
                // ต้องเชื่อถือใบรับรองนั้นตรง ๆ (Kotchasan อ่านค่าเดียวกันนี้จาก settings/database.php)
                $sql .= ';TrustServerCertificate=1';
            }
        } else {
            $sql = $dbdriver.':host='.$hostname.';port='.$port.';dbname='.$db_config['dbname'];
        }
        // connect to database
        $this->connection = new \PDO($sql, isset($db_config['username']) ? $db_config['username'] : '', isset($db_config['password']) ? $db_config['password'] : '', $options);
        if ($dbdriver == 'mysql') {
            $this->connection->query("SET SESSION sql_mode = ''");
        }
    }

    /**
     * พอร์ตมาตรฐานของฐานข้อมูลแต่ละชนิด
     *
     * @param string $dbdriver
     *
     * @return int
     */
    public static function defaultPort($dbdriver)
    {
        $ports = ['mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433];

        return isset($ports[$dbdriver]) ? $ports[$dbdriver] : 0;
    }

    /**
     * ชนิดของฐานข้อมูลที่เชื่อมต่ออยู่
     *
     * @return string mysql, pgsql, sqlite หรือ sqlsrv
     */
    public function driver()
    {
        return $this->driver;
    }

    /**
     * ครอบชื่อตาราง/คอลัมน์ตามชนิดฐานข้อมูล (`name`, "name" หรือ [name])
     *
     * @param string $name
     *
     * @return string
     */
    public function quote($name)
    {
        if ($this->driver == 'mysql') {
            return '`'.$name.'`';
        } elseif ($this->driver == 'sqlsrv') {
            return '['.$name.']';
        }

        return '"'.$name.'"';
    }

    /**
     * ตรวจสอบฐานข้อมูล
     * คืนค่า true ถ้ามีอยู่แล้ว (SQLite คือไฟล์ฐานข้อมูล)
     *
     * @param string $database_name ชื่อฐานข้อมูล
     *
     * @return bool
     */
    public function databaseExists($database_name)
    {
        if ($this->driver == 'pgsql') {
            $result = $this->customQuery('SELECT 1 FROM pg_database WHERE datname = ?', false, [$database_name]);
        } elseif ($this->driver == 'sqlsrv') {
            $result = $this->customQuery('SELECT 1 AS x FROM sys.databases WHERE name = ?', false, [$database_name]);
        } elseif ($this->driver == 'sqlite') {
            return is_file($database_name);
        } else {
            $result = $this->customQuery('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', false, [$database_name]);
        }
        return empty($result) ? false : true;
    }

    /**
     * รายชื่อตารางทั้งหมดในฐานข้อมูลที่เชื่อมต่ออยู่
     *
     * @return array
     */
    public function tables()
    {
        if ($this->driver == 'pgsql') {
            $sql = "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'";
        } elseif ($this->driver == 'sqlsrv') {
            $sql = "SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_TYPE = 'BASE TABLE'";
        } elseif ($this->driver == 'sqlite') {
            $sql = "SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
        } else {
            $sql = "SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
        }
        $tables = [];
        foreach ($this->customQuery($sql) as $row) {
            $tables[] = (string) $row->t;
        }
        sort($tables);

        return $tables;
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
        if ($this->driver == 'mysql') {
            $result = $this->customQuery("SHOW TABLES LIKE '$table_name'");
            return empty($result) ? false : true;
        }
        return in_array($table_name, $this->tables(), true);
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
        if ($this->driver == 'pgsql') {
            $result = $this->customQuery('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', false, [$table_name, $field]);
        } elseif ($this->driver == 'sqlsrv') {
            $result = $this->customQuery('SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ? AND COLUMN_NAME = ?', false, [$table_name, $field]);
        } elseif ($this->driver == 'sqlite') {
            $result = $this->customQuery('SELECT 1 FROM pragma_table_info(?) WHERE name = ?', false, [$table_name, $field]);
        } else {
            $result = $this->customQuery("SHOW COLUMNS FROM $table_name LIKE '$field'");
        }
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
        if ($this->driver == 'pgsql') {
            $result = $this->customQuery('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?', false, [$table_name, $index]);
        } elseif ($this->driver == 'sqlsrv') {
            $result = $this->customQuery('SELECT 1 AS x FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?', false, [$table_name, $index]);
        } elseif ($this->driver == 'sqlite') {
            $result = $this->customQuery('SELECT 1 FROM pragma_index_list(?) WHERE name = ?', false, [$table_name, $index]);
        } else {
            $result = $this->customQuery(
                'SELECT index_name FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name',
                false,
                [
                    ':table_name' => $table_name,
                    ':index_name' => $index
                ]
            );
        }
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
        if ($this->driver == 'pgsql') {
            $result = $this->customQuery('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? AND data_type ILIKE ?', false, [$table_name, $field, $type.'%']);
        } elseif ($this->driver == 'sqlsrv') {
            $result = $this->customQuery('SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND DATA_TYPE LIKE ?', false, [$table_name, $field, $type.'%']);
        } elseif ($this->driver == 'sqlite') {
            $result = $this->customQuery('SELECT 1 FROM pragma_table_info(?) WHERE name = ? AND type LIKE ?', false, [$table_name, $field, $type.'%']);
        } else {
            $result = $this->customQuery("SHOW FIELDS FROM $table_name WHERE `Field`='$field' AND `Type` LIKE '$type%'");
        }
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
    public function search($table, $conditions = [], $limit = 0, $start = 0, $sort = null)
    {
        $datas = [];
        $sql = 'SELECT * FROM '.$this->quote($table);
        if (!empty($conditions)) {
            $sql .= ' WHERE '.$this->createWhere($conditions, $datas);
        }
        if (!empty($sort)) {
            $sql .= ' ORDER BY '.$sort;
        }
        if ($limit > 0) {
            if ($this->driver == 'sqlsrv') {
                // SQL Server ไม่มี LIMIT — OFFSET ... FETCH ต้องมี ORDER BY เสมอ
                $sql .= (empty($sort) ? ' ORDER BY (SELECT NULL)' : '').' OFFSET '.(int) $start.' ROWS FETCH NEXT '.(int) $limit.' ROWS ONLY';
            } else {
                $sql .= ' LIMIT '.(int) $limit.($start > 0 ? ' OFFSET '.(int) $start : '');
            }
        }
        try {
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
        $n = 0;
        foreach ($save as $key => $value) {
            // ชื่อ placeholder เป็นลำดับ ไม่ใช่ชื่อคอลัมน์ — คอลัมน์ภาษาหรือคอลัมน์ที่มีอักขระ
            // นอกเหนือ [a-z0-9_] ใช้เป็นชื่อ placeholder ไม่ได้
            $keys[$this->quote($key)] = ':p'.$n;
            $values[':p'.$n] = $value;
            ++$n;
        }
        $sql = 'INSERT INTO '.$this->quote($table).' ('.implode(',', array_keys($keys)).') VALUES ('.implode(',', $keys).')';
        $query = $this->connection->prepare($sql);
        $query->execute($values);
        try {
            return $this->connection->lastInsertId();
        } catch (\PDOException $e) {
            // PostgreSQL: ตารางที่ไม่มี sequence (หรือใส่ id เอง) ไม่มี lastval() ให้อ่าน
            return true;
        }
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
        $n = 0;
        foreach ($save as $key => $value) {
            $keys[] = $this->quote($key).'=:s'.$n;
            $values[':s'.$n] = $value;
            ++$n;
        }
        $where = $this->createWhere($condition, $values);
        if ($where == '' || sizeof($keys) == 0) {
            return false;
        } else {
            // UPDATE ... LIMIT มีเฉพาะ MySQL
            $sql = 'UPDATE '.$this->quote($table).' SET '.implode(',', $keys).' WHERE '.$where.($this->driver == 'mysql' ? ' LIMIT 1' : '');
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
     *                             (DELETE ... LIMIT มีเฉพาะ MySQL ฐานอื่นลบทุกรายการที่ตรงเงื่อนไข)
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
            $sql = 'DELETE FROM '.$this->quote($table).' WHERE '.$where;
            if ($limit > 0 && $this->driver == 'mysql') {
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
            $n = 0;
            foreach ($condition as $key => $value) {
                if (is_array($value)) {
                    $ks = [];
                    foreach ($value as $v) {
                        $_key = ':w'.$n;
                        $ks[] = $_key;
                        $values[$_key] = $v;
                        ++$n;
                    }
                    $datas[] = $this->quote($key).' IN ('.implode(',', $ks).')';
                } else {
                    $datas[] = $this->quote($key).'=:w'.$n;
                    $values[':w'.$n] = $value;
                    ++$n;
                }
            }
            $where = sizeof($datas) == 0 ? '' : implode(' AND ', $datas);
        } else {
            $id = (int) $condition;
            $where = $id == 0 ? '' : $this->quote('id').'=:id';
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
        if ($this->driver == 'sqlsrv') {
            // pdo_sqlsrv รัน query() ผ่าน sp_executesql คำสั่ง SET (เช่น IDENTITY_INSERT)
            // จึงมีผลแค่ในคำสั่งนั้นเอง ต้องใช้ exec() ให้ค่าคงอยู่ทั้ง session
            return $this->connection->exec($sql);
        }
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
        // คำสั่งที่ไม่มีชุดผลลัพธ์ (SET, ALTER ฯลฯ) ห้าม fetch — pdo_sqlsrv โยนข้อยกเว้น
        if ($query && $query->columnCount() > 0) {
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
