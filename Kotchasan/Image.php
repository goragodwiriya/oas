<?php

namespace Kotchasan;

/**
 * Kotchasan Image Class
 *
 * This class provides methods for loading, processing, and saving images.
 * It supports various image formats and includes functionality for cropping,
 * resizing, flipping, and adding watermarks to images.
 *
 * @package Kotchasan
 */
class Image
{
    /**
     * @var int Image quality (0-100) for JPEG or WEBP. Default is 75.
     */
    public static $quality = 75;

    /**
     * @var array Default background color (white) in RGB format.
     */
    public static $backgroundColor = [255, 255, 255];

    /**
     * @var string Path to the font file used for watermark text.
     */
    public static $fontPath = __DIR__.'/fonts/leelawad.ttf';

    /**
     * Load an image from a file and create an image resource.
     *
     * @param string $source Path to the source image file.
     * @return Mixed Image resource on success.
     * @throws \RuntimeException If the image file is invalid or unsupported.
     */
    public static function loadImageResource($source)
    {
        $info = getimagesize($source);
        if ($info === false) {
            throw new \RuntimeException('Invalid image file: '.$source);
        }
        switch ($info['mime']) {
            case 'image/gif':
                return imagecreatefromgif($source);
            case 'image/jpeg':
            case 'image/jpg':
            case 'image/pjpeg':
                return self::orient($source);
            case 'image/png':
            case 'image/x-png':
                return imagecreatefrompng($source);
            case 'image/webp':
                return imagecreatefromwebp($source);
            default:
                throw new \RuntimeException('Unsupported image type: '.$info['mime']);
        }
    }

    /**
     * Load an image from a Base64 string and create an image resource.
     *
     * @param string $base64_string Base64 encoded string of the image.
     * @param array $allowedExtensions List of allowed file extensions, e.g., ['jpg', 'png'].
     * @return array|bool An array containing the image resource, MIME type, and file extension, or false on failure.
     */
    public static function loadImageFromBase64($base64_string, $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'])
    {
        if (preg_match('/^data:image\/([a-zA-Z]+);base64,/', $base64_string, $matches)) {
            $extension = strtolower($matches[1]);
            $mime = 'image/'.$extension;
            $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
        } else {
            return false;
        }

        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        $imageData = base64_decode($base64_string, true);
        if ($imageData === false) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($imageData);
        if ($detectedMime !== $mime) {
            return false;
        }

        $img_info = getimagesizefromstring($imageData);
        if ($img_info === false) {
            return false;
        }

        $imageResource = imagecreatefromstring($imageData);
        if ($imageResource === false) {
            return false;
        }

        return [
            'resource' => $imageResource,
            'mime' => $mime,
            'extension' => $extension
        ];
    }

    /**
     * Process an image resource by resizing or cropping based on provided dimensions.
     * Optionally adds a watermark to the processed image.
     *
     * @param Mixed $o_im Original image resource.
     * @param int $thumbwidth Desired width of the thumbnail.
     * @param int $thumbheight Desired height of the thumbnail (0 to maintain aspect ratio).
     * @param string $watermark Watermark text to add to the image (optional).
     * @param bool $fit If true, fit the image to the specified dimensions while maintaining aspect ratio.
     * @return Mixed Processed image resource.
     */
    public static function processImageResource($o_im, $thumbwidth, $thumbheight = 0, $watermark = '', $fit = false)
    {
        $o_wd = imagesx($o_im);
        $o_ht = imagesy($o_im);

        if ($thumbheight === 0) {
            $w = $o_wd;
            $h = $o_ht;
            if ($thumbwidth > 0 && ($o_wd > $thumbwidth || $o_ht > $thumbwidth)) {
                if ($o_wd <= $o_ht) {
                    $h = $thumbwidth;
                    $w = round($h * $o_wd / $o_ht);
                } else {
                    $w = $thumbwidth;
                    $h = round($w * $o_ht / $o_wd);
                }
            }
        } else {
            $w = $thumbwidth;
            $h = $thumbheight;
        }

        $t_im = imagecreatetruecolor($w, $h);
        imagealphablending($t_im, false);
        imagesavealpha($t_im, true);
        $transparent = imagecolorallocatealpha($t_im, 255, 255, 255, 127);
        imagefill($t_im, 0, 0, $transparent);

        if ($thumbheight === 0) {
            imagecopyresampled($t_im, $o_im, 0, 0, 0, 0, $w, $h, $o_wd, $o_ht);
        } else {
            $o_ratio = $o_wd / $o_ht;
            $t_ratio = $thumbwidth / $thumbheight;
            if ($fit) {
                if ($o_ratio > $t_ratio) {
                    $new_width = $thumbwidth;
                    $new_height = intval($thumbwidth / $o_ratio);
                    $x_offset = 0;
                    $y_offset = intval(($thumbheight - $new_height) / 2);
                } else {
                    $new_height = $thumbheight;
                    $new_width = intval($thumbheight * $o_ratio);
                    $x_offset = intval(($thumbwidth - $new_width) / 2);
                    $y_offset = 0;
                }
                imagecopyresampled($t_im, $o_im, $x_offset, $y_offset, 0, 0, $new_width, $new_height, $o_wd, $o_ht);
            } else {
                $src_x = $src_y = 0;
                $src_w = $o_wd;
                $src_h = $o_ht;

                $cmp_x = $o_wd / $thumbwidth;
                $cmp_y = $o_ht / $thumbheight;

                if ($cmp_x > $cmp_y) {
                    $src_w = round($o_wd / $cmp_x * $cmp_y);
                    $src_x = round(($o_wd - $src_w) / 2);
                } elseif ($cmp_y > $cmp_x) {
                    $src_h = round($o_ht / $cmp_y * $cmp_x);
                    $src_y = round(($o_ht - $src_h) / 2);
                }

                imagecopyresampled($t_im, $o_im, 0, 0, $src_x, $src_y, $w, $h, $src_w, $src_h);
            }
        }

        if (!empty($watermark)) {
            self::watermarkText($t_im, $watermark);
        }

        return $t_im;
    }

    /**
     * Save an image resource to a file, determining the image type from the file extension.
     *
     * @param Mixed $imageResource The image resource to save.
     * @param string $target Path to the target image file.
     * @return bool Success or failure.
     * @throws \RuntimeException If the file extension is unsupported or the save operation fails.
     */
    public static function saveImageResource($imageResource, $target)
    {
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $result = imagejpeg($imageResource, $target, self::$quality);
                break;
            case 'png':
                $result = imagepng($imageResource, $target);
                break;
            case 'gif':
                $result = imagegif($imageResource, $target);
                break;
            case 'webp':
                $result = imagewebp($imageResource, $target, self::$quality);
                break;
            default:
                throw new \RuntimeException('Unsupported file extension for saving image: '.$extension);
        }

        if ($result === false) {
            throw new \RuntimeException('Failed to save image to '.$target);
        }

        return true;
    }

