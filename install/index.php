<?php
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(-1);
 */
session_start();
// path
define('ROOT_PATH', str_replace(array('\\', 'install/index.php'), array('/', ''), __FILE__));
// step
$step = isset($_REQUEST['step']) ? (int) $_REQUEST['step'] : 0;
// โหลดค่าติดตั้งปัจจุบัน
$new_config = include ROOT_PATH.'install/settings/config.php';
// ไตเติล
$title = 'การติดตั้ง &rsaquo; Setup Configuration File';
$h1 = 'การติดตั้ง เวอร์ชั่น '.$new_config['version'];
// เนื้อหา
$content = '';
if (is_file(ROOT_PATH.'settings/config.php') && is_array(include (ROOT_PATH.'settings/config.php')) && is_file(ROOT_PATH.'settings/database.php') && is_array(include (ROOT_PATH.'settings/database.php'))) {
    // โหลดค่าติดตั้งเก่า
    $config = include ROOT_PATH.'settings/config.php';
    if (empty($config['version']) || version_compare($config['version'], $new_config['version']) == -1) {
        // อัปเกรด
        $title = 'การปรับรุ่น เวอร์ชั่น '.$new_config['version'];
        $h1 = 'การปรับรุ่น เวอร์ชั่น '.$new_config['version'];
        $file = ROOT_PATH.'install/upgrade'.$step.'.php';
    } else {
        // ติดตั้งแล้ว
        $file = ROOT_PATH.'install/complete.php';
    }
} elseif (is_file(ROOT_PATH.'install/step'.$step.'.php')) {
    // ติดตั้ง
    $file = ROOT_PATH.'install/step'.$step.'.php';
}

// header
echo '<!DOCTYPE html>';
echo '<html lang=TH dir=ltr>';
echo '<head>';
echo '<meta charset=utf-8>';
echo '<title>'.$title.'</title>';
echo '<link rel=stylesheet href="../skin/gcss.css">';
echo '<link rel=stylesheet href="../skin/fonts.css">';
echo '<link rel=stylesheet href="./style.css">';
echo '<link rel="shortcut icon" href="../favicon.ico">';
echo '</head>';
echo '<body>';
echo '<main>';
echo '<h1 id=logo>'.$h1.'</h1>';
// เนื้อหา
include $file;
// footer
echo '<div class=footer><a href="https://www.kotchasan.com">Kotchasan</a> สงวนลิขสิทธิ์ ตามพระราชบัญญัติลิขสิทธิ์ พ.ศ. 2539</div>';
echo '</main>';
echo '</body>';
echo '</html>';
