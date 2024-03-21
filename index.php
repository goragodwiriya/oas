<?php
/**
 * index.php
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
if (!is_file('settings/config.php')) {
    // ติดตั้งครั้งแรก
    header('Location: install/index.php');
} else {
    // load Kotchasan
    include 'load.php';
    // Initial Kotchasan Framework
    $app = Kotchasan::createWebApplication('Gcms\Config');
    $app->run();
}
