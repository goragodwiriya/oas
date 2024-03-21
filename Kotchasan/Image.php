<?php
/**
 * @filesource Kotchasan/Image.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class Image
 * A class for image manipulation.
 *
 * @see https://www.kotchasan.com/
 */
class Image
{
    /**
     * @var int The image quality (0-100) for JPEG images.
     */
    private static $quality = 75;

    /**
     * Crop an image to the specified size.
     *
     * The resulting image will have the exact dimensions specified. If the original image
     * has a different aspect ratio, it will be cropped or stretched to fit the target dimensions.
     * The resulting image will be saved as a JPEG file.
     *
     * @param string $source The path and filename of the source image.
     * @param string $target The path and filename of the target image.
     * @param int $thumbwidth The desired width of the target image.
     * @param int $thumbheight The desired height of the target image.
     * @param string $watermark (optional) The watermark text.
     *
     * @return bool True on success, false on failure.
     */
    public static function crop($source, $target, $thumbwidth, $thumbheight, $watermark = '')
    {
        // Load the original image
        $info = getimagesize($source);
        switch ($info['mime']) {
            case 'image/gif':
                $o_im = imageCreateFromGIF($source);
                break;
            case 'image/jpg':
            case 'image/jpeg':
            case 'image/pjpeg':
                $o_im = self::orient($source);
                break;
            case 'image/png':
            case 'image/x-png':
                $o_im = imageCreateFromPNG($source);
                break;
            default:
                return false;
        }

        // Calculate dimensions and resize
        $o_wd = imagesx($o_im);
        $o_ht = imagesy($o_im);
        $wm = $o_wd / $thumbwidth;
        $hm = $o_ht / $thumbheight;
        $h_height = $thumbheight / 2;
        $w_height = $thumbwidth / 2;
        $t_im = imageCreateTrueColor($thumbwidth, $thumbheight);
        $int_width = 0;
        $int_height = 0;
        $adjusted_width = $thumbwidth;
        $adjusted_height = $thumbheight;
        if ($o_wd > $o_ht) {
            $adjusted_width = ceil($o_wd / $hm);
            $half_width = $adjusted_width / 2;
            $int_width = intval($half_width - $w_height);
            if ($adjusted_width < $thumbwidth) {
                $adjusted_height = ceil($o_ht / $wm);
                $half_height = $adjusted_height / 2;
                $int_height = intval($half_height - $h_height);
                $adjusted_width = $thumbwidth;
                $int_width = 0;
            }
        } elseif (($o_wd < $o_ht) || ($o_wd == $o_ht)) {
            $adjusted_height = ceil($o_ht / $wm);
            $half_height = $adjusted_height / 2;
            $int_height = intval($half_height - $h_height);
            if ($adjusted_height < $thumbheight) {
                $adjusted_width = ceil($o_wd / $hm);
                $half_width = $adjusted_width / 2;
                $int_width = intval($half_width - $w_height);
                $adjusted_height = $thumbheight;
                $int_height = 0;
            }
        }

        // Resize and crop the image
        if (function_exists('ImageCopyResampled')) {
            imageCopyResampled($t_im, $o_im, -$int_width, -$int_height, 0, 0, $adjusted_width, $adjusted_height, $o_wd, $o_ht);
        } else {
            imageCopyResized($t_im, $o_im, -$int_width, -$int_height, 0, 0, $adjusted_width, $adjusted_height, $o_wd, $o_ht);
        }

        // Add watermark if specified
        if (!empty($watermark)) {
            self::watermarkText($t_im, $watermark);
        }

        // Save the resulting image as a JPEG file
        $result = imageJPEG($t_im, $target, self::$quality);

        // Clean up resources
        imageDestroy($t_im);
        imageDestroy($o_im);

        return $result;
    }

    /**
     * Flip an image horizontally.
     *
     * This method flips the specified image horizontally.
     *
     * @param \GdImage $imgsrc The source image resource.
     *
     * @return \GdImage The flipped image resource.
     */
    public static function flip($imgsrc)
    {
        $width = imagesx($imgsrc);
        $height = imagesy($imgsrc);
        $src_x = $width - 1;
        $src_y = 0;
        $src_width = -$width;
        $src_height = $height;
        $imgdest = imagecreatetruecolor($width, $height);

        if (function_exists('ImageCopyResampled')) {
            imageCopyResampled($imgdest, $imgsrc, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height);
        } else {
            imageCopyResized($imgdest, $imgsrc, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height);
        }

        return $imgsrc;
    }

    /**
     * Retrieve image information.
     *
     * This method retrieves the width, height, and MIME type of an image using the `getimagesize()` function.
     *
     * @param string $source The path and filename of the image.
     *
     * @return array|bool An array containing the image properties (width, height, and mime) on success,
     *                    or false if the information cannot be obtained.
     */
    public static function info($source)
    {
        $info = getimagesize($source);
        if (!$info) {
            return false;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'mime' => $info['mime']
        ];
    }

    /**
     * @param $source
     * @return mixed
     */
    public static function orient($source)
    {
        $im = imageCreateFromJPEG($source);

        if (!$im) {
            return false;
        }

        try {
            $exif = exif_read_data($source);
        } catch (\Throwable $th) {
            // exif error
            return $im;
        }

        $orientation = $exif['Orientation'] ?? 0;

        switch ($orientation) {
            case 2:
                // horizontal flip
                $im = self::flip($im);
                break;
            case 3:
                // 180 rotate left
                $im = self::rotateImage($im, 180);
                break;
            case 4:
                // vertical flip
                $im = self::flipImage($im);
                break;
            case 5:
                // vertical flip + 90 rotate left
                $im = self::flipImage($im);
                $im = self::rotateImage($im, -90);
                break;
            case 6:
                // 90 rotate right
                $im = self::rotateImage($im, 90);
                break;
            case 7:
                // horizontal flip + 90 rotate right
                $im = self::flipImage($im);
                $im = self::rotateImage($im, 90);
                break;
            case 8:
                // 90 rotate left
                $im = self::rotateImage($im, -90);
                break;
        }

        return $im;
    }

