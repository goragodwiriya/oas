<?php
if (defined('ROOT_PATH')) {
    $username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'admin@localhost';
    $password = isset($_SESSION['admin_password']) ? $_SESSION['admin_password'] : 'admin';
    echo '<form method=post action=index.php autocomplete=off>';
    echo '<h2>สมาชิกผู้ดูแลระบบ</h2>';
    echo '<p>คุณจะต้องระบุข้อมูลสมาชิกผู้ดูแลระบบ ซึ่งจะมีสิทธิสูงสุดในระบบ <em>ห้ามลืม ห้ามหาย</em></p>';
    echo '<p class=item><label for=username>ชื่อผู้ใช้</label><span class="g-input icon-user"><input type=text size=50 maxlength=255 id=username name=username value="'.$username.'"></span></p>';
    if (empty($username)) {
        echo '<p class=comment><em>กรุณากรอกชื่อผู้ใช้ที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด</em></p>';
    } else {
        echo '<p class=comment>กรุณากรอกชื่อผู้ใช้ที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด</p>';
    }
    echo '<p class=item><label for=password>รหัสผ่าน</label><span class="g-input icon-password"><input type=password size=50 maxlength=20 id=password name=password value="'.$password.'"></span></p>';
    if (empty($password)) {
        echo '<p class=comment><em>กรุณากรอกรหัสผ่านที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด</em></p>';
    } else {
        echo '<p class=comment>กรุณากรอกรหัสผ่านที่ต้องการ ใช้ในการเข้าระบบเป็นผู้ดูแลสูงสุด</p>';
    }
    echo '<input type=hidden name=step value=3>';
    echo '<p><input class="button large save" type=submit value="ดำเนินการต่อ"></p>';
    echo '</form>';
}
