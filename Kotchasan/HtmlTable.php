<?php
/**
 * @filesource Kotchasan/HtmlTable.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * HTML table class
 *
 * @see https://www.kotchasan.com/
 */
class HtmlTable
{
    /**
     * @var string The caption of the table
     */
    private $caption;

    /**
     * @var array The properties of the table
     */
    private $properties;

    /**
     * @var array The rows of the table (tbody)
     */
    private $tbody;

    /**
     * @var array The rows of the table (tfoot)
     */
    private $tfoot;

    /**
     * @var array The headers of the table (thead)
     */
    private $thead;

    /**
     * Constructor.
     *
     * @param array $properties The properties of the table
     */
    public function __construct($properties = [])
    {
        $this->tbody = [];
        $this->tfoot = [];
        $this->thead = [];
        $this->properties = $properties;
    }

    /**
     * Set the caption of the table.
     *
     * @param string $text The caption text
     */
    public function addCaption($text)
    {
        $this->caption = $text;
    }

    /**
     * Add a footer row to the table (tfoot).
     *
     * @param TableRow $row The TableRow object representing the row
     */
    public function addFooter(TableRow $row)
    {
        $this->tfoot[] = $row;
    }

    /**
     * Add a header row to the table (thead).
     *
     * @param array $headers The header data for the row
     */
    public function addHeader($headers)
    {
        $this->thead[] = $headers;
    }

    /**
     * Add a data row to the table (tbody).
     *
     * @param array $rows       The data for the row
     * @param array $attributes The attributes of the row
     */
    public function addRow($rows, $attributes = [])
    {
        $tr = TableRow::create($attributes);
        foreach ($rows as $td) {
            $tr->addCell($td);
        }
        $this->tbody[] = $tr;
    }

    /**
     * Create a new HtmlTable object.
     *
     * @param array $properties The properties of the table
     *
     * @return HtmlTable The created HtmlTable object
     */
    public static function create($properties = [])
    {
        $obj = new static($properties);
        return $obj;
    }

    /**
     * Render the table to HTML.
     *
     * @return string The HTML representation of the table
     */
    public function render()
    {
        $prop = [];
        foreach ($this->properties as $k => $v) {
            $prop[] = $k.'="'.$v.'"';
        }
        $table = array("\n<table".(empty($prop) ? '' : ' '.implode(' ', $prop)).'>');
        if (!empty($this->caption)) {
            $table[] = '<caption>'.$this->caption.'</caption>';
        }

        // thead
        if (!empty($this->thead)) {
            $thead = [];
            foreach ($this->thead as $r => $rows) {
                $tr = [];
                foreach ($rows as $c => $th) {
                    $prop = array('id' => 'id="c'.$c.'"', 'scope' => 'scope="col"');
                    foreach ($th as $key => $value) {
                        if ($key != 'text') {
                            $prop[$key] = $key.'="'.$value.'"';
                        }
                    }
                    $tr[] = '<th '.implode(' ', $prop).'>'.(isset($th['text']) ? $th['text'] : '').'</th>';
                }
                if (!empty($tr)) {
                    $thead[] = "<tr>\n".implode("\n", $tr)."\n</tr>";
                }
            }
            if (!empty($thead)) {
                $table[] = "<thead>\n".implode("\n", $thead)."\n</thead>";
            }
        }

        // tfoot
        if (!empty($this->tfoot)) {
            $rows = [];
            foreach ($this->tfoot as $tr) {
                $rows[] = $tr->render();
            }
            if (!empty($rows)) {
                $table[] = "<tfoot>\n".implode("\n", $rows)."\n</tfoot>";
            }
        }

        // tbody
        if (!empty($this->tbody)) {
            $rows = [];
            foreach ($this->tbody as $tr) {
                $rows[] = $tr->render();
            }
            if (!empty($rows)) {
                $table[] = "<tbody>\n".implode("\n", $rows)."\n</tbody>";
            }
        }

        $table[] = "</table>\n";
        return implode("\n", $table);
    }
}

/**
 * HTML table row class
 *
 * @see https://www.kotchasan.com/
 */
class TableRow
{
    /**
     * @var array The properties of the row
     */
    private $properties;

    /**
     * @var array The cells of the row
     */
    private $tds;

    /**
     * Constructor.
     *
     * @param array $properties The properties of the row
     */
    public function __construct($properties = [])
    {
        $this->properties = $properties;
        $this->tds = [];
    }

    /**
     * Add a cell to the row.
     *
     * @param array $td The data for the cell
     */
    public function addCell($td)
    {
        $this->tds[] = $td;
    }

    /**
     * Create a new TableRow object.
     *
     * @param array $properties The properties of the row
     *
     * @return TableRow The created TableRow object
     */
    public static function create($properties = [])
    {
        $obj = new static($properties);
        return $obj;
    }

    /**
     * Render the row to HTML.
     *
     * @return string The HTML representation of the row
     */
    public function render()
    {
        $prop = [];
        foreach ($this->properties as $key => $value) {
            $prop[$key] = $key.'="'.$value.'"';
        }
        $row = array('<tr '.implode(' ', $prop).'>');
        foreach ($this->tds as $c => $td) {
            $prop = [];
            $tag = 'td';
            foreach ($td as $key => $value) {
                if ($key == 'scope') {
                    $tag = 'th';
                    $prop['scope'] = 'scope="'.$value.'"';
                    if (isset($this->properties['id'])) {
                        $prop['id'] = 'id="r'.$this->properties['id'].'"';
                    }
                } elseif ($key != 'text') {
                    $prop[$key] = $key.'="'.$value.'"';
                }
            }
            if (isset($this->properties['id'])) {
                $prop['headers'] = $tag == 'th' ? 'headers="c'.$c.'"' : 'headers="r'.$this->properties['id'].' c'.$c.'"';
            }
            $tr[] = '<'.$tag.' '.implode(' ', $prop).'>'.(isset($th['text']) ? $th['text'] : '').'</'.$tag.'>';
            $row[] = '<'.$tag.' '.implode(' ', $prop).'>'.(empty($td['text']) ? '' : $td['text']).'</'.$tag.'>';
        }
        $row[] = '</tr>';
        return implode("\n", $row);
    }
}
