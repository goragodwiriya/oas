<?php
/**
 * @filesource Kotchasan/DataTable.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

use Kotchasan\Http\Uri;

/**
 * Class for managing data presentation from a Model in table format.
 *
 * @see https://www.kotchasan.com/
 */
class DataTable extends \Kotchasan\KBase
{
    /**
     * Table ID.
     *
     * @var string
     */
    private $id;
    /**
     * Table class.
     *
     * @var string
     */
    private $class;
    /**
     * Name of the Model to retrieve data from.
     *
     * @var \Kotchasan\Database\QueryBuilder
     */
    private $model;
    /**
     * All data of the table in array format.
     * If the table does not connect to a Model, specify the data here.
     *
     * @var array
     */
    private $datas;
    /**
     * URL for reading data using Ajax.
     * Returns JSON data based on columns.
     *
     * @var string
     */
    private $url = null;
    /**
     * Array data to be sent to $url when called by Ajax.
     *
     * @var array
     */
    private $params = [];
    /**
     * Database cache.
     *
     * @var bool
     */
    private $cache = false;
    /**
     * List of fields to query.
     *
     * @var array
     */
    private $fields = [];
    /**
     * Column index of the checkbox.
     * -1 to hide the checkbox.
     *
     * @var int
     */
    private $checkCol = -1;
    /**
     * Determines whether to hide the checkbox.
     * If set to true, the checkbox will always be hidden.
     *
     * @var bool
     */
    public $hideCheckbox = false;
    /**
     * Displays the table with 100% width.
     *
     * @var bool
     */
    private $fullWidth = true;
    /**
     * Displays the table with borders.
     *
     * @var bool
     */
    private $border = false;
    /**
     * Displays buttons for adding and deleting rows.
     *
     * @var bool
     */
    private $pmButton = false;
    /**
     * Displays the table in responsive mode.
     *
     * @var bool
     */
    private $responsive = false;
    /**
     * Displays the table caption.
     *
     * @var bool
     */
    private $showCaption = true;

    /**
     * URL for receiving actions such as delete.
     * Format: index/[controller|model]/className/method.php
     *
     * @var string
     */
    private $action;
    /**
     * If specified, checkboxes and action buttons will be shown.
     * Example: array('delete' => Language::get('Delete'), 'published' => Language::get('Published'))
     * This means a select option will be shown for deleting and publishing.
     *
     * @var array
     */
    public $actions = [];
    /**
     * Name of the Javascript function to call after sending data from action.
     * Example: doFormSubmit
     *
     * @var string
     */
    private $actionCallback;

    /**
     * Name of the JavaScript function called after clicking an action.
     * For example, confirmAction(text, action, id)
     *
     * @var string
     */
    private $actionConfirm;

