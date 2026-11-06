<?php
/**
 * install/step2.php — ฟอร์มสมาชิกผู้ดูแลระบบ (ขั้นตอนการติดตั้ง)
 *
 * เนื้อหาอยู่ที่ adminForm() ใน install/common.php เพราะการปรับรุ่น
 * (install/upgrade1.php) ใช้ฟอร์มเดียวกันนี้
 */
if (defined('ROOT_PATH')) {
    adminForm(3, false);
}
