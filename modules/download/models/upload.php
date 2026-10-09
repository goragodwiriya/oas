<?php
/**
 * @filesource modules/download/models/upload.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Download\Upload;

use Kotchasan\File;
use Kotchasan\Http\Request;

/**
 * อัปโหลดไฟล์
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\KBase
{
    /**
     * อัปโหลดไฟล์
     * ที่เก็บไฟล์ ROOT_PATH.DATA_FOLDER.$module.'/'.$id.'/'
     * คืนค่าข้อมูลไฟล์อัปโหลด
     *
     * @param array $ret ตัวแปรสำหรับรับค่ากลับ
     * @param Request $request
     * @param int|string $id ไดเร็คทอรี่เก็บไฟล์ ปกติจะเป็น ID ของไฟล์
     * @param string $module ไดเร็คทอรี่เก็บไฟล์ปกติจะเป็นชื่อโมดูล และเป็นชื่อ input ด้วย
     * @param array $typies ประเภทของไฟล์ที่สามารถอัปโหลดได้
     * @param int $size ขนาดของไฟล์ (byte) ที่สามารถอัปโหลดได้, 0 หมายถึงไม่ตรวจสอบ
     * @param int $image_width ขนาดของรูปภาพที่จัดเก็บ 0 หมายถึงอัปโหลดไฟล์ต้นฉบับ มากกว่า 0 ปรับขนาดรูปภาพ
     * @param bool $multiple true (default) สามารถอัปโหลดได้หลายไฟล์, false ลบไฟล์ก่อนหน้าทั้งหมดเมื่อมีการอัปโหลดใหม่
     *
     * @return array
     */
    public static function execute(&$ret, Request $request, $id, $module, $typies, $size = 0, $image_width = 0, $multiple = true)
    {
        // รายการไฟล์อัปโหลด
        $uploadFiles = $request->getUploadedFiles();
        $files = [];
        if (!empty($uploadFiles)) {
            // ไดเร็คทอรี่เก็บไฟล์
            $dir = ROOT_PATH.DATA_FOLDER.$module.'/'.$id.'/';
            if (!File::makeDirectory(ROOT_PATH.DATA_FOLDER.$module.'/') || !File::makeDirectory($dir)) {
                // ไดเรคทอรี่ไม่สามารถสร้างได้
                $ret[$module] = 'Upload directory cannot be created or is read-only.';
            } else {
                // ลบไฟล์ก่อนหน้า
                if ($multiple == false) {
                    File::removeDirectory($dir, false);
                }
                $time = time();
                // อัปโหลดไฟล์
                foreach ($uploadFiles as $item => $file) {
                    /* @var $file \Kotchasan\Http\UploadedFile */
                    if (preg_match('/^'.$module.'(\[[0-9]{0,}\])?$/', $item)) {
                        if ($file->hasUploadFile()) {
                            if (!$file->validFileExt($typies)) {
                                // ชนิดของไฟล์ไม่ถูกต้อง
                                $ret[$module] = 'The type of file is invalid';
                            } elseif ($size > 0 && $size < $file->getSize()) {
                                // ขนาดของไฟล์ใหญ่เกินไป
                                $ret[$module] = 'The file size larger than the limit';
                            } else {
                                $ext = $file->getClientFileExt();
                                $isImage = in_array($ext, ['gif', 'jpeg', 'jpg', 'png', 'webp']);
                                $outputExt = $image_width > 0 && $isImage ? self::$cfg->stored_img_type : '.'.$ext;
                                $file_upload = $time.$outputExt;
                                while (file_exists($dir.$file_upload)) {
                                    $time++;
                                    $file_upload = $time.$outputExt;
                                }
                                try {
                                    if ($image_width == 0 || !$isImage) {
                                        // ไม่ต้องปรับขนาด หรือไม่ใช่รูปภาพ
                                        $file->moveTo($dir.$file_upload);
                                        // คืนค่ารายละเอียดของไฟล์อัปโหลด
                                        $files[] = [
                                            'ext' => $ext,
                                            'name' => preg_replace('/\\.'.$ext.'$/', '', $file->getClientFilename()),
                                            'size' => $file->getSize(),
                                            'file' => $file_upload
                                        ];
                                    } else {
                                        // ปรับขนาดรูปภาพ $image_width รักษาอัตราส่วนของรูปภาพต้นฉบับ
                                        $image = $file->resizeImage($typies, $dir, $file_upload, $image_width);
                                        if ($image === false) {
                                            $ret[$module] = 'Unable to create image';
                                        } elseif (preg_match('/(.*)\.([a-z]+)$/', $image['name'], $match)) {
                                            // คืนค่ารายละเอียดของไฟล์อัปโหลด
                                            $files[] = [
                                                'ext' => $match[2],
                                                'name' => $match[1],
                                                'size' => $file->getSize(),
                                                'file' => $image['name']
                                            ];
                                        }
                                    }
                                } catch (\Exception $exc) {
                                    // ไม่สามารถอัปโหลดได้
                                    $ret[$module] = $exc->getMessage();
                                }
                            }
                        } elseif ($file->hasError()) {
                            // ข้อผิดพลาดการอัปโหลด
                            $ret[$module] = $file->getErrorMessage();
                        }
                    }
                }
            }
        }
        return $files;
    }
}
