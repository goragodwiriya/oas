<?php

namespace Kotchasan\Http;

use Kotchasan\Image;
use Kotchasan\Language;
use Kotchasan\Psr\Http\Message\StreamInterface;
use Kotchasan\Psr\Http\Message\UploadedFileInterface;

/**
 * HTTP Uploaded File Class
 * Implements PSR-7 UploadedFileInterface
 *
 * @package Kotchasan\Http
 */
class UploadedFile implements UploadedFileInterface
{
    /**
     * @var string|null
     */
    private $clientFilename;

    /**
     * @var string|null
     */
    private $clientMediaType;

    /**
     * @var string|null
     */
    private $ext;

    /**
     * @var int
     */
    private $error;

    /**
     * @var string|null
     */
    private $file;

    /**
     * @var bool
     */
    private $moved = false;

    /**
     * @var int
     */
    private $size;

    /**
     * @var StreamInterface|null
     */
    private $stream;

    /**
     * Create a new UploadedFile instance.
     *
     * @param string $file The full path to the uploaded file
     * @param int $size The file size in bytes
     * @param int $error The UPLOAD_ERR_XXX code representing the status of the upload
     * @param string|null $clientFilename The filename as provided by the client
     * @param string|null $clientMediaType The media type as provided by the client
     */
    public function __construct($file, $size, $error, $clientFilename = null, $clientMediaType = null)
    {
        $this->file = $file;
        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    /**
     * {@inheritDoc}
     */
    public function getStream()
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot retrieve stream after it has already been moved');
        }

        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }

        $this->stream = new Stream($this->file);
        return $this->stream;
    }

    /**
     * Retrieves the path of the uploaded file (temporary filename).
     *
     * @return string|null
     */
    public function getTempFileName()
    {
        return $this->file;
    }

    /**
     * {@inheritDoc}
     */
    public function moveTo($targetPath)
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot move file; already moved!');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cannot retrieve stream due to upload error');
        }

        if (!is_string($targetPath) || empty($targetPath)) {
            throw new \InvalidArgumentException('Invalid path provided for move operation');
        }

        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory) || !is_writable($targetDirectory)) {
            throw new \RuntimeException('The target directory is not writable');
        }

        $sapi = PHP_SAPI;
        if (empty($sapi) || substr($sapi, 0, 3) !== 'cli') {
            // PHP SAPI is not CLI, use move_uploaded_file
            if (!move_uploaded_file($this->file, $targetPath)) {
                throw new \RuntimeException('Error occurred while moving uploaded file');
            }
        } else {
            // CLI SAPI, use rename
            if (!rename($this->file, $targetPath)) {
                throw new \RuntimeException('Error occurred while moving uploaded file');
            }
        }

        $this->moved = true;
    }

    /**
     * Move uploaded file immutably: perform move and return a new UploadedFile instance representing the moved file.
     * This preserves the original instance unchanged and provides a new instance pointing to the new location.
     *
     * @param string $targetPath
     * @return UploadedFile New instance pointing to moved file
     * @throws \RuntimeException|\InvalidArgumentException
     */
    // moveToImmutable removed: callers should use moveTo() which mutates the instance per PSR behavior

    /**
     * {@inheritDoc}
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * {@inheritDoc}
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * {@inheritDoc}
     */
    public function getClientFilename()
    {
        return $this->clientFilename;
    }

    /**
     * {@inheritDoc}
     */
    public function getClientMediaType()
    {
        return $this->clientMediaType;
    }

    /**
     * Check if file was uploaded without error.
     *
     * @return bool
     */
    public function hasUploadFile(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->size > 0;
    }

    /**
     * Check if there was an upload error.
     * Note: UPLOAD_ERR_NO_FILE is not considered an error, just no file uploaded.
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->error !== UPLOAD_ERR_OK && $this->error !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Get upload error message.
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        switch ($this->error) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Validate file extension against allowed extensions.
     *
     * @param array $allowedExtensions Array of allowed file extensions (without dots)
     * @return bool
     */
    public function validFileExt(array $allowedExtensions): bool
    {
        if (empty($this->clientFilename)) {
            return false;
        }

        $extension = strtolower(pathinfo($this->clientFilename, PATHINFO_EXTENSION));

        // Convert allowed extensions to lowercase for case-insensitive comparison
        $allowedExtensions = array_map('strtolower', $allowedExtensions);

        return in_array($extension, $allowedExtensions);
    }

    /**
     * Copy uploaded file to a target path.
     *
     * @param string $targetPath
     * @return bool
     */
    public function copyTo($targetPath)
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot move file; already moved!');
        }
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new \RuntimeException('The target directory is not writable');
        }
        if (!copy($this->file, $targetPath)) {
            throw new \RuntimeException('Error occurred while copying uploaded file');
        }
        return true;
    }

    /**
     * Get upload_max_filesize or bytes
     *
     * @param bool $return_byte
     * @return string|int
     */
    public static function getUploadSize($return_byte = false)
    {
        $val = trim(ini_get('upload_max_filesize'));
        if (is_numeric($val)) {
            return $val;
        } elseif ($return_byte && preg_match('/^([0-9]+)([gmk])$/', strtolower($val), $match)) {
            $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $val = (int) $match[1] * $units[$match[2]];
        }
        return $val;
    }

    /**
     * Clean filename
     *
     * @param string $replace
     * @return string
     */
    public function getCleanFilename($replace = '_')
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.\(\)]{1,}/', $replace, $this->clientFilename ?? '');
    }

    /**
     * Get display name from filename
     * - Removes file extension
     * - kebab-case (one-two) → Title Case (One Two)
     * - snake_case (one_two) → Sentence case (One two)
     *
     * @return string
     */
    public function getDisplayName()
    {
        // Get filename without extension
        $filename = pathinfo($this->clientFilename ?? '', PATHINFO_FILENAME);

        if (empty($filename)) {
            return '';
        }

        $hasUnderscore = strpos($filename, '_') !== false;

        // Replace separators with spaces
        $result = str_replace(['-', '_'], ' ', $filename);

        // Normalize multiple spaces
        $result = preg_replace('/\s+/', ' ', trim($result));

        if ($hasUnderscore) {
            // snake_case → Sentence case (first letter uppercase only)
            return ucfirst(strtolower($result));
        } else {
            // kebab-case → Title Case (each word capitalized)
            return ucwords(strtolower($result));
        }
    }

    /**
     * Get client file extension (lowercase)
     *
     * @return string|null
     */
    public function getClientFileExt()
    {
        if ($this->ext == null) {
            $exts = explode('.', $this->clientFilename ?? '');
            $this->ext = strtolower(end($exts));
        }
        return $this->ext;
    }

    /**
     * Resize image and save to target directory using Kotchasan\Image
     *
     * @param array $exts
     * @param string $target
     * @param string $name
     * @param int $width
     * @param string $watermark
     * @param bool $forceConvert
     * @return array
     */
    public function resizeImage($exts, $target, $name, $width = 0, $watermark = '', $forceConvert = true)
    {
        $this->check($exts, $target);
        $ret = Image::resize($this->file, $target, $name, $width, $watermark, $forceConvert);
        if ($ret === false) {
            throw new \RuntimeException(Language::get('Unable to create image'));
        }
        return $ret;
    }

    /**
     * Crop image and save to target path using Kotchasan\Image
     *
     * @param array $exts
     * @param string $targetPath
     * @param int $width
     * @param int $height
     * @param string $watermark
     * @param bool $fit
     * @return bool
     */
    public function cropImage($exts, $targetPath, $width, $height, $watermark = '', $fit = false)
    {
        $this->check($exts, dirname($targetPath));
        $ret = Image::crop($this->file, $targetPath, $width, $height, $watermark, $fit);
        if ($ret === false) {
            throw new \RuntimeException(Language::get('Unable to create image'));
        }
        return true;
    }

    /**
     * Check extension and writable dir
     *
     * @param array $exts
     * @param string $targetDir
     * @return bool
     */
    private function check($exts, $targetDir)
    {
        if (!$this->validFileExt($exts)) {
            throw new \RuntimeException(Language::get('The type of file is invalid'));
        }
        if (!is_writable($targetDir)) {
            throw new \InvalidArgumentException(Language::sprintf('Target directory "%s" is not writable', $targetDir));
        }
        return true;
    }
}
