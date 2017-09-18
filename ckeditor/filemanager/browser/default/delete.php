<?php
// ลบไฟล์
session_start();
// header
header("content-type: text/html; charset=UTF-8");
// load Kotchasan
include '../../../../load.php';
// Initial Kotchasan Framework
Kotchasan::createWebApplication();
$request = new \Kotchasan\Http\Request;
if ($request->isReferer() && Kotchasan\Login::isAdmin()) {
  $did = $request->post('did')->toString();
  $fid = $request->post('fid')->toString();
  if (!empty($did) && strpos($did, '..') === false) {
    Kotchasan\File::removeDirectory(ROOT_PATH.$did);
  } elseif (!empty($fid) && strpos($fid, '..') === false) {
    @unlink(ROOT_PATH.$fid);
  }
} else {
  echo 'Do not delete!';
}
