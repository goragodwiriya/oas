<?php
if (defined('ROOT_PATH')) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        include ROOT_PATH.'install/upgrade1.php';
    } else {
        $error = false;
        // Database Class
        include ROOT_PATH.'install/db.php';
        // ค่าติดตั้งฐานข้อมูล
        $db_config = include ROOT_PATH.'settings/database.php';
        try {
            $db_config = $db_config['mysql'];
            // เขื่อมต่อฐานข้อมูล
            $db = new Db($db_config);
        } catch (\Exception $exc) {
            $error = true;
            echo '<h2>ความผิดพลาดในการเชื่อมต่อกับฐานข้อมูล</h2>';
            echo '<p class=warning>ไม่สามารถเชื่อมต่อกับฐานข้อมูลของคุณได้ในขณะนี้</p>';
            echo '<p>อาจเป็นไปได้ว่า</p>';
            echo '<ol>';
            echo '<li>เซิร์ฟเวอร์ของฐานข้อมูลของคุณไม่สามารถใช้งานได้ในขณะนี้</li>';
            echo '<li>ค่ากำหนดของฐานข้อมูลไม่ถูกต้อง (ตรวจสอบไฟล์ settings/database.php)</li>';
            echo '<li>ไม่พบฐานข้อมูลที่ต้องการติดตั้ง กรุณาสร้างฐานข้อมูลก่อน หรือใช้ฐานข้อมูลที่มีอยู่แล้ว</li>';
            echo '<li class="incorrect">'.$exc->getMessage().'</li>';
            echo '</ol>';
            echo '<p>หากคุณไม่สามารถดำเนินการแก้ไขข้อผิดพลาดด้วยตัวของคุณเองได้ ให้ติดต่อผู้ดูแลระบบเพื่อขอข้อมูลที่ถูกต้อง หรือ ลองติดตั้งใหม่</p>';
            echo '<p class="submit"><a href="index.php?step=1" class="btn large btn-secondary">กลับไปลองใหม่</a></p>';
        }
        if (!$error) {
            // เชื่อมต่อฐานข้อมูลสำเร็จ
            $content = ['<li class="correct">เชื่อมต่อฐานข้อมูลสำเร็จ</li>'];
            try {
                if (!isset($new_config) || !is_array($new_config)) {
                    throw new \Exception('ไม่พบค่ากำหนดเวอร์ชั่นใหม่สำหรับการปรับรุ่น');
                }

                // =========================================================
                // user
                // =========================================================
                $table_user = $db_config['prefix'].'_user';
                if (empty($config['password_key'])) {
                    // อัปเดตข้อมูลผู้ดูแลระบบ
                    $config['password_key'] = uniqid();
                }
                // ตรวจสอบการ login
                updateAdmin($db, $table_user, $_POST['username'], $_POST['password'], $config['password_key']);

                foreach (['username', 'token', 'id_card', 'phone', 'activatecode', 'line_uid', 'telegram_id', 'status'] as $_idx) {
                    if ($db->indexExists($table_user, $_idx)) {
                        $db->query("ALTER TABLE `$table_user` DROP INDEX `$_idx`");
                    }
                }

                // rename create_date → created_at
                if ($db->fieldExists($table_user, 'create_date')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `create_date` `created_at` DATETIME NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เปลี่ยนชื่อ create_date → created_at</li>';
                }
                // activatecode: varchar(32) NOT NULL → varchar(64) NULL
                if (!$db->isColumnType($table_user, 'activatecode', 'varchar(64)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `activatecode` `activatecode` VARCHAR(64) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข activatecode เป็น VARCHAR(64) NULL</li>';
                }
                // address: varchar(150) → varchar(64)
                if (!$db->isColumnType($table_user, 'address', 'varchar(64)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `address` `address` VARCHAR(64) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข address เป็น VARCHAR(64)</li>';
                }
                // password: varchar(50) → varchar(64)
                if (!$db->isColumnType($table_user, 'password', 'varchar(64)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `password` `password` VARCHAR(64) NOT NULL");
                    $content[] = '<li class="correct">user: แก้ไข password เป็น VARCHAR(64)</li>';
                }
                // permission: text → TEXT
                if (!$db->isColumnType($table_user, 'permission', 'text')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `permission` `permission` TEXT NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข permission เป็น TEXT</li>';
                }
                // phone: varchar(32) → varchar(20)
                if (!$db->isColumnType($table_user, 'phone', 'varchar(20)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `phone` `phone` VARCHAR(20) NULL DEFAULT NULL");
                    $db->query("UPDATE `$table_user` SET `phone` = NULL WHERE `phone` = ''");
                    $content[] = '<li class="correct">user: แก้ไข phone เป็น VARCHAR(20)</li>';
                }
                if ($db->fieldExists($table_user, 'id_card')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `id_card` `id_card` VARCHAR(13) NULL DEFAULT NULL");
                    $db->query("UPDATE `$table_user` SET `id_card` = NULL WHERE `id_card` = ''");
                    $content[] = '<li class="correct">user: อัปเดท id_card</li>';
                }
                // province: varchar(50) → varchar(64)
                if (!$db->isColumnType($table_user, 'province', 'varchar(64)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `province` `province` VARCHAR(64) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข province เป็น VARCHAR(64)</li>';
                }
                // provinceID: varchar(3) → smallint(3)
                if (!$db->isColumnType($table_user, 'provinceID', 'smallint(3)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `provinceID` `provinceID` SMALLINT(3) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข provinceID เป็น SMALLINT(3)</li>';
                }
                // salt: allow null
                if (!$db->isColumnType($table_user, 'salt', 'varchar(32)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `salt` `salt` VARCHAR(32) NOT NULL DEFAULT ''");
                    $content[] = '<li class="correct">user: แก้ไข salt เป็น NOT NULL DEFAULT \'\'</li>';
                }
                // social: tinyint → enum (migrate 0 → 'user' first)
                if ($db->isColumnType($table_user, 'social', 'tinyint')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `social` `social` VARCHAR(32) NULL DEFAULT NULL");
                    $db->query("UPDATE `$table_user` SET `social` = 'user' WHERE `social` = 0 OR `social` IS NULL");
                    $db->query("UPDATE `$table_user` SET `social` = 'facebook' WHERE `social` = 1");
                    $db->query("UPDATE `$table_user` SET `social` = 'google' WHERE `social` = 2");
                    $db->query("UPDATE `$table_user` SET `social` = 'line' WHERE `social` = 3");
                    $db->query("UPDATE `$table_user` SET `social` = 'telegram' WHERE `social` = 4");
                    $db->query("ALTER TABLE `$table_user` CHANGE `social` `social` ENUM('user','facebook','google','line','telegram') NULL DEFAULT 'user'");
                    $content[] = '<li class="correct">user: แก้ไข social เป็น ENUM</li>';
                }
                // telegram_id: varchar(13) → varchar(20)
                if (!$db->isColumnType($table_user, 'telegram_id', 'varchar(20)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `telegram_id` `telegram_id` VARCHAR(20) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข telegram_id เป็น VARCHAR(20)</li>';
                }
                // token: varchar(50) → varchar(512)
                if (!$db->isColumnType($table_user, 'token', 'varchar(512)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `token` `token` VARCHAR(512) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข token เป็น VARCHAR(512)</li>';
                }
                // zipcode: varchar(10) → varchar(5)
                if (!$db->isColumnType($table_user, 'zipcode', 'varchar(5)')) {
                    $db->query("ALTER TABLE `$table_user` CHANGE `zipcode` `zipcode` VARCHAR(5) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: แก้ไข zipcode เป็น VARCHAR(5)</li>';
                }
                // add new columns
                if (!$db->fieldExists($table_user, 'address2')) {
                    $db->query("ALTER TABLE `$table_user` ADD `address2` VARCHAR(64) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม address2</li>';
                }
                if (!$db->fieldExists($table_user, 'birthday')) {
                    $db->query("ALTER TABLE `$table_user` ADD `birthday` DATE NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม birthday</li>';
                }
                if (!$db->fieldExists($table_user, 'company')) {
                    $db->query("ALTER TABLE `$table_user` ADD `company` VARCHAR(64) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม company</li>';
                }
                if (!$db->fieldExists($table_user, 'phone1')) {
                    $db->query("ALTER TABLE `$table_user` ADD `phone1` VARCHAR(20) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม phone1</li>';
                }
                if (!$db->fieldExists($table_user, 'tax_id')) {
                    $db->query("ALTER TABLE `$table_user` ADD `tax_id` VARCHAR(13) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม tax_id</li>';
                }
                if (!$db->fieldExists($table_user, 'token_expires')) {
                    $db->query("ALTER TABLE `$table_user` ADD `token_expires` DATETIME NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม token_expires</li>';
                }
                if (!$db->fieldExists($table_user, 'visited')) {
                    $db->query("ALTER TABLE `$table_user` ADD `visited` INT(11) NOT NULL DEFAULT 0");
                    $content[] = '<li class="correct">user: เพิ่ม visited</li>';
                }
                if (!$db->fieldExists($table_user, 'website')) {
                    $db->query("ALTER TABLE `$table_user` ADD `website` VARCHAR(255) NULL DEFAULT NULL");
                    $content[] = '<li class="correct">user: เพิ่ม website</li>';
                }

                foreach (['activatecode', 'line_uid', 'telegram_id'] as $_idx) {
                    if (!$db->indexExists($table_user, $_idx)) {
                        $db->query("ALTER TABLE `$table_user` ADD INDEX `$_idx` (`$_idx`)");
                    }
                }
                foreach (['username', 'token', 'id_card', 'phone'] as $_idx) {
                    if (!$db->indexExists($table_user, $_idx)) {
                        $db->query("ALTER TABLE `$table_user` ADD UNIQUE `$_idx` (`$_idx`)");
                    }
                }

                if (!$db->indexExists($table_user, 'idx_status')) {
                    $db->query("ALTER TABLE `$table_user` ADD INDEX `idx_status` (`active`, `status`)");
                    $content[] = '<li class="correct">user: เพิ่ม index idx_status(active, status)</li>';
                }

                $content[] = '<li class="correct">user อัปเกรดสำเร็จ</li>';

                // =========================================================
                // category
                // =========================================================
                $table_category = $db_config['prefix'].'_category';

                if ($db->isColumnType($table_category, 'category_id', 'varchar(10)')) {
                    $db->query("UPDATE `$table_category` SET `category_id` = '0' WHERE `category_id` IS NULL");
                    $db->query("ALTER TABLE `$table_category` CHANGE `category_id` `category_id` VARCHAR(10) NOT NULL DEFAULT '0'");
                    $content[] = '<li class="correct">category: แก้ไข category_id เป็น NOT NULL</li>';
                }
                if ($db->isColumnType($table_category, 'language', 'varchar(2)')) {
                    $db->query("UPDATE `$table_category` SET `language` = '' WHERE `language` IS NULL");
                    $db->query("ALTER TABLE `$table_category` CHANGE `language` `language` VARCHAR(2) NOT NULL DEFAULT ''");
                    $content[] = '<li class="correct">category: แก้ไข language เป็น NOT NULL</li>';
                }
                // migrate published → is_active
                if (!$db->fieldExists($table_category, 'is_active')) {
                    $db->query("ALTER TABLE `$table_category` ADD `is_active` TINYINT(1) NULL");
                    if ($db->fieldExists($table_category, 'published')) {
                        $db->query("UPDATE `$table_category` SET `is_active` = `published`");
                    } else {
                        $db->query("UPDATE `$table_category` SET `is_active` = 1");
                    }
                    $db->query("ALTER TABLE `$table_category` MODIFY `is_active` TINYINT(1) NOT NULL DEFAULT 1");
                    $content[] = '<li class="correct">category: เพิ่ม is_active</li>';
                }
                if ($db->fieldExists($table_category, 'published')) {
                    $db->query("ALTER TABLE `$table_category` DROP COLUMN `published`");
                    $content[] = '<li class="correct">category: ลบ published</li>';
                }
                $db->query("UPDATE `$table_category` SET `type` = 'car_accessory' WHERE `type` = 'car_accessories'");
                $content[] = '<li class="correct">category อัปเกรดสำเร็จ</li>';

                // =========================================================
                // logs
                // =========================================================
                $table_logs = $db_config['prefix'].'_logs';

                if ($db->fieldExists($table_logs, 'create_date')) {
                    $db->query("ALTER TABLE `$table_logs` CHANGE `create_date` `created_at` DATETIME NOT NULL");
                    $content[] = '<li class="correct">logs: เปลี่ยนชื่อ create_date → created_at</li>';
                }
                if ($db->isColumnType($table_logs, 'datas', 'text')) {
                    $db->query("ALTER TABLE `$table_logs` CHANGE `datas` `datas` TEXT NULL DEFAULT NULL");
                    $content[] = '<li class="correct">logs: แก้ไข datas เป็น TEXT</li>';
                }
                if ($db->isColumnType($table_logs, 'member_id', 'int(11)')) {
                    $db->query("UPDATE `$table_logs` SET `member_id` = 0 WHERE `member_id` IS NULL");
                    $db->query("ALTER TABLE `$table_logs` CHANGE `member_id` `member_id` INT(11) NOT NULL");
                    $content[] = '<li class="correct">logs: แก้ไข member_id เป็น NOT NULL</li>';
                }
                if ($db->isColumnType($table_logs, 'reason', 'text')) {
                    $db->query("ALTER TABLE `$table_logs` CHANGE `reason` `reason` TEXT NULL DEFAULT NULL");
                    $content[] = '<li class="correct">logs: แก้ไข reason เป็น TEXT</li>';
                }
                if ($db->isColumnType($table_logs, 'topic', 'text')) {
                    $db->query("ALTER TABLE `$table_logs` CHANGE `topic` `topic` TEXT NOT NULL");
                    $content[] = '<li class="correct">logs: แก้ไข topic เป็น TEXT</li>';
                }
                if (!$db->indexExists($table_logs, 'created_at')) {
                    $db->query("ALTER TABLE `$table_logs` ADD INDEX `created_at` (`created_at`)");
                    $content[] = '<li class="correct">logs: เพิ่ม index created_at</li>';
                }
                $content[] = '<li class="correct">logs อัปเกรดสำเร็จ</li>';

                // =========================================================
                // language
                // =========================================================
                $table_language = $db_config['prefix'].'_language';

                foreach (['js', 'la', 'owner'] as $_col) {
                    if ($db->fieldExists($table_language, $_col)) {
                        $db->query("ALTER TABLE `$table_language` DROP COLUMN `$_col`");
                        $content[] = '<li class="correct">language: ลบ '.$_col.'</li>';
                    }
                }
                $content[] = '<li class="correct">language อัปเกรดสำเร็จ</li>';

                prepareLegacyTablesForSync($db, $db_config['prefix'], $content);

                // sync remaining schema against install/database.sql
                syncSchemaFromSql($db, ROOT_PATH.'install/database.sql', $db_config['prefix'], $content);
                seedOperationalDefaults($db, $db_config['prefix'], $content);
                normalizeCustomerData($db, $db_config['prefix'], $content);
                normalizeInventoryData($db, $db_config['prefix'], $content);
                ensureLegacyStockInventoryRecords($db, $db_config['prefix'], $content);
                migrateLegacyOrders($db, $db_config['prefix'], $content);

                // บันทึก settings/config.php
                $config['version'] = $new_config['version'];
                $config['reversion'] = time();
                if (function_exists('imagewebp')) {
                    $config['stored_img_type'] = isset($config['stored_img_type']) ? $config['stored_img_type'] : '.jpg';
                } else {
                    $config['stored_img_type'] = '.jpg';
                }
                if (isset($new_config['default_icon'])) {
                    $config['default_icon'] = $new_config['default_icon'];
                }
                // กำหนดค่า API หากยังไม่มี
                include_once ROOT_PATH.'Kotchasan/Password.php';
                if (empty($config['api_tokens']['internal']) || empty($config['api_tokens']['external'])) {
                    $config['api_tokens'] = [
                        'internal' => \Kotchasan\Password::uniqid(40),
                        'external' => \Kotchasan\Password::uniqid(40)
                    ];
                }
                if (empty($config['api_secret'])) {
                    $config['api_secret'] = \Kotchasan\Password::uniqid();
                }
                if (empty($config['jwt_secret'])) {
                    $config['jwt_secret'] = \Kotchasan\Password::uniqid(64);
                }
                if (!isset($config['api_ips'])) {
                    $config['api_ips'] = ['0.0.0.0'];
                }
                if (!isset($config['api_cors'])) {
                    $config['api_cors'] = '*';
                }
                $f = save($config, ROOT_PATH.'settings/config.php');
                $content[] = '<li class="'.($f ? 'correct' : 'incorrect').'">บันทึก <b>config.php</b> ...</li>';
                // นำเข้าภาษา
                include ROOT_PATH.'install/language.php';
            } catch (\PDOException $exc) {
                $content[] = '<li class="incorrect">'.$exc->getMessage().'</li>';
                $error = true;
            } catch (\Exception $exc) {
                $content[] = '<li class="incorrect">'.$exc->getMessage().'</li>';
                $error = true;
            }
            if (!$error) {
                echo '<h2>ปรับรุ่นเรียบร้อย</h2>';
                echo '<p>การปรับรุ่นได้ดำเนินการเสร็จเรียบร้อยแล้ว หากคุณต้องการความช่วยเหลือในการใช้งาน คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
                echo '<ul>'.implode('', $content).'</ul>';
                echo '<p class=warning>กรุณาลบไดเร็คทอรี่ <em>install/</em> ออกจาก Server ของคุณ</p>';
                echo '<p>คุณควรปรับ chmod ให้ไดเร็คทอรี่ <em>datas/</em> และ <em>settings/</em> (และไดเร็คทอรี่อื่นๆที่คุณได้ปรับ chmod ไว้ก่อนการปรับรุ่น) ให้เป็น 644 ก่อนดำเนินการต่อ (ถ้าคุณได้ทำการปรับ chmod ไว้ด้วยตัวเอง)</p>';
                echo '<p class="submit"><a href="../" class="btn btn-primary large">เข้าระบบ</a></p>';
            } else {
                echo '<h2>ปรับรุ่นไม่สำเร็จ</h2>';
                echo '<p>การปรับรุ่นยังไม่สมบูรณ์ ลองตรวจสอบข้อผิดพลาดที่เกิดขึ้นและแก้ไขดู หากคุณต้องการความช่วยเหลือการติดตั้ง คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
                echo '<ul>'.implode('', $content).'</ul>';
                echo '<p class="submit"><a href="." class="btn btn-primary large">ลองใหม่</a></p>';
            }
        }
    }
}

/**
 * @param Db $db
 * @param string $table_name
 * @param string $username
 * @param string $password
 * @param string $password_key
 */
function updateAdmin($db, $table_name, $username, $password, $password_key)
{
    include ROOT_PATH.'Kotchasan/Text.php';
    $username = \Kotchasan\Text::username($username);
    $password = \Kotchasan\Text::password($password);
    $result = $db->first($table_name, [
        'username' => $username,
        'status' => 1
    ]);
    if (!$result || $result->id > 1) {
        throw new \Exception('ชื่อผู้ใช้ไม่ถูกต้อง หรือไม่ใช่ผู้ดูแลระบบสูงสุด');
    } elseif ($result->password === sha1($password.$result->salt)) {
        // password เวอร์ชั่นเก่า
        $password = sha1($password_key.$password.$result->salt);
        $db->update($table_name, ['id' => $result->id], ['password' => $password]);
    } elseif ($result->password != sha1($password_key.$password.$result->salt)) {
        throw new \Exception('รหัสผ่านไม่ถูกต้อง');
    }
}

/**
 * @param array $config
 * @param string $file
 */
function save($config, $file)
{
    $f = @fopen($file, 'wb');
    if ($f !== false) {
        if (!preg_match('/^.*\/([^\/]+)\.php?/', $file, $match)) {
            $match[1] = 'config';
        }
        fwrite($f, '<'."?php\n/* $match[1].php */\nreturn ".var_export((array) $config, true).';');
        fclose($f);
        return true;
    } else {
        return false;
    }
}

/**
 * Ensure a table exists.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $sql
 * @param array $content
 * @param string $message
 */
function ensureTable($db, $table_name, $sql, &$content, $message)
{
    if (!$db->tableExists($table_name)) {
        $db->query($sql);
        $content[] = '<li class="correct">'.$message.'</li>';
    }
}

/**
 * Ensure a column exists.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $field
 * @param string $sql
 * @param array $content
 * @param string $message
 */
function ensureColumn($db, $table_name, $field, $sql, &$content, $message)
{
    if (!$db->fieldExists($table_name, $field)) {
        $db->query("ALTER TABLE `$table_name` $sql");
        $content[] = '<li class="correct">'.$message.'</li>';
    }
}

/**
 * Ensure an index exists.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $index
 * @param string $sql
 * @param array $content
 * @param string $message
 */
function ensureIndex($db, $table_name, $index, $sql, &$content, $message)
{
    if (!$db->indexExists($table_name, $index)) {
        $db->query("ALTER TABLE `$table_name` $sql");
        $content[] = '<li class="correct">'.$message.'</li>';
    }
}

/**
 * Ensure a category row exists.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $type
 * @param string $category_id
 * @param string $topic
 * @param string|null $color
 * @param int $is_active
 * @param array $content
 * @param string $message
 */
function ensureCategory($db, $table_name, $type, $category_id, $topic, $color, $is_active, &$content, $message)
{
    $result = $db->first($table_name, [
        'type' => $type,
        'category_id' => $category_id,
        'language' => ''
    ]);
    if (!$result) {
        $db->insert($table_name, [
            'type' => $type,
            'category_id' => $category_id,
            'language' => '',
            'topic' => $topic,
            'color' => $color,
            'is_active' => $is_active
        ]);
        $content[] = '<li class="correct">'.$message.'</li>';
    }
}

/**
 * Ensure a config key exists.
 *
 * @param array $config
 * @param string $key
 * @param mixed $value
 * @param array $content
 * @param string $message
 */
function ensureConfigDefault(&$config, $key, $value, &$content, $message)
{
    if (!isset($config[$key])) {
        $config[$key] = $value;
        $content[] = '<li class="correct">'.$message.'</li>';
    }
}

/**
 * Prepare legacy tables so schema sync can safely add the new keys and constraints.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function prepareLegacyTablesForSync($db, $prefix, &$content)
{
    $table_inventory_items = $prefix.'_inventory_items';
    if (!$db->tableExists($table_inventory_items)) {
        return;
    }

    if (!$db->fieldExists($table_inventory_items, 'id')) {
        $db->query("ALTER TABLE `$table_inventory_items` ADD `id` INT(11) NULL DEFAULT NULL");
        $db->query('SET @inventory_item_seq := 0');
        $order_column = $db->fieldExists($table_inventory_items, 'product_no') ? '`product_no`' : '`inventory_id`';
        $db->query("UPDATE `$table_inventory_items` SET `id` = (@inventory_item_seq := @inventory_item_seq + 1) ORDER BY `inventory_id`, $order_column");
        $db->query("ALTER TABLE `$table_inventory_items` MODIFY `id` INT(11) NOT NULL");
        $content[] = '<li class="correct">inventory_items: เตรียมคอลัมน์ id สำหรับการปรับรุ่น</li>';
    }

    if (!$db->fieldExists($table_inventory_items, 'sku')) {
        $db->query("ALTER TABLE `$table_inventory_items` ADD `sku` VARCHAR(150) NULL DEFAULT NULL");
        if ($db->fieldExists($table_inventory_items, 'product_no')) {
            $db->query("UPDATE `$table_inventory_items` SET `sku` = `product_no` WHERE `sku` IS NULL OR `sku` = ''");
        }
        $db->query("UPDATE `$table_inventory_items` SET `sku` = CONCAT('SKU-', `id`) WHERE `sku` IS NULL OR `sku` = ''");
        $db->query("ALTER TABLE `$table_inventory_items` MODIFY `sku` VARCHAR(150) NOT NULL");
        $content[] = '<li class="correct">inventory_items: เตรียมคอลัมน์ sku สำหรับการปรับรุ่น</li>';
    } elseif ($db->fieldExists($table_inventory_items, 'product_no')) {
        $db->query("UPDATE `$table_inventory_items` SET `sku` = `product_no` WHERE `sku` IS NULL OR `sku` = ''");
        $db->query("UPDATE `$table_inventory_items` SET `sku` = CONCAT('SKU-', `id`) WHERE `sku` IS NULL OR `sku` = ''");
    }
}

/**
 * Seed required operational defaults after the schema is in place.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function seedOperationalDefaults($db, $prefix, &$content)
{
    $table_warehouse = $prefix.'_inventory_warehouse';
    if ($db->tableExists($table_warehouse)) {
        if (tableRowCount($db, $table_warehouse) == 0) {
            $now = date('Y-m-d H:i:s');
            $db->insert($table_warehouse, [
                'id' => 1,
                'code' => 'MAIN',
                'name' => 'Main Warehouse',
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now
            ]);
            $content[] = '<li class="correct">inventory_warehouse: เพิ่มคลังหลักเริ่มต้น</li>';
        } elseif (!$db->first($table_warehouse, ['is_default' => 1, 'is_active' => 1])) {
            $rows = $db->search($table_warehouse, [], 1, 0, 'id ASC');
            if (!empty($rows)) {
                $db->update($table_warehouse, ['id' => (int) $rows[0]->id], ['is_default' => 1, 'is_active' => 1]);
                $content[] = '<li class="correct">inventory_warehouse: กำหนดคลังเริ่มต้น</li>';
            }
        }
    }

    $table_shipping = $prefix.'_shipping_method';
    if ($db->tableExists($table_shipping) && tableRowCount($db, $table_shipping) == 0) {
        $now = date('Y-m-d H:i:s');
        $db->insert($table_shipping, [
            'id' => 1,
            'name' => 'Standard Shipping',
            'description' => null,
            'price' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);
        $content[] = '<li class="correct">shipping_method: เพิ่มวิธีจัดส่งเริ่มต้น</li>';
    }
}

/**
 * Normalize migrated customer data into the new schema.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function normalizeCustomerData($db, $prefix, &$content)
{
    $table_customer = $prefix.'_customer';
    if (!$db->tableExists($table_customer)) {
        return;
    }

    $purchase_ids = [];
    $sales_ids = [];
    $table_legacy_orders = $prefix.'_orders';
    if ($db->tableExists($table_legacy_orders)) {
        foreach ($db->customQuery("SELECT `customer_id`, `status` FROM `$table_legacy_orders` WHERE `customer_id` > 0", true) as $row) {
            $customer_id = (int) $row['customer_id'];
            $status = strtoupper(trim((string) $row['status']));
            if (in_array($status, ['PO', 'IN', 'RET'])) {
                $purchase_ids[$customer_id] = true;
            }
            if (in_array($status, ['OUT', 'QUO'])) {
                $sales_ids[$customer_id] = true;
            }
        }
    }

    $updated = 0;
    foreach ($db->search($table_customer, [], 0, 0, 'id ASC') as $customer) {
        $save = [];
        $customer_id = (int) ($customer->id ?? 0);
        $company = trim((string) ($customer->company ?? ''));
        $name = trim((string) ($customer->name ?? ''));
        $contact = trim((string) ($customer->contact ?? ''));
        $type = trim((string) ($customer->type ?? ''));
        $is_purchase = isset($purchase_ids[$customer_id]);
        $is_sales = isset($sales_ids[$customer_id]);

        if (empty($customer->code)) {
            if (!empty($customer->customer_no)) {
                $save['code'] = (string) $customer->customer_no;
            } else {
                $save['code'] = ($is_purchase && !$is_sales ? 'SUP' : 'CUS').sprintf('%04d', $customer_id);
            }
        }
        if (empty($customer->province_id) && !empty($customer->provinceID)) {
            $save['province_id'] = (int) $customer->provinceID;
        }
        if (empty($customer->bank_account) && !empty($customer->bank_no)) {
            $save['bank_account'] = (string) $customer->bank_no;
        }
        if (empty($customer->bank_branch) && !empty($customer->bank)) {
            $save['bank_branch'] = (string) $customer->bank;
        }
        if ($name === '' && $company !== '') {
            $save['name'] = $company;
            $name = $company;
        }
        if ($contact === '' && $company !== '' && $name !== $company) {
            $save['contact'] = $name;
        }
        if ($type === '' || !in_array($type, ['customer', 'supplier'])) {
            $type = $is_purchase && !$is_sales ? 'supplier' : 'customer';
            $save['type'] = $type;
        }
        if (property_exists($customer, 'is_customer')) {
            $target = $is_sales || !$is_purchase ? 1 : 0;
            if ((int) $customer->is_customer !== $target) {
                $save['is_customer'] = $target;
            }
        }
        if (property_exists($customer, 'is_supplier')) {
            $target = $is_purchase ? 1 : 0;
            if ((int) $customer->is_supplier !== $target) {
                $save['is_supplier'] = $target;
            }
        }

        if (!empty($save)) {
            $db->update($table_customer, ['id' => $customer_id], $save);
            ++$updated;
        }
    }

    if ($updated > 0) {
        $content[] = '<li class="correct">customer: ปรับข้อมูลให้ตรงกับ schema ใหม่ '.$updated.' รายการ</li>';
    }
}

/**
 * Normalize product and inventory item data into the new schema.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function normalizeInventoryData($db, $prefix, &$content)
{
    $table_inventory = $prefix.'_inventory';
    $table_inventory_items = $prefix.'_inventory_items';
    if (!$db->tableExists($table_inventory) || !$db->tableExists($table_inventory_items)) {
        return;
    }

    $inventories = [];
    foreach ($db->search($table_inventory, [], 0, 0, 'id ASC') as $inventory) {
        $inventories[(int) $inventory->id] = $inventory;
    }

    $first_sku_by_inventory = [];
    $updated_items = 0;
    foreach ($db->search($table_inventory_items, [], 0, 0, 'inventory_id ASC, id ASC') as $item) {
        $inventory_id = (int) ($item->inventory_id ?? 0);
        $inventory = isset($inventories[$inventory_id]) ? $inventories[$inventory_id] : null;
        $save = [];
        $sku = trim((string) ($item->sku ?? ''));

        if ($sku === '' && !empty($item->product_no)) {
            $sku = (string) $item->product_no;
            $save['sku'] = $sku;
        }
        if ($sku === '') {
            $sku = 'SKU-'.(int) $item->id;
            $save['sku'] = $sku;
        }
        if (!isset($first_sku_by_inventory[$inventory_id])) {
            $first_sku_by_inventory[$inventory_id] = $sku;
        }
        if (empty($item->unit) && $inventory && !empty($inventory->unit)) {
            $save['unit'] = (string) $inventory->unit;
        }
        if ((float) ($item->price ?? 0) <= 0 && $inventory && isset($inventory->cost)) {
            $save['price'] = (float) $inventory->cost;
        }

        if (!empty($save)) {
            $db->update($table_inventory_items, ['id' => (int) $item->id], $save);
            ++$updated_items;
        }
    }

    $updated_inventory = 0;
    foreach ($inventories as $inventory_id => $inventory) {
        $save = [];
        if (empty($inventory->product_code)) {
            $save['product_code'] = isset($first_sku_by_inventory[$inventory_id]) ? $first_sku_by_inventory[$inventory_id] : 'INV'.sprintf('%04d', $inventory_id);
        }
        if (trim((string) ($inventory->topic ?? '')) === '' && !empty($save['product_code'])) {
            $save['topic'] = $save['product_code'];
        }
        $target_stockable = (int) (($inventory->count_stock ?? 1) == 0 ? 0 : 1);
        if ((int) ($inventory->stockable ?? $target_stockable) !== $target_stockable) {
            $save['stockable'] = $target_stockable;
        }

        if (!empty($save)) {
            $db->update($table_inventory, ['id' => $inventory_id], $save);
            ++$updated_inventory;
        }
    }

    if ($updated_items > 0) {
        $content[] = '<li class="correct">inventory_items: ปรับข้อมูลให้ตรงกับ schema ใหม่ '.$updated_items.' รายการ</li>';
    }
    if ($updated_inventory > 0) {
        $content[] = '<li class="correct">inventory: ปรับข้อมูลให้ตรงกับ schema ใหม่ '.$updated_inventory.' รายการ</li>';
    }
}

/**
 * Create minimal inventory and inventory item records for surviving legacy stock rows.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function ensureLegacyStockInventoryRecords($db, $prefix, &$content)
{
    $table_inventory = $prefix.'_inventory';
    $table_inventory_items = $prefix.'_inventory_items';
    $table_stock = $prefix.'_stock';
    if (!$db->tableExists($table_inventory) || !$db->tableExists($table_inventory_items) || !$db->tableExists($table_stock)) {
        return;
    }

    $rows = $db->customQuery(
        "SELECT S.inventory_id, S.product_no, S.topic, S.unit, S.price, S.cut_stock, I.id AS inventory_exists, II.id AS inventory_item_exists
        FROM `$table_stock` S
        LEFT JOIN `$table_inventory` I ON I.id = S.inventory_id
        LEFT JOIN `$table_inventory_items` II ON II.inventory_id = S.inventory_id AND II.sku = S.product_no
        WHERE S.inventory_id > 0 AND S.product_no IS NOT NULL AND S.product_no != '' AND (I.id IS NULL OR II.id IS NULL)
        GROUP BY S.inventory_id, S.product_no
        ORDER BY S.inventory_id, S.product_no",
        false
    );

    $created_inventory = 0;
    $created_items = 0;
    foreach ($rows as $row) {
        $inventory_id = (int) ($row->inventory_id ?? 0);
        $sku = trim((string) ($row->product_no ?? ''));
        if ($inventory_id < 1 || $sku === '') {
            continue;
        }

        if (empty($row->inventory_exists)) {
            $save_inventory = [
                'id' => $inventory_id,
                'category_id' => '0',
                'product_code' => $sku,
                'topic' => normalizeLegacyInventoryTopic($row->topic ?? '', $sku),
                'description' => null,
                'inuse' => 1,
                'cost' => round((float) ($row->price ?? 0), 4),
                'stockable' => 1,
                'allow_negative' => 0
            ];
            if ($db->fieldExists($table_inventory, 'count_stock')) {
                $save_inventory['count_stock'] = 1;
            }
            $db->insert($table_inventory, $save_inventory);
            ++$created_inventory;
        }

        if (empty($row->inventory_item_exists)) {
            $save_item = [
                'sku' => $sku,
                'barcode' => null,
                'inventory_id' => $inventory_id,
                'unit' => empty($row->unit) ? null : (string) $row->unit,
                'stock' => 0,
                'price' => round((float) ($row->price ?? 0), 4)
            ];
            if ($db->fieldExists($table_inventory_items, 'cut_stock')) {
                $save_item['cut_stock'] = round(max(0, (float) ($row->cut_stock ?? 1)), 4) ?: 1;
            }
            $db->insert($table_inventory_items, $save_item);
            ++$created_items;
        }
    }

    if ($created_inventory > 0 || $created_items > 0) {
        $content[] = '<li class="correct">inventory: สร้างสินค้าอ้างอิงจาก stock เดิม '.$created_inventory.' รายการ และ sku '.$created_items.' รายการ</li>';
    }
}

/**
 * @param mixed $topic
 * @param string $fallback
 *
 * @return string
 */
function normalizeLegacyInventoryTopic($topic, $fallback)
{
    $topic = trim(html_entity_decode(strip_tags((string) $topic), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return $topic === '' ? $fallback : $topic;
}

/**
 * Migrate legacy orders/stock into the new order schema and rebuild FIFO stock layers.
 *
 * @param Db $db
 * @param string $prefix
 * @param array $content
 */
function migrateLegacyOrders($db, $prefix, &$content)
{
    $table_legacy_orders = $prefix.'_orders';
    $table_legacy_stock = $prefix.'_stock';
    $table_order = $prefix.'_order';
    $table_order_item = $prefix.'_order_item';
    $table_customer = $prefix.'_customer';
    $table_inventory = $prefix.'_inventory';
    $table_inventory_items = $prefix.'_inventory_items';
    $table_inventory_stock = $prefix.'_inventory_stock';
    $table_inventory_stock_movement = $prefix.'_inventory_stock_movement';
    $table_inventory_cost_layer = $prefix.'_inventory_cost_layer';
    $table_inventory_cost_allocation = $prefix.'_inventory_cost_allocation';

    if (!$db->tableExists($table_legacy_orders) || !$db->tableExists($table_legacy_stock) || !$db->tableExists($table_order)) {
        return;
    }

    $customers = [];
    if ($db->tableExists($table_customer)) {
        foreach ($db->search($table_customer, [], 0, 0, 'id ASC') as $customer) {
            $customers[(int) $customer->id] = $customer;
        }
    }

    $migrated_orders = 0;
    $migrated_items = 0;
    foreach ($db->search($table_legacy_orders, [], 0, 0, 'order_date ASC, id ASC') as $legacy_order) {
        $legacy_order_id = (int) ($legacy_order->id ?? 0);
        if ($legacy_order_id < 1) {
            continue;
        }

        $legacy_items = $db->search($table_legacy_stock, ['order_id' => $legacy_order_id], 0, 0, 'id ASC');
        if (!is_array($legacy_items)) {
            $legacy_items = [];
        }

        if (!$db->first($table_order, ['id' => $legacy_order_id])) {
            $customer = isset($customers[(int) ($legacy_order->customer_id ?? 0)]) ? $customers[(int) $legacy_order->customer_id] : null;
            $document_type = legacyOrderStatusToDocumentType($legacy_order->status ?? 'QUO');
            $summary = summarizeLegacyOrder($legacy_order, $legacy_items);
            $timestamp = normalizeLegacyDateTime($legacy_order->order_date ?? null);
            $partner_name = legacyCustomerDisplayName($customer);
            $partner_address = buildLegacyCustomerAddress($customer);
            $note = trim((string) ($legacy_order->comment ?? ''));
            if ((float) ($legacy_order->tax ?? 0) > 0) {
                $tax_note = 'Migrated withholding tax: '.number_format((float) $legacy_order->tax, 2, '.', '');
                $note = $note === '' ? $tax_note : $note."\n".$tax_note;
            }

            $db->insert($table_order, [
                'id' => $legacy_order_id,
                'order_no' => (string) ($legacy_order->order_no ?? legacyFallbackOrderNo($legacy_order_id, $document_type)),
                'document_type' => $document_type,
                'document_status' => 'issued',
                'payment_status' => 'paid',
                'root_document_id' => $legacy_order_id,
                'reference_document_no' => null,
                'customer_id' => (int) ($legacy_order->customer_id ?? 0) ?: null,
                'customer_name' => $partner_name,
                'customer_phone' => $customer ? (string) ($customer->phone ?? '') : '',
                'customer_tax_id' => $customer ? firstNonEmpty([$customer->tax_id ?? '', $customer->idcard ?? '']) : '',
                'customer_email' => $customer ? (string) ($customer->email ?? '') : '',
                'customer_contact' => $customer ? (string) ($customer->contact ?? '') : '',
                'customer_company' => $customer ? (string) ($customer->company ?? '') : '',
                'member_id' => (int) ($legacy_order->member_id ?? 0) ?: null,
                'subtotal' => $summary['subtotal'],
                'discount_amount' => $summary['discount_amount'],
                'tax_amount' => $summary['tax_amount'],
                'tax_rate' => (float) (($legacy_order->vat_status ?? 0) ? 7 : 0),
                'total' => $summary['total'],
                'paid_amount' => $summary['total'],
                'change_amount' => 0,
                'currency' => 'THB',
                'payment_method' => empty($legacy_order->payment_method) ? null : (string) $legacy_order->payment_method,
                'payment_ref' => null,
                'issued_at' => $timestamp,
                'due_date' => empty($legacy_order->due_date) ? null : $legacy_order->due_date,
                'paid_at' => empty($legacy_order->payment_date) ? $timestamp : $legacy_order->payment_date,
                'completed_at' => $timestamp,
                'cancelled_at' => null,
                'note' => $note,
                'internal_note' => $note,
                'created_at' => $timestamp,
                'updated_at' => $timestamp
            ]);
            ++$migrated_orders;
        }

        foreach ($legacy_items as $legacy_item) {
            $legacy_item_id = (int) ($legacy_item->id ?? 0);
            if ($legacy_item_id < 1 || $db->first($table_order_item, ['id' => $legacy_item_id])) {
                continue;
            }

            $inventory_id = (int) ($legacy_item->inventory_id ?? 0);
            $sku = trim((string) ($legacy_item->product_no ?? ''));
            $inventory_item = resolveInventoryItemBySku($db, $table_inventory_items, $inventory_id, $sku);
            $quantity = normalizeLegacyStockQuantity($legacy_item);
            $unit_price = round((float) ($legacy_item->price ?? 0), 4);
            $line_base = round($quantity * $unit_price, 4);
            $line_subtotal = round((float) ($legacy_item->total ?? 0), 4);
            if ($line_subtotal <= 0 && $line_base > 0) {
                $line_subtotal = $line_base;
            }
            $discount_amount = round(max(0, $line_base - $line_subtotal), 4);

            $db->insert($table_order_item, [
                'id' => $legacy_item_id,
                'order_id' => $legacy_order_id,
                'source_item_id' => null,
                'root_item_id' => null,
                'product_id' => $inventory_id,
                'inventory_item_id' => $inventory_item ? (int) ($inventory_item->id ?? 0) : null,
                'item_id' => 0,
                'product_code' => $sku,
                'name' => trim((string) ($legacy_item->topic ?? '')) === '' ? $sku : (string) $legacy_item->topic,
                'quantity' => $quantity,
                'unit' => empty($legacy_item->unit) ? ($inventory_item ? (string) ($inventory_item->unit ?? '') : '') : (string) $legacy_item->unit,
                'unit_price' => $unit_price,
                'cost_price' => $unit_price,
                'discount_amount' => $discount_amount,
                'tax_amount' => 0,
                'subtotal' => $line_subtotal,
                'note' => null
            ]);
            ++$migrated_items;
        }
    }

    if ($migrated_orders > 0 || $migrated_items > 0) {
        $content[] = '<li class="correct">order: ย้ายข้อมูลเอกสาร '.$migrated_orders.' รายการ และรายการสินค้า '.$migrated_items.' รายการ</li>';
    }

    if (tableRowCount($db, $table_inventory_stock_movement) > 0) {
        $content[] = '<li class="correct">inventory history: พบ movement เดิมอยู่แล้ว จึงไม่ replay stock ซ้ำ</li>';

        return;
    }

    $db->delete($table_inventory_cost_allocation, ['id' => [0]], 0);
    $db->delete($table_inventory_cost_layer, ['id' => [0]], 0);
    $db->delete($table_inventory_stock_movement, ['id' => [0]], 0);
    $db->delete($table_inventory_stock, ['id' => [0]], 0);
    $db->query("UPDATE `$table_inventory_items` SET `stock` = 0");

    $movement_count = 0;
    $layer_count = 0;
    $allocation_count = 0;
    foreach ($db->customQuery("SELECT * FROM `$table_legacy_stock` ORDER BY `create_date` ASC, `id` ASC") as $legacy_stock) {
        $status = strtoupper(trim((string) ($legacy_stock->status ?? '')));
        if (!in_array($status, ['IN', 'RET', 'OUT'])) {
            continue;
        }

        $inventory_id = (int) ($legacy_stock->inventory_id ?? 0);
        $sku = trim((string) ($legacy_stock->product_no ?? ''));
        $quantity = normalizeLegacyStockQuantity($legacy_stock);
        if ($inventory_id < 1 || $sku === '' || $quantity <= 0) {
            continue;
        }

        $inventory_item = resolveInventoryItemBySku($db, $table_inventory_items, $inventory_id, $sku);
        $inventory_item_id = $inventory_item ? (int) ($inventory_item->id ?? 0) : null;
        $reference = buildLegacyStockReference($db, $table_order, $table_order_item, $legacy_stock);
        $timestamp = normalizeLegacyDateTime($legacy_stock->create_date ?? null);
        $created_by = (int) ($legacy_stock->member_id ?? 0) ?: null;
        $note = 'Migrated legacy stock #'.(int) ($legacy_stock->id ?? 0);

        if ($status === 'OUT') {
            updateInventoryItemStockBalance($db, $table_inventory_items, $inventory_id, $sku, -$quantity, $inventory_item_id);
            $allocation_count += postLegacyIssue(
                $db,
                $table_inventory,
                $table_inventory_stock,
                $table_inventory_stock_movement,
                $table_inventory_cost_layer,
                $table_inventory_cost_allocation,
                $inventory_id,
                $inventory_item_id,
                $sku,
                $quantity,
                'sale',
                $reference['type'],
                $reference['id'],
                $reference['no'],
                $reference['item_id'],
                $note,
                $created_by,
                $timestamp
            );
            ++$movement_count;
        } else {
            updateInventoryItemStockBalance($db, $table_inventory_items, $inventory_id, $sku, $quantity, $inventory_item_id);
            postLegacyReceipt(
                $db,
                $table_inventory_stock,
                $table_inventory_stock_movement,
                $table_inventory_cost_layer,
                $inventory_id,
                $inventory_item_id,
                $sku,
                $quantity,
                round((float) ($legacy_stock->price ?? 0), 4),
                'purchase',
                $reference['type'],
                $reference['id'],
                $reference['no'],
                $reference['item_id'],
                $note,
                $created_by,
                $timestamp
            );
            ++$movement_count;
            ++$layer_count;
        }
    }

    refreshInventoryCostsFromLayers($db, $table_inventory, $table_inventory_cost_layer);
    $content[] = '<li class="correct">inventory history: replay stock สำเร็จ movement '.$movement_count.' รายการ, layer '.$layer_count.' รายการ, allocation '.$allocation_count.' รายการ</li>';
}

/**
 * @param Db $db
 * @param string $prefix
 *
 * @return int|null
 */
function getDefaultWarehouseId($db, $prefix)
{
    $table_warehouse = $prefix.'_inventory_warehouse';
    if (!$db->tableExists($table_warehouse)) {
        return null;
    }
    $warehouse = $db->first($table_warehouse, ['is_default' => 1, 'is_active' => 1]);
    if ($warehouse) {
        return (int) $warehouse->id;
    }
    $rows = $db->search($table_warehouse, [], 1, 0, 'id ASC');

    return empty($rows) ? null : (int) $rows[0]->id;
}

/**
 * @param mixed $status
 *
 * @return string
 */
function legacyOrderStatusToDocumentType($status)
{
    switch (strtoupper(trim((string) $status))) {
    case 'OUT':
        return 'RCP';
    case 'PO':
        return 'PO';
    case 'IN':
    case 'RET':
        return 'GR';
    case 'QUO':
    default:
        return 'QT';
    }
}

/**
 * @param int $order_id
 * @param string $document_type
 *
 * @return string
 */
function legacyFallbackOrderNo($order_id, $document_type)
{
    return $document_type.sprintf('%04d', $order_id);
}

/**
 * @param mixed $date
 *
 * @return string
 */
function normalizeLegacyDateTime($date)
{
    if (empty($date)) {
        return date('Y-m-d H:i:s');
    }

    $date = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date.' 00:00:00';
    }

    return $date;
}

/**
 * @param object|null $customer
 *
 * @return string
 */
function legacyCustomerDisplayName($customer)
{
    if (!$customer) {
        return '';
    }

    return firstNonEmpty([
        $customer->company ?? '',
        $customer->name ?? '',
        $customer->code ?? ''
    ]);
}

/**
 * @param object|null $customer
 *
 * @return string
 */
function buildLegacyCustomerAddress($customer)
{
    if (!$customer) {
        return '';
    }

    return trim(implode(' ', array_filter([
        trim((string) ($customer->address ?? '')),
        trim((string) ($customer->province ?? '')),
        trim((string) ($customer->zipcode ?? ''))
    ])));
}

/**
 * @param array $values
 *
 * @return string
 */
function firstNonEmpty($values)
{
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * @param object $legacy_order
 * @param array $legacy_items
 *
 * @return array
 */
function summarizeLegacyOrder($legacy_order, $legacy_items)
{
    $subtotal = 0;
    foreach ($legacy_items as $legacy_item) {
        $line_subtotal = round((float) ($legacy_item->total ?? 0), 4);
        if ($line_subtotal <= 0) {
            $quantity = normalizeLegacyStockQuantity($legacy_item);
            $line_subtotal = round((float) ($legacy_item->price ?? 0) * $quantity, 4);
        }
        $subtotal += $line_subtotal;
    }
    $subtotal = round($subtotal + (float) ($legacy_order->discount ?? 0), 4);
    $discount_amount = round((float) ($legacy_order->discount ?? 0) + (float) ($legacy_order->tax ?? 0), 4);
    $tax_amount = round((float) ($legacy_order->vat ?? 0), 4);
    $total = round($subtotal - $discount_amount + $tax_amount, 4);

    return [
        'subtotal' => max(0, $subtotal),
        'discount_amount' => max(0, $discount_amount),
        'tax_amount' => max(0, $tax_amount),
        'total' => max(0, $total)
    ];
}

/**
 * @param object $legacy_item
 *
 * @return float
 */
function normalizeLegacyStockQuantity($legacy_item)
{
    $quantity = (float) ($legacy_item->quantity ?? 0);
    $cut_stock = (float) ($legacy_item->cut_stock ?? 1);
    if ($cut_stock <= 0) {
        $cut_stock = 1;
    }

    return round(max(0, $quantity * $cut_stock), 4);
}

/**
 * @param Db $db
 * @param string $table_inventory_items
 * @param int $inventory_id
 * @param string $sku
 *
 * @return object|false
 */
function resolveInventoryItemBySku($db, $table_inventory_items, $inventory_id, $sku)
{
    $sku = trim((string) $sku);
    if ($inventory_id < 1 || $sku === '') {
        return false;
    }

    $rows = $db->customQuery(
        "SELECT * FROM `$table_inventory_items` WHERE `inventory_id` = :inventory_id AND `sku` = :sku ORDER BY `id` ASC LIMIT 1",
        false,
        [
            ':inventory_id' => $inventory_id,
            ':sku' => $sku
        ]
    );

    return empty($rows) ? false : $rows[0];
}

/**
 * @param Db $db
 * @param string $table_order
 * @param string $table_order_item
 * @param object $legacy_stock
 *
 * @return array
 */
function buildLegacyStockReference($db, $table_order, $table_order_item, $legacy_stock)
{
    $reference = [
        'type' => 'opening',
        'id' => (int) ($legacy_stock->id ?? 0),
        'no' => 'OPEN-'.(int) ($legacy_stock->id ?? 0),
        'item_id' => null
    ];

    $legacy_order_id = (int) ($legacy_stock->order_id ?? 0);
    if ($legacy_order_id < 1) {
        return $reference;
    }

    $order = $db->first($table_order, ['id' => $legacy_order_id]);
    if (!$order) {
        return $reference;
    }

    $reference['type'] = 'order';
    $reference['id'] = (int) $order->id;
    $reference['no'] = (string) ($order->order_no ?? $reference['no']);
    $order_item = $db->first($table_order_item, ['id' => (int) ($legacy_stock->id ?? 0)]);
    $reference['item_id'] = $order_item ? (int) $order_item->id : null;

    return $reference;
}

/**
 * @param Db $db
 * @param string $table_inventory_items
 * @param int $inventory_id
 * @param string $sku
 * @param float $delta
 * @param int|null $inventory_item_id
 */
function updateInventoryItemStockBalance($db, $table_inventory_items, $inventory_id, $sku, $delta, $inventory_item_id = null)
{
    if ($inventory_id < 1 || trim($sku) === '' || abs((float) $delta) < 0.00005) {
        return;
    }

    if ($inventory_item_id !== null && $inventory_item_id > 0) {
        $item = $db->first($table_inventory_items, ['id' => $inventory_item_id]);
        if ($item) {
            $db->update($table_inventory_items, ['id' => $inventory_item_id], [
                'stock' => round((float) ($item->stock ?? 0) + $delta, 4)
            ]);

            return;
        }
    }

    $item = resolveInventoryItemBySku($db, $table_inventory_items, $inventory_id, $sku);
    if ($item) {
        $db->update($table_inventory_items, ['id' => (int) $item->id], [
            'stock' => round((float) ($item->stock ?? 0) + $delta, 4)
        ]);
    }
}

/**
 * @param Db $db
 * @param string $table_inventory_stock
 * @param int $inventory_id
 * @param int|null $inventory_item_id
 * @param string $sku
 * @param float $delta
 */
function updateInventoryStockAggregate($db, $table_inventory_stock, $inventory_id, $inventory_item_id, $sku, $delta)
{
    if ($inventory_id < 1 || trim($sku) === '' || abs((float) $delta) < 0.00005) {
        return;
    }

    $rows = $db->customQuery(
        "SELECT * FROM `$table_inventory_stock` WHERE `inventory_id` = :inventory_id  AND `sku` = :sku LIMIT 1",
        false,
        [
            ':inventory_id' => $inventory_id,
            ':sku' => $sku
        ]
    );
    if (!empty($rows)) {
        $stock = $rows[0];
        $db->update($table_inventory_stock, ['id' => (int) $stock->id], [
            'qty' => round((float) ($stock->qty ?? 0) + $delta, 4)
        ]);

        return;
    }

    $db->insert($table_inventory_stock, [
        'inventory_id' => $inventory_id,
        'inventory_item_id' => $inventory_item_id,
        'sku' => $sku,
        'qty' => round((float) $delta, 4),
        'reserved_qty' => 0
    ]);
}

/**
 * @param Db $db
 * @param string $table_inventory_stock
 * @param string $table_inventory_stock_movement
 * @param string $table_inventory_cost_layer
 * @param int $inventory_id
 * @param int|null $inventory_item_id
 * @param string $sku
 * @param float $quantity
 * @param float $unit_cost
 * @param string $movement_type
 * @param string $reference_type
 * @param int|null $reference_id
 * @param string $reference_no
 * @param int|null $reference_item_id
 * @param string $note
 * @param int|null $created_by
 * @param string $timestamp
 */
function postLegacyReceipt($db, $table_inventory_stock, $table_inventory_stock_movement, $table_inventory_cost_layer, $inventory_id, $inventory_item_id, $sku, $quantity, $unit_cost, $movement_type, $reference_type, $reference_id, $reference_no, $reference_item_id, $note, $created_by, $timestamp)
{
    $total_cost = round($quantity * $unit_cost, 4);
    $db->insert($table_inventory_stock_movement, [
        'inventory_id' => $inventory_id,
        'inventory_item_id' => $inventory_item_id,
        'sku' => $sku,
        'movement_direction' => 'in',
        'movement_type' => $movement_type,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'reference_no' => $reference_no,
        'reference_item_id' => $reference_item_id,
        'source_movement_id' => null,
        'quantity' => round($quantity, 4),
        'unit_cost' => round($unit_cost, 4),
        'total_cost' => $total_cost,
        'note' => $note,
        'occurred_at' => $timestamp,
        'created_by' => $created_by,
        'created_at' => $timestamp
    ]);
    $db->insert($table_inventory_cost_layer, [
        'inventory_id' => $inventory_id,
        'inventory_item_id' => $inventory_item_id,
        'sku' => $sku,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'reference_no' => $reference_no,
        'reference_item_id' => $reference_item_id,
        'source_allocation_id' => null,
        'received_qty' => round($quantity, 4),
        'remaining_qty' => round($quantity, 4),
        'unit_cost' => round($unit_cost, 4),
        'currency' => 'THB',
        'note' => $note,
        'received_at' => $timestamp,
        'created_by' => $created_by,
        'created_at' => $timestamp
    ]);
    updateInventoryStockAggregate($db, $table_inventory_stock, $inventory_id, $inventory_item_id, $sku, $quantity);
}

/**
 * @param Db $db
 * @param string $table_inventory
 * @param string $table_inventory_stock
 * @param string $table_inventory_stock_movement
 * @param string $table_inventory_cost_layer
 * @param string $table_inventory_cost_allocation
 * @param int $inventory_id
 * @param int|null $inventory_item_id
 * @param string $sku
 * @param float $quantity
 * @param string $movement_type
 * @param string $reference_type
 * @param int|null $reference_id
 * @param string $reference_no
 * @param int|null $reference_item_id
 * @param string $note
 * @param int|null $created_by
 * @param string $timestamp
 *
 * @return int
 */
function postLegacyIssue($db, $table_inventory, $table_inventory_stock, $table_inventory_stock_movement, $table_inventory_cost_layer, $table_inventory_cost_allocation, $inventory_id, $inventory_item_id, $sku, $quantity, $movement_type, $reference_type, $reference_id, $reference_no, $reference_item_id, $note, $created_by, $timestamp)
{
    $movement_id = (int) $db->insert($table_inventory_stock_movement, [
        'inventory_id' => $inventory_id,
        'inventory_item_id' => $inventory_item_id,
        'sku' => $sku,
        'movement_direction' => 'out',
        'movement_type' => $movement_type,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'reference_no' => $reference_no,
        'reference_item_id' => $reference_item_id,
        'source_movement_id' => null,
        'quantity' => round($quantity, 4),
        'unit_cost' => 0,
        'total_cost' => 0,
        'note' => $note,
        'occurred_at' => $timestamp,
        'created_by' => $created_by,
        'created_at' => $timestamp
    ]);

    $remaining = round($quantity, 4);
    $total_cost = 0.0;
    $allocation_count = 0;
    $sql = "SELECT `id`, `remaining_qty`, `unit_cost` FROM `$table_inventory_cost_layer` WHERE `inventory_id` = :inventory_id AND `sku` = :sku AND `remaining_qty` > 0";
    $params = [
        ':inventory_id' => $inventory_id,
        ':sku' => $sku
    ];
    if ($inventory_item_id !== null && $inventory_item_id > 0) {
        $sql .= ' AND (`inventory_item_id` = :inventory_item_id OR `inventory_item_id` IS NULL)';
        $params[':inventory_item_id'] = $inventory_item_id;
    }
    $sql .= ' ORDER BY `received_at` ASC, `id` ASC';

    foreach ($db->customQuery($sql, false, $params) as $layer) {
        if ($remaining <= 0) {
            break;
        }
        $available = round((float) ($layer->remaining_qty ?? 0), 4);
        if ($available <= 0) {
            continue;
        }
        $allocated_qty = min($remaining, $available);
        $unit_cost = round((float) ($layer->unit_cost ?? 0), 4);
        $allocation_cost = round($allocated_qty * $unit_cost, 4);
        $db->insert($table_inventory_cost_allocation, [
            'layer_id' => (int) $layer->id,
            'inventory_id' => $inventory_id,
            'inventory_item_id' => $inventory_item_id,
            'sku' => $sku,
            'movement_id' => $movement_id,
            'source_allocation_id' => null,
            'reference_type' => $reference_type,
            'reference_id' => $reference_id,
            'reference_no' => $reference_no,
            'reference_item_id' => $reference_item_id,
            'quantity' => $allocated_qty,
            'unit_cost' => $unit_cost,
            'total_cost' => $allocation_cost,
            'note' => $note,
            'created_by' => $created_by,
            'created_at' => $timestamp
        ]);
        $db->update($table_inventory_cost_layer, ['id' => (int) $layer->id], [
            'remaining_qty' => round($available - $allocated_qty, 4)
        ]);
        $remaining = round($remaining - $allocated_qty, 4);
        $total_cost = round($total_cost + $allocation_cost, 4);
        ++$allocation_count;
    }

    if ($remaining > 0) {
        $inventory = $db->first($table_inventory, ['id' => $inventory_id]);
        $fallback_unit_cost = round((float) ($inventory->cost ?? 0), 4);
        $total_cost = round($total_cost + ($remaining * $fallback_unit_cost), 4);
    }

    $db->update($table_inventory_stock_movement, ['id' => $movement_id], [
        'unit_cost' => $quantity > 0 ? round($total_cost / $quantity, 4) : 0,
        'total_cost' => $total_cost
    ]);
    updateInventoryStockAggregate($db, $table_inventory_stock, $inventory_id, $inventory_item_id, $sku, -$quantity);

    return $allocation_count;
}

/**
 * @param Db $db
 * @param string $table_inventory
 * @param string $table_inventory_cost_layer
 */
function refreshInventoryCostsFromLayers($db, $table_inventory, $table_inventory_cost_layer)
{
    foreach ($db->customQuery(
        "SELECT `inventory_id`, SUM(`remaining_qty`) AS `qty`, SUM(`remaining_qty` * `unit_cost`) AS `cost_total` FROM `$table_inventory_cost_layer` WHERE `remaining_qty` > 0 GROUP BY `inventory_id`",
        true
    ) as $row) {
        $inventory_id = (int) $row['inventory_id'];
        $qty = (float) $row['qty'];
        if ($inventory_id < 1 || $qty <= 0) {
            continue;
        }
        $db->update($table_inventory, ['id' => $inventory_id], [
            'cost' => round(((float) $row['cost_total']) / $qty, 4)
        ]);
    }
}

/**
 * @param Db $db
 * @param string $table_name
 *
 * @return int
 */
function tableRowCount($db, $table_name)
{
    if (!$db->tableExists($table_name)) {
        return 0;
    }

    $result = $db->customQuery("SELECT COUNT(*) AS `count` FROM `$table_name`", true);

    return empty($result) ? 0 : (int) $result[0]['count'];
}

/**
 * Sync tables, columns, indexes, and AUTO_INCREMENT clauses from install/database.sql.
 * The upgrader reads the current database schema first and only applies missing or mismatched parts.
 *
 * @param Db $db
 * @param string $schema_file
 * @param string $prefix
 * @param array $content
 */
function syncSchemaFromSql($db, $schema_file, $prefix, &$content)
{
    $schema = loadSchemaFromSql($schema_file, $prefix);
    $schema_defaults = getUpgradeSchemaDefaults($db);
    $content[] = '<li class="correct">ฐานข้อมูล: ใช้ ENGINE='.strtoupper($schema_defaults['engine']).', CHARSET='.$schema_defaults['charset'].', COLLATE='.$schema_defaults['collation'].'</li>';
    foreach ($schema['tables'] as $table_name => $table) {
        if (!$db->tableExists($table_name)) {
            $db->query($table['sql']);
            $content[] = '<li class="correct">'.$table_name.': สร้างตารางจาก database.sql</li>';
        }
    }
    foreach ($schema['tables'] as $table_name => $table) {
        if (!$db->tableExists($table_name)) {
            continue;
        }
        syncTableOptionsFromSchema($db, $table_name, $table['options'], $schema_defaults, $content);
        syncTableColumnsFromSchema($db, $table_name, $table['columns'], $schema_defaults, $content);
    }
    foreach ($schema['alters'] as $table_name => $clauses) {
        if (!$db->tableExists($table_name)) {
            continue;
        }
        foreach ($clauses as $clause) {
            syncTableAlterClauseFromSchema($db, $table_name, $clause, $content);
        }
    }
}

/**
 * Parse CREATE TABLE and ALTER TABLE statements from install/database.sql.
 *
 * @param string $schema_file
 * @param string $prefix
 *
 * @return array
 */
function loadSchemaFromSql($schema_file, $prefix)
{
    /**
     * @var array
     */
    static $cache = [];

    $cache_key = $schema_file.'|'.$prefix;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $sql = @file_get_contents($schema_file);
    if ($sql === false) {
        throw new \Exception('ไม่สามารถอ่านไฟล์ install/database.sql ได้');
    }
    if (strpos($sql, '{prefix}') !== false) {
        $sql = str_replace('{prefix}', $prefix, $sql);
    } else {
        $sql = preg_replace('/`oas_([a-z0-9_]+)`/i', '`'.$prefix.'_$1`', $sql);
    }

    $schema = [
        'tables' => [],
        'alters' => []
    ];

    if (preg_match_all('/CREATE TABLE\s+`([^`]+)`\s*\((.*?)\)\s*(ENGINE=.*?);/is', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $table_name = $match[1];
            $columns = [];
            foreach (preg_split('/\R/', trim($match[2])) as $line) {
                $line = trim(rtrim($line, ','));
                if ($line === '' || $line[0] !== '`') {
                    continue;
                }
                if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $column_match)) {
                    $columns[$column_match[1]] = $column_match[2];
                }
            }
            $schema['tables'][$table_name] = [
                'sql' => trim($match[0]),
                'columns' => $columns,
                'options' => parseTableOptionsFromSql($match[3])
            ];
        }
    }

    if (preg_match_all('/ALTER TABLE\s+`([^`]+)`\s*(.*?);/is', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $table_name = $match[1];
            if (!isset($schema['alters'][$table_name])) {
                $schema['alters'][$table_name] = [];
            }
            foreach (preg_split('/\R/', trim($match[2])) as $line) {
                $line = trim(rtrim($line, ','));
                if ($line !== '') {
                    $schema['alters'][$table_name][] = $line;
                }
            }
        }
    }

    $cache[$cache_key] = $schema;

    return $schema;
}

/**
 * Parse ENGINE / CHARSET / COLLATE table options.
 *
 * @param string $options_sql
 *
 * @return array
 */
function parseTableOptionsFromSql($options_sql)
{
    $options = [];
    if (preg_match('/ENGINE\s*=\s*([a-z0-9_]+)/i', $options_sql, $match)) {
        $options['engine'] = strtolower($match[1]);
    }
    if (preg_match('/DEFAULT\s+CHARSET\s*=\s*([a-z0-9_]+)/i', $options_sql, $match)) {
        $options['charset'] = strtolower($match[1]);
    }
    if (preg_match('/COLLATE\s*=\s*([a-z0-9_]+)/i', $options_sql, $match)) {
        $options['collation'] = strtolower($match[1]);
    }

    return $options;
}

/**
 * Resolve the preferred engine/charset/collation for upgrades.
 * Prioritize Thai-aware utf8mb4 collation when the server supports it.
 *
 * @param Db $db
 *
 * @return array
 */
function getUpgradeSchemaDefaults($db)
{
    /**
     * @var array|null
     */
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'engine' => 'innodb',
        'charset' => 'utf8mb4',
        'collation' => resolveSupportedUpgradeCollation($db, [
            'utf8mb4_unicode_ci',
            'utf8mb4_general_ci'
        ])
    ];

    return $cache;
}

/**
 * Pick the first available collation from the preferred list.
 *
 * @param Db $db
 * @param array $collations
 *
 * @return string
 */
function resolveSupportedUpgradeCollation($db, $collations)
{
    foreach ($collations as $collation) {
        $result = $db->customQuery("SHOW COLLATION LIKE '$collation'", true);
        if (!empty($result)) {
            return strtolower($result[0]['Collation']);
        }
    }

    throw new \Exception('Server นี้ไม่รองรับ utf8mb4 collation ที่ต้องใช้สำหรับการปรับรุ่น');
}

/**
 * Ensure table engine / charset / collation matches database.sql.
 *
 * @param Db $db
 * @param string $table_name
 * @param array $target_options
 * @param array $schema_defaults
 * @param array $content
 */
function syncTableOptionsFromSchema($db, $table_name, $target_options, $schema_defaults, &$content)
{
    $status = getTableStatus($db, $table_name);
    if (empty($status)) {
        return;
    }

    $sql = [];
    if (!empty($schema_defaults['engine']) && !empty($status['Engine']) && strtolower($status['Engine']) !== $schema_defaults['engine']) {
        $sql[] = 'ENGINE='.$schema_defaults['engine'];
    }

    if (!empty($schema_defaults['charset'])) {
        $current_collation = empty($status['Collation']) ? '' : strtolower($status['Collation']);
        $target_collation = empty($schema_defaults['collation']) ? '' : $schema_defaults['collation'];
        $needs_convert = false;
        if ($target_collation !== '') {
            $needs_convert = $current_collation !== $target_collation;
        } else {
            $needs_convert = $current_collation === '' || strpos($current_collation, $schema_defaults['charset'].'_') !== 0;
        }
        if ($needs_convert) {
            $convert = 'CONVERT TO CHARACTER SET '.$schema_defaults['charset'];
            if ($target_collation !== '') {
                $convert .= ' COLLATE '.$target_collation;
            }
            $sql[] = $convert;
        }
    }

    if (!empty($sql)) {
        $db->query("ALTER TABLE `$table_name` ".implode(', ', $sql));
        $content[] = '<li class="correct">'.$table_name.': ปรับ ENGINE/CHARSET/COLLATE เป็น '.strtoupper($schema_defaults['engine']).' / '.$schema_defaults['charset'].' / '.$schema_defaults['collation'].'</li>';
    }
}

/**
 * Ensure columns from database.sql exist and match the target definition.
 *
 * @param Db $db
 * @param string $table_name
 * @param array $target_columns
 * @param array $schema_defaults
 * @param array $content
 */
function syncTableColumnsFromSchema($db, $table_name, $target_columns, $schema_defaults, &$content)
{
    $current_columns = getTableColumns($db, $table_name);
    foreach ($target_columns as $column_name => $definition) {
        $definition = applyUpgradeSchemaDefaultsToColumn($definition, $schema_defaults);
        if (!isset($current_columns[$column_name])) {
            $db->query("ALTER TABLE `$table_name` ADD `$column_name` $definition");
            $content[] = '<li class="correct">'.$table_name.': เพิ่มคอลัมน์ '.$column_name.'</li>';
            $current_columns = getTableColumns($db, $table_name);
            continue;
        }
        if (columnNeedsSync($current_columns[$column_name], $definition)) {
            $db->query("ALTER TABLE `$table_name` MODIFY `$column_name` $definition");
            $content[] = '<li class="correct">'.$table_name.': ปรับคอลัมน์ '.$column_name.'</li>';
            $current_columns = getTableColumns($db, $table_name);
        }
    }
}

/**
 * Normalize text column definitions to the chosen upgrade charset/collation.
 *
 * @param string $definition
 * @param array $schema_defaults
 *
 * @return string
 */
function applyUpgradeSchemaDefaultsToColumn($definition, $schema_defaults)
{
    $definition = trim(preg_replace('/\s+character set\s+[a-z0-9_]+/i', '', $definition));
    $definition = trim(preg_replace('/\s+collate\s+[a-z0-9_]+/i', '', $definition));

    $parsed = parseColumnDefinition($definition);
    if (!isCharacterColumnType($parsed['type'])) {
        return $definition;
    }

    if (preg_match('/^(.*?)(\s+(?:not null|null|default|auto_increment)\b.*)?$/i', $definition, $match)) {
        $type = trim($match[1]);
        $suffix = empty($match[2]) ? '' : ' '.trim($match[2]);

        return $type.' CHARACTER SET '.$schema_defaults['charset'].' COLLATE '.$schema_defaults['collation'].$suffix;
    }

    return $definition.' CHARACTER SET '.$schema_defaults['charset'].' COLLATE '.$schema_defaults['collation'];
}

/**
 * Determine whether a column type supports charset/collation attributes.
 *
 * @param string $type
 *
 * @return bool
 */
function isCharacterColumnType($type)
{
    return preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/i', $type) === 1;
}

/**
 * Ensure an ALTER TABLE clause from database.sql is applied when needed.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $clause
 * @param array $content
 */
function syncTableAlterClauseFromSchema($db, $table_name, $clause, &$content)
{
    if (preg_match('/^ADD PRIMARY KEY\s*\((.+)\)$/i', $clause, $match)) {
        $indexes = getTableIndexes($db, $table_name);
        $target_columns = normalizeIndexColumnsSql($match[1]);
        if (!isset($indexes['PRIMARY'])) {
            $db->query("ALTER TABLE `$table_name` $clause");
            $content[] = '<li class="correct">'.$table_name.': เพิ่ม PRIMARY KEY</li>';
        } elseif (normalizeCurrentIndexColumns($indexes['PRIMARY']) !== $target_columns) {
            $db->query("ALTER TABLE `$table_name` DROP PRIMARY KEY, $clause");
            $content[] = '<li class="correct">'.$table_name.': ปรับ PRIMARY KEY ให้ตรงกับ database.sql</li>';
        }

        return;
    }

    if (preg_match('/^ADD UNIQUE KEY `([^`]+)`\s*\((.+)\)$/i', $clause, $match)) {
        syncNamedIndexFromSchema($db, $table_name, $match[1], $match[2], true, $clause, $content);

        return;
    }

    if (preg_match('/^ADD KEY `([^`]+)`\s*\((.+)\)$/i', $clause, $match)) {
        syncNamedIndexFromSchema($db, $table_name, $match[1], $match[2], false, $clause, $content);

        return;
    }

    if (preg_match('/^MODIFY `([^`]+)`\s+(.+)$/i', $clause, $match)) {
        $columns = getTableColumns($db, $table_name);
        if (!isset($columns[$match[1]]) || columnNeedsSync($columns[$match[1]], $match[2])) {
            $db->query("ALTER TABLE `$table_name` $clause");
            $content[] = '<li class="correct">'.$table_name.': ปรับ '.$match[1].' ตาม database.sql</li>';
        }
    }
}

/**
 * Ensure a named index matches database.sql.
 *
 * @param Db $db
 * @param string $table_name
 * @param string $index_name
 * @param string $columns_sql
 * @param bool $unique
 * @param string $clause
 * @param array $content
 */
function syncNamedIndexFromSchema($db, $table_name, $index_name, $columns_sql, $unique, $clause, &$content)
{
    $indexes = getTableIndexes($db, $table_name);
    $target_columns = normalizeIndexColumnsSql($columns_sql);
    if (!isset($indexes[$index_name])) {
        $db->query("ALTER TABLE `$table_name` $clause");
        $content[] = '<li class="correct">'.$table_name.': เพิ่ม index '.$index_name.'</li>';

        return;
    }

    $current_unique = (int) $indexes[$index_name][0]['Non_unique'] === 0;
    $current_columns = normalizeCurrentIndexColumns($indexes[$index_name]);
    if ($current_unique !== $unique || $current_columns !== $target_columns) {
        $db->query("ALTER TABLE `$table_name` DROP INDEX `$index_name`, $clause");
        $content[] = '<li class="correct">'.$table_name.': ปรับ index '.$index_name.'</li>';
    }
}

/**
 * Load column metadata for a table.
 *
 * @param Db $db
 * @param string $table_name
 *
 * @return array
 */
function getTableColumns($db, $table_name)
{
    $columns = [];
    foreach ($db->customQuery("SHOW FULL COLUMNS FROM `$table_name`", true) as $column) {
        $columns[$column['Field']] = $column;
    }

    return $columns;
}

/**
 * Load index metadata for a table.
 *
 * @param Db $db
 * @param string $table_name
 *
 * @return array
 */
function getTableIndexes($db, $table_name)
{
    $indexes = [];
    foreach ($db->customQuery("SHOW INDEX FROM `$table_name`", true) as $index) {
        if (!isset($indexes[$index['Key_name']])) {
            $indexes[$index['Key_name']] = [];
        }
        $indexes[$index['Key_name']][] = $index;
    }

    return $indexes;
}

/**
 * Load table status metadata.
 *
 * @param Db $db
 * @param string $table_name
 *
 * @return array
 */
function getTableStatus($db, $table_name)
{
    $escaped = str_replace("'", "''", $table_name);
    $result = $db->customQuery("SHOW TABLE STATUS LIKE '$escaped'", true);

    return empty($result) ? [] : $result[0];
}

/**
 * Check whether a column differs from the target SQL definition.
 *
 * @param array $current_column
 * @param string $target_definition
 *
 * @return bool
 */
function columnNeedsSync($current_column, $target_definition)
{
    $target = parseColumnDefinition($target_definition);
    if (normalizeSqlFragment($current_column['Type']) !== $target['type']) {
        return true;
    }
    if ($target['collation'] !== '' && strtolower((string) $current_column['Collation']) !== $target['collation']) {
        return true;
    }
    if (($current_column['Null'] === 'YES') !== $target['nullable']) {
        return true;
    }
    $current_default = normalizeDefaultValue($current_column['Default']);
    $target_default = $target['default']['specified'] ? $target['default']['value'] : null;
    if ($current_default !== $target_default) {
        return true;
    }

    $current_auto_increment = stripos((string) $current_column['Extra'], 'auto_increment') !== false;

    return $current_auto_increment !== $target['auto_increment'];
}

/**
 * Parse a column definition body from database.sql.
 *
 * @param string $definition
 *
 * @return array
 */
function parseColumnDefinition($definition)
{
    $definition = trim($definition);
    $type_definition = preg_replace('/\s+character set\s+[a-z0-9_]+/i', '', $definition);
    $type_definition = preg_replace('/\s+collate\s+[a-z0-9_]+/i', '', $type_definition);

    $type = $type_definition;
    if (preg_match('/^(.*?)(?=\s+(?:not null|null|default|auto_increment)\b|$)/i', $type_definition, $match)) {
        $type = $match[1];
    }

    $default_specified = false;
    $default_value = null;
    if (preg_match('/\bdefault\s+(.+?)(?=\s+auto_increment\b|$)/i', $definition, $match)) {
        $default_specified = true;
        $default_value = normalizeDefaultValue($match[1]);
    }

    return [
        'type' => normalizeSqlFragment($type),
        'collation' => preg_match('/\bcollate\s+([a-z0-9_]+)/i', $definition, $match) ? strtolower($match[1]) : '',
        'nullable' => !preg_match('/\bnot null\b/i', $definition),
        'default' => [
            'specified' => $default_specified,
            'value' => $default_value
        ],
        'auto_increment' => preg_match('/\bauto_increment\b/i', $definition) === 1
    ];
}

/**
 * Normalize defaults from SHOW FULL COLUMNS / database.sql.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function normalizeDefaultValue($value)
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (strcasecmp($value, 'null') === 0) {
        return null;
    }
    if (($value[0] === "'" && substr($value, -1) === "'") || ($value[0] === '"' && substr($value, -1) === '"')) {
        $value = substr($value, 1, -1);
    }
    if ($value === "''" || $value === '""') {
        return '';
    }

    return $value;
}

/**
 * Normalize an index column list from database.sql.
 *
 * @param string $columns_sql
 *
 * @return string
 */
function normalizeIndexColumnsSql($columns_sql)
{
    $normalized = [];
    foreach (explode(',', $columns_sql) as $column_sql) {
        $column_sql = normalizeSqlFragment($column_sql);
        $column_sql = preg_replace('/\s+asc$/', '', $column_sql);
        $normalized[] = $column_sql;
    }

    return implode(', ', $normalized);
}

/**
 * Normalize current index columns from SHOW INDEX metadata.
 *
 * @param array $index_rows
 *
 * @return string
 */
function normalizeCurrentIndexColumns($index_rows)
{
    usort($index_rows, function ($a, $b) {
        return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
    });

    $normalized = [];
    foreach ($index_rows as $row) {
        $column_sql = '`'.strtolower($row['Column_name']).'`';
        if (isset($row['Collation']) && strtoupper((string) $row['Collation']) === 'D') {
            $column_sql .= ' desc';
        }
        $normalized[] = $column_sql;
    }

    return implode(', ', $normalized);
}

/**
 * Normalize SQL fragments for simple comparisons.
 *
 * @param string $sql
 *
 * @return string
 */
function normalizeSqlFragment($sql)
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
}