    /**
     * Crop and resize an image based on specified dimensions.
     *
     * @param string $source Path to the source image file.
     * @param string $target Path to save the cropped image.
     * @param int $thumbwidth Desired width of the cropped image.
     * @param int $thumbheight Desired height of the cropped image.
     * @param string $watermark Watermark text to add to the image (optional).
     * @param bool $fit If true, fit the image to the specified dimensions while maintaining aspect ratio.
     * @return bool Success or failure.
     */
    public static function crop($source, $target, $thumbwidth, $thumbheight, $watermark = '', $fit = false)
    {
        $imageResource = self::loadImageResource($source);
        if ($imageResource === false) {
            return false;
        }

        $processedImage = self::processImageResource($imageResource, $thumbwidth, $thumbheight, $watermark, $fit);

        $result = self::saveImageResource($processedImage, $target);

        imagedestroy($processedImage);
        imagedestroy($imageResource);

        return $result;
    }

    /**
     * Resize an image and save it.
     *
     * @param string $source Path to the source image file.
     * @param string $target Directory to save the resized image.
     * @param string $name Filename for the resized image.
     * @param int $width Desired width of the resized image.
     * @param string $watermark Watermark text to add to the image (optional).
     * @return array|bool Array of resized image info or false on failure.
     */
    public static function resize($source, $target, $name, $width = 0, $watermark = '')
    {
        $imageResource = self::loadImageResource($source);
        if ($imageResource === false) {
            return false;
        }

        $info = getimagesize($source);
        $mime = $info['mime'];

        $processedImage = self::processImageResource($imageResource, $width, 0, $watermark, false);

        $result = self::saveImageResource($processedImage, $target.$name);
        if ($result === false) {
            imagedestroy($processedImage);
            imagedestroy($imageResource);
            return false;
        }

        $w = imagesx($processedImage);
        $h = imagesy($processedImage);

        imagedestroy($processedImage);
        imagedestroy($imageResource);

        return [
            'name' => $name,
            'width' => $w,
            'height' => $h,
            'mime' => $mime
        ];
    }

    /**
     * Flip an image horizontally.
     *
     * @param Mixed $imgsrc Source image resource.
     * @return Mixed Flipped image resource.
     */
    public static function flip($imgsrc)
    {
        $width = imagesx($imgsrc);
        $height = imagesy($imgsrc);
        $imgdest = imagecreatetruecolor($width, $height);
        imagealphablending($imgdest, false);
        imagesavealpha($imgdest, true);
        imagecopyresampled($imgdest, $imgsrc, 0, 0, ($width - 1), 0, $width, $height, -$width, $height);
        return $imgdest;
    }

