<?php
/**
 * load.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
/*
 * Site root
 */
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)).'/');
/*
 * ไดเร็คทอรี่เก็บไฟล์ของ user
 */
define('DATA_FOLDER', 'datas/');
/*
 * 0 (default) บันทึกเฉพาะข้อผิดพลาดร้ายแรงลง error_log .php
 * 1 บันทึกข้อผิดพลาดและคำเตือนลง error_log .php
 * 2 แสดงผลข้อผิดพลาดและคำเตือนออกทางหน้าจอ (ใช้เฉพาะตอนออกแบบเท่านั้น)
 */
define('DEBUG', 0);
/*
 * false (default)
 * true บันทึกการ query ฐานข้อมูลลง log (ใช้เฉพาะตอนออกแบบเท่านั้น)
 */
define('DB_LOG', false);
/**
 * load Kotchasan
 */
include 'Kotchasan/load.php';
