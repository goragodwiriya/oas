<?php
/**
 * @filesource Kotchasan/Barcode.php
 *
 * Barcode generation class.
 * This class generates barcode images using the Barcode 128 encoding.
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Barcode generation class.
 * This class generates barcode images using the Barcode 128 encoding.
 *
 * @see https://www.kotchasan.com/
 */
class Barcode
{
    /**
     * The height of the barcode.
     *
     * @var int
     */
    private $height;

    /**
     * The width of each bar in the barcode (2D).
     *
     * @var int
     */
    private $bar_width = 1;

    /**
     * The total width of the barcode.
     *
     * @var int
     */
    private $width = 0;

    /**
     * The barcode data.
     *
     * @var array
     */
    private $datas;

    /**
     * The barcode code.
     *
     * @var string
     */
    private $code;

    /**
     * The font file path.
     *
     * @var string
     */
    public $font = ROOT_PATH.'skin/fonts/thsarabunnew-webfont.ttf';

    /**
     * The font size of the label text (in pixels).
     * 0 (default) means no label.
     *
     * @var int
     */
    private $fontSize = 0;

    /**
     * Class constructor.
     *
     * @param string $code     The barcode code.
     * @param int    $height   The height of the barcode (in pixels).
     * @param int    $fontSize The font size of the label text (in pixels), 0 (default) means no label.
     */
    protected function __construct($code, $height, $fontSize = 0)
    {
        $this->code = (string) $code;
        // Get barcode data
        $data = self::Barcode128($this->code);
        if ($data === '') {
            // Error
            $this->datas = [];
            $this->width = 1;
        } else {
            // Split to array (2D)
            $this->datas = str_split($data, 1);
            // Calculate barcode width
            $this->width = count($this->datas) * $this->bar_width;
        }
        // Set barcode height
        $this->height = $height;
        // Show barcode code as text label
        $this->fontSize = $fontSize;
    }

    /**
     * Create a Barcode instance.
     *
     * @param string $code     The barcode code.
     * @param int    $height   The height of the barcode (in pixels). Default is 30.
     * @param int    $fontSize The font size of the label text (in pixels), 0 (default) means no label.
     *
     * @return static
     */
    public static function create($code, $height = 30, $fontSize = 0)
    {
        return new static($code, $height, $fontSize);
    }

