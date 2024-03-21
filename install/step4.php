<?php
if (defined('ROOT_PATH')) {
    // ค่าที่ส่งมา
    $_SESSION['db_username'] = $_POST['db_username'];
    $_SESSION['db_password'] = $_POST['db_password'];
    $_SESSION['db_server'] = $_POST['db_server'];
    $_SESSION['db_port'] = preg_replace('/[^0-9]+/', '', $_POST['db_port']);
    $_SESSION['db_name'] = preg_replace('/[^a-zA-Z0-9_]+/', '', $_POST['db_name']);
    $_SESSION['prefix'] = preg_replace('/[^a-zA-Z0-9_]+/', '', $_POST['prefix']);
    $content = [];
    $error = false;
    // Database Class
    include ROOT_PATH.'install/db.php';
    try {
        // เขื่อมต่อฐานข้อมูล
        $db = new Db(array(
            'dbname' => 'INFORMATION_SCHEMA',
            'username' => $_SESSION['db_username'],
            'password' => $_SESSION['db_password'],
            'port' => $_SESSION['db_port'],
            'hostname' => $_SESSION['db_server']
        ));
        $db_name = $db->databaseExists($_SESSION['db_name']);
        if (!$db_name) {
            $db->query('CREATE DATABASE '.$_SESSION['db_name'].' CHARACTER SET utf8');
        }
        $db->query('USE '.$_SESSION['db_name']);
    } catch (\PDOException $e) {
        $error = true;
        echo '<h2>ความผิดพลาดในการเชื่อมต่อกับฐานข้อมูล</h2>';
        echo '<p class=warning>'.$e->getMessage().'</p>';
        echo '<p>อาจเป็นไปได้ว่า</p>';
        echo '<ol>';
        echo '<li>เซิร์ฟเวอร์ของฐานข้อมูลของคุณไม่สามารถใช้งานได้ในขณะนี้</li>';
        echo '<li>ไม่มีฐานข้อมูลที่ต้องการติดตั้ง กรุณาสร้างฐานข้อมูลก่อน หรือใช้ฐานข้อมูลที่มีอยู่แล้ว</li>';
        echo '<li>ข้อมูลต่างๆที่กรอกไม่ถูกต้อง กรุณากลับไปตรวจสอบ</li>';
        echo '</ol>';
        echo '<p>หากคุณไม่สามารถดำเนินการแก้ไขข้อผิดพลาดด้วยตัวของคุณเองได้ ให้ติดต่อผู้ดูแลระบบเพื่อขอข้อมูลที่ถูกต้อง</p>';
        echo '<p><a href="index.php?step=2" class="button large pink">กลับไปลองใหม่</a></p>';
    }
    if (!$error) {
        // เชื่อมต่อฐานข้อมูลสำเร็จ
        $content[] = '<li class="correct">เชื่อมต่อฐานข้อมูลสำเร็จ</li>';
        // ประมวลผลฐานข้อมูล
        $commands = file_get_contents('database.sql');
        $lines = explode("\n", $commands);
        $commands = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && !startsWith($line, '--')) {
                if (preg_match('/CREATE TABLE `\{prefix\}_([a-z_\-]+)`/i', $line, $match)) {
                    $commands .= 'DROP TABLE IF EXISTS `'.$_SESSION['prefix'].'_'.$match[1]."`;\n";
                }
                $commands .= $line."\n";
            }
        }
        $commands = explode(";\n", $commands);
        foreach ($commands as $command) {
            if (trim($command)) {
                $command = str_replace('{prefix}', $_SESSION['prefix'], $command);
                try {
                    $db->query($command);
                    $content[] = '<li class="correct">'.$command.'</li>';
                } catch (\PDOException $ex) {
                    $error = true;
                    $content[] = '<li class="incorrect">'.$ex->getMessage().'</li>';
                }
            }
        }
        if (!$error) {
            try {
                $db->query("DELETE FROM `".$_SESSION['prefix']."_user` WHERE `id` IN (1,2)");
                // default user
                $password_key = uniqid();
                $salt = uniqid();
                $username = $_SESSION['admin_username'];
                $password = $_SESSION['admin_password'];
                $today = date('Y-m-d H:i:s');
                $sql = "INSERT INTO `".$_SESSION['prefix']."_user` (`id`, `username`, `salt`, `password`, `token`, `status`, `permission`, `name`, `create_date`) VALUES";
                $sql .= "(1, '$username', '$salt', '".sha1($password_key.$password.$salt)."', NULL, 1, '', 'แอดมิน', '$today'),";
                $sql .= "(2, 'demo', '$salt', '".sha1($password_key.'demo'.$salt)."', NULL, 0, '', 'ตัวอย่าง', '$today');";
                $db->query($sql);
            } catch (\PDOException $ex) {
                $error = true;
                $content[] = '<li class="incorrect">'.$ex->getMessage().'</li>';
            }
        }
        if (!$error) {
            // บันทึก settings/database.php
            $database_cfg = include 'settings/database.php';
            $database_cfg['mysql']['username'] = $_SESSION['db_username'];
            $database_cfg['mysql']['password'] = $_SESSION['db_password'];
            $database_cfg['mysql']['dbname'] = $_SESSION['db_name'];
            $database_cfg['mysql']['hostname'] = $_SESSION['db_server'];
            $database_cfg['mysql']['port'] = $_SESSION['db_port'];
            $database_cfg['mysql']['prefix'] = $_SESSION['prefix'];
            $f = save($database_cfg, ROOT_PATH.'settings/database.php');
            $content[] = '<li class="'.($f ? 'correct' : 'incorrect').'">สร้างไฟล์ตั้งค่า <b>database.php</b> ...</li>';
            // บันทึก settings/config.php
            $cfg = include 'settings/config.php';
            $cfg['password_key'] = $password_key;
            $cfg['reversion'] = time();
            $f = save($cfg, ROOT_PATH.'settings/config.php');
            $content[] = '<li class="'.($f ? 'correct' : 'incorrect').'">สร้างไฟล์ตั้งค่า <b>config.php</b> ...</li>';
            // นำเข้าภาษา
            $db_config = array(
                'prefix' => $_SESSION['prefix']
            );
            include ROOT_PATH.'install/language.php';
        }
        if (!$error) {
            unset($_SESSION);
            echo '<h2>ติดตั้งเรียบร้อย</h2>';
            echo '<p>การติดตั้งได้ดำเนินการเสร็จเรียบร้อยแล้ว หากคุณต้องการความช่วยเหลือในการใช้งาน คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
            echo '<ul>'.implode('', $content).'</ul>';
            echo '<p class=warning>กรุณาลบไดเร็คทอรี่ <em>install/</em> ออกจาก Server ของคุณ</p>';
            echo '<p>คุณควรปรับ chmod ให้ไดเร็คทอรี่ <em>datas/</em> และ <em>settings/</em> (และไดเร็คทอรี่อื่นๆที่คุณได้ปรับ chmod ไว้ก่อนการติดตั้ง) ให้เป็น 644 ก่อนดำเนินการต่อ (ถ้าคุณได้ทำการปรับ chmod ไว้ด้วยตัวเอง)</p>';
            echo '<p>เมื่อเรียบร้อยแล้ว กรุณา<b>เข้าระบบ</b>เพื่อตั้งค่าที่จำเป็นอื่นๆโดยใช้ขื่ออีเมล <em>'.$username.'</em> และรหัสผ่าน <em>'.$password.'</em> ตามที่ได้ลงทะเบียนไว้</p>';
            echo '<p><a href="../index.php" class="button large admin">เข้าระบบ</a></p>';
        } else {
            echo '<h2>ติดตั้งไม่สำเร็จ</h2>';
            echo '<p>การติดตั้งยังไม่สมบูรณ์ ลองตรวจสอบข้อผิดพลาดที่เกิดขึ้นและแก้ไขดู หากคุณต้องการความช่วยเหลือการติดตั้ง คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
            echo '<ul>'.implode('', $content).'</ul>';
            echo '<p><a href="." class="button large admin">ลองใหม่</a></p>';
        }
    }
}

/**
 * @param $haystack
 * @param $needle
 */
function startsWith($haystack, $needle)
{
    $length = strlen($needle);
    return substr($haystack, 0, $length) === $needle;
}

/**
 * @param $config
 * @param $file
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
