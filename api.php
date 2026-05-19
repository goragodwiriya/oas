<?php
/**
 * api.php
 * หน้าเพจสำหรับให้ API เรียกมา
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
// load Kotchasan
include 'load.php';
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->run();