    /**
     * Generate a PNG image of the barcode.
     *
     * @return string The PNG image data.
     */
    public function toPng()
    {
        // Create image with barcode dimensions
        $img = imagecreatetruecolor($this->width, $this->height);
        // Set text color
        $black = ImageColorAllocate($img, 0, 0, 0);
        // Set background color
        $white = ImageColorAllocate($img, 255, 255, 255);
        // Fill the background
        imagefilledrectangle($img, 0, 0, $this->width, $this->height, $white);
        if (!empty($this->datas)) {
            if ($this->fontSize > 0) {
                // Get the dimensions of the code
                $p = imagettfbbox($this->fontSize, 0, $this->font, $this->code);
                $barHeight = $this->height + $p[5] - 3;
                // Display the code
                imagettftext($img, $this->fontSize, 0, floor(($this->width - $p[2]) / 2), $this->height, $black, $this->font, $this->code);
            } else {
                $barHeight = $this->height;
            }
            // Draw bars
            foreach ($this->datas as $i => $data) {
                $x1 = $i * $this->bar_width;
                $x2 = ($i * $this->bar_width) + $this->bar_width;
                $color = $data === '1' ? $black : $white;
                imagefilledrectangle($img, $x1, 0, $x2, $barHeight, $color);
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        // Return the image data
        return ob_get_clean();
    }

    /**
     * แปลงข้อมูล Barcode 128
     *
     * @param $code
     */
    private static function Barcode128($code)
    {
        $len = strlen($code);
        if ($len == 0) {
            // If the code is empty, return an empty string
            return '';
        }
        // Mapping of characters to their corresponding Code 128 encoding
        $characters = array(
            ' ' => '11011001100', '!' => '11001101100', '"' => '11001100110', '#' => '10010011000',
            '$' => '10010001100', '%' => '10001001100', '&' => '10011001000', "'" => '10011000100',
            '(' => '10001100100', ')' => '11001001000', '*' => '11001000100', '+' => '11000100100',
            ',' => '10110011100', '-' => '10011011100', '.' => '10011001110', '/' => '10111001100',
            '0' => '10011101100', '1' => '10011100110', '2' => '11001110010', '3' => '11001011100',
            '4' => '11001001110', '5' => '11011100100', '6' => '11001110100', '7' => '11101101110',
            '8' => '11101001100', '9' => '11100101100', ':' => '11100100110', ';' => '11101100100',
            '<' => '11100110100', '=' => '11100110010', '>' => '11011011000', '?' => '11011000110',
            '@' => '11000110110', 'A' => '10100011000', 'B' => '10001011000', 'C' => '10001000110',
            'D' => '10110001000', 'E' => '10001101000', 'F' => '10001100010', 'G' => '11010001000',
            'H' => '11000101000', 'I' => '11000100010', 'J' => '10110111000', 'K' => '10110001110',
            'L' => '10001101110', 'M' => '10111011000', 'N' => '10111000110', 'O' => '10001110110',
            'P' => '11101110110', 'Q' => '11010001110', 'R' => '11000101110', 'S' => '11011101000',
            'T' => '11011100010', 'U' => '11011101110', 'V' => '11101011000', 'W' => '11101000110',
            'X' => '11100010110', 'Y' => '11101101000', 'Z' => '11101100010', '[' => '11100011010',
            '\\' => '11101111010', ']' => '11001000010', '^' => '11110001010', '_' => '10100110000',
            '`' => '10100001100', 'a' => '10010110000', 'b' => '10010000110', 'c' => '10000101100',
            'd' => '10000100110', 'e' => '10110010000', 'f' => '10110000100', 'g' => '10011010000',
            'h' => '10011000010', 'i' => '10000110100', 'j' => '10000110010', 'k' => '11000010010',
            'l' => '11001010000', 'm' => '11110111010', 'n' => '11000010100', 'o' => '10001111010',
            'p' => '10100111100', 'q' => '10010111100', 'r' => '10010011110', 's' => '10111100100',
            't' => '10011110100', 'u' => '10011110010', 'v' => '11110100100', 'w' => '11110010100',
            'x' => '11110010010', 'y' => '11011011110', 'z' => '11011110110', '{' => '11110110110',
            '|' => '10101111000', '}' => '10100011110', '~' => '10001011110', 'DEL' => '10111101000',
            'FNC 3' => '10111100010', 'FNC 2' => '11110101000', 'SHIFT' => '11110100010', 'CODE C' => '10111011110',
            'CODE B' => '10111101110', 'CODE A' => '11101011110', 'FNC 1' => '11110101110', 'Start A' => '11010000100',
            'Start B' => '11010010000', 'Start C' => '11010011100', 'Stop' => '11000111010'
        );

        // Get the array of valid characters
        $validCharacters = array_keys($characters);

        // Check if the code contains unsupported characters
        $i = 0;
        while ($i < $len) {
            if (!isset($characters[$code[$i]])) {
                // If an unsupported character is found, return an empty string
                return '';
            }
            $i++;
        }

        // Create an array of character codes from the valid characters
        $charactersCode = array_flip($validCharacters);

        // Get the Code 128 encoding for each character
        $encoding = array_values($characters);

        // Determine the barcode type (Type C or Type B)
        $typeC = preg_match('/^[0-9]{2,4}/', $code);
        if ($typeC) {
            $sum = 105;
            // Start type C
            $result = $characters['Start C'];
        } else {
            $sum = 104;
            // Start type B
            $result = $characters['Start B'];
        }

        // Process the data and calculate the checksum
        $i = 0;
        $isum = 0;
        while ($i < $len) {
            if (!$typeC) {
                $j = 0;
                while (($i + $j < $len) && preg_match('/[0-9]/', $code[$i + $j])) {
                    $j++;
                }
                $typeC = ($j > 5) || (($i + $j - 1 == $len) && ($j > 3));
                if ($typeC) {
                    // Code C
                    $result .= $characters['CODE C'];
                    $sum += ++$isum * 99;
                }
            } else if (($i == $len - 1) || (preg_match('/[^0-9]/', $code[$i])) || (preg_match('/[^0-9]/', $code[$i + 1]))) {
                // Code B
                $typeC = false;
                $result .= $characters['CODE B'];
                $sum += ++$isum * 100;
            }
            if ($typeC) {
                // Code C
                $value = intval(substr($code, $i, 2));
                $i += 2;
            } else {
                // Code B
                $value = $charactersCode[$code[$i]];
                $i++;
            }
            $result .= $encoding[$value];
            $sum += ++$isum * $value;
        }

        // Add the checksum and stop pattern to the result
        $result .= $encoding[$sum % 103].$characters['Stop'].'11';

        // Return the generated barcode code
        return $result;
    }
}