    /**
     * Method to handle each row's data before displaying.
     * function($item, $index, $prop)
     * $item: array of data
     * $row: index of the row (key)
     * $prop: array of properties for the tr element, e.g., $prop[0]['id'] = xxx
     *
     * @var array array($this, methodName)
     */
    private $onRow;
    /**
     * Name of the JavaScript function called before deleting a row (pmButton).
     * If the function returns true, the row will be deleted.
     * function(tr){return true;}
     *
     * @var string
     */
    private $onBeforeDelete;
    /**
     * Name of the JavaScript function called after deleting a row (pmButton).
     * function(){}
     *
     * @var string
     */
    private $onDelete;
    /**
     * Name of the JavaScript function called when adding a new row (pmButton).
     * This function is called before $onInitRow.
     * function(tr)
     *
     * @var string
     */
    private $onAddRow;
    /**
     * Name of the JavaScript function called to handle a new row.
     * function(tr, row)
     *
     * @var string
     */
    private $onInitRow;
    /**
     * Name of the JavaScript function called after loading data via Ajax.
     * function(tbody, items)
     *
     * @var string
     */
    private $onChanged;
    /**
     * List of main query commands for data selection.
     * array('id', 1) WHERE `id` = 1 AND ...
     * array('id', array(1, 2)) WHERE `id` IN (1, 2) AND ...
     * array('id', '!=' , 1) WHERE `id` != 1 AND ...
     *
     * @var array
     */
    public $defaultFilters = [];
    /**
     * Data display filters.
     * If this list is specified, it will display filter options above the table.
     *
     * @var array
     */
    public $filters = [];
    /**
     * List of columns that should not be displayed.
     *
     * @var array
     */
    public $hideColumns = [];
    /**
     * List of all columns.
     *
     * @var array
     */
    public $cols = [];
    /**
     * List of header names for columns.
     *
     * @var array
     */
    public $headers = [];
    /**
     * List of fields that can be searched.
     * If this list is specified, it will display a search box.
     *
     * @var array
     */
    public $searchColumns = [];
    /**
     * Specify the search behavior from the search box.
     * true (default): Automatically search based on $searchColumns.
     * false: Manually specify the search behavior.
     *
     * @var bool
     */
    public $autoSearch = true;
    /**
     * Determines the display of the search form.
     * - 'auto' (default): Shows the search form if $searchColumns are specified.
     * - true: Always shows the search form.
     * - false: Doesn't show the search form.
     *
     * @var bool|string
     */
    public $searchForm = 'auto';
    /**
     * The search text.
     *
     * @var string
     */
    public $search = '';
    /**
     * The number of items per page.
     * If specified, the table will be paginated and display options for the number of items per page.
     *
     * @var int|null
     */
    public $perPage = null;
    /**
     * The current page being displayed.
     *
     * @var int
     */
    public $page = 1;
    /**
     * The column name used for sorting.
     * Default is null for automatic value retrieval.
     *
     * @var string|null
     */
    public $sort = null;
    /**
     * The default column name used for sorting.
     * If specified, the table will be initially sorted based on this column.
     *
     * @var string|null
     */
    public $defaultSort = null;
    /**
     * The active sorting information.
     *
     * @var array
     */
    protected $sorts = [];
    /**
     * Buttons to be added at the end of each row.
     *
     * @var array
     */
    public $buttons = [];
    /**
     * A method for preparing button rendering.
     * If it returns false, no buttons will be created.
     * function($btn, $attributes, $items)
     * $btn: The button ID.
     * $attributes: The button properties.
     * $items: The data in the row.
     *
     * @var array array($this, methodName)
     */
    private $onCreateButton;
    /**
     * A method to call when creating the header.
     * Returns the <tr> tag within the header.
     * function()
     *
     * @var array array($this, methodName)
     */
    private $onCreateHeader;
    /**
     * A method to call when creating the footer.
     * Returns the <tr> tag within the footer.
     * function()
     *
     * @var array array($this, methodName)
     */
    private $onCreateFooter;
    /**
     * Specifies the column that allows drag and drop for table reordering.
     *
     * @var int
     */
    private $dragColumn = -1;
    /**
     * The primary key column name for data identification.
     * Used to read the ID of each row.
     *
     * @var string
     */
    private $primaryKey = 'id';
    /**
     * Javascript code.
     *
     * @var array
     */
    private $javascript = [];
    /**
     * Enables the usage of DataTable's JavaScript.
     * - true: Enables the usage of GTable.
     * - false: Disables GTable but still allows other JavaScript to be inserted.
     *
     * @var bool
     */
    public $enableJavascript = true;
    /**
     * The current URI of the web page.
     *
     * @var Uri
     */
    private $uri;
    /**
     * Options for the number of entries to be displayed per page.
     *
     * @var array
     */
    public $entriesList = array(10, 20, 30, 40, 50, 100);
    /**
     * Displays the query on the screen.
     *
     * @var bool
     */
    private $debug = false;
    /**
     * Displays the query's explain plan.
     *
     * @var bool
     */
    private $explain = false;
    /**
     * @var array
     */
    private $columns;
    /**
     * The button for adding new data.
     *
     * @var array
     */
    public $addNew;
    /**
     * Text, such as Notes, is displayed next to the table.
     *
     * @var string
     */
    public $comment = '';
    /**
     * Note class name
     *
     * @var string
     */
    public $commentClass = 'comment';

    /**
     * Constructor.
     *
     * @param array $param The parameters to initialize the object.
     */
    public function __construct($param)
    {
        $this->id = 'datatable';

        // Assign the values from the $param array to the class properties
        foreach ($param as $key => $value) {
            $this->{$key} = $value;
        }

        // Check if $uri is empty and set it to the current request URI if so
        if (empty($this->uri)) {
            $this->uri = self::$request->getUri();
        }
        // Convert $uri to a Uri object if it's a string
        elseif (is_string($this->uri)) {
            $this->uri = Uri::createFromUri($this->uri);
        }

        // Pagination: Get the number of entries per page from the table selection
        if ($this->perPage !== null) {
            $count = self::$request->globals(array('POST', 'GET'), 'count', $this->perPage)->toInt();
            if (in_array($count, $this->entriesList)) {
                $this->perPage = $count;
                $this->uri = $this->uri->withParams(array('count' => $count));
            }
        }

        // Table header: Get the header from the model, data, or manual configuration
        if (isset($this->model)) {
            // Convert the database to a Model object
            $model = new \Kotchasan\Model();
            $model = $model->db()->createQuery()->select();

            // Read the first item to use its field names as table headers
            if (is_string($this->model)) {
                // If the model is a Recordset, create a Recordset object
                $rs = new \Kotchasan\Orm\Recordset($this->model);
                // Convert the Recordset to a QueryBuilder object
                $this->model = $model->from(array($rs->toQueryBuilder(), 'Z9'));
            } else {
                $this->model = $model->from(array($this->model, 'Z9'));
            }

            // Read the first item
            if ($this->explain) {
                $first = $this->model->copy()->explain()->first();
            } else {
                $first = $this->model->copy()->first($this->fields);
            }

            // Read the columns of the table
            if ($first) {
                foreach ($first as $k => $v) {
                    $this->columns[$k] = array('text' => $k);
                }
            } elseif (!empty($this->fields)) {
                foreach ($this->fields as $field) {
                    if (is_array($field)) {
                        $this->columns[$field[1]] = array('text' => $field[1]);
                    } elseif (is_string($field) && preg_match('/(.*?[`\s]+)?([a-z0-9_]+)`?$/i', $field, $match)) {
                        $this->columns[$match[2]] = array('text' => $match[2]);
                    }
                }
            }
        } elseif (isset($this->datas)) {
            // Read the columns from the first data item
            $this->columns = [];
            if (!empty($this->datas)) {
                foreach (reset($this->datas) as $key => $value) {
                    $this->columns[$key] = array('text' => $key);
                }
            }
        }

        // Check if sorting is enabled based on the headers
        $autoSort = false;

        // Handle headers, check against the provided values, sort headers based on columns
        if (!empty($this->columns)) {
            $headers = [];

            foreach ($this->columns as $field => $attributes) {
                if (!in_array($field, $this->hideColumns)) {
                    if (isset($this->headers[$field])) {
                        $headers[$field] = $this->headers[$field];

                        if (!isset($headers[$field]['text'])) {
                            $headers[$field]['text'] = $field;
                        }

                        if (isset($headers[$field]['sort'])) {
                            $autoSort = true;
                        }
                    } else {
                        $headers[$field]['text'] = $field;
                    }
                }
            }

            $this->headers = $headers;
        }

        // Get the sorting value if sort is specified
        if ($autoSort) {
            $this->sort = self::$request->globals(array('POST', 'GET'), 'sort', $this->sort)->topic();
        }

        if (!empty($this->sort)) {
            $this->uri = $this->uri->withParams(array('sort' => $this->sort));
        }

        // Search
        $this->search = self::$request->globals(array('POST', 'GET'), 'search')->text();
    }

