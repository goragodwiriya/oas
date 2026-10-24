<?php
/**
 * install/step1.php — ตรวจไฟล์และโฟลเดอร์ที่ต้องเขียนได้ (ขั้นตอนการติดตั้ง)
 *
 * เนื้อหาอยู่ที่ checkFolders() ใน install/common.php เพราะการปรับรุ่น
 * (install/upgrade0.php) ใช้หน้าจอเดียวกันนี้ ต่างกันแค่เลข step ของปุ่ม
 */
if (defined('ROOT_PATH')) {
    checkFolders(2, 1);
}
