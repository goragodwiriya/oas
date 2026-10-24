<?php
namespace Kotchasan;

/**
 * Kotchasan HtmlTable Class
 *
 * This class provides methods for creating and rendering HTML tables.
 * It supports adding headers, footers, and rows with various attributes.
 *
 * @package Kotchasan
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
        $table = ["\n<table".(empty($prop) ? '' : ' '.implode(' ', $prop)).'>'];
        if (!empty($this->caption)) {
            $table[] = '<caption>'.$this->caption.'</caption>';
        }

        // thead
        if (!empty($this->thead)) {
            $thead = [];
            foreach ($this->thead as $r => $rows) {
                $tr = [];
                foreach ($rows as $c => $th) {
                    $prop = ['id' => 'id="c'.$c.'"', 'scope' => 'scope="col"'];
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

    // =========================================================================
    // Declarative data-table — สร้างตารางตามมาตรฐาน Now.js ปัจจุบัน (TableManager)
    // จาก columns metadata (JSON เดียวกับที่ dynamic-columns ฝั่ง JS ใช้). tbody ว่าง
    // TableManager โหลดแถวจาก data-source เอง. ใช้กับการ generate template file
    // =========================================================================

    /**
     * สร้าง <table data-table ...> declarative จาก columns + options
     *
     * @param array $columns [{field, label, sort?, align?, format?, filter?, type?, template?}]
     * @param array $opt {name, source, actionUrl, sort, pageSize, searchColumns, checkbox, actions, actionButton, rowActions}
     *
     * @return string
     */
    public static function dataTable(array $columns, array $opt = [])
    {
        $t = ['class="table border fullwidth"'];
        if (!empty($opt['name'])) {
            $t[] = 'data-table="'.self::attr($opt['name']).'"';
        }
        if (!empty($opt['source'])) {
            $t[] = 'data-source="'.self::attr($opt['source']).'"';
        }
        $t[] = 'data-default-sort="'.self::attr($opt['sort'] ?? 'id asc').'"';
        if (!empty($opt['pageSize'])) {
            $t[] = 'data-page-size="'.(int) $opt['pageSize'].'"';
        }

        if (!empty($opt['searchColumns'])) {
            $t[] = 'data-search-columns="'.self::attr($opt['searchColumns']).'"';
        }
        if (!empty($opt['checkbox'])) {
            $t[] = 'data-show-checkbox="true"';
        }
        if (!empty($opt['editableRows'])) {
            $t[] = 'data-editable-rows="true"';
        }
        if (!empty($opt['attr'])) {
            $t[] = 'data-attr="data:'.self::attr($opt['attr']).'"';
        }
        if (!empty($opt['dynamicColumns'])) {
            $t[] = 'data-dynamic-columns="true"';
        }
        if (!empty($opt['actions'])) {
            $t[] = "data-actions='".self::attrJson($opt['actions'])."'";
            if (!empty($opt['actionUrl'])) {
                $t[] = 'data-action-url="'.self::attr($opt['actionUrl']).'"';
            }
            $t[] = 'data-action-button="'.self::attr($opt['actionButton'] ?? 'Process|btn-danger').'"';
        }
        if (!empty($opt['rowActions'])) {
            $t[] = "data-row-actions='".self::attrJson($opt['rowActions'])."'";
        }

        $th = '';
        foreach ($columns as $col) {
            $th .= self::dataHead($col);
        }

        return "<div class=\"tablebody\">\n"
        ."  <table ".implode(' ', $t).">\n"
            ."    <thead>\n      <tr>\n".$th."      </tr>\n    </thead>\n"
            ."    <tbody></tbody>\n  </table>\n</div>\n";
    }

    /**
     * สร้าง <th> หนึ่งคอลัมน์ของ data-table
     *
     * @param array $col
     *
     * @return string
     */
    protected static function dataHead(array $col)
    {
        $a = ['data-field="'.self::attr($col['field'] ?? '').'"'];
        if (!empty($col['sort'])) {
            $a[] = 'data-sort="'.self::attr(is_string($col['sort']) ? $col['sort'] : ($col['field'] ?? '')).'"';
        }
        if (!empty($col['align'])) {
            $a[] = 'class="'.self::attr($col['align']).'"';
            $a[] = 'data-cell-class="'.self::attr($col['align']).'"';
        }
        if (!empty($col['format'])) {
            $a[] = 'data-format="'.self::attr($col['format']).'"';
        }
        if (!empty($col['formatter'])) {
            $a[] = 'data-formatter="'.self::attr($col['formatter']).'"';
        }
        if (!empty($col['filter'])) {
            $a[] = 'data-filter="true"';
            $a[] = 'data-type="'.self::attr($col['type'] ?? 'select').'"';
        }
        if (!empty($col['template'])) {
            $a[] = "data-template='".str_replace("'", '&#39;', $col['template'])."'";
        }
        if (isset($col['label'])) {
            $a[] = 'data-i18n';
            $label = '{LNG_'.self::esc($col['label']).'}';
        } else {
            $label = self::esc($col['field'] ?? '');
        }

        return "        <th ".implode(' ', $a).">".$label."</th>\n";
    }

    /**
     * escape ค่าเข้า attribute (double-quoted)
     *
     * @param string $v
     *
     * @return string
     */
    protected static function attr($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * escape text ใน element
     *
     * @param string $v
     *
     * @return string
     */
    protected static function esc($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * json สำหรับ single-quoted attribute (data-actions/data-row-actions)
     *
     * @param mixed $v array หรือ JSON string
     *
     * @return string
     */
    protected static function attrJson($v)
    {
        $json = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return str_replace("'", '&#39;', $json);
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
        $row = ['<tr '.implode(' ', $prop).'>'];
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
