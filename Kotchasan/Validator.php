<?php
/**
 * @filesource Kotchasan/Validator.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class for validating various variables.
 *
 * @see https://www.kotchasan.com/
 */
class Validator extends \Kotchasan\KBase
{
    /**
     * Validates an email address.
     *
     * Returns true if the email format is correct.
     *
     * @assert ('admin@localhost.com') [==] true
     * @assert ('admin@localhost') [==] true
     * @assert ('Abc01.d_e-f@1081009.com') [==] true
     * @assert ('ทดสอบ@localhost') [==] false
     *
     * @param string $email
     *
     * @return bool
     */
    public static function email($email)
    {
        if (function_exists('idn_to_ascii') && preg_match('/(.*)@(.*)/', $email, $match)) {
            // Thai domain
            $email = $match[1].'@'.idn_to_ascii($match[2], 0, INTL_IDNA_VARIANT_UTS46);
        }
        if (preg_match('/^[a-zA-Z0-9\._\-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD', $email)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks if an uploaded file is an image.
     *
     * Returns an array [ext, width, height, mime] of the image if it is an image,
     * or false if it is not an image.
     *
     * @assert (['gif'], ['name' => 'blank.GIF', 'tmp_name' => ROOT_PATH.'skin/img/blank.gif']) [==] ['ext' => 'gif', 'width' => 1, 'height' => 1, 'mime' => 'image/gif']
     *
     * @param array $excepts     Accepted file types, e.g. array('jpg', 'gif', 'png')
     * @param array $file_upload Value from $_FILES
     *
     * @return array|bool
     */
    public static function isImage($excepts, $file_upload)
    {
        // ext
        $imageInfo = explode('.', $file_upload['name']);
        $imageInfo = ['ext' => strtolower(end($imageInfo))];
        if (!in_array($imageInfo['ext'], $excepts)) {
            return false;
        }
        // Exif
        $info = getimagesize($file_upload['tmp_name']);
        if ($info[0] == 0 || $info[1] == 0) {
            return false;
        }
        if (!Mime::check($excepts, $info['mime'])) {
            return false;
        }
        $imageInfo['width'] = $info[0];
        $imageInfo['height'] = $info[1];
        $imageInfo['mime'] = $info['mime'];
        return $imageInfo;
    }
}
