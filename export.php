<?php
/**
 * export.php
 * A page for API calls to download various data such as reports, product information,
 * or other information. that require users to download as a CSV or Excel file
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
$app->defaultController = 'Index\Export\Controller';
$app->run();