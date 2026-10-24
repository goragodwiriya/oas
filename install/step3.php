<?php
if (defined('ROOT_PATH')) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        include ROOT_PATH.'install/step2.php';
    } else {
        include ROOT_PATH.'Kotchasan/Text.php';
        include_once ROOT_PATH.'install/db.php';
        // ค่าที่ส่งมา
        $_SESSION['admin_username'] = \Kotchasan\Text::username($_POST['username']);
        $_SESSION['admin_password'] = \Kotchasan\Text::password($_POST['password']);
        // database settings
        $database = include ROOT_PATH.'install/settings/database.php';
        // ชนิดฐานข้อมูลที่ติดตั้งได้ (ไฟล์สคีมาครบ + PHP มี PDO driver) ค่าเริ่มต้นคือ dbdriver ของแม่แบบ
        $drivers = installableDrivers();
        $dbdriver = isset($_SESSION['dbdriver']) ? $_SESSION['dbdriver'] : ($database['mysql']['dbdriver'] ?? 'mysql');
        if (!isset($drivers[$dbdriver])) {
            $dbdriver = (string) key($drivers);
        }
        $db_username = isset($_SESSION['db_username']) ? $_SESSION['db_username'] : $database['mysql']['username'];
        $db_password = isset($_SESSION['db_password']) ? $_SESSION['db_password'] : $database['mysql']['password'];
        $db_server = isset($_SESSION['db_server']) ? $_SESSION['db_server'] : 'localhost';
        // มีชนิดเดียวให้เติมพอร์ตมาตรฐานไว้เลย ถ้าเลือกได้หลายชนิดเว้นว่าง (= พอร์ตมาตรฐานของชนิดที่เลือก)
        $db_port = isset($_SESSION['db_port']) ? $_SESSION['db_port'] : ($database['mysql']['port'] ?? (count($drivers) > 1 ? '' : Db::defaultPort($dbdriver)));
        $db_name = isset($_SESSION['db_name']) ? $_SESSION['db_name'] : $database['mysql']['dbname'];
        $prefix = isset($_SESSION['prefix']) ? $_SESSION['prefix'] : $database['mysql']['prefix'];
        $db_label = count($drivers) == 1 ? $drivers[$dbdriver] : 'ฐานข้อมูล';
        echo '<form method=post action=index.php autocomplete=off>';
        echo '<h2>ค่ากำหนดของฐานข้อมูล</h2>';
        echo '<p>คุณจะต้องระบุข้อมูลการเชื่อมต่อที่ถูกต้องด้านล่างเพื่อเริ่มดำเนินการติดตั้งฐานข้อมูล</p>';
        if (count($drivers) > 1) {
            echo '<p class=item><label for=dbdriver>ชนิดฐานข้อมูล</label><span class="form-control icon-database"><select id=dbdriver name=dbdriver>';
            foreach ($drivers as $value => $label) {
                echo '<option value="'.$value.'"'.($value === $dbdriver ? ' selected' : '').'>'.$label.'</option>';
            }
            echo '</select></span></p>';
            echo '<p class=comment>SQLite ไม่ใช้ชื่อผู้ใช้ รหัสผ่าน โฮสท์ และพอร์ต ฐานข้อมูลเป็นไฟล์ใน <em>datas/</em></p>';
        } else {
            echo '<input type=hidden name=dbdriver value="'.htmlspecialchars($dbdriver, ENT_QUOTES).'">';
        }
        echo '<p class=item><label for=db_username>ชื่อผู้ใช้</label><span class="form-control icon-user"><input type=text size=50 id=db_username name=db_username value="'.htmlspecialchars($db_username, ENT_QUOTES).'"></span></p>';
        echo '<p class=comment>ชื่อผู้ใช้ของ '.$db_label.' ของคุณ</p>';
        echo '<p class=item><label for=db_password>รหัสผ่าน</label><span class="form-control icon-password"><input type=password size=50 id=db_password name=db_password value="'.htmlspecialchars($db_password, ENT_QUOTES).'"></span></p>';
        echo '<p class=comment>รหัสผ่านของ '.$db_label.' ของคุณ</p>';
        echo '<p class=item><label for=db_server>โฮสท์ของฐานข้อมูล</label><span class="form-control icon-world"><input type=text size=50 id=db_server name=db_server value="'.htmlspecialchars($db_server, ENT_QUOTES).'"></span></p>';
        echo '<p class=comment>ดาตาเบสเซิร์ฟเวอร์ของคุณ (โฮสท์ส่วนใหญ่ใช้ localhost)</p>';
        echo '<p class=item><label for=db_port>พอร์ต</label><span class="form-control icon-link"><input type=text size=50 id=db_port name=db_port value="'.htmlspecialchars((string) $db_port, ENT_QUOTES).'"></span></p>';
        $ports = [];
        foreach ($drivers as $value => $label) {
            if (Db::defaultPort($value) > 0) {
                $ports[] = $label.' '.Db::defaultPort($value);
            }
        }
        echo '<p class=comment>ดาตาเบสเซิร์ฟเวอร์พอร์ตของคุณ เว้นว่างไว้เพื่อใช้พอร์ตมาตรฐาน'.(empty($ports) ? '' : ' ('.implode(', ', $ports).')').'</p>';
        echo '<p class=item><label for=db_name>ชื่อฐานข้อมูล</label><span class="form-control icon-database"><input type=text size=50 id=db_name name=db_name value="'.htmlspecialchars($db_name, ENT_QUOTES).'"></span></p>';
        echo '<p class=comment>ชื่อฐานข้อมูลที่ใช้ในการติดตั้งโปรแกรม ถ้ายังไม่มี ตัวติดตั้งจะสร้างให้ (ผู้ใช้ต้องมีสิทธิ์สร้างฐานข้อมูล)</p>';
        echo '<p class=item><label for=prefix>คำนำหน้าตาราง</label><span class="form-control icon-table"><input type=text size=50 id=prefix name=prefix value="'.htmlspecialchars($prefix, ENT_QUOTES).'"></span></p>';
        echo '<p class=comment>ใช้สำหรับแยกฐานข้อมูลที่กำลังจะติดตั้งออกจากฐานข้อมูลอื่นๆ หากมีการติดตั้งข้อมูลอื่นๆร่วมกันบนฐานข้อมูลนี้</p>';
        echo '<input type=hidden name=step value=4>';
        echo '<p class="submit"><button class="btn large btn-primary" type=submit>ติดตั้ง</button></p>';
        echo '</form>';
    }
}