    /**
     * Adds a JavaScript script to the table.
     *
     * @param string $script The JavaScript script to add.
     */
    public function script($script)
    {
        $this->javascript[] = $script;
    }

    /**
     * Render the component.
     *
     * This function is responsible for rendering the component.
     * It generates the necessary HTML code based on the provided data.
     *
     * @return string
     */
    public function render()
    {
        // Check if actions are present and the checkCol is not set
        // If true, set the checkCol to 1
        if (!empty($this->actions) && $this->checkCol == -1) {
            $this->checkCol = 1;
        }

        $url_query = [];
        $hidden_fields = [];
        $query_string = [];

        // Parse the query string and populate the $query_string array
        parse_str($this->uri->getQuery(), $query_string);
        self::$request->map($url_query, $query_string);

        foreach ($url_query as $key => $value) {
            // Exclude certain keys from the input for the next page
            // These keys are filtered using a regular expression
            if (!preg_match('/.*?([0-9]+|username|password|token|time|search|count|page|action).*?/', $key)) {
                $hidden_fields[$key] = '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'">';
            }
        }

        if (isset($this->model)) {
            // Build the main query list (AND)
            $qs = [];
            foreach ($this->defaultFilters as $array) {
                $qs[] = $array;
            }
        }

        // Create HTML
        $content = array('<div class="datatable" id="'.$this->id.'">');

        // Form
        $form = [];
        if ($this->perPage !== null) {
            $entries = Language::get('entries');
            $options = [];
            foreach ($this->entriesList as $c) {
                if ($c == 0) {
                    $options[0] = Language::get('all items');
                } else {
                    $options[$c] = $c.' '.$entries;
                }
            }
            $form[] = $this->addFilter(array(
                'name' => 'count',
                'text' => Language::get('Show'),
                'value' => $this->perPage,
                'options' => $options
            ));
        }
        // Iterate through the $this->filters array and add filters to the form
        foreach ($this->filters as $key => $items) {
            $form[] = $this->addFilter($items);
            if (isset($items['name'])) {
                unset($hidden_fields[$items['name']]);
            }
            if (!isset($items['default'])) {
                $items['default'] = '';
            }
            // Add query items to the main query list (AND) if they meet certain conditions
            if (!empty($items['options']) && isset($items['value']) && $items['value'] !== $items['default'] && in_array($items['value'], array_keys($items['options']), true)) {
                if (isset($items['onFilter'])) {
                    $q = call_user_func($items['onFilter'], $key, $items['value']);
                    if ($q) {
                        $qs[] = $q;
                    }
                } elseif (is_string($key)) {
                    $qs[] = array($key, $items['value']);
                }
            }
        }
        if ($this->model && !empty($qs)) {
            $this->model->andWhere($qs);
        }
        // Search
        if ($this->searchForm === true || ($this->searchForm === 'auto' && !empty($this->searchColumns))) {
            if (!empty($this->search) && $this->autoSearch) {
                if (isset($this->model)) {
                    $sh = [];
                    foreach ($this->searchColumns as $key) {
                        $sh[] = array($key, 'LIKE', '%'.$this->search.'%');
                    }
                    $this->model->andWhere($sh, 'OR');
                } elseif (isset($this->datas)) {
                    // Array filter
                    $this->datas = ArrayTool::filter($this->datas, $this->search);
                }
                $this->uri = $this->uri->withParams(array('search' => $this->search));
            }
            $form[] = '<fieldset class=search>';
            $form[] = '<label accesskey=f><input type=text name=search value="'.$this->search.'" placeholder="'.Language::get('Search').'"></label>';
            $form[] = '<button type=button class=clear_search>&#x78;</button>';
            $form[] = '</fieldset>';
        }
        if (!$this->explain && !empty($form)) {
            // GO button
            $form[] = '<fieldset>';
            $form[] = '<button type=submit class="button go circle">'.Language::get('Go').'</button>';
            $form[] = implode('', $hidden_fields);
            $form[] = '</fieldset>';
            $content[] = '<form class="table_nav clear" method="get" action="'.$this->uri.'"><div>'.implode('', $form).'</div></form>';
        }
        if (isset($this->model)) {
            if ($this->explain) {
                // Explain mode
                $count = 0;
            } else {
                // Fields select
                $this->model->select($this->fields);
                // Datas count (Query Builder)
                $model = new \Kotchasan\Model();
                $query = $model->db()->createQuery()
                    ->selectCount()
                    ->from(array($this->model, 'Z'));
                if ($this->cache) {
                    $query->cacheOn();
                }
                $result = $query->toArray()->execute();
                $count = empty($result) ? 0 : $result[0]['count'];
            }
        } elseif (!empty($this->datas)) {
            // Datas count
            $count = count($this->datas);
        } else {
            // Empty
            $count = 0;
        }
        // Pagination
        if ($this->perPage > 0) {
            // Display page
            $this->page = max(1, self::$request->globals(array('POST', 'GET'), 'page', 1)->toInt());
            // Max pages
            $totalpage = round($count / $this->perPage);
            $totalpage += ($totalpage * $this->perPage < $count) ? 1 : 0;
            $this->page = max(1, $this->page > $totalpage ? $totalpage : $this->page);
            $start = $this->perPage * ($this->page - 1);
            // Current page
            $s = $start < 0 ? 0 : $start + 1;
            $e = min($count, $s + $this->perPage - 1);
        } else {
            $start = 0;
            $totalpage = 1;
            $this->page = 1;
            $s = 1;
            $e = $count;
            $this->perPage = 0;
        }

        // Table caption
        if ($this->showCaption) {
            if (empty($this->search)) {
                $caption = Language::get('All :count entries, displayed :start to :end, page :page of :total pages');
            } else {
                $caption = Language::get('Search <strong>:search</strong> found :count entries, displayed :start to :end, page :page of :total pages');
            }
            $caption = str_replace(array(':search', ':count', ':start', ':end', ':page', ':total'), array($this->search, number_format($count), number_format($s), number_format($e), number_format($this->page), number_format($totalpage)), $caption);
        }

        // Sort
        $orders = [];
        if (!empty($this->defaultSort)) {
            foreach (explode(',', $this->defaultSort) as $sort) {
                if (preg_match('/^([a-z0-9_\-]+)([\s]+(desc|asc))?$/i', trim($sort), $match)) {
                    $sortType = isset($match[3]) && strtolower($match[3]) == 'desc' ? 'desc' : 'asc';
                    $orders[] = $match[1].' '.$sortType;
                    $this->sorts[$match[1]] = $sortType;
                }
            }
        }

        if (!empty($this->sort)) {
            $sorts = [];
            foreach (explode(',', $this->sort) as $sort) {
                if (preg_match('/^([a-z0-9_\-]+)([\s]+(desc|asc))?$/i', trim($sort), $match)) {
                    if (isset($this->headers[$match[1]]['sort'])) {
                        $sort = $this->headers[$match[1]]['sort'];
                    } elseif (isset($this->columns[$match[1]])) {
                        $sort = $match[1];
                    } elseif ($this->model && isset($this->columns[$match[1]])) {
                        $sort = $match[1];
                    } else {
                        $sort = null;
                    }
                    if ($sort) {
                        $sortType = isset($match[3]) && strtolower($match[3]) == 'desc' ? 'desc' : 'asc';
                        $this->sorts[$sort] = $sortType;
                        foreach (explode(',', $sort) as $sort_item) {
                            $sorts[] = $sort_item.' '.$sortType;
                        }
                    }
                }
            }
            $this->sort = implode(',', $sorts);
            $orders = array_merge($orders, $sorts);
        }

        if (isset($this->model)) {
            if (!empty($orders)) {
                $this->model->order($orders);
            }
        } elseif (!empty($this->sorts)) {
            reset($this->sorts);
            $sort = key($this->sorts);
            $this->datas = ArrayTool::sort($this->datas, $sort, $this->sorts[$sort]);
        }
        if (isset($this->model)) {
            // Explain mode
            if ($this->explain) {
                $this->model->explain();
            }
            // Database debugger
            if ($this->debug === true) {
                $this->debug = $this->model->toArray()->limit($this->perPage, $start)->text();
            }

            // Execute model
            $this->datas = $this->model->toArray()->limit($this->perPage, $start)->execute();

            // first and last item index
            $end = $this->perPage + 1;
            $start = -1;
        } elseif (isset($this->datas)) {
            // Array debugger
            if ($this->debug === true) {
                $this->debug = var_export($this->datas, true);
            }

            // first and last item index
            $end = $start + $this->perPage - 1;
            $start = $start - 2;
        } else {
            $end = 0;
        }

        // Table properties
        $prop = [];
        $c = [];
        if (!empty($this->class)) {
            $c[] = $this->class;
        }
        if ($this->border) {
            $c[] = 'border';
        }
        if ($this->responsive) {
            $c[] = 'responsive-v';
        }
        if ($this->fullWidth) {
            $c[] = 'fullwidth';
        }
        if (count($c) > 0) {
            $prop[] = ' class="'.implode(' ', $c).'"';
        }
        // table
        $content[] = '<div class="tablebody"><table'.implode('', $prop).'>';
        if ($this->showCaption) {
            $content[] = '<caption>'.$caption.'</caption>';
        }
        $colCount = 0;
        // table header
        $thead = null;
        if (!$this->explain && isset($this->onCreateHeader)) {
            $thead = call_user_func($this->onCreateHeader);
        } elseif (!empty($this->headers)) {
            $row = [];
            $i = 0;
            $colspan = 0;
            foreach ($this->headers as $key => $attributes) {
                if ($colspan === 0) {
                    if (!$this->explain) {
                        if (!$this->hideCheckbox && $i == $this->checkCol) {
                            $row[] = '<th class="check-column"><a class="checkall icon-uncheck"></a></th>';
                            ++$colCount;
                        }
                        if ($i == $this->dragColumn) {
                            $row[] = '<th></th>';
                            ++$colCount;
                        }
                    }
                    if (isset($attributes['colspan'])) {
                        $colspan = $attributes['colspan'] - 1;
                    }
                    $row[] = $this->th($i, $key, $attributes);
                    ++$i;
                } else {
                    --$colspan;
                }
                ++$colCount;
            }
            if (!$this->explain && !$this->hideCheckbox && $colCount == $this->checkCol) {
                $row[] = '<th class="check-column"><a class="checkall icon-uncheck"></a></th>';
                ++$colCount;
            }
            if ($colspan === 0) {
                if (!empty($this->buttons)) {
                    $row[] = $this->th($i, '', array('text' => ''));
                    ++$colCount;
                    ++$i;
                }
            } else {
                --$colspan;
            }
            if ($colspan === 0) {
                if ($this->pmButton) {
                    $row[] = $this->th($i, '', array('text' => ''));
                    ++$colCount;
                }
            } else {
                --$colspan;
            }
            $thead = '<tr>'.implode('', $row).'</tr>';
        }
        if (!empty($thead) && is_string($thead)) {
            $content[] = '<thead>'.$thead.'</thead>';
        }
        // tbody
        if (!empty($this->datas)) {
            $content[] = '<tbody>'.$this->tbody($start, $end).'</tbody>';
        }
        if (!$this->explain) {
            // tfoot
            $tfoot = null;
            if (isset($this->onCreateFooter)) {
                $tfoot = call_user_func($this->onCreateFooter);
            } elseif (!$this->hideCheckbox && $this->checkCol > -1) {
                $tfoot = '<tr>';
                $tfoot .= '<td colspan="'.$this->checkCol.'">&nbsp;</td>';
                $tfoot .= '<td class="check-column"><a class="checkall icon-uncheck"></a></td>';
                $tfoot .= '<td colspan="'.($colCount - $this->checkCol - 1).'"></td>';
                $tfoot .= '</tr>';
            }
            if (!empty($tfoot) && is_string($tfoot)) {
                $content[] = '<tfoot>'.$tfoot.'</tfoot>';
            }
        }
        $content[] = '</table></div>';
        if (!empty($this->comment)) {
            $content[] = '<div class="table_comment '.$this->commentClass.'">'.$this->comment.'</div>';
        }
        $table_nav = [];
        $table_nav_float = [];
        if (!empty($this->actions) && is_array($this->actions)) {
            foreach ($this->actions as $item) {
                $action = $this->addAction($item);
                if ($action !== null) {
                    if (isset($item['float']) && $item['float']) {
                        $table_nav_float[] = $this->addAction($item);
                    } else {
                        $table_nav[] = $this->addAction($item);
                    }
                }
            }
        }
        if (!empty($table_nav_float)) {
            $table_nav[] = '<div class=float_bottom_menu>'.implode('', $table_nav_float).'</div>';
        }
        if (!empty($this->addNew) && is_array($this->addNew)) {
            $prop = [];
            foreach ($this->addNew as $k => $v) {
                if ($k != 'text') {
                    $prop[$k] = $k.'="'.$v.'"';
                }
            }
            $text = isset($this->addNew['text']) ? $this->addNew['text'] : '';
            if (isset($this->addNew['class']) && preg_match('/^((.*)\s+)?(icon-[a-z0-9\-_]+)(\s+(.*))?$/', $this->addNew['class'], $match)) {
                $prop['class'] = 'class="'.trim($match[2].' '.(isset($match[5]) ? $match[5] : '')).'"';
                if (isset($prop['href'])) {
                    $table_nav[] = '<a '.implode(' ', $prop).'><span class="'.$match[3].'">'.$text.'</span></a>';
                } else {
                    $table_nav[] = '<button '.implode(' ', $prop).' type="button"><span class="'.$match[3].'">'.$text.'</span></button>';
                }
            } elseif (isset($prop['href'])) {
                $table_nav[] = '<a '.implode(' ', $prop).'>'.$text.'</a>';
            } else {
                $table_nav[] = '<button '.implode(' ', $prop).' type="button">'.$text.'</button>';
            }
        }
        if (!$this->explain) {
            if (!empty($table_nav)) {
                $content[] = '<div class="table_nav clear action">'.implode('', $table_nav).'</div>';
            }
            // Pagination
            if ($this->perPage > 0) {
                $content[] = '<div class="splitpage">'.$this->uri->pagination($totalpage, $this->page).'</div>';
            }
        }
        $content[] = '</div>';
        // Check if JavaScript is enabled and the explain flag is not set
        if ($this->enableJavascript && !$this->explain) {
            // Create an array containing various properties to be used in JavaScript
            $script = array(
                'page' => $this->page,
                'search' => $this->search,
                'sort' => $this->sort,
                'action' => $this->action,
                'actionCallback' => $this->actionCallback,
                'actionConfirm' => $this->actionConfirm,
                'onBeforeDelete' => $this->onBeforeDelete,
                'onDelete' => $this->onDelete,
                'onInitRow' => $this->onInitRow,
                'onAddRow' => $this->onAddRow,
                'onChanged' => $this->onChanged,
                'pmButton' => $this->pmButton,
                'dragColumn' => $this->dragColumn,
                'url' => $this->url,
                'params' => $this->params,
                'cols' => $this->cols,
                'debug' => is_string($this->debug) ? $this->debug : ''
            );
            // Add JavaScript code to initialize a GTable object with the given properties
            $this->javascript[] = 'var table = new GTable("'.$this->id.'", '.json_encode($script).');';
        }

        // Create a script tag and add each JavaScript code from the $javascript array as a separate line
        if (!empty($this->javascript)) {
            $content[] = "<script>\n".implode("\n", $this->javascript)."\n</script>";
        }

        // Return the generated HTML code
        return implode("\n", $content);
    }