    /**
     * Flip an image vertically.
     *
     * @param Mixed $imgsrc Source image resource.
     * @return Mixed Flipped image resource.
     */
    public static function flipVertical($imgsrc)
    {
        $width = imagesx($imgsrc);
        $height = imagesy($imgsrc);
        $imgdest = imagecreatetruecolor($width, $height);
        imagealphablending($imgdest, false);
        imagesavealpha($imgdest, true);
        imagecopyresampled($imgdest, $imgsrc, 0, 0, 0, ($height - 1), $width, $height, $width, -$height);
        return $imgdest;
    }

    /**
     * Get information about an image such as width, height, and MIME type.
     *
     * @param string $source Path to the image file.
     * @return array Image information.
     * @throws \RuntimeException If unable to get image info.
     */
    public static function info($source)
    {
        $info = getimagesize($source);
        if (!$info) {
            throw new \RuntimeException('Unable to get image info: '.$source);
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'mime' => $info['mime']
        ];
    }

    /**
     * Adjust image orientation based on EXIF data.
     *
     * @param string $source Path to the image file.
     * @return Mixed Image resource with corrected orientation.
     * @throws \RuntimeException If the image resource creation fails.
     */
    public static function orient($source)
    {
        $im = imagecreatefromjpeg($source);

        if (!$im) {
            throw new \RuntimeException('Failed to create image resource from '.$source);
        }

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = isset($exif['Orientation']) ? $exif['Orientation'] : 0;
        } else {
            $orientation = 0;
        }

        switch ($orientation) {
            case 2:
                // Flip horizontal
                $im = self::flip($im);
                break;
            case 3:
                // Rotate 180 degrees
                $im = imagerotate($im, 180, 0);
                break;
            case 4:
                // Flip vertical
                $im = self::flipVertical($im);
                break;
            case 5:
                // Flip vertical and rotate 90 degrees CW
                $im = self::flipVertical($im);
                $im = imagerotate($im, -90, 0);
                break;
            case 6:
                // Rotate 90 degrees CW
                $im = imagerotate($im, -90, 0);
                break;
            case 7:
                // Flip horizontal and rotate 90 degrees CW
                $im = self::flip($im);
                $im = imagerotate($im, -90, 0);
                break;
            case 8:
                // Rotate 90 degrees CCW
                $im = imagerotate($im, 90, 0);
                break;
        }

        return $im;
    }

    /**
     * Add a text watermark to an image.
     *
     * @param Mixed $imgsrc Source image resource.
     * @param string $text Watermark text.
     * @param string $pos Position of the watermark (e.g., 'center', 'bottom-right').
     * @param string $color Color of the watermark in hexadecimal format (e.g., 'CCCCCC').
     * @param int $font_size Font size of the watermark text.
     * @param int $opacity Opacity of the watermark (0-100).
     * @return Mixed Image resource with watermark added.
     * @throws \RuntimeException If the font file is not found.
     */
    public static function watermarkText($imgsrc, $text, $pos = 'bottom-right', $color = 'CCCCCC', $font_size = 20, $opacity = 50)
    {
        $font = self::$fontPath;
        if (!file_exists($font)) {
            throw new \RuntimeException('Font file not found: '.$font);
        }
        $offset = 5;
        $rgb = sscanf($color, "%02x%02x%02x");
        $alpha_color = imagecolorallocatealpha(
            $imgsrc,
            $rgb[0],
            $rgb[1],
            $rgb[2],
            127 * (100 - $opacity) / 100
        );
        $box = imagettfbbox($font_size, 0, $font, $text);
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[7] - $box[1];

        $imageWidth = imagesx($imgsrc);
        $imageHeight = imagesy($imgsrc);

        switch (strtolower($pos)) {
            case 'center':
                $x = ($imageWidth - $textWidth) / 2;
                $y = ($imageHeight - $textHeight) / 2;
                break;
            case 'bottom-right':
                $x = $imageWidth - $textWidth - $offset;
                $y = $imageHeight - $offset;
                break;
            case 'bottom-left':
                $x = $offset;
                $y = $imageHeight - $offset;
                break;
            case 'top-right':
                $x = $imageWidth - $textWidth - $offset;
                $y = $textHeight + $offset;
                break;
            case 'top-left':
                $x = $offset;
                $y = $textHeight + $offset;
                break;
            default:
                $x = $offset;
                $y = $imageHeight - $offset;
        }

        imagettftext($imgsrc, $font_size, 0, $x, $y, $alpha_color, $font, $text);

        return $imgsrc;
    }

    /**
     * Set the image quality for JPEG and WEBP images.
     *
     * @param int $quality Image quality level (0-100).
     */
    public static function setQuality($quality)
    {
        self::$quality = max(0, min($quality, 100));
    }
}
