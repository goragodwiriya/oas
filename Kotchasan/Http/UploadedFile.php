<?php
/**
 * @filesource Kotchasan/Http/UploadedFile.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

use Kotchasan\Image;
use Kotchasan\Language;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Class for handling uploaded files.
 *
 * @see https://www.kotchasan.com/
 */
class UploadedFile implements UploadedFileInterface
{
    /**
     * The upload error code (UPLOAD_ERR_XXX).
     *
     * @var int
     */
    private $error;

    /**
     * The file extension of the uploaded file.
     *
     * @var string|null
     */
    private $ext;

    /**
     * Indicates whether the file has been moved.
     *
     * @var bool
     */
    private $isMoved = false;

    /**
     * The MIME type of the file.
     *
     * @var string|null
     */
    private $mime;

    /**
     * The name of the uploaded file.
     *
     * @var string|null
     */
    private $name;

    /**
     * Indicates if the upload is from a SAPI environment.
     *
     * @var bool
     */
    private $sapi = false;

    /**
     * The size of the uploaded file.
     *
     * @var int|null
     */
    private $size;

    /**
     * The file stream.
     *
     * @var Stream|null
     */
    private $stream;

    /**
     * The path to the uploaded file.
     *
     * @var string|null
     */
    private $tmp_name;

    /**
     * Creates a new UploadedFile instance.
     *
     * @param string      $path         The path to the uploaded file.
     * @param string      $originalName The original name of the uploaded file.
     * @param string|null $mimeType     The MIME type of the uploaded file.
     * @param int|null    $size         The size of the uploaded file.
     * @param int|null    $error        The upload error code (UPLOAD_ERR_XXX).
     * @param bool        $sapi         Indicates if the upload is in a SAPI environment.
     */
    public function __construct($path, $originalName, $mimeType = null, $size = null, $error = null, $sapi = true)
    {
        $this->tmp_name = $path;
        $this->name = $originalName;
        $this->mime = $mimeType;
        $this->size = $size;
        $this->error = $error;
        $this->sapi = $sapi;
    }

    /**
     * Copies the uploaded file to a new location.
     *
     * @param string $targetPath The destination path to which the file should be moved.
     *
     * @throws \RuntimeException         If an error occurs while copying the file.
     * @throws \InvalidArgumentException If the target directory is not writable.
     *
     * @return bool True on success, false otherwise.
     */
    public function copyTo($targetPath)
    {
        if ($this->isMoved) {
            throw new \RuntimeException(sprintf(Language::get('Uploaded file %1s has already been moved'), $this->name));
        }

        if (!is_writable(dirname($targetPath))) {
            throw new \InvalidArgumentException(Language::get('Target directory is not writable'));
        }
        if (!copy($this->tmp_name, $targetPath)) {
            throw new \RuntimeException(sprintf(Language::get('Error copying file %1s to %2s'), $this->name, $targetPath));
        }
        return true;
    }

    /**
     * ฟังก์ชั่น ตัดรูปภาพ ตามขนาดที่กำหนด และย้ายไปยังปลายทาง
     * รูปภาพปลายทางจะมีขนาดเท่าที่กำหนด หากรูปภาพต้นฉบับมีขนาดหรืออัตราส่วนไม่พอดีกับขนาดของภาพปลายทาง
     * รูปภาพจะถูกตัดขอบออกหรือจะถูกขยาย เพื่อให้พอดีกับรูปภาพปลายทางที่ต้องการ
     * ผลลัพท์จะได้ไฟล์รูปภาพ jpg เท่านั้น
     * สำเร็จคืนค่า true ไม่สำเร็จคืนค่าข้อความผิดพลาด
     *
     * @param array  $exts       นามสกุลของไฟล์รูปภาพที่ยอมรับ เช่น [jpg, gif, png]
     * @param string $targetPath path และชื่อไฟล์ของไฟล์รูปภาพปลายทาง
     * @param int    $width      ความกว้างของรูปภาพที่ต้องการ
     * @param int    $height     ความสูงของรูปภาพที่ต้องการ
     * @param string $watermark  (optional) ข้อความลายน้ำ
     *
     * @throws \InvalidArgumentException ข้อผิดพลาดหากที่อยู่ปลายทางไม่สามารถเขียนได้
     * @throws \RuntimeException         ข้อผิดพลาดไม่สามารถสร้างรูปภาพได้
     *
     * @return bool|string
     */
    public function cropImage($exts, $targetPath, $width, $height, $watermark = '')
    {
        $this->check($exts, dirname($targetPath));
        $ret = Image::crop($this->tmp_name, $targetPath, $width, $height, $watermark);
        if ($ret === false) {
            throw new \RuntimeException(Language::get('Unable to create image'));
        }
        return true;
    }

