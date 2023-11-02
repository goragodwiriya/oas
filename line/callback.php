<?php
/**
 * line/callback.php
 * URL สำหรับรับ callback จาก LINE Login
 * https://developers.line.biz/en/docs/line-login/integrate-line-login/#create-a-channel
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
// load Kotchasan
include '../load.php';
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->defaultController = 'Index\Linecallback\Controller';
$app->run();