    /**
     * Generates HTML markup for the table body (<tbody>).
     *
     * @param int $start The start index of the rows to be included in the tbody.
     * @param int $end The end index of the rows to be included in the tbody.
     * @return string The generated HTML for the table body.
     */
    private function tbody($start, $end)
    {
        $row = [];
        $n = 0;

        foreach ($this->datas as $o => $items) {
            if ($this->perPage <= 0 || ($n > $start && $n < $end)) {
                $src_items = $items;

                // Get the ID of the data
                $id = isset($items[$this->primaryKey]) ? $items[$this->primaryKey] : $o;

                // Properties of the table row
                $prop = (object) array(
                    'id' => $this->id.'_'.$id
                );

                $buttons = [];

                if (!$this->explain) {
                    // Generate buttons
                    if (!empty($this->buttons)) {
                        foreach ($this->buttons as $btn => $attributes) {
                            if (isset($this->onCreateButton)) {
                                // Event for main buttons
                                $attributes = call_user_func($this->onCreateButton, $btn, $attributes, $items);
                            }
                            if ($attributes && $attributes !== false) {
                                $buttons[] = $this->button($btn, $attributes, $items);
                            }
                        }
                    }

                    // Event for row
                    if (isset($this->onRow)) {
                        $items = call_user_func($this->onRow, $items, $o, $prop);
                    }

                    // Drag column class
                    if (isset($this->dragColumn)) {
                        $prop->class = (empty($prop->class) ? 'sort' : $prop->class.' sort');
                    }
                }

                // Table row
                $p = [];
                foreach ($prop as $k => $v) {
                    $p[] = $k.'="'.$v.'"';
                }
                $row[] = '<tr '.implode(' ', $p).'>';

                // Display data
                $i = 0;
                foreach ($this->headers as $field => $attributes) {
                    if (!empty($field) && !in_array($field, $this->hideColumns)) {
                        if (!$this->explain) {
                            // Checkbox column
                            if (!$this->hideCheckbox && $i == $this->checkCol) {
                                $row[] = '<td headers="r'.$id.'" class="check-column"><a id="check_'.$id.'" class="icon-uncheck"></a></td>';
                            }

                            // Drag column
                            if ($i == $this->dragColumn) {
                                $row[] = '<td class=center><a id="move_'.$id.'" title="'.Language::get('Drag and drop to reorder').'" class="icon-move"></a></td>';
                            }
                        }

                        $properties = isset($this->cols[$field]) ? $this->cols[$field] : [];
                        $text = isset($items[$field]) ? $items[$field] : '';
                        $th = isset($attributes['text']) ? $attributes['text'] : $field;
                        $row[] = $this->td($id, $i, $properties, $text, $th);
                        ++$i;
                    }
                }

                if (!$this->explain) {
                    // Checkbox column
                    if (!$this->hideCheckbox && $i == $this->checkCol) {
                        $row[] = '<td headers="r'.$id.'" class="check-column"><a id="check_'.$id.'" class="icon-uncheck"></a></td>';
                        ++$i;
                    }

                    // Buttons column
                    if (!empty($this->buttons)) {
                        if (!empty($buttons)) {
                            $patt = [];
                            $replace = [];
                            $keys = array_keys($src_items);
                            rsort($keys);
                            foreach ($keys as $k) {
                                if (!is_array($src_items[$k])) {
                                    $patt[] = ":$k";
                                    $replace[] = $src_items[$k];
                                }
                            }
                            if (isset($this->cols['buttons']) && isset($this->cols['buttons']['class'])) {
                                $prop = array('class' => $this->cols['buttons']['class'].' buttons');
                            } else {
                                $prop = array('class' => 'buttons');
                            }
                            $row[] = str_replace($patt, $replace, $this->td($id, $i, $prop, implode('', $buttons), ''));
                        } else {
                            $row[] = $this->td($id, $i, [], '', '');
                        }
                    }

                    // Plus/minus buttons column
                    if ($this->pmButton) {
                        $row[] = '<td class="icons"><div><a class="icon-plus" title="'.Language::get('Add').'"></a><a class="icon-minus" title="'.Language::get('Remove').'"></a></div></td>';
                    }
                }

                $row[] = '</tr>';
            }

            ++$n;
        }

        return implode("\n", $row);
    }

