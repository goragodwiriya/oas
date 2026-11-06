<?php
/**
 * telegram/webhook.php
 * URL สำหรับรับ webhook จาก Telegram Bot API
 * https://core.telegram.org/bots/api#setwebhook
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
// load Kotchasan
include '../load.php';
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->defaultController = 'Index\Telegramwebhook\Controller';
$app->run();