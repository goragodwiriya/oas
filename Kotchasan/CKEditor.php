<?php
/**
 * @filesource Kotchasan/CKEditor.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides functions to work with CKEditor.
 *
 * @see https://www.kotchasan.com/
 */
class CKEditor extends Html
{
    /**
     * Check if CKEDITOR upload is enabled.
     *
     * @return bool
     */
    public static function enabledUpload()
    {
        if (Login::isAdmin()) {
            return true;
        } elseif ($login = Login::isMember()) {
            return !empty($_SESSION['CKEDITOR']) && $_SESSION['CKEDITOR'] == $login['id'];
        }
        return false;
    }

    /**
     * Render the CKEditor HTML code.
     *
     * @return string
     */
    public function render()
    {
        $content = [
            'item' => '',
            'label' => '',
            'tag' => '',
            'itemClass' => ''
        ];
        $prop = [];
        $innerHTML = '';

        if (isset($this->attributes['id']) && !isset($this->attributes['name'])) {
            $this->attributes['name'] = $this->attributes['id'];
        }
        if (isset($this->attributes['name']) && !isset($this->attributes['id'])) {
            $this->attributes['id'] = $this->attributes['name'];
        }

        foreach ($this->attributes as $key => $value) {
            if ($key === 'itemClass') {
                $content['item'] = '<div class="'.$value.'">';
                $content['itemClass'] = '</div>';
            } elseif ($key === 'id') {
                $for = ' for="'.$value.'"';
                $prop[] = ' id="'.$value.'"';
            } elseif ($key === 'name') {
                $prop[] = ' name="'.$value.'"';
            } elseif ($key === 'value') {
                $innerHTML = $this->tag == 'textarea' ? $this->toTextarea($value) : $this->toDiv($value);
            } elseif ($key !== 'label' && $key !== 'upload') {
                $attributes[$key] = $value;
            }
        }

        if (isset($this->attributes['label'])) {
            $content['label'] = '<label'.$for.'>'.$this->attributes['label'].'</label>';
        }

        $content['tag'] = '<div><'.$this->tag.implode('', $prop).'>'.$innerHTML.'</'.$this->tag.'></div>';

        $login = Login::isMember();

        if ($login) {
            $_SESSION['CKEDITOR'] = $login['id'];
        }

        if (isset($this->attributes['id'])) {
            $script = [];

            foreach ($attributes as $key => $value) {
                $script[] = $key.':'.(is_int($value) ? $value : '"'.$value.'"');
            }

            if (isset($this->attributes['upload']) && $this->attributes['upload'] == true) {
                if (is_dir(ROOT_PATH.'ckfinder')) {
                    $script[] = 'filebrowserBrowseUrl:"'.WEB_URL.'ckfinder/ckfinder.html"';
                    $script[] = 'filebrowserImageBrowseUrl:"'.WEB_URL.'ckfinder/ckfinder.html?Type=Images"';
                    $script[] = 'filebrowserFlashBrowseUrl:"'.WEB_URL.'ckfinder/ckfinder.html?Type=Flash"';
                    $script[] = 'filebrowserUploadUrl:"'.WEB_URL.'ckfinder/core/connector/php/connector.php?command=QuickUpload&type=Files"';
                    $script[] = 'filebrowserImageUploadUrl:"'.WEB_URL.'ckfinder/core/connector/php/connector.php?command=QuickUpload&type=Images"';
                    $script[] = 'filebrowserFlashUploadUrl:"'.WEB_URL.'ckfinder/core/connector/php/connector.php?command=QuickUpload&type=Flash"';
                } else {
                    $connector = urlencode(WEB_URL.'ckeditor/filemanager/connectors/php/connector.php');
                    $script[] = 'filebrowserBrowseUrl:"'.WEB_URL.'ckeditor/filemanager/browser/default/browser.html?Connector='.$connector.'"';
                    $script[] = 'filebrowserImageBrowseUrl:"'.WEB_URL.'ckeditor/filemanager/browser/default/browser.html?Type=Image&Connector='.$connector.'"';
                    $script[] = 'filebrowserFlashBrowseUrl:"'.WEB_URL.'ckeditor/filemanager/browser/default/browser.html?Type=Flash&Connector='.$connector.'"';
                    $script[] = 'filebrowserUploadUrl:"'.WEB_URL.'ckeditor/filemanager/connectors/php/upload.php"';
                    $script[] = 'filebrowserImageUploadUrl:"'.WEB_URL.'ckeditor/filemanager/connectors/php/upload.php?Type=Image"';
                    $script[] = 'filebrowserFlashUploadUrl:"'.WEB_URL.'ckeditor/filemanager/connectors/php/upload.phpType=Flash"';
                }
            }

            self::$form->javascript[] = 'CKEDITOR.replace("'.$this->attributes['id']."\", {\n".implode(",\n", $script)."\n});";
        }

        return implode('', $content);
    }

    /**
     * Convert {} to HTML entities.
     * Used for div tag.
     *
     * @param string $str The text
     *
     * @return string
     */
    public function toDiv($str)
    {
        return preg_replace(array('/{/', '/}/'), array('&#x007B;', '&#x007D;'), $str);
    }

    /**
     * Convert special characters and {} to HTML entities.
     * Used for textarea tag.
     *
     * @param string $str The text
     *
     * @return string
     */
    public function toTextarea($str)
    {
        return preg_replace(array('/{/', '/}/'), array('&#x007B;', '&#x007D;'), htmlspecialchars($str));
    }
}