    /**
     * Generates HTML markup for a table header cell (<th>).
     *
     * @param int $i The column index.
     * @param string $column The column name or identifier.
     * @param array $properties The properties of the table header cell.
     *
     * @return string The generated HTML for the table header cell.
     */
    private function th($i, $column, $properties)
    {
        $c = [];
        $c['id'] = 'id="c'.$i.'"';

        if (!empty($properties['sort'])) {
            // Check if a valid sort type is specified
            $sort = isset($this->sorts[$properties['sort']]) ? $this->sorts[$properties['sort']] : 'none';
            $properties['class'] = 'sort_'.$sort.' col_'.$column.(empty($properties['class']) ? '' : ' '.$properties['class']);
        }

        foreach ($properties as $key => $value) {
            if ($key !== 'sort' && $key !== 'text') {
                $c[$key] = $key.'="'.$value.'"';
            }
        }

        return '<th '.implode(' ', $c).'>'.(isset($properties['text']) ? $properties['text'] : $column).'</th>';
    }

    /**
     * Generates HTML markup for a table cell (<td>) or header cell (<th>).
     *
     * @param string $id The cell ID.
     * @param int $i The cell index.
     * @param array $properties The properties of the cell.
     * @param string $text The cell content.
     * @param string $th The content of the corresponding table header cell.
     *
     * @return string The generated HTML for the table cell.
     */
    private function td($id, $i, $properties, $text, $th)
    {
        $c = array('data-text' => 'data-text="'.strip_tags($th).'"');

        foreach ($properties as $key => $value) {
            $c[$key] = $key.'="'.$value.'"';
        }

        $c = implode(' ', $c);

        if ($i == 0) {
            // Table header cell
            $c .= ' id="r'.$id.'" headers="c'.$i.'"';
            return '<th '.$c.'>'.$text.'</th>';
        } else {
            // Table data cell
            $c .= ' headers="c'.$i.' r'.$id.'"';
            return '<td '.$c.'>'.$text.'</td>';
        }
    }

