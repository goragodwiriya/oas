<?php
if (defined('ROOT_PATH')) {
    echo '<h2>ตรวจสอบไฟล์และโฟลเดอร์ที่จำเป็นสำหรับการติดตั้ง</h2>';
    echo '<p>ไฟล์และโฟลเดอร์ทั้งหมดตามรายการด้านล่างต้องถูกสร้างขึ้น และกำหนดค่าให้สามารถเขียนได้ <a href="https://www.kotchasan.com/index.php?module=knowledge&id=91" target=_blank class="icon-help notext"></a></p>';
    echo '<ul>';
    $folders = [];
    $folders[] = ROOT_PATH.'datas/';
    $folders[] = ROOT_PATH.'settings/';
    $folders[] = ROOT_PATH.'datas/cache/';
    $folders[] = ROOT_PATH.'datas/logs/';
    $folders[] = ROOT_PATH.'datas/images/';
    foreach ($folders as $folder) {
        makeDirectory($folder, 0755);
        if (is_writable($folder)) {
            echo '<li class=correct>โฟลเดอร์ <strong>'.str_replace(ROOT_PATH, '', $folder).'</strong> <i>สามารถใช้งานได้</i></li>';
        } else {
            $error = true;
            echo '<li class=incorrect>โฟลเดอร์ <strong>'.str_replace(ROOT_PATH, '', $folder).'</strong> <em>ไม่สามารถเขียนหรือสร้างได้</em> กรุณาสร้างและปรับ chmod ให้สามารถเขียนได้</li>';
        }
    }
    $files = [];
    $files[] = ROOT_PATH.'settings/config.php';
    $files[] = ROOT_PATH.'settings/database.php';
    foreach ($files as $file) {
        if (!is_file($file)) {
            $f = @fopen($file, 'wb');
            if ($f) {
                fclose($f);
            }
        }
        if (is_writable($file)) {
            echo '<li class=correct>ไฟล์ <strong>'.str_replace(ROOT_PATH, '', $file).'</strong> <i>สามารถใช้งานได้</i></li>';
        } else {
            $error = true;
            echo '<li class=incorrect>ไฟล์ <strong>'.str_replace(ROOT_PATH, '', $file).'</strong> <em>ไม่สามารถเขียนหรือสร้างได้</em> กรุณาสร้างไฟล์นี้และปรับ chmod ให้เป็น 755 ด้วยตัวเอง</li>';
        }
    }
    echo '</ul>';
    echo '<p><a href="index.php?step=1" class="button large pink">ตรวจสอบใหม่</a>&nbsp;<a href="index.php?step=2" class="button large save">ดำเนินการต่อ</a></p>';
}

/**
 * @param string $dir
 * @param mixed  $mode
 *
 * @return bool
 */
function makeDirectory($dir, $mode = 0755)
{
    if (!is_dir($dir)) {
        $old = umask(0);
        @mkdir($dir, $mode, true);
        umask($old);
    }
    $old = umask(0);
    $f = @chmod($dir, $mode);
    umask($old);
    return $f;
}