    /**
     * อ่านชื่อไฟล์จากไฟล์ที่อัปโหลดและตัดตัวอักษที่ไม่สามารถใช้เป็นชื่อไฟล์ได้ออก
     * ยอมรับ ภาษาอังกฤษ ตัวเลข ( ) _ - และ .(จุด) เท่านั้น
     * นอกเหนือจากนั้นจะถูกแทนที่ด้วย $replace ติดกันไม่เกิน 1 ตัวอักษร
     *
     * @param string $replace ตัวอักษรที่จะแทนที่อักขระไที่ไม่ต้องการ ถ้าไม่ระบุจะใช้ _ (ขีดล่าง)
     *
     * @return string
     */
    public function getCleanFilename($replace = '_')
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.\(\)]{1,}/', $replace, $this->name);
    }

    /**
     * คืนค่านามสกุลของไฟล์อัปโหลด ตัวพิมพ์เล็ก เช่น jpg
     *
     * @return string
     */
    public function getClientFileExt()
    {
        if ($this->ext == null) {
            $exts = explode('.', $this->name);
            $this->ext = strtolower(end($exts));
        }
        return $this->ext;
    }

    /**
     * อ่านชื่อไฟล์ (ต้นฉบับ) ของไฟล์ที่อัปโหลด
     *
     * @return string|null
     */
    public function getClientFilename()
    {
        return $this->name;
    }

    /**
     * อ่าน MIME Type ของไฟล์
     *
     * @return string|null
     */
    public function getClientMediaType()
    {
        return $this->mime;
    }

    /**
     * อ่านข้อผิดพลาดของไฟล์อัปโหลด
     * คืนค่า UPLOAD_ERR_XXX
     *
     * @return int
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * อ่านข้อผิดพลาดของไฟล์อัปโหลด เป็นข้อความ
     *
     * @staticvar array $errors
     *
     * @return string
     */
    public function getErrorMessage()
    {
        switch ($this->error) {
            case UPLOAD_ERR_INI_SIZE:
                return sprintf('The file "%s" exceeds your upload_max_filesize ini directive (limit is %s).', $this->getClientFilename(), self::getUploadSize());
                break;
            case UPLOAD_ERR_FORM_SIZE:
                return sprintf('The file "%s" exceeds the upload limit defined in your form.', $this->getClientFilename());
                break;
            case UPLOAD_ERR_PARTIAL:
                return sprintf('The file "%s" was only partially uploaded.', $this->getClientFilename());
                break;
            case UPLOAD_ERR_CANT_WRITE:
                return sprintf('The file "%s" could not be written on disk.', $this->getClientFilename());
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'File could not be uploaded: missing temporary directory.';
                break;
            case UPLOAD_ERR_EXTENSION:
                return 'File upload was stopped by a PHP extension.';
                break;
            case UPLOAD_ERR_OK:
            case UPLOAD_ERR_NO_FILE:
                return null;
                break;
            default:
                return sprintf('The file "%s" was not uploaded due to an unknown error.', $this->getClientFilename());
                break;
        }
    }

    /**
     * Retrieves the size of the uploaded file.
     *
     * @return int|null The size of the uploaded file in bytes, or null if the size is unknown.
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Retrieves the uploaded file as a stream.
     *
     * @throws \RuntimeException If the file has already been moved or if the file is not found.
     *
     * @return StreamInterface The uploaded file stream.
     */
    public function getStream()
    {
        if (!is_file($this->tmp_name)) {
            throw new \RuntimeException(sprintf(Language::get('Uploaded file %1s has already been moved'), $this->name));
        }
        if ($this->stream === null) {
            $this->stream = new Stream($this->tmp_name);
        }
        return $this->stream;
    }

    /**
     * Retrieves the path of the uploaded file, including the temporary directory.
     *
     * @return string|null The path of the uploaded file, or null if the file is not available.
     */
    public function getTempFileName()
    {
        return $this->tmp_name;
    }

    /**
     * Retrieves the configured maximum upload file size.
     *
     * @param bool $return_byte False (default) to return the size as a string (e.g., '2M'), true to return the size in bytes.
     *
     * @return string|int The maximum upload file size as a string or an integer (bytes).
     */
    public static function getUploadSize($return_byte = false)
    {
        $val = trim(ini_get('upload_max_filesize'));
        if (is_numeric($val)) {
            return $val;
        } elseif ($return_byte && preg_match('/^([0-9]+)([gmk])$/', strtolower($val), $match)) {
            $units = array('k' => 1024, 'm' => 1048576, 'g' => 1073741824);
            $val = (int) $match[1] * $units[$match[2]];
        }
        return $val;
    }

    /**
     * Checks if an error occurred during the upload process.
     *
     * @return bool True if an error occurred, false otherwise.
     */
    public function hasError()
    {
        if ($this->error === null || $this->error === UPLOAD_ERR_OK || $this->error === UPLOAD_ERR_NO_FILE) {
            return false;
        }
        return true;
    }

    /**
     * Checks if an upload file exists.
     *
     * @return bool True if an upload file exists, false otherwise.
     */
    public function hasUploadFile()
    {
        return $this->error == UPLOAD_ERR_OK;
    }

    /**
     * Moves the uploaded file to the specified target path.
     *
     * @param string $targetPath The path where the file will be moved.
     *
     * @return bool True if the file was successfully moved, false otherwise.
     *
     * @throws \RuntimeException If the file has already been moved or an error occurred during the move operation.
     * @throws \InvalidArgumentException If the target directory is not writable.
     */
    public function moveTo($targetPath)
    {
        if ($this->isMoved) {
            throw new \RuntimeException(sprintf(Language::get('Uploaded file %1s has already been moved'), $this->name));
        }
        if (!is_writable(dirname($targetPath))) {
            throw new \InvalidArgumentException(Language::get('Target directory is not writable'));
        }
        if (strpos($targetPath, '://') > 0) {
            if (!copy($this->tmp_name, $targetPath)) {
                throw new \RuntimeException(sprintf(Language::get('Error moving uploaded file %1s to %2s'), $this->name, $targetPath));
            }
            if (!unlink($this->tmp_name)) {
                throw new \RuntimeException(sprintf(Language::get('Error removing uploaded file %1s'), $this->name));
            }
        } elseif ($this->sapi) {
            if (!move_uploaded_file($this->tmp_name, $targetPath)) {
                throw new \RuntimeException(sprintf(Language::get('Error moving uploaded file %1s to %2s'), $this->name, $targetPath));
            }
        } elseif (copy($this->tmp_name, $targetPath)) {
            unlink($this->tmp_name);
        } else {
            throw new \RuntimeException(sprintf(Language::get('Error moving uploaded file %1s to %2s'), $this->name, $targetPath));
        }
        $this->isMoved = true;
        return true;
    }

    /**
     * Resizes an image.
     *
     * @param array $exts An array of valid file extensions.
     * @param string $target The target directory for saving the resized image.
     * @param string $name The name of the resized image.
     * @param int $width The desired width of the resized image.
     * @param string $watermark Optional watermark to be applied on the resized image.
     *
     * @return string The path to the resized image.
     *
     * @throws \RuntimeException If unable to create the image.
     * @throws \InvalidArgumentException If the file extension or target directory is invalid.
     */
    public function resizeImage($exts, $target, $name, $width, $watermark = '')
    {
        $this->check($exts, $target);
        $ret = Image::resize($this->tmp_name, $target, $name, $width, $watermark);
        if ($ret === false) {
            throw new \RuntimeException(Language::get('Unable to create image'));
        } else {
            return $ret;
        }
    }

    /**
     * Checks if the file extension is valid.
     *
     * @param array $exts An array of valid file extensions.
     *
     * @return bool True if the file extension is valid, false otherwise.
     */
    public function validFileExt($exts)
    {
        return in_array($this->getClientFileExt(), $exts);
    }

    /**
     * Checks if the uploaded file is valid and the target directory is writable.
     *
     * @param array $exts An array of valid file extensions.
     * @param string $targetDir The target directory.
     *
     * @throws \RuntimeException If the type of file is invalid.
     * @throws \InvalidArgumentException If the target directory is not writable.
     *
     * @return bool True if the file is valid and the directory is writable, false otherwise.
     */
    private function check($exts, $targetDir)
    {
        if (!$this->validFileExt($exts)) {
            throw new \RuntimeException(Language::get('The type of file is invalid'));
        }
        if (!is_writable($targetDir)) {
            throw new \InvalidArgumentException(Language::get('Target directory is not writable'));
        }
        return true;
    }
}