    /**
     * Generates HTML markup for a button element.
     *
     * @param string $btn The button identifier.
     * @param array $properties The properties of the button.
     * @param array $items The items associated with the button.
     *
     * @return string The generated HTML for the button element.
     */
    private function button($btn, $properties, $items)
    {
        if (isset($properties['submenus'])) {
            // Button with submenus
            $innerHTML = '';
            $li = '';
            $attributes = [];

            foreach ($properties as $name => $item) {
                if ($name == 'submenus') {
                    foreach ($item as $btn => $menu) {
                        $prop = [];
                        if (isset($this->onCreateButton)) {
                            // Submenu button event
                            $menu = call_user_func($this->onCreateButton, $btn, $menu, $items);
                        }
                        if ($menu && $menu !== false) {
                            $text = '';
                            foreach ($menu as $key => $value) {
                                if ($key == 'text') {
                                    $text = $value;
                                } else {
                                    $prop[$key] = $key.'="'.$value.'"';
                                }
                            }
                            $li .= '<li><a '.implode(' ', $prop).'>'.$text.'</a></li>';
                        }
                    }
                } elseif ($name == 'text') {
                    $innerHTML = $item;
                } elseif ($name == 'class') {
                    $attributes['class'] = 'class="'.$item.' menubutton"';
                } else {
                    $attributes[$name] = $name.'="'.$item.'"';
                }
            }

            if (!isset($attributes['class'])) {
                $attributes['class'] = 'class="menubutton"';
            }

            if (!isset($attributes['tabindex'])) {
                $attributes['tabindex'] = 'tabindex="0"';
            }

            return '<div '.implode(' ', $attributes).'>'.$innerHTML.'<ul>'.$li.'</ul></div>';
        } else {
            // Regular button
            $prop = [];

            foreach ($properties as $key => $value) {
                if ($key === 'id') {
                    $prop[$key] = $key.'="'.$btn.'_'.$value.'"';
                } elseif ($key !== 'text') {
                    $prop[$key] = $key.'="'.$value.'"';
                }
            }

            if (!empty($properties['class']) && preg_match('/(.*)\s?(icon\-[a-z0-9\-_]+)($|\s(.*))/', $properties['class'], $match)) {
                $class = [];

                foreach (array(1, 4) as $i) {
                    if (!empty($match[$i])) {
                        $class[] = $match[$i];
                    }
                }

                if (empty($properties['text'])) {
                    $class[] = 'notext';
                    $prop['class'] = 'class="'.implode(' ', $class).'"';
                    return '<a '.implode(' ', $prop).'><span class="'.$match[2].'"></span></a>';
                } else {
                    $prop['class'] = 'class="'.implode(' ', $class).'"';

                    if (!isset($prop['title'])) {
                        $prop['title'] = 'title="'.strip_tags($properties['text']).'"';
                    }

                    return '<a '.implode(' ', $prop).'><span class="'.$match[2].' button_w_text"><span class=mobile>'.$properties['text'].'</span></span></a>';
                }
            } else {
                return '<a'.(empty($prop) ? '' : ' '.implode(' ', $prop)).'></a>';
            }
        }
    }

