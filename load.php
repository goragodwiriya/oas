<?php
/**
 * load.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
/**
 * document root (full path)
 * eg /home/user/public_html/
 *
 * @var string
 */
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)).'/');
/**
 * โฟลเดอร์เก็บข้อมูล
 *
 * @var string
 */
define('DATA_FOLDER', 'datas/');
/**
 * 0 (default) บันทึกเฉพาะข้อผิดพลาดร้ายแรงลง error_log.php
 * 1 บันทึกข้อผิดพลาดและคำเตือนลง error_log.php
 * 2 แสดงผลข้อผิดพลาดและคำเตือนออกทางหน้าจอ (ใช้เฉพาะตอนออกแบบเท่านั้น)
 *
 * @var int
 */
define('DEBUG', 0);
/**
 * กำหนดที่เก็บ error log
 * LOG_FILE   = บันทึกลงไฟล์ error_log.php ของระบบ
 * LOG_SYSTEM = บันทึกไป Apache/PHP error log (error_log())
 * LOG_BOTH   = บันทึกทั้งสองที่
 *
 * @var string
 */
define('LOG_DESTINATION', 'LOG_SYSTEM');
/**
 * false (default)
 * true บันทึกการ query ฐานข้อมูลลง log (ใช้เฉพาะตอนออกแบบเท่านั้น)
 *
 * @var bool
 */
define('DB_LOG', false);
/**
 * ไฟล์เก็บ SQL query log เมื่อ DB_LOG = true
 * ไฟล์จะถูกสร้างใต้ ROOT_PATH
 *
 * @var string
 */
define('DB_LOG_FILE', DATA_FOLDER.'logs/sql_log.php');
/**
 * จำนวนวันเก็บ SQL query log
 *
 * @var int
 */
define('DB_LOG_RETENTION_DAYS', 7);
/**
 * เปิด/ปิดการใช้งาน Query Cache สำหรับ Database
 * true  = เปิดใช้งาน cache (แนะนำสำหรับ production)
 * false = ปิดใช้งาน cache (สำหรับ development)
 *
 * @var bool
 */
define('DB_CACHE', true);
/**
 * ประเภท Cache Driver
 * file   = เก็บ cache ลงไฟล์ (default)
 * memory = เก็บ cache ใน memory (หายเมื่อ request จบ)
 * redis  = เก็บ cache ใน Redis server
 *
 * @var string
 */
define('CACHE_DRIVER', 'file');
/**
 * ภาษาเริ่มต้น
 * auto = อัตโนมัติจากบราวเซอร์
 * th, en ตามภาษาที่เลือก
 *
 * @var string
 */
define('INIT_LANGUAGE', 'th');
/**
 * เปิด/ปิดการใช้งาน Session บน Database
 * ต้องติดตั้งตาราง sessions ด้วย
 *
 * @var bool
 */
define('USE_SESSION_DATABASE', false);
/*
 * ระบุ SQL Mode ที่ต้องการ
 * หากพบปัญหาการใช้งาน
 *
 * @var string
 */
//define('SQL_MODE', '');
/**
 * load Kotchasan
 */
include 'Kotchasan/load.php';
