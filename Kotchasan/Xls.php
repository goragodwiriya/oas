<?php
namespace Kotchasan;

/**
 * Kotchasan Xls Class
 *
 * Sends rows as an Excel-readable HTML table (.xls).
 *
 * @package Kotchasan
 */
class Xls
{
    /**
     * Sends the XLS file for download.
     *
     * @param string $file   The file name without extension.
     * @param array  $header The header section of the data.
     * @param array  $datas  The data.
     *
     * @return bool Returns true on success.
     */
    public static function send($file, $header, $datas)
    {
        // Set headers
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: '.\Kotchasan\Http\Response::contentDisposition($file.'.xls'));
        header("Pragma:no-cache");

        // XLS Template
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"';
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        echo ' xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"';
        echo ' "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        echo '<html>';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
        echo '</head>';
        echo '<body><table><thead><tr>';

        $n = 0;
        foreach ($header as $k => $items) {
            if ($k === 'rows') {
                foreach ($items as $rows) {
                    if ($n > 0) {
                        echo '</tr><tr>';
                    }
                    foreach ($rows as $item) {
                        // th
                        echo self::cell('th', $item);
                    }
                    $n++;
                }
            } else {
                // th
                echo self::cell('th', $items);
            }
        }

        echo '</tr></thead><tbody>';

        foreach ($datas as $items) {
            echo '<tr>';
            foreach ($items as $item) {
                echo self::cell('td', $item);
            }
            echo '</tr>';
        }

        echo '</tbody></table></body>';
        echo '</html>';

        // Return success
        return true;
    }

    /**
     * Returns the th or td cell.
     *
     * The value is text and is escaped, attribute names other than letters, digits
     * and - are dropped, and attribute values are escaped: they used to be written
     * as they were, so data could add markup (or formulas) to the sheet.
     *
     * @param string $type The type of cell (th or td).
     * @param array  $item The cell item.
     *
     * @return string The formatted cell HTML.
     */
    public static function cell($type, $item)
    {
        $value = '';
        $prop = '';

        if (is_array($item)) {
            foreach ($item as $k => $v) {
                if ($k === 'value') {
                    $value = $v;
                } elseif (is_string($k) && preg_match('/^[a-zA-Z][a-zA-Z0-9\-:]*$/', $k)) {
                    $prop .= ' '.$k.'="'.htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8').'"';
                }
            }
        } else {
            $value = $item;
        }
        $type = $type === 'th' ? 'th' : 'td';
        return '<'.$type.$prop.'>'.htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8').'</'.$type.'>';
    }
}
