<?php

namespace Kotchasan;

/**
 * Kotchasan CSV Class
 *
 * This class provides methods for importing and exporting CSV data.
 *
 * @package Kotchasan
 */
class Csv
{
    private const DELIMITER = ',';

    private const ENCLOSURE = '"';

    private const ESCAPE = '\\';

    /**
     * @var mixed
     */
    private $charset;
    /**
     * @var mixed
     */
    private $columns;
    /**
     * @var mixed
     */
    private $datas;
    /**
     * @var mixed
     */
    private $keys;

    /**
     * Import CSV data
     *
     * @param string $csv  File path
     * @param array  $columns  Column data array('column1' => 'data type', 'column2' => 'data type', ....)
     * @param array  $keys  Column names for duplicate data checking. Null(default) means no checking.
     * @param string $charset  File character encoding. Default is UTF-8.
     *
     * @return array  Imported data array
     */
    public static function import($csv, $columns, $keys = null, $charset = 'UTF-8')
    {
        $obj = new static();
        $obj->columns = $columns;
        $obj->datas = [];
        $obj->charset = strtoupper($charset);
        $obj->keys = $keys;
        $obj->read($csv, [$obj, 'importDatas'], array_keys($columns));
        return $obj->datas;
    }

    /**
     * Read a CSV file and process each row of data
     *
     * @param string   $file      Path to the CSV file
     * @param callable $onRow     Callback function to be executed for each row of data
     * @param array    $headers   Array of expected header values for validation (optional)
     * @param string   $charset   Character encoding of the CSV file (default: UTF-8)
     * @param callable $onBeforeRead  Callback function to be executed before processing rows (optional)
     * @param mixed    $args      Additional arguments to be passed to the callback functions (optional)
     *
     * @throws \Exception If an error occurs, such as an invalid CSV header or missing column
     *
     * @return void
     */
    public static function read($file, $onRow, $headers = null, $charset = 'UTF-8', $onBeforeRead = null, $args = null)
    {
        $columns = [];
        $f = @fopen($file, 'r');
        if ($f) {
            // Convert charset to uppercase
            $charset = strtoupper($charset);

            while (($data = fgetcsv($f, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE)) !== false) {
                if (empty($columns)) {
                    if (is_array($headers)) {
                        if (count($headers) != count($data)) {
                            throw new \Exception('Invalid CSV Header');
                        } else {
                            if ($charset == 'UTF-8') {
                                // Remove BOM
                                $data[0] = trim(self::removeBomUtf8($data[0]), " \t\n\r\0\x0B\'\"");
                            } else {
                                // Convert to UTF-8
                                foreach ($data as $k => $v) {
                                    $data[$k] = trim(iconv($charset, 'UTF-8//IGNORE', $v), " \t\n\r\0\x0B\'\"");
                                }
                            }

                            // Check header values
                            foreach ($headers as $k) {
                                if (!in_array($k, $data)) {
                                    throw new \Exception('Column not found : '.$k);
                                }
                            }
                        }
                    }
                    $columns = $data;
                    if (is_callable($onBeforeRead)) {
                        // Call the provided callback function before processing rows
                        call_user_func($onBeforeRead, $columns, $args);
                    }
                } else {
                    $items = [];
                    foreach ($data as $k => $v) {
                        if (isset($columns[$k])) {
                            if ($charset == 'UTF-8') {
                                $items[$columns[$k]] = $v;
                            } else {
                                // Convert to UTF-8
                                $items[$columns[$k]] = iconv($charset, 'UTF-8//IGNORE', $v);
                            }
                        }
                    }

                    if (is_callable($onRow)) {
                        // Call the provided callback function with the processed row data
                        call_user_func($onRow, $items, $args);
                    }
                }
            }

            fclose($f);
        }
    }

