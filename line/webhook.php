<?php
/**
 * line/webhook.php
 * URL สำหรับรับ webhook จาก Messaging API
 * https://developers.line.biz/en/docs/messaging-api/receiving-messages/
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
$app->defaultController = 'Index\Linewebhook\Controller';
$app->run();
