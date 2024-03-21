<?php
/**
 * @filesource Kotchasan/Pdf.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Pdf Class
 *
 * @see https://www.kotchasan.com/
 */
class Pdf extends \PDF\FPDF
{
    /**
     * @var mixed
     */
    protected $B;
    /**
     * @var mixed
     */
    protected $I;
    /**
     * @var mixed
     */
    protected $U;
    /**
     * @var mixed
     */
    protected $css;
    /**
     * @var mixed
     */
    protected $cssClass;
    /**
     * @var mixed
     */
    protected $fontSize;
    /**
     * @var mixed
     */
    protected $lastBlock = true;
    /**
     * @var int
     */
    protected $lineHeight = 5;
    /**
     * @var mixed
     */
    protected $link = null;
    /**
     * @var mixed
     */
    protected $unit;

    /**
     * Output a cell
     *
     * @param int $w Width of the cell (automatically calculated if set to 0)
     * @param int $h Line height
     * @param string $txt Text to be displayed
     * @param int|string $border Border style: 0 (no border), 1 (all sides), LTRB (custom sides)
     * @param int $ln Position after drawing: 0 (to the right) (default), 1 (start of next line), 2 (below)
     * @param string $align Text alignment: L (left align) (default), R (right align), C (center), J (justify)
     * @param bool $fill Fill color: true (background color), false (transparent)
     * @param string $link URL link
     * @param int $tPadding Top padding
     * @param int $rPadding Right padding
     * @param int $bPadding Bottom padding
     * @param int $lPadding Left padding
     */
    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $tPadding = 0, $rPadding = 0, $bPadding = 0, $lPadding = 0)
    {
        $k = $this->k;
        if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            // Automatic page break
            $x = $this->x;
            $ws = $this->ws;
            if ($ws > 0) {
                $this->ws = 0;
                $this->_out('0 Tw');
            }
            $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
            $this->x = $x;
            if ($ws > 0) {
                $this->ws = $ws;
                $this->_out(sprintf('%.3F Tw', $ws * $k));
            }
        }
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $s = '';
        if ($fill || $border == 1) {
            if ($fill) {
                $op = ($border == 1) ? 'B' : 'f';
            } else {
                $op = 'S';
            }
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x * $k, ($this->h - $this->y + $tPadding) * $k, $w * $k, (-$h - $bPadding - $tPadding) * $k, $op);
        }
        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (strpos($border, 'L') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y + $tPadding) * $k, $x * $k, ($this->h - ($y + $h) - $bPadding) * $k);
            }
            if (strpos($border, 'T') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y + $tPadding) * $k, ($x + $w) * $k, ($this->h - $y + $tPadding) * $k);
            }
            if (strpos($border, 'R') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->h - $y + $tPadding) * $k, ($x + $w) * $k, ($this->h - ($y + $h) - $bPadding) * $k);
            }
            if (strpos($border, 'B') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - ($y + $h) - $bPadding) * $k, ($x + $w) * $k, ($this->h - ($y + $h) - $bPadding) * $k);
            }
        }
        if ($txt !== '') {
            if (!isset($this->CurrentFont)) {
                $this->Error('No font has been set');
            }
            if ($align == 'R') {
                $dx = $w - $this->cMargin - $this->GetStringWidth($txt) - $rPadding;
            } elseif ($align == 'C') {
                $dx = ($w - $this->GetStringWidth($txt)) / 2;
            } else {
                $dx = $this->cMargin + $lPadding;
            }
            if ($this->ColorFlag) {
                $s .= 'q '.$this->TextColor.' ';
            }
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x + $dx) * $k, ($this->h - ($this->y + .5 * $h + .3 * $this->FontSize)) * $k, $this->_escape($txt));
            if ($this->underline) {
                $s .= ' '.$this->_dounderline($this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $txt);
            }
            if ($this->ColorFlag) {
                $s .= ' Q';
            }
            if ($link) {
                $this->Link($this->x + $dx, $this->y + .5 * $h - .5 * $this->FontSize, $this->GetStringWidth($txt), $this->FontSize, $link);
            }
        }
        if ($s) {
            $this->_out($s);
        }
        $this->lasth = $h;
        if ($ln > 0) {
            // Go to next line
            $this->y += $h;
            if ($ln == 1) {
                $this->x = $this->lMargin;
            }
        } else {
            $this->x += $w;
        }
    }

    /**
     * Line break.
     *
     * @param int $h line-height. If not specified, the last used value is used.
     */
    public function Ln($h = null)
    {
        // Line break
        $this->x = $this->lMargin;
        $this->y += ($h ? $h : $this->lineHeight);
        // Indicate line break
        $this->lastBlock = true;
    }

    /**
     * Output text with automatic or explicit line breaks.
     *
     * @param int        $w        Width. Automatically calculated if set to 0.
     * @param int        $h        Line-height.
     * @param string     $s        Text to display.
     * @param int|string $border   Border style. 0 for no border, 1 for border on all sides, LTRB for custom sides.
     * @param string     $align    Alignment. L (or empty) for left-aligned, R for right-aligned, C for center, J for justified (default).
     * @param bool       $fill     true to display background, false for transparent.
     * @param int        $tPadding Top padding.
     * @param int        $rPadding Right padding.
     * @param int        $bPadding Bottom padding.
     * @param int        $lPadding Left padding.
     */
    public function MultiCell($w, $h, $s, $border = 0, $align = 'J', $fill = false, $tPadding = 0, $rPadding = 0, $bPadding = 0, $lPadding = 0)
    {
        if (!isset($this->CurrentFont)) {
            $this->Error('No font has been set');
        }
        if ($border == 1) {
            $border = 'RLTB';
        }
        $this->y += $tPadding;
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $cell_width = $this->w - $this->rMargin - $this->x;
            $w = $cell_width - $lPadding - $rPadding;
        } else {
            $cell_width = $w;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            --$nb;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while ($i < $nb) {
            // Next character
            $c = $s[$i];
            if ($c == "\n") {
                // Line break
                if ($this->ws > 0) {
                    $this->ws = 0;
                    $this->_out('0 Tw');
                }
                if ($nl == 1) {
                    // First line
                    $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
                } else {
                    $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
                }
                ++$i;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                ++$nl;
                continue;
            } elseif ($c == ' ') {
                $sep = $i;
                $ls = $l;
                ++$ns;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                // Line break when text exceeds document width
                if ($sep == -1) {
                    if ($i == $j) {
                        ++$i;
                    }
                    if ($this->ws > 0) {
                        $this->ws = 0;
                        $this->_out('0 Tw');
                    }
                    if ($nl == 1) {
                        // First line
                        $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
                    } else {
                        $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
                    }
                } else {
                    if ($align == 'J') {
                        $this->ws = ($ns > 1) ? ($wmax - $ls) / 1000 * $this->FontSize / ($ns - 1) : 0;
                        $this->_out(sprintf('%.3F Tw', $this->ws * $this->k));
                    }
                    if ($nl == 1) {
                        // First line
                        $this->Cell($cell_width, $h, substr($s, $j, $sep - $j), str_replace('B', '', $border), 2, $align, $fill, '', $tPadding, $rPadding, 0, $lPadding);
                    } else {
                        $this->Cell($cell_width, $h, substr($s, $j, $sep - $j), str_replace(array('T', 'B'), '', $border), 2, $align, $fill, '', 0, $rPadding, 0, $lPadding);
                    }
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                ++$nl;
            } else {
                ++$i;
            }
        }
        // Last chunk
        if ($this->ws > 0) {
            $this->ws = 0;
            $this->_out('0 Tw');
        }
        if ($nl == 1) {
            // Single line
            $this->Cell($cell_width, $h, substr($s, $j, $i - $j), $border, 2, $align, $fill, '', $tPadding, $rPadding, $bPadding, $lPadding);
        } else {
            // Last line
            $this->Cell($cell_width, $h, substr($s, $j, $i - $j), str_replace('T', '', $border), 2, $align, $fill, '', 0, $rPadding, $bPadding, $lPadding);
        }
        $this->y += $bPadding;
        $this->x = $this->lMargin;
    }

    /**
     * Set the style attributes for a class.
     *
     * @param string $className
     * @param array  $attributes
     */
    public function SetCssClass($className, $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->cssClass[strtoupper($className)][strtoupper($key)] = $value;
        }
    }

    /**
     * Set the style of a tag.
     *
     * @param string $tag
     * @param array  $attributes
     */
    public function SetStyles($tag, $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->css[strtoupper($tag)][strtoupper($key)] = $value;
        }
    }

    /**
     * Create a PDF from HTML code.
     * Render the output based on the format specified by Kosit.
     *
     * @param string $html    HTML4 code
     * @param string $charset default cp874 (Thai language)
     */
    public function WriteHTML($html, $charset = 'cp874')
    {
        // Parse HTML
        $dom = new DOMParser($html, $charset);
        // Render
        foreach ($dom->nodes() as $node) {
            $this->render($node);
        }
    }

    /**
     * Create FPDF for Thai language.
     *
     * @param string $orientation
     * @param string $unit
     * @param string $size
     * @param int    $fontSize
     */
    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4', $fontSize = 10)
    {
        // Create FPDF
        parent::__construct($orientation, $unit, $size);
        // Default variable values
        $this->B = 0;
        $this->I = 0;
        $this->U = 0;
        $this->unit = $unit;
        $this->fontSize = $fontSize;
        // Thai fonts
        $this->AddFont('loma', '', 'Loma.php');
        $this->AddFont('loma', 'B', 'Loma-Bold.php');
        $this->AddFont('loma', 'I', 'Loma-Oblique.php');
        $this->AddFont('loma', 'BI', 'Loma-BoldOblique.php');
        $this->AddFont('angsana', '', 'angsa.php');
        $this->AddFont('angsana', 'B', 'angsab.php');
        $this->AddFont('angsana', 'I', 'angsai.php');
        $this->AddFont('angsana', 'BI', 'angsaz.php');
        // Default font
        $this->SetFont('loma', '', $this->fontSize);
        // Default styles
        $this->css = array(
            'H1' => array(
                'SIZE' => $this->fontSize + 10,
                'LINE-HEIGHT' => $this->lineHeight + 2.5
            ),
            'H2' => array(
                'SIZE' => $this->fontSize + 8,
                'LINE-HEIGHT' => $this->lineHeight + 2
            ),
            'H3' => array(
                'SIZE' => $this->fontSize + 6,
                'LINE-HEIGHT' => $this->lineHeight + 1.5
            ),
            'H4' => array(
                'SIZE' => $this->fontSize + 4,
                'LINE-HEIGHT' => $this->lineHeight + 1
            ),
            'H5' => array(
                'SIZE' => $this->fontSize + 2,
                'LINE-HEIGHT' => $this->lineHeight + 0.5
            ),
            'EM' => array(
                'COLOR' => '#FF5722'
            ),
            'I' => array(
                'FONT-STYLE' => 'ITALIC'
            ),
            'B' => array(
                'FONT-WEIGHT' => 'BOLD'
            ),
            'STRONG' => array(
                'FONT-WEIGHT' => 'BOLD'
            ),
            'U' => array(
                'TEXT-DECORATION' => 'UNDERLINE'
            ),
            'A' => array(
                'TEXT-DECORATION' => 'UNDERLINE'
            ),
            'BLOCKQUOTE' => array(
                'BORDER-COLOR' => '#DDDDDD',
                'BACKGROUND-COLOR' => '#F9F9F9',
                'COLOR' => '#666666',
                'SIZE' => $this->fontSize - 1,
                'PADDING' => 2
            ),
            'CODE' => array(
                'BORDER-COLOR' => '#DDDDDD',
                'BACKGROUND-COLOR' => '#F9F9F9',
                'COLOR' => '#666666',
                'SIZE' => $this->fontSize + 4,
                'PADDING' => 2,
                'DISPLAY' => 'BLOCK',
                'FONT-FAMILY' => 'angsana',
                'FONT-STYLE' => 'ITALIC'
            ),
            'TABLE' => array(
                'BORDER-COLOR' => '#DDDDDD'
            ),
            'TH' => array(
                'TEXT-ALIGN' => 'CENTER',
                'BACKGROUND-COLOR' => '#EEEEEE'
            ),
            'TD' => array(
                'COLOR' => '#333333'
            )
        );
        // Class styles
        $this->cssClass = array(
            'COMMENT' => array(
                'SIZE' => $this->fontSize - 1,
                'COLOR' => '#259B24'
            ),
            'CENTER' => array(
                'TEXT-ALIGN' => 'CENTER'
            ),
            'LEFT' => array(
                'TEXT-ALIGN' => 'LEFT'
            ),
            'RIGHT' => array(
                'TEXT-ALIGN' => 'RIGHT'
            ),
            'BG2' => array(
                'BACKGROUND-COLOR' => '#F9F9F9'
            ),
            'FULLWIDTH' => array(
                'WIDTH' => '100%'
            )
        );
    }

    /**
     * Check the height of a table. If the height exceeds the page,
     * start a new page.
     *
     * @param int $h Table height
     */
    protected function CheckPageBreak($h)
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }

    /**
     * Calculate the height of a cell.
     *
     * @param int    $w Width of the cell
     * @param string $s Text content of the cell
     *
     * @return int Height of the cell
     */
    protected function NbLines($w, $s)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            --$nb;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                ++$i;
                $sep = -1;
                $j = $i;
                $l = 0;
                ++$nl;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        ++$i;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                ++$nl;
            } else {
                ++$i;
            }
        }
        return $nl;
    }

    /**
     * Set bold, italic, or underline style for rendering text.
     *
     * @param string $style  B, I, or U
     * @param bool   $enable true to enable, false to disable
     */
    protected function SetStyle($style, $enable)
    {
        $this->{$style} += ($enable ? 1 : -1);
        $font_style = '';
        foreach (array('B', 'I', 'U') as $s) {
            if ($this->{$s} > 0) {
                $font_style .= $s;
            }
        }
        $this->SetFont('', $font_style);
    }

    /**
     * Apply CSS styles to the document.
     *
     * @param DOMNode $node DOM node
     */
    protected function applyCSS($node)
    {
        // Font family
        if (!empty($node->attributes['FONT-FAMILY'])) {
            $node->FontFamily = $this->FontFamily;
            $this->SetFont($node->attributes['FONT-FAMILY']);
        }
        // Text color
        if (!empty($node->attributes['COLOR'])) {
            if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sr)?g/', $this->TextColor, $match)) {
                $node->TextColor = array(
                    'r' => $match[1],
                    'g' => isset($match[3]) ? $match[3] : null,
                    'b' => isset($match[4]) ? $match[4] : null
                );
            }
            list($r, $g, $b) = $this->colorToRGb($node->attributes['COLOR']);
            $this->SetTextColor($r, $g, $b);
        }
        // Background color
        if (!empty($node->attributes['BACKGROUND-COLOR'])) {
            if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sr)?g/', $this->FillColor, $match)) {
                $node->FillColor = array(
                    'r' => $match[1],
                    'g' => isset($match[3]) ? $match[3] : null,
                    'b' => isset($match[4]) ? $match[4] : null
                );
            }
            list($r, $g, $b) = $this->colorToRGb($node->attributes['BACKGROUND-COLOR']);
            $this->SetFillColor($r, $g, $b);
        }
        // Border color
        if (!empty($node->attributes['BORDER-COLOR'])) {
            if (preg_match('/([0-9\.]+)\s(([0-9\.]+)\s([0-9\.]+)\sR)?G/', $this->DrawColor, $match)) {
                $node->DrawColor = array(
                    'r' => $match[1],
                    'g' => isset($match[3]) ? $match[3] : null,
                    'b' => isset($match[4]) ? $match[4] : null
                );
            }
            list($r, $g, $b) = $this->colorToRGb($node->attributes['BORDER-COLOR']);
            $this->SetDrawColor($r, $g, $b);
        }
        // Font weight
        if (!empty($node->attributes['FONT-WEIGHT'])) {
            $this->SetStyle('B', $node->attributes['FONT-WEIGHT'] == 'BOLD');
        }
        // Font style
        if (!empty($node->attributes['FONT-STYLE'])) {
            $this->SetStyle('I', $node->attributes['FONT-STYLE'] == 'ITALIC');
        }
        // Text decoration
        if (!empty($node->attributes['TEXT-DECORATION'])) {
            $this->SetStyle('U', $node->attributes['TEXT-DECORATION'] == 'UNDERLINE');
        }
        // Font size
        if (!empty($node->attributes['SIZE'])) {
            $node->FontSizePt = $this->FontSizePt;
            $this->SetFontSize($node->attributes['SIZE']);
        }
    }

    /**
     * Calculate the width of columns as a percentage.
     *
     * @param DOMNode $table The table node.
     *
     * @return array An array of calculated column widths.
     */
    protected function calculateColumnsWidth($table)
    {
        // Page width
        $cw = $this->w - $this->lMargin - $this->rMargin;

        // Table width
        if (!empty($table->attributes['WIDTH'])) {
            $table_width = $this->calculateSize($table->attributes['WIDTH'], $cw);
        }

        $columnSizes = [];

        foreach ($table->childNodes as $child) {
            foreach ($child->childNodes as $tr) {
                foreach ($tr->childNodes as $col => $td) {
                    // Set node value
                    $td->nodeValue = $td->nodeText();
                    // Calculate text width
                    $td->textWidth = $this->GetStringWidth($td->nodeValue);
                    // Remove child nodes
                    unset($td->childNodes);

                    $length = isset($table_width) && !empty($td->attributes['WIDTH']) ? $this->calculateSize($td->attributes['WIDTH'], $table_width) : $td->textWidth;

                    $columnSizes[$col]['max'] = !isset($columnSizes[$col]['max']) ? $length : ($columnSizes[$col]['max'] < $length ? $length : $columnSizes[$col]['max']);
                    $columnSizes[$col]['avg'] = !isset($columnSizes[$col]['avg']) ? $length : $columnSizes[$col]['avg'] + $length;
                    $columnSizes[$col]['raw'][] = $length;
                }
            }
        }

        $columnSizes = array_map(function ($columnSize) {
            $columnSize['avg'] = $columnSize['avg'] / sizeof($columnSize['raw']);
            return $columnSize;
        }, $columnSizes);

        foreach ($columnSizes as $key => $columnSize) {
            $colMaxSize = $columnSize['max'];
            $colAvgSize = $columnSize['avg'];
            $stdDeviation = $this->sd($columnSize['raw']);
            $coefficientVariation = $stdDeviation / $colAvgSize;
            $columnSizes[$key]['cv'] = $coefficientVariation;
            $columnSizes[$key]['stdd'] = $stdDeviation;
            $columnSizes[$key]['stdd/max'] = $stdDeviation / $colMaxSize;

            if (($columnSizes[$key]['stdd/max'] < 0.3 || $coefficientVariation == 1) && ($coefficientVariation == 0 || ($coefficientVariation > 0.6 && $coefficientVariation < 1.5))) {
                $columnSizes[$key]['calc'] = $colAvgSize;
            } else {
                if ($coefficientVariation > 1 && $columnSizes[$key]['stdd'] > 4.5 && $columnSizes[$key]['stdd/max'] > 0.2) {
                    $tmp = ($colMaxSize - $colAvgSize) / 2;
                } else {
                    $tmp = 0;
                }

                $columnSizes[$key]['calc'] = $colAvgSize + ($colMaxSize / $colAvgSize) * 2 / abs(1 - $coefficientVariation);
                $columnSizes[$key]['calc'] = $columnSizes[$key]['calc'] > $colMaxSize ? $colMaxSize - $tmp : $columnSizes[$key]['calc'];
            }
        }

        $totalCalculatedSize = 0;

        foreach ($columnSizes as $columnSize) {
            $totalCalculatedSize += $columnSize['calc'];
        }

        $result = [];

        foreach ($columnSizes as $key => $columnSize) {
            if (empty($table_width)) {
                $result[$key] = 100 / ($totalCalculatedSize / $columnSize['calc']);
            } else {
                $result[$key] = ($columnSize['calc'] * $table_width) / $totalCalculatedSize;
            }
        }

        return $result;
    }

    /**
     * Calculate the size.
     *
     * @param int|string $size The size value (e.g., "100%", "20px").
     * @param int $max_size The maximum size.
     *
     * @return int The calculated size.
     */
    protected function calculateSize($size, $max_size)
    {
        if (preg_match('/^([0-9]+)(px|pt|mm|cm|in|\%)?$/', strtolower($size), $match)) {
            if ($match[2] == '%') {
                return ($max_size * (int) $match[1]) / 100;
            } else {
                return (int) $match[1];
            }
        }

        return (int) $size;
    }

    /**
     * Convert HTML hex color value, e.g., #FF0000, to RGB color value.
     * Returns an array [$r, $g, $b], e.g., #FF0000 = [255, 0, 0].
     *
     * @param string $color The HTML hex color value, e.g., #FF0000.
     *
     * @return array The RGB color value as an array.
     */
    protected function colorToRGb($color)
    {
        return [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2))
        ];
    }

    /**
     * Draw a horizontal line.
     *
     * @param DOMNode $node The node.
     *
     * @return void
     */
    protected function drawHr($node)
    {
        // New line height
        $ln = 2;

        if (!$this->lastBlock) {
            if ($node->attributes['DISPLAY'] !== 'INLINE') {
                $ln = 7;
            } elseif ($node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
                $ln = 7;
            }
        }

        $this->Ln($ln);
        // Current position
        $x = $this->GetX();
        $y = $this->GetY();
        // Client width
        $cw = $this->w - $this->lMargin - $this->rMargin;

        if (empty($node->attributes['WIDTH'])) {
            // Width 100%
            $w = $cw;
        } else {
            // Custom width
            $w = $this->calculateSize($node->attributes['WIDTH'], $cw);

            if (!empty($node->attributes['ALIGN']) && $cw > $w) {
                switch (strtoupper($node->attributes['ALIGN'])) {
                    case 'CENTER':
                        $x = ($cw - $w) / 2;
                        break;
                    case 'RIGHT':
                        $x = $cw - $w;
                        break;
                }
            }
        }

        if (!empty($node->attributes['COLOR'])) {
            $node->DrawColor = $this->DrawColor;
            list($r, $g, $b) = $this->colorToRGb($node->attributes['COLOR']);
            $this->SetDrawColor($r, $g, $b);
        }

        $lineWidth = $this->LineWidth;
        $this->SetLineWidth(0.4);
        $this->Line($x, $y, $x + $w, $y);
        $this->SetLineWidth($lineWidth);

        if (!empty($node->attributes['COLOR'])) {
            $this->DrawColor = $node->DrawColor;
        }

        // New line
        $this->Ln(2);
    }

    /**
     * Draw an image.
     *
     * @param DOMNode $node The node.
     */
    protected function drawImg($node)
    {
        if (isset($node->attributes['SRC']) && file_exists($node->attributes['SRC'])) {
            list($left, $top, $width, $height) = $this->resizeImage($node);

            if ($node->parentNode->nodeName == 'FIGURE') {
                $this->Image($node->attributes['SRC'], $left, $top, $width, $height);
                $this->lastBlock = true;
            } else {
                if ($node->attributes['DISPLAY'] == 'INLINE' && $node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
                    // New line
                    $x = $this->lMargin;
                    $y = $this->y + $this->lineHeight;
                } else {
                    // Current X and Y
                    $x = $this->GetX();
                    $y = $this->GetY();
                }

                $this->Image($node->attributes['SRC'], $x, $y);
                $this->x = $x + $width;
                $this->y = $y;
                $this->lastBlock = false;
            }
        }
    }

    /**
     * Draws a table.
     *
     * @param DOMNode $table The table node.
     */
    protected function drawTable($table)
    {
        if (!$this->lastBlock) {
            $this->Ln();
        }

        // Calculate column widths
        $columnSizes = $this->calculateColumnsWidth($table);

        // Apply CSS
        $this->applyCSS($table);

        // Line height
        $lineHeight = $this->lineHeight + 2;

        // Process thead, tbody, tfoot
        foreach ($table->childNodes as $tableGroup) {
            foreach ($tableGroup->childNodes as $tr) {
                // Load node's CSS
                $this->loadStyle($tr);

                // Calculate row height
                $h = 0;
                foreach ($tr->childNodes as $col => $td) {
                    // Apply CSS from tr
                    foreach ($tr->attributes as $key => $value) {
                        $td->attributes[$key] = $value;
                    }

                    // Load node's CSS
                    $this->loadStyle($td);

                    // Calculate number of text lines
                    $h = max($h, $this->NbLines($columnSizes[$col], $td->nodeValue));
                }
                $h = $h * $lineHeight;

                // Check page break
                $this->CheckPageBreak($h);

                // Display content
                $y = $this->y;
                foreach ($tr->childNodes as $col => $td) {
                    // Apply CSS
                    $this->applyCSS($td);

                    $align = '';
                    if (!empty($td->attributes['TEXT-ALIGN'])) {
                        $align = $td->attributes['TEXT-ALIGN'][0];
                    }

                    // Current x position
                    $x = $this->x;

                    // Background and border
                    $this->Cell($columnSizes[$col], $h, '', 1, 0, '', !empty($td->attributes['BACKGROUND-COLOR']));

                    // Restore position
                    $this->x = $x;
                    $this->y = $y;

                    // Draw text
                    $this->MultiCell($columnSizes[$col], $lineHeight, $td->nodeValue, 0, $align);

                    // Move to next cell
                    $this->x = $x + $columnSizes[$col];
                    $this->y = $y;

                    // Restore CSS
                    $this->restoredCSS($td);
                }

                $this->SetXY($this->lMargin, $y + $h);
            }
        }

        // Restore CSS
        $this->restoredCSS($table);

        // New line
        $this->lastBlock = true;
    }

    /**
     * Loads the CSS of a node.
     *
     * @param DOMNode $node The node to load the CSS for.
     */
    protected function loadStyle($node)
    {
        // Display
        $node->attributes['DISPLAY'] = $node->isInlineElement() ? 'INLINE' : 'BLOCK';

        // Default styles
        if (isset($this->css[$node->nodeName])) {
            foreach ($this->css[$node->nodeName] as $key => $value) {
                $node->attributes[$key] = $value;
            }
        }

        // Styles from 'style' property
        if (!empty($node->attributes['STYLE'])) {
            foreach (explode(';', strtoupper($node->attributes['STYLE'])) as $style) {
                if (preg_match('/^([A-Z\-]+)[\s]{0,}\:[\s]{0,}([A-Z0-9\-]+).*?/', trim($style), $match)) {
                    $node->attributes[$match[1]] = $match[2];
                }
            }
            unset($node->attributes['STYLE']);
        }

        // Styles from class
        if (isset($node->attributes['CLASS'])) {
            foreach (explode(' ', $node->attributes['CLASS']) as $class) {
                $class = strtoupper($class);
                if (isset($this->cssClass[$class])) {
                    foreach ($this->cssClass[$class] as $key => $value) {
                        if (!isset($node->attributes[$key])) {
                            $node->attributes[$key] = $value;
                        }
                    }
                }
            }
            unset($node->attributes['CLASS']);
        }

        // Padding
        if (!empty($node->attributes['PADDING'])) {
            $value = (int) $node->attributes['PADDING'];
            if (!isset($node->attributes['PADDING-LEFT'])) {
                $node->attributes['PADDING-LEFT'] = $value;
            }
            if (!isset($node->attributes['PADDING-TOP'])) {
                $node->attributes['PADDING-TOP'] = $value;
            }
            if (!isset($node->attributes['PADDING-RIGHT'])) {
                $node->attributes['PADDING-RIGHT'] = $value;
            }
            if (!isset($node->attributes['PADDING-BOTTOM'])) {
                $node->attributes['PADDING-BOTTOM'] = $value;
            }
            unset($node->attributes['PADDING']);
        }
    }

    /**
     * Renders a node.
     *
     * @param DOMNode $node The node to render.
     * @return void
     */
    protected function render($node)
    {
        if ($node->nodeName == '') {
            // Text node
            $node->attributes['DISPLAY'] = 'INLINE';
            $lineHeight = empty($node->parentNode->attributes['LINE-HEIGHT']) ? $this->lineHeight : $node->parentNode->attributes['LINE-HEIGHT'];

            if ($node->parentNode && $node->parentNode->attributes['DISPLAY'] !== 'INLINE' && sizeof($node->parentNode->childNodes) == 1) {
                // Block node
                $align = empty($node->parentNode->attributes['TEXT-ALIGN']) ? '' : $node->parentNode->attributes['TEXT-ALIGN'][0];
                $border = empty($node->parentNode->attributes['BORDER-COLOR']) ? 0 : 1;
                $fill = empty($node->parentNode->attributes['BACKGROUND-COLOR']) ? false : true;
                $tPadding = empty($node->parentNode->attributes['PADDING-TOP']) ? 0 : $node->parentNode->attributes['PADDING-TOP'];
                $rPadding = empty($node->parentNode->attributes['PADDING-RIGHT']) ? 0 : $node->parentNode->attributes['PADDING-RIGHT'];
                $bPadding = empty($node->parentNode->attributes['PADDING-BOTTOM']) ? 0 : $node->parentNode->attributes['PADDING-BOTTOM'];
                $lPadding = empty($node->parentNode->attributes['PADDING-LEFT']) ? 0 : $node->parentNode->attributes['PADDING-LEFT'];

                $this->MultiCell(0, $lineHeight, $node->unentities($node->nodeValue), $border, $align, $fill, $tPadding, $rPadding, $bPadding, $lPadding);
                $this->lastBlock = true;
            } else {
                // Inline node
                if ($this->link) {
                    // Link
                    $this->Write($lineHeight, $node->unentities($node->nodeValue), $this->link);
                } else {
                    // Text
                    $this->Write($lineHeight, $node->unentities($node->nodeValue));
                }
                $this->lastBlock = false;
            }
        } else {
            // Read node's CSS
            $this->loadStyle($node);

            // Open tag
            if ($node->nodeName == 'BR') {
                // Line break
                $this->Ln();
            } elseif ($node->nodeName == 'IMG') {
                // Image
                $this->drawImg($node);
            } elseif ($node->nodeName == 'HR') {
                // Horizontal rule
                $this->drawHr($node);
            } elseif ($node->nodeName == 'TABLE') {
                // Table
                $this->drawTable($node);
            } else {
                // Line break
                if (!$this->lastBlock) {
                    if ($node->attributes['DISPLAY'] !== 'INLINE') {
                        $this->Ln();
                    } elseif ($node->previousSibling && $node->previousSibling->attributes['DISPLAY'] !== 'INLINE') {
                        $this->Ln();
                    }
                }

                // Link
                if ($node->nodeName == 'A' && !empty($node->attributes['HREF'])) {
                    $this->link = $node->attributes['HREF'];
                }

                // Apply CSS
                $this->applyCSS($node);

                // Render child nodes
                foreach ($node->childNodes as $child) {
                    $this->render($child);
                }

                // Restore CSS
                $this->restoredCSS($node);
            }
        }
    }

    /**
     * Resizes an image.
     *
     * @param $node The node containing the image.
     *
     * @return array An array containing the coordinates and dimensions of the resized image.
     */
    protected function resizeImage($node)
    {
        // Get the width and height of the image
        list($width, $height) = getimagesize($node->attributes['SRC']);

        if ($width < $this->wPt && $height < $this->hPt) {
            // Calculate the scaling factor
            $k = 72 / 96 / $this->k;
            $l = null;

            if (isset($node->parentNode->attributes['TEXT-ALIGN'])) {
                // Determine the horizontal position based on the text alignment
                switch ($node->parentNode->attributes['TEXT-ALIGN']) {
                    case 'CENTER':
                        $l = ($this->w - ($width * $k)) / 2;
                        break;
                    case 'RIGHT':
                        $l = ($this->w - ($width * $k));
                        break;
                }
            }

            // Return the coordinates and dimensions of the resized image
            return array($l, null, $width * $k, $height * $k);
        } else {
            // Calculate the scaling factors for width and height
            $ws = $this->wPt / $width;
            $hs = $this->hPt / $height;
            $scale = min($ws, $hs);

            // Determine the scaling factor based on the unit
            if ($this->unit == 'pt') {
                $k = 1;
            } elseif ($this->unit == 'mm') {
                $k = 25.4 / 72;
            } elseif ($this->unit == 'cm') {
                $k = 2.54 / 72;
            } elseif ($this->unit == 'in') {
                $k = 1 / 72;
            }

            // Return the coordinates and dimensions of the resized image
            return array(null, null, ((($scale * $width) - 56.7) * $k), ((($scale * $height) - 56.7) * $k));
        }
    }

    /**
     * Restores CSS properties for a given DOMNode.
     *
     * @param object $node The DOMNode object to restore CSS properties for.
     */
    protected function restoredCSS($node)
    {
        // Restore font family
        if (!empty($node->attributes['FONT-FAMILY'])) {
            $this->SetFont($node->FontFamily);
        }

        // Restore border color
        if (!empty($node->attributes['BORDER-COLOR']) && isset($node->DrawColor)) {
            $this->SetDrawColor($node->DrawColor['r'], $node->DrawColor['g'], $node->DrawColor['b']);
        }

        // Restore background color
        if (!empty($node->attributes['BACKGROUND-COLOR']) && isset($node->FillColor)) {
            $this->SetFillColor($node->FillColor['r'], $node->FillColor['g'], $node->FillColor['b']);
        }

        // Restore text color
        if (!empty($node->attributes['COLOR']) && isset($node->TextColor)) {
            $this->SetTextColor($node->TextColor['r'], $node->TextColor['g'], $node->TextColor['b']);
        }

        // Restore font weight (bold)
        if (!empty($node->attributes['FONT-WEIGHT'])) {
            $this->SetStyle('B', $node->attributes['FONT-WEIGHT'] != 'BOLD');
        }

        // Restore font style (italic)
        if (!empty($node->attributes['FONT-STYLE'])) {
            $this->SetStyle('I', $node->attributes['FONT-STYLE'] != 'ITALIC');
        }

        // Restore text decoration (underline)
        if (!empty($node->attributes['TEXT-DECORATION'])) {
            $this->SetStyle('U', $node->attributes['TEXT-DECORATION'] != 'UNDERLINE');
        }

        // Restore font size
        if (!empty($node->attributes['SIZE'])) {
            $this->SetFontSize($node->FontSizePt);
        }
    }

    /**
     * calculate standard deviation
     *
     * @param  $array
     *
     * @return float
     */
    protected function sd($array)
    {
        if (sizeof($array) == 1) {
            return 1.0;
        }
        $sd_square = function ($x, $mean) {
            return pow($x - $mean, 2);
        };
        return sqrt(array_sum(array_map($sd_square, $array, array_fill(0, sizeof($array), (array_sum($array) / sizeof($array))))) / (sizeof($array) - 1));
    }
}