    /**
     * Generate and send a CSV file as a download
     *
     * @param string $file     File name (without extension)
     * @param array  $header   Array of header values for the CSV file
     * @param iterable $datas  Array or Iterator of data rows for the CSV file
     * @param string $charset  Character encoding of the CSV file (default: UTF-8)
     * @param bool   $bom      Whether to include the Byte Order Mark (BOM) in the CSV file (default: true)
     *
     * @throws \Exception If headers already sent or invalid input
     * @return void
     */
    public static function send($file, $header, $datas, $charset = 'UTF-8', $bom = true)
    {
        // Allow Iterator for large datasets (checking iterable instead of is_array)
        if (!is_array($datas) && !($datas instanceof \Traversable)) {
            throw new \Exception('Data must be an array or traversable');
        }

        // Header must be an array
        if (!is_array($header)) {
            throw new \Exception('Header must be an array');
        }

        // Clear output buffer
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Check if headers have already been sent
        if (headers_sent($filename, $line)) {
            throw new \Exception("Headers already sent in {$filename} on line {$line}");
        }

        // Sanitizing filename (Optional: Keep basic sanitization but allow Thai if handled by browser)
        // Usually, modern browsers handle UTF-8 filenames in Content-Disposition better with the filename* syntax
        $encodedFile = rawurlencode($file.'.csv');

        try {
            // Set response headers
            header('Content-Type: text/csv; charset='.$charset);
            // Support UTF-8 Filename correctly
            header("Content-Disposition: attachment; filename=\"{$file}.csv\"; filename*=UTF-8''{$encodedFile}");
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            // Create a stream for output
            $f = fopen('php://output', 'w');
            if ($f === false) {
                throw new \Exception('Failed to open output stream');
            }

            // Add BOM for UTF-8 if requested
            if (strtoupper($charset) === 'UTF-8' && $bom) {
                fwrite($f, "\xEF\xBB\xBF");
            }

            $charset = strtoupper($charset);

            // Write Header
            if (!empty($header)) {
                fputcsv($f, self::convert($header, $charset), self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            }

            // Write Data
            foreach ($datas as $item) {
                fputcsv($f, self::convert($item, $charset), self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            }

            fclose($f);
            exit();

        } catch (\Exception $e) {
            if (ob_get_length()) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Remove the Byte Order Mark (BOM) from a UTF-8 encoded string
     *
     * @param string $s  UTF-8 encoded string
     *
     * @return string  String with BOM removed
     */
    private static function removeBomUtf8($s)
    {
        if (substr($s, 0, 3) == chr(hexdec('EF')).chr(hexdec('BB')).chr(hexdec('BF'))) {
            return substr($s, 3);
        } else {
            return $s;
        }
    }

    /**
     * Convert data array to the specified character encoding
     *
     * @param array  $datas  Data array to convert
     * @param string $charset  Target character encoding
     *
     * @return array  Converted data array
     */
    private static function convert($datas, $charset)
    {
        if ($charset != 'UTF-8') {
            foreach ($datas as $k => $v) {
                if ($v != '') {
                    $datas[$k] = iconv('UTF-8', $charset.'//IGNORE', $v);
                }
            }
        }
        return $datas;
    }

    /**
     * Import data and process into a specific format
     *
     * @param array $data The data array to be imported
     *
     * @return void
     */
    private function importDatas($data)
    {
        $save = [];
        foreach ($this->columns as $key => $type) {
            $save[$key] = null;
            if (isset($data[$key])) {
                if (is_array($type)) {
                    $save[$key] = call_user_func($type, $data[$key]);
                } elseif ($type == 'int') {
                    $save[$key] = (int) $data[$key];
                } elseif ($type == 'double') {
                    $save[$key] = (float) $data[$key];
                } elseif ($type == 'float') {
                    $save[$key] = (float) $data[$key];
                } elseif ($type == 'number') {
                    $save[$key] = preg_replace('/[^0-9]+/', '', $data[$key]);
                } elseif ($type == 'en') {
                    $save[$key] = preg_replace('/[^a-zA-Z0-9]+/', '', $data[$key]);
                } elseif ($type == 'date') {
                    if (preg_match('/^([0-9]{4,4})[\-\/]([0-9]{1,2})[\-\/]([0-9]{1,2})$/', $data[$key], $match)) {
                        $save[$key] = "$match[1]-$match[2]-$match[3]";
                    } elseif (preg_match('/^([0-9]{1,2})[\-\/]([0-9]{1,2})[\-\/]([0-9]{4,4})$/', $data[$key], $match)) {
                        $save[$key] = "$match[3]-$match[2]-$match[1]";
                    }
                } elseif ($type == 'datetime') {
                    if (preg_match('/^([0-9]{4,4})[\-\/]([0-9]{2,2})[\-\/]([0-9]{2,2})\s([0-9]{2,2}):([0-9]{2,2}):([0-9]{2,2})$/', $data[$key])) {
                        $save[$key] = $data[$key];
                    } elseif (preg_match('/^([0-9]{2,2})[\-\/]([0-9]{2,2})[\-\/]([0-9]{4,4})\s(([0-9]{2,2}):([0-9]{2,2}):([0-9]{2,2}))$/', $data[$key], $match)) {
                        $save[$key] = "$match[4]-$match[3]-$match[2] $match[1]";
                    }
                } elseif ($type == 'time') {
                    if (preg_match('/^([0-9]{2,2}):([0-9]{2,2}):([0-9]{2,2})$/', $data[$key])) {
                        $save[$key] = $data[$key];
                    }
                } elseif ($this->charset == 'UTF-8') {
                    $save[$key] = \Kotchasan\Text::topic($data[$key]);
                } else {
                    $save[$key] = iconv($this->charset, 'UTF-8', \Kotchasan\Text::topic($data[$key]));
                }
            }
        }
        if (empty($this->keys)) {
            $this->datas[] = $save;
        } else {
            $keys = '';
            foreach ($this->keys as $item) {
                if ($save[$item] !== null && $save[$item] !== '') {
                    $keys .= $save[$item];
                } else {
                    $save = null;
                    continue;
                }
            }
            if (!empty($save) && !isset($this->datas[$keys])) {
                $this->datas[$keys] = $save;
            }
        }
    }
}
