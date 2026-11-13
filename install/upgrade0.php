<?php
/**
 * install/upgrade0.php — ตรวจไฟล์และโฟลเดอร์ที่ต้องเขียนได้ (ขั้นตอนการปรับรุ่น)
 *
 * เนื้อหาเดียวกับ install/step1.php ต่างกันแค่เลข step ของปุ่ม
 */
if (defined('ROOT_PATH')) {
    checkFolders(1, 0);
}