    /**
     * @param $image
     * @param $angle
     */
    private static function rotateImage($image, $angle)
    {
        return imagerotate($image, $angle, 0);
    }

    /**
     * @param $image
     */
    private static function flipImage($image)
    {
        return self::rotateImage($image, 180);
    }

    /**
     * Resize an image and optionally add a watermark.
     *
     * This method resizes the specified image to the given width while maintaining aspect ratio.
     * It can also add a watermark to the resized image if specified.
     *
     * @param string $source    The path to the source image.
     * @param string $target    The path to save the resized image.
     * @param string $name      The name of the resized image.
     * @param int    $width     The desired width for the resized image.
     * @param string $watermark Optional. The watermark text to add to the resized image. Default is an empty string.
     *
     * @return array|false An array containing information about the resized image if successful, false otherwise.
     *                    The returned array will have the following keys:
     *                    - 'name': The name of the resized image.
     *                    - 'width': The width of the resized image.
     *                    - 'height': The height of the resized image.
     *                    - 'mime': The MIME type of the resized image ('image/jpeg' for JPEG images).
     */
    public static function resize($source, $target, $name, $width, $watermark = '')
    {
        $info = getimagesize($source);
        if ($info[0] > $width || $info[1] > $width) {
            switch ($info['mime']) {
                case 'image/gif':
                    $o_im = imageCreateFromGIF($source);
                    break;
                case 'image/jpg':
                case 'image/jpeg':
                case 'image/pjpeg':
                    $o_im = self::orient($source);
                    break;
                case 'image/png':
                case 'image/x-png':
                    $o_im = imageCreateFromPNG($source);
                    break;
            }
            $o_wd = imagesx($o_im);
            $o_ht = imagesy($o_im);
            if ($o_wd <= $o_ht) {
                $h = $width;
                $w = round($h * $o_wd / $o_ht);
            } else {
                $w = $width;
                $h = round($w * $o_ht / $o_wd);
            }
            $t_im = ImageCreateTrueColor($w, $h);
            if (function_exists('ImageCopyResampled')) {
                imageCopyResampled($t_im, $o_im, 0, 0, 0, 0, $w + 1, $h + 1, $o_wd, $o_ht);
            } else {
                imageCopyResized($t_im, $o_im, 0, 0, 0, 0, $w + 1, $h + 1, $o_wd, $o_ht);
            }
            if ($watermark != '') {
                $t_im = self::watermarkText($t_im, $watermark);
            }
            $newname = substr($name, 0, strrpos($name, '.')).'.jpg';
            if (!@ImageJPEG($t_im, $target.$newname, self::$quality)) {
                $ret = false;
            } else {
                $ret['name'] = $newname;
                $ret['width'] = $w;
                $ret['height'] = $h;
                $ret['mime'] = 'image/jpeg';
            }
            imageDestroy($o_im);
            imageDestroy($t_im);
            return $ret;
        } elseif (copy($source, $target.$name)) {
            $ret['name'] = $name;
            $ret['width'] = $info[0];
            $ret['height'] = $info[1];
            $ret['mime'] = $info['mime'];
            return $ret;
        }
        return false;
    }

    /**
     * Add a text watermark to an image.
     *
     * This method adds a text watermark to the specified image. The watermark text, position,
     * color, font size, and opacity can be customized.
     *
     * @param \GdImage $imgsrc The source image resource.
     * @param string $text The text to be used as the watermark.
     * @param string $pos The position of the watermark. Valid values: 'center', 'bottom', 'left', 'right' (default: '').
     * @param string $color The color of the watermark in hexadecimal format (default: 'CCCCCC').
     * @param int $font_size The font size of the watermark (default: 20).
     * @param int $opacity The opacity of the watermark (0-100) (default: 50).
     *
     * @return \GdImage The modified image resource with the added watermark.
     */
    public static function watermarkText($imgsrc, $text, $pos = '', $color = 'CCCCCC', $font_size = 20, $opacity = 50)
    {
        $font = ROOT_PATH.'skin/fonts/leelawad.ttf';
        $offset = 5;
        $alpha_color = imagecolorallocatealpha($imgsrc, hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2)), 127 * (100 - $opacity) / 100);
        $box = imagettfbbox($font_size, 0, $font, $text);

        if (preg_match('/center/i', $pos)) {
            $y = $box[1] + (imagesy($imgsrc) / 2) - ($box[5] / 2);
        } elseif (preg_match('/bottom/i', $pos)) {
            $y = imagesy($imgsrc) - $offset;
        } else {
            $y = $box[1] - $box[5] + $offset;
        }

        if (preg_match('/center/i', $pos)) {
            $x = $box[0] + (imagesx($imgsrc) / 2) - ($box[4] / 2);
        } elseif (preg_match('/right/i', $pos)) {
            $x = $box[0] - $box[4] + imagesx($imgsrc) - $offset;
        } else {
            $x = $offset;
        }

        imagettftext($imgsrc, $font_size, 0, $x, $y, $alpha_color, $font, $text);

        return $imgsrc;
    }

    /**
     * Set the image quality for JPEG images.
     *
     * This method sets the quality level (0-100) for JPEG images. Higher quality values
     * result in larger file sizes but better image quality. The default value is 75.
     *
     * @param int $quality The image quality level (0-100).
     */
    public static function setQuality($quality)
    {
        self::$quality = max(0, min($quality, 100));
    }
}