    /**
     * Adds an action element based on the provided item configuration.
     *
     * @param array $item The item configuration for the action element.
     *
     * @return string|null The generated HTML for the action element, or null if no action is defined.
     */
    private function addAction($item)
    {
        if (isset($item['class']) && preg_match('/^((.*)\s+)?(icon-[a-z0-9\-_]+)(\s+(.*))?$/', $item['class'], $match)) {
            $match[2] = trim($match[2].' '.(isset($match[5]) ? $match[5] : ''));
        }

        if (isset($item['options'])) {
            if (!empty($item['options'])) {
                // Select
                $rows = [];
                foreach ($item['options'] as $key => $text) {
                    $rows[] = '<option value="'.$key.'">'.$text.'</option>';
                }
                return '<fieldset><select id="'.$item['id'].'">'.implode('', $rows).'</select><label for="'.$item['id'].'" class="button '.$item['class'].' action"><span>'.$item['text'].'</span></label></fieldset>';
            } else {
                return null;
            }
        } elseif (isset($item['type'])) {
            $prop = array(
                'type="'.$item['type'].'"'
            );
            $prop2 = array(
                'button' => 'class="button action"'
            );

            foreach ($item as $key => $value) {
                if ($key == 'id') {
                    $prop[] = 'id="'.$value.'"';
                    $prop2[] = 'for="'.$value.'"';
                } elseif ($key == 'class') {
                    $prop2['button'] = 'class="button '.$value.' action"';
                } elseif (!in_array($key, array('type', 'text'))) {
                    $prop[] = $key.'="'.$value.'"';
                }
            }

            return '<fieldset><input '.implode(' ', $prop).'><label '.implode(' ', $prop2).'><span>'.$item['text'].'</span></label></fieldset>';
        } else {
            // Link or button
            $prop = [];
            $text = isset($item['text']) ? $item['text'] : '';

            if ($text != '' && !isset($item['title'])) {
                $item['title'] = strip_tags($text);
            }

            if (isset($item['class'])) {
                if (empty($match[3])) {
                    $prop[] = 'class="'.$item['class'].'"';
                } else {
                    $text = '<span class="'.$match[3].'">'.$text.'</span>';
                    $prop[] = 'class="'.$match[2].'"';
                }
            }

            foreach ($item as $k => $v) {
                if ($k != 'class' && $k != 'text' && $k != 'float') {
                    $prop[] = $k.'="'.$v.'"';
                }
            }

            if (isset($item['href'])) {
                // Link
                return '<a '.implode(' ', $prop).'>'.$text.'</a>';
            } else {
                // Button
                return '<button '.implode(' ', $prop).' type="button">'.$text.'</button>';
            }
        }
    }

