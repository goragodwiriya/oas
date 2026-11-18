<?php
/**
 * @filesource modules/index/models/userrepository.php
 *
 * User Repository - Data Access Layer
 * Handles all database operations for users
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\UserRepository;

/**
 * User Repository
 *
 * Data Access Layer for user operations
 * Separates database queries from business logic
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Create a new user
     *
     * @param array $data User data
     *
     * @return int User ID
     */
    public static function createUser(array $data)
    {
        return self::saveUser(\Kotchasan\DB::create(), 0, $data);
    }

    /**
     * Save one user aggregate across user and user_meta.
     *
     * @param \Kotchasan\DB $db
     * @param int $id
     * @param array $data
     *
     * @return int
     */
    public static function saveUser($db, int $id, array $data)
    {
        $isNew = $id === 0;
        $metas = isset($data['metas']) && is_array($data['metas']) ? $data['metas'] : [];
        unset($data['metas']);
        $data = self::fitUserColumns($db, $data, $isNew);

        if ($isNew) {
            $id = (int) $db->insert('user', $data);
        } else {
            unset($data['id']);
            $db->update('user', ['id', $id], $data);
        }

        if ($isNew) {
            $defaultDepartment = trim((string) (self::$cfg->default_department ?? ''));
            $hasDepartment = isset($metas['department'])
            && is_array($metas['department'])
            && !empty($metas['department']);

            if ($defaultDepartment !== '' && !$hasDepartment) {
                $metas['department'] = [$defaultDepartment];
            }
        }

        self::saveUserMeta($db, $id, $metas);

        return $id;
    }

    /**
     * Fit the values to the user table of this site.
     *
     * Empty phone/id_card/... arrive as NULL: in the current schema they are UNIQUE columns
     * that allow NULL (NULL may repeat, '' may not). The user table of a site carried over
     * from the old GCMS still has them NOT NULL without a default, so NULL made every insert
     * fail ("Column 'phone' cannot be null" — nobody could sign up with Google/Facebook), and
     * under a strict sql_mode a column left out fails with "Field '...' doesn't have a default
     * value". A NOT NULL column gets its default or the empty value of its type instead;
     * a column that allows NULL keeps NULL.
     *
     * @param \Kotchasan\DB $db
     * @param array $data
     * @param bool $isNew Fill the NOT NULL columns without a default that $data leaves out
     *
     * @return array
     */
    private static function fitUserColumns($db, array $data, $isNew)
    {
        foreach (self::notNullColumns($db) as $field => $column) {
            if (array_key_exists($field, $data)) {
                if ($data[$field] === null) {
                    $data[$field] = $column['empty'];
                }
            } elseif ($isNew && !$column['hasDefault']) {
                $data[$field] = $column['empty'];
            }
        }

        return $data;
    }

    /**
     * NOT NULL columns of the user table (except auto_increment) and the value that stands
     * for "empty" in each: its default, otherwise the empty value of its type.
     *
     * @param \Kotchasan\DB $db
     *
     * @return array [field => ['empty' => mixed, 'hasDefault' => bool]]
     */
    private static function notNullColumns($db)
    {
        static $cache = [];
        $table = $db->getTableName('user');
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        // only MySQL accepts the zero date; the other drivers reject it, so "empty" is today there
        $zeroDate = $db->getDatabase()->getDriverName() === 'mysql';
        foreach (self::describeColumns($db, $table) as $row) {
            if (($row['Null'] ?? 'YES') !== 'NO' || strpos((string) ($row['Extra'] ?? ''), 'auto_increment') !== false) {
                continue;
            }
            $type = strtolower((string) $row['Type']);
            if ($row['Default'] !== null) {
                $empty = preg_match('/^(current_timestamp|now\(\))/i', (string) $row['Default']) ? date('Y-m-d H:i:s') : $row['Default'];
            } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|double|float|real)/', $type)) {
                $empty = 0;
            } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
                $empty = $zeroDate ? '0000-00-00 00:00:00' : date('Y-m-d H:i:s');
            } elseif (strpos($type, 'date') === 0) {
                $empty = $zeroDate ? '0000-00-00' : date('Y-m-d');
            } elseif (preg_match("/^enum\('([^']*)'/", $type, $match)) {
                $empty = $match[1];
            } else {
                $empty = '';
            }
            $columns[$row['Field']] = ['empty' => $empty, 'hasDefault' => $row['Default'] !== null];
        }

        return $cache[$table] = $columns;
    }

    /**
     * Columns of a table in the shape of MySQL's SHOW COLUMNS (Field, Type, Null,
     * Default, Extra) on every driver. Default is the plain value, without the
     * quotes and casts other drivers wrap it in ('TH'::character varying, ((0))).
     *
     * @param \Kotchasan\DB $db
     * @param string $table Full table name
     *
     * @return array
     */
    private static function describeColumns($db, $table)
    {
        switch ($db->getDatabase()->getDriverName()) {
        case 'pgsql':
            $sql = 'SELECT column_name AS "Field", data_type AS "Type", is_nullable AS "Null", column_default AS "Default",'
                ." CASE WHEN is_identity = 'YES' OR column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS \"Extra\""
                .' FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?';
            break;
        case 'sqlite':
            $sql = 'SELECT name AS "Field", type AS "Type", CASE WHEN notnull = 1 THEN \'NO\' ELSE \'YES\' END AS "Null", dflt_value AS "Default",'
                ." CASE WHEN pk = 1 AND lower(type) = 'integer' THEN 'auto_increment' ELSE '' END AS \"Extra\""
                .' FROM pragma_table_info(?)';
            break;
        case 'sqlsrv':
            $sql = 'SELECT COLUMN_NAME AS [Field], DATA_TYPE AS [Type], IS_NULLABLE AS [Null], COLUMN_DEFAULT AS [Default],'
                ." CASE WHEN COLUMNPROPERTY(OBJECT_ID(TABLE_NAME), COLUMN_NAME, 'IsIdentity') = 1 THEN 'auto_increment' ELSE '' END AS [Extra]"
                .' FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?';
            break;
        default:
            $result = $db->raw('SHOW COLUMNS FROM `'.$table.'`');

            return $result ? array_map(function ($row) {
                return (array) $row;
            }, $result->fetchAll()) : [];
        }
        $rows = [];
        $result = $db->raw($sql, [$table]);
        foreach ($result ? $result->fetchAll() : [] as $row) {
            $row = (array) $row;
            if ($row['Default'] !== null) {
                // 'TH'::character varying → TH · ((0)) → 0 · (N'TH') → TH · NULL → null
                $default = trim(preg_replace('/::[a-z ]+(\(\d+\))?$/i', '', trim((string) $row['Default'])), '()');
                if (strcasecmp($default, 'NULL') === 0) {
                    $default = null;
                } elseif (preg_match("/^N?'(.*)'$/s", $default, $match)) {
                    $default = str_replace("''", "'", $match[1]);
                }
                $row['Default'] = $default;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Find user by username
     *
     * @param string $username
     *
     * @return object|null
     */
    public static function findByUsername($username)
    {
        return \Kotchasan\DB::create()->first('user', ['username', $username]);
    }

    /**
     * Replace the given user_meta fields of the member.
     *
     * ⚠️ แทนที่เฉพาะชื่อที่ส่งมา — ไม่ล้างทั้งสมาชิก เพราะ user_meta เป็นที่เก็บกลางที่โมดูลอื่น
     * ฝากค่าของตัวเองไว้ด้วย (เช่น กะทำงานของระบบลา · position) ฟอร์มโปรไฟล์ส่งมาแค่ department
     * ถ้าล้างทั้งหมด การแก้โปรไฟล์ครั้งเดียวจะลบค่าของโมดูลอื่นทิ้งเงียบ ๆ
     *
     * @param \Kotchasan\DB $db
     * @param int $userId
     * @param array $metas [ชื่อ => ค่า|ค่าหลายค่า] ชื่อที่ส่งมาพร้อมค่าว่าง = ลบชื่อนั้น
     *
     * @return void
     */
    public static function saveUserMeta($db, int $userId, array $metas): void
    {
        $names = array_values(array_unique(array_map('strval', array_keys($metas))));
        if (empty($names)) {
            return;
        }
        $db->delete('user_meta', [['member_id', $userId], ['name', $names]], 0);

        foreach ($metas as $name => $value) {
            foreach (self::normalizeMetaValues($value) as $item) {
                $db->insert('user_meta', [
                    'member_id' => $userId,
                    'name' => $name,
                    'value' => $item
                ]);
            }
        }
    }

    /**
     * Normalize one meta field into a flat scalar list.
     *
     * @param mixed $value
     *
     * @return array
     */
    private static function normalizeMetaValues($value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($values as $item) {
            if (is_string($item)) {
                $item = trim($item);
            }
            if ($item === '' || $item === null) {
                continue;
            }
            $result[] = $item;
        }

        return array_values($result);
    }

    /**
     * Check if field value is unique
     *
     * @param string $field Field name (username, phone, email)
     * @param mixed $value Field value
     * @param int $excludeId User ID to exclude from check
     *
     * @return bool True if unique
     */
    public static function isFieldUnique($field, $value, $excludeId = 0)
    {
        if (empty($value)) {
            return true;
        }

        $user = \Kotchasan\DB::create()->first('user', [$field, $value], ['id']);

        if ($excludeId > 0) {
            return $user ? $user->id == $excludeId : true;
        }

        return !$user;
    }
}
