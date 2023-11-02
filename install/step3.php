<?php
if (defined('ROOT_PATH')) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        include ROOT_PATH.'install/step2.php';
    } else {
        include ROOT_PATH.'Kotchasan/Text.php';
        // ค่าที่ส่งมา
        $_SESSION['admin_username'] = \Kotchasan\Text::username($_POST['username']);
        $_SESSION['admin_password'] = \Kotchasan\Text::password($_POST['password']);
        // database settings
        $database = include 'settings/database.php';
        $db_username = isset($_SESSION['db_username']) ? $_SESSION['db_username'] : $database['mysql']['username'];
        $db_password = isset($_SESSION['db_password']) ? $_SESSION['db_password'] : $database['mysql']['password'];
        $db_server = isset($_SESSION['db_server']) ? $_SESSION['db_server'] : 'localhost';
        $db_port = isset($_SESSION['db_port']) ? $_SESSION['db_port'] : 3306;
        $db_name = isset($_SESSION['db_name']) ? $_SESSION['db_name'] : $database['mysql']['dbname'];
        $prefix = isset($_SESSION['prefix']) ? $_SESSION['prefix'] : $database['mysql']['prefix'];
        echo '<form method=post action=index.php autocomplete=off>';
        echo '<h2>ค่ากำหนดของฐานข้อมูล</h2>';
        echo '<p>คุณจะต้องระบุข้อมูลการเชื่อมต่อที่ถูกต้องด้านล่างเพื่อเริ่มดำเนินการติดตั้งฐานข้อมูล</p>';
        echo '<p class=item><label for=db_username>ชื่อผู้ใช้</label><span class="g-input icon-user"><input type=text size=50 id=db_username name=db_username value="'.$db_username.'"></span></p>';
        echo '<p class=comment>ชื่อผู้ใช้ของ MySQL ของคุณ</p>';
        echo '<p class=item><label for=db_password>รหัสผ่าน</label><span class="g-input icon-password"><input type=password size=50 id=db_password name=db_password value="'.$db_password.'"></span></p>';
        echo '<p class=comment>รหัสผ่านของ MySQL ของคุณ</p>';
        echo '<p class=item><label for=db_server>โฮสท์ของฐานข้อมูล</label><span class="g-input icon-world"><input type=text size=50 id=db_server name=db_server value="'.$db_server.'"></span></p>';
        echo '<p class=comment>ดาตาเบสเซิร์ฟเวอร์ของคุณ (โฮสท์ส่วนใหญ่ใช้ localhost)</p>';
        echo '<p class=item><label for=db_port>พอร์ต</label><span class="g-input icon-link"><input type=text size=50 id=db_port name=db_port value="'.$db_port.'"></span></p>';
        echo '<p class=comment>ดาตาเบสเซิร์ฟเวอร์พอร์ตของคุณ (โฮสท์ส่วนใหญ่ใช้ 3306)</p>';
        echo '<p class=item><label for=db_name>ชื่อฐานข้อมูล</label><span class="g-input icon-database"><input type=text size=50 id=db_name name=db_name value="'.$db_name.'"></span></p>';
        echo '<p class=comment>ชื่อฐานข้อมูลที่ใช้ในการติดตั้งโปรแกรม ต้องเป็นฐานข้อมูลที่มีอยู่แล้ว หรือสร้างฐานข้อมูลก่อน</p>';
        echo '<p class=item><label for=prefix>คำนำหน้าตาราง</label><span class="g-input icon-table"><input type=text size=50 id=prefix name=prefix value="'.$prefix.'"></span></p>';
        echo '<p class=comment>ใช้สำหรับแยกฐานข้อมูลที่กำลังจะติดตั้งออกจากฐานข้อมูลอื่นๆ หากมีการติดตั้งข้อมูลอื่นๆร่วมกันบนฐานข้อมูลนี้</p>';
        echo '<input type=hidden name=step value=4>';
        echo '<p><input class="button large save" type=submit value="ติดตั้ง"></p>';
        echo '</form>';
    }
}
