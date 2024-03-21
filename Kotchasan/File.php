<?php
/**
 * @filesource Kotchasan/File.php
 *
 * File and Directory management class.
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class for managing files and directories.
 *
 * @see https://www.kotchasan.com/
 */
class File
{
    /**
     * Copy a directory recursively.
     *
     * @param string $sourceDir Source directory path (with trailing slash)
     * @param string $destDir   Destination directory path (with trailing slash)
     */
    public static function copyDirectory($sourceDir, $destDir)
    {
        $sourceHandle = @opendir($sourceDir);
        if ($sourceHandle === false) {
            return;
        }

        while (false !== ($file = readdir($sourceHandle))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($sourceDir.$file)) {
                self::makeDirectory($destDir.$file.'/');
                self::copyDirectory($sourceDir.$file.'/', $destDir.$file.'/');
            } elseif (is_dir($destDir)) {
                @copy($sourceDir.$file, $destDir.$file);
            }
        }

        closedir($sourceHandle);
    }

    /**
     * Get the file extension of a file (e.g., 'config.php' returns 'php').
     *
     * @param string $path File path
     *
     * @return string File extension (lowercase)
     */
    public static function ext($path)
    {
        $exts = explode('.', strtolower($path));
        return end($exts);
    }

    /**
     * Get the list of files in a directory and its subdirectories.
     *
     * @param string $dir     Directory path (with trailing slash)
     * @param array  $result  Array to store the found files
     * @param array  $filter  (optional) File extensions to filter (lowercase). An empty array means all extensions.
     */
    public static function listFiles($dir, &$result, $filter = [])
    {
        if (!is_dir($dir)) {
            return;
        }

        $dirHandle = @opendir($dir);
        if ($dirHandle === false) {
            return;
        }

        while (false !== ($file = readdir($dirHandle))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($dir.$file)) {
                self::listFiles($dir.$file.'/', $result, $filter);
            } elseif (empty($filter) || in_array(self::ext($file), $filter)) {
                $result[] = $dir.$file;
            }
        }

        closedir($dirHandle);
    }

    /**
     * Create and validate a directory for writing.
     *
     * @param string $dir  Directory path
     * @param int    $mode (optional) Directory permission mode (default: 0755)
     *
     * @return bool True if the directory exists or is created successfully and is writable, false otherwise
     */
    public static function makeDirectory($dir, $mode = 0755)
    {
        if (!is_dir($dir)) {
            $oldUmask = umask(0);
            $success = @mkdir($dir, $mode);
            umask($oldUmask);
            if (!$success) {
                return false;
            }
        }

        if (!is_writable($dir)) {
            $oldUmask = umask(0);
            $success = @chmod($dir, $mode);
            umask($oldUmask);
            if (!$success) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory and its files, or only the files inside the directory.
     *
     * @param string $dir         Directory path to delete (with trailing slash)
     * @param bool   $removeSelf  True (default) to delete the directory itself, false to delete only the files inside
     */
    public static function removeDirectory($dir, $removeSelf = true)
    {
        if (!is_dir($dir)) {
            return;
        }

        $dirHandle = opendir($dir);
        while (false !== ($file = readdir($dirHandle))) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            if (is_dir($dir.$file)) {
                self::removeDirectory($dir.$file.'/');
            } else {
                @unlink($dir.$file);
            }
        }

        closedir($dirHandle);

        if ($removeSelf) {
            @rmdir($dir);
        }
    }
}
