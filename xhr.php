<?php
/**
 * xhr.php
 * หน้าเพจสำหรับให้ Ajax เรียกมา ซึ่งจะส่งไปยัง class และ method ที่ส่งค่ามา
 * $_POST[class] = ชื่อคลาสที่เรียกเช่น Vender\Class
 * $_POST[method] = ชื่อเมธอดเช่น action.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
// load Kotchasan
include 'load.php';
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->defaultController = 'Index\Xhr\Controller';
$app->run();