    /**
     * Adds a filter element based on the provided item configuration.
     *
     * @param array $item The item configuration for the filter element.
     *
     * @return string The generated HTML for the filter element.
     */
    private function addFilter($item)
    {
        if (isset($item['datalist'])) {
            $item['type'] = 'text';
        }
        if (isset($item['type'])) {
            $prop = [];
            $datalist = '';
            foreach ($item as $key => $value) {
                if ($key == 'datalist') {
                    foreach ($value as $k => $v) {
                        $datalist .= '<option value="'.$k.'">'.$v.'</option>';
                    }
                } elseif ($key != 'text' && $key != 'default' && !is_array($value)) {
                    $prop[$key] = $key.'="'.$value.'"';
                }
            }
            if ($datalist != '' && isset($item['name'])) {
                if (!isset($item['id'])) {
                    $item['id'] = $item['name'];
                    $prop['id'] = 'id="'.$item['name'].'"';
                }
                $prop['autocomplete'] = 'autocomplete="off"';
                $this->javascript[] = 'new Datalist("'.$item['id'].'");';
                $prop['list'] = 'list="'.$item['name'].'-datalist"';
                $datalist = '<datalist id="'.$item['name'].'-datalist">'.$datalist.'</datalist>';
            } else {
                $datalist = '';
            }
            $row = '<fieldset><label>'.(isset($item['text']) ? $item['text'] : '').' <input '.implode(' ', $prop).'>'.$datalist.'</label></fieldset>';
        } elseif (isset($item['items'])) {
            $button = '';
            foreach ($item['items'] as $link) {
                foreach ($link as $key => $value) {
                    if ($key != 'text') {
                        $prop[$key] = $key.'="'.$value.'"';
                    }
                }
                if (isset($item['name'])) {
                    $prop['name'] = 'name="'.$item['name'].'"';
                }
                if (isset($item['href'])) {
                    $button .= '<a '.implode(' ', $prop).'>'.(isset($link['text']) ? $link['text'] : '').'</a>';
                } else {
                    $button .= '<button '.implode(' ', $prop).'>'.(isset($link['text']) ? $link['text'] : '').'</button>';
                }
            }
            $row = '<fieldset class="buttons"><label>'.(isset($item['text']) ? $item['text'] : '').'</label>'.$button.'</fieldset>';
        } elseif (isset($item['href'])) {
            foreach ($item as $key => $value) {
                if ($key != 'text') {
                    $prop[$key] = $key.'="'.$value.'"';
                }
            }
            $row = '<a '.implode(' ', $prop).'>'.(isset($item['text']) ? $item['text'] : '').'</a>';
        } else {
            $prop = [];
            foreach ($item as $key => $value) {
                if ($key != 'options' && $key != 'value' && $key != 'text' && $key != 'default' && !is_array($value)) {
                    $prop[$key] = $key.'="'.$value.'"';
                }
            }
            $row = '<fieldset><label>'.(isset($item['text']) ? $item['text'] : '').' <select '.implode(' ', $prop).'>';
            if (!empty($item['options'])) {
                foreach ($item['options'] as $key => $text) {
                    $sel = isset($item['value']) && (string) $key == $item['value'] ? ' selected' : '';
                    $row .= '<option value="'.$key.'"'.$sel.'>'.$text.'</option>';
                }
            }
            $row .= '</select></label></fieldset>';
        }
        return $row;
    }
}
