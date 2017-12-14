<?php
/**
 * @filesource Kotchasan/DataTable.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\ArrayTool;
use \Kotchasan\Language;
use \Kotchasan\Http\Uri;

/**
 * คลาสสำหรับจัดการแสดงผลข้อมูลจาก Model ในรูปแบบตาราง
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class DataTable extends \Kotchasan\KBase
{
  /**
   * id ของตาราง
   *
   * @var string
   */
  private $id;
  /**
   * ชื่อ Model ที่ต้องการเรียกข้อมูล
   *
   * @var \Kotchasan\Database\QueryBuilder
   */
  private $model;
  /**
   * ข้อมูลทั้งหมดของตารางรูปแบบแอเรย์
   * หากไม่ได้ใช้ตารางเชื่อมต่อกับ Model ให้กำหนดข้อมูลทั้งหมดที่นี่
   *
   * @var array
   */
  private $datas;
  /**
   * database cache
   *
   * @var bool
   */
  private $cache = false;
  /**
   * รายชื่อฟิลด์ที่จะ query
   *
   * @var array
   */
  private $fields = array();
  /**
   * คอลัมน์ของ checkbox
   * -1 ไม่แสดง checkbox
   *
   * @var int
   */
  private $checkCol = -1;
  /**
   * กำหนดการแสดงผล checkbox
   * ถ้าเป็น true จะซ่อน checkbox เสมอ
   *
   * @var boolean
   */
  public $hideCheckbox = false;
  /**
   * แสดงตารางกว้าง 100%
   *
   * @var bool
   */
  private $fullWidth = true;
  /**
   * แสดงเส้นกรอบ
   *
   * @var bool
   */
  private $border = false;
  /**
   * แสดงปุ่ม สำหรับเพิ่มและลบแถว
   *
   * @var bool
   */
  private $pmButton = false;
  /**
   * แสดงตารางแบบ responsive
   *
   * @var bool
   */
  private $responsive = false;
  /**
   * แสดงส่วนหัวของตาราง
   *
   * @var bool
   */
  private $showCaption = true;
  /**
   * URL สำหรับรับค่าจาก action ต่างๆ เช่นการลบ
   * เช่น index/[controller|model]/className/method.php
   *
   * @var string
   */
  private $action;
  /**
   * ถ้ากำหนดรายการนี้จะแสดง checkbox และ ปุ่ม action
   * array('delete' => Language::get('Delete'), 'published' => Language::get('Published'))
   * หมายถึงแสดง select สำหรับ ลบ และ เผยแพร่
   *
   * @var array
   */
  public $actions = array();
  /**
   * ชื่อฟังก์ชั่น Javascript เรียกหลังจากทำการส่งค่าจาก action ไปประมวลผลแล้ว
   * เช่น doFormSubmit
   *
   * @var string
   */
  private $actionCallback;
  /**
   * ชื่อฟังก์ชั่น Javascript เรียกหลังจากคลิก action
   * เช่น confirmAction
   *
   * @var string
   */
  private $actionConfirm;
  /**
   * method สำหรับจัดการข้อมูลแต่ละแถวก่อนการแสดงผล
   * function($item, $index, $prop)
   * $item array ข้อมูล
   * $row int ลำดับที่ของข้อมูล (key)
   * $prop array property ของ tr เช่น $prop[0]['id'] = xxx
   *
   * @var array array($this, methodName)
   */
  private $onRow;
  /**
   * ชื่อฟังก์ชั่น Javascript เรียกก่อนที่จะมีการลบแถว (pmButton)
   * ถ้าฟังก์ชั่นคืนค่า true มา ถึงจะมีการลบแถว
   * function(tr){return true;}
   *
   * @var string
   */
  private $onBeforeDelete;
  /**
   * ชื่อฟังก์ชั่น Javascript เรียกเมื่อมีการเพิ่มแถวใหม่ (pmButton)
   * ฟังก์ชั่นนี้จะมีการเรียกใช้ก่อนเรียกใช้ $onInitRow
   * function(tr)
   *
   * @var string
   */
  private $onAddRow;
  /**
   * ชื่อฟังก์ชั่น Javascript เรียกเพื่อจัดการแถวใหม่
   * function(tr, row)
   *
   * @var string
   */
  private $onInitRow;
  /**
   * ลิสต์คำสั่ง Query หลัก สำหรับคัดเลือกข้อมูล
   * array('id', 1) WHERE `id` = 1 AND ...
   * array('id', array(1, 2)) WHERE `id` IN (1, 2) AND ...
   * array('id', '!=' , 1) WHERE `id` != 1 AND ...
   *
   * @var array
   */
  public $defaultFilters = array();
  /**
   * ฟิลเตอร์ข้อมูลแสดงผล
   * ถ้ากำหนดรายการนี้จะแสดงตัวเลือกการ filter ด้านบนตาราง
   *
   * @var array
   */
  public $filters = array();
  /**
   * รายชื่อคอลัมน์ที่ไม่ต้องแสดงผล
   *
   * @var array
   */
  public $hideColumns = array();
  /**
   * รายชื่อคอลัมน์ทั้งหมด
   *
   * @var array
   */
  public $columns = array();
  /**
   * รายชื่อส่วนหัวของตอลัมน์
   *
   * @var array
   */
  public $headers = array();
  /**
   * รายชื่อฟิลด์ที่สามารถค้นหาได้
   * ถ้ากำหนดรายการนี้จะแสดงกล่องค้นหา
   *
   * @var array
   */
  public $searchColumns = array();
  /**
   * จำนวนรายการต่อหน้า
   * ถ้ากำหนดรายการนี้จะแสดงรายการแบ่งหน้า และตัวเลือกแสดงรายการต่อหน้า
   *
   * @var int
   */
  public $perPage = 0;
  /**
   * ชื่อคอลัมน์ที่ใช้เรียงลำดับ
   *
   * @var string
   */
  public $sort;
  /**
   * ข้อมูลการเรียงลำดับที่กำลังใช้งานอยู่
   *
   * @var array
   */
  protected $sorts = array();
  /**
   * ปุ่มที่จะใส่ไว้ด้านหลังของแต่ละแถว
   *
   * @var array
   */
  public $buttons = array();
  /**
   * method สำหรับเตรียมการแสดงผล button
   * ถ้าคืนค่า false กลับมาจะไม่มีการสรางปุ่ม
   * function($btn, $attributes, $items)
   * $btn string id ของ button
   * $attributes array property ของปุ่ม
   * $items array ข้อมูลในแถว
   *
   * @var array array($this, methodName)
   */
  private $onCreateButton;
  /**
   * method เรียกเมื่อต้องการสร้าง footer
   * คืนค่า tag tr ที่อยู่ภายใน footer
   * function()
   *
   * @var array array($this, methodName)
   */
  private $onCreateFooter;
  /**
   * กำหนดคอลัมน์ หากยอมให้สามารถจัดลำดับตารางด้วยการลากได้
   *
   * @var int
   */
  private $dragColumn = -1;
  /**
   * ชื่อคีย์หลักของข้อมูล
   * สำหรับอ่าน id ของ แถว
   *
   * @var string
   */
  private $primaryKey = 'id';
  /**
   * Javascript
   *
   * @var array
   */
  private $javascript = array();
  /**
   * เปิดใช้งาน Javascript ของตาราง
   * true เปิดใช้งาน GTable
   * false ปิดใช้งาน GTable แต่ยังแทรก Javascript อื่นๆได้
   *
   * @var boolean
   */
  public $enableJavascript = true;
  /**
   * Uri ปัจจุบันของหน้าเว็บ
   *
   * @var Uri
   */
  private $uri;
  /**
   * ตัวเลือกจำนวนการแสดงผล
   *
   * @var array
   */
  public $entriesList = array(10, 20, 30, 40, 50, 100);

  /**
   * Initial Class
   *
   * @param array $param
   */
  public function __construct($param)
  {
    $this->id = 'datatable';
    foreach ($param as $key => $value) {
      $this->$key = $value;
    }
    if (empty($this->uri)) {
      $this->uri = self::$request->getUri();
    } elseif (is_string($this->uri)) {
      $this->uri = Uri::createFromUri($this->uri);
    }
    // รายการต่อหน้า มาจากการเลือกภายในตาราง
    if ($this->perPage > 0) {
      $count = self::$request->globals(array('POST', 'GET'), 'count')->toInt();
      if (in_array($count, $this->entriesList)) {
        $this->perPage = $count;
        $this->uri = $this->uri->withParams(array('count' => $count));
      }
    }
    // header ของตาราง มาจาก model หรือมาจากข้อมูล หรือ มาจากการกำหนดเอง
    if (isset($this->model)) {
      // แปลงฐานข้อมูลเป็น Model
      $model = new \Kotchasan\Model;
      $model = $model->db()->createQuery()->select();
      // อ่านข้อมูลรายการแรกเพื่อใช้ชื่อฟิลด์เป็นหัวตาราง
      if (is_string($this->model)) {
        // model เป็น Recordset, create Recordset
        $rs = new \Kotchasan\Orm\Recordset($this->model);
        // แปลง Recordset เป็น QueryBuilder
        $this->model = $model->from(array($rs->toQueryBuilder(), 'Z9'));
      } else {
        $this->model = $model->from(array($this->model, 'Z9'));
      }
      // อ่านข้อมูลรายการแรก
      $first = $this->model->copy()->first($this->fields);
      // อ่านคอลัมน์ของตาราง
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
      // อ่านคอลัมน์จากข้อมูลเราการแรก
      $this->columns = array();
      if (!empty($this->datas)) {
        foreach (reset($this->datas) as $key => $value) {
          $this->columns[$key] = array('text' => $key);
        }
      }
    }
    // จัดการ header, ตรวจสอบกับค่ากำหนดมา เรียงลำดับ header ตาม columns
    if (!empty($this->columns)) {
      $headers = array();
      foreach ($this->columns as $field => $attributes) {
        if (!in_array($field, $this->hideColumns)) {
          if (isset($this->headers[$field])) {
            $headers[$field] = $this->headers[$field];
            if (!isset($headers[$field]['text'])) {
              $headers[$field]['text'] = $field;
            }
          } else {
            $headers[$field]['text'] = $field;
          }
        }
      }
      $this->headers = $headers;
    }
    $this->sort = self::$request->globals(array('POST', 'GET'), 'sort', $this->sort)->toString();
    if (!empty($this->sort)) {
      $this->uri = $this->uri->withParams(array('sort' => $this->sort));
    }
  }

  /**
   * กำหนด Javascript
   *
   * @param string $script
   */
  public function script($script)
  {
    $this->javascript[] = $script;
  }

  /**
   * สร้างตาราง และเริ่มต้นทำงานตาราง
   * คืนค่าเป็นโค้ด HTML ของ DataTable
   *
   * @return string
   */
  public function render()
  {
    if (!empty($this->actions) && $this->checkCol == -1) {
      $this->checkCol = 1;
    }
    $url_query = array();
    $hidden_fields = array();
    parse_str($this->uri->getQuery(), $query_string);
    foreach ($query_string as $key => $value) {
      if ($value != '') {
        $url_query[$key] = $key.'='.$value;
        // แอเรย์เก็บรายการ input ที่ไม่ต้องสร้าง
        if ($key !== 'search' && $key !== 'count' && $key !== 'page' && $key !== 'action') {
          $hidden_fields[$key] = '<input type="hidden" name="'.$key.'" value="'.$value.'">';
        }
      }
    }
    if (isset($this->model)) {
      // รายการ Query หลัก (AND)
      $qs = array();
      foreach ($this->defaultFilters as $array) {
        $qs[] = $array;
      }
    }
    // create HTML
    $content = array('<div class="datatable" id="'.$this->id.'">');
    // form
    $form = array();
    if ($this->perPage > 0) {
      $entries = Language::get('entries');
      $options = array();
      foreach ($this->entriesList as $c) {
        $options[$c] = $c.' '.$entries;
      }
      $form[] = $this->addFilter(array(
        'name' => 'count',
        'text' => Language::get('Show'),
        'value' => $this->perPage,
        'options' => $options
      ));
    }
    // รายการ Query กำหนดโดย User (AND)
    foreach ($this->filters as $key => $items) {
      $form[] = $this->addFilter($items);
      unset($hidden_fields[$items['name']]);
      if (!isset($items['default'])) {
        $items['default'] = '';
      }
      // ไม่ Query รายการ default
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
    if ($this->model) {
      if (!empty($qs)) {
        $this->model->andWhere($qs);
      }
    }
    // ปุ่ม Go
    if (!empty($form)) {
      $form[] = '<fieldset class=go>';
      $form[] = '<button type=submit class="button go">'.Language::get('Go').'</button>';
      $form[] = implode('', $hidden_fields);
      $form[] = '</fieldset>';
    }
    // search
    $search = self::$request->globals(array('POST', 'GET'), 'search')->text();
    if (!empty($this->searchColumns)) {
      if (!empty($search)) {
        if (isset($this->model)) {
          $sh = array();
          foreach ($this->searchColumns as $key) {
            $sh[] = array($key, 'LIKE', "%$search%");
          }
          $this->model->andWhere($sh, 'OR');
        } elseif (isset($this->datas)) {
          // filter ข้อมูลจาก array
          $this->datas = ArrayTool::filter($this->datas, $search);
        }
        $this->uri = $this->uri->withParams(array('search' => $search));
      }
      $form[] = '<fieldset class=search>';
      $form[] = '<label accesskey=f><input type=text name=search value="'.$search.'" placeholder="'.Language::get('Search').'"></label>';
      $form[] = '<button type=submit>&#xe607;</button>';
      $form[] = '<button type=submit class=clear_search>&#x78;</button>';
      $form[] = '</fieldset>';
    }
    if (!empty($form)) {
      $content[] = '<form class="table_nav" method="get" action="'.$this->uri.'">'.implode('', $form).'</form>';
    }
    if (isset($this->model)) {
      // field select
      $this->model->select($this->fields);
      // จำนวนข้อมูลทั้งหมด (Query Builder)
      $model = new \Kotchasan\Model;
      $query = $model->db()->createQuery()
        ->selectCount()
        ->from(array($this->model, 'Z'));
      if ($this->cache) {
        $query->cacheOn();
      }
      $result = $query->toArray()->execute();
      $count = empty($result) ? 0 : $result[0]['count'];
    } elseif (!empty($this->datas)) {
      // จำนวนข้อมูลทั้งหมดจาก array
      $count = sizeof($this->datas);
    } else {
      // ไม่มีข้อมูล
      $count = 0;
    }
    // การแบ่งหน้า
    if ($this->perPage > 0) {
      // หน้าที่เลือก
      $page = max(1, self::$request->globals(array('POST', 'GET'), 'page', 1)->toInt());
      // ตรวจสอบหน้าที่เลือกสูงสุด
      $totalpage = round($count / $this->perPage);
      $totalpage += ($totalpage * $this->perPage < $count) ? 1 : 0;
      $page = max(1, $page > $totalpage ? $totalpage : $page);
      $start = $this->perPage * ($page - 1);
      // คำนวณรายการที่แสดง
      $s = $start < 0 ? 0 : $start + 1;
      $e = min($count, $s + $this->perPage - 1);
    } else {
      $start = 0;
      $totalpage = 1;
      $page = 1;
      $s = 1;
      $e = $count;
      $this->perPage = 0;
    }
    // table caption
    if ($this->showCaption) {
      if (empty($search)) {
        $caption = Language::get('All :count entries, displayed :start to :end, page :page of :total pages');
      } else {
        $caption = Language::get('Search <strong>:search</strong> found :count entries, displayed :start to :end, page :page of :total pages');
      }
      $caption = str_replace(array(':search', ':count', ':start', ':end', ':page', ':total'), array($search, number_format($count), number_format($s), number_format($e), number_format($page), number_format($totalpage)), $caption);
    }
    // เรียงลำดับ
    if (!empty($this->sort)) {
      $sorts = array();
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
            $sorts[] = $sort.' '.$sortType;
          }
        }
      }
      $this->sort = implode(',', $sorts);
      if (isset($this->model)) {
        if (!empty($sorts)) {
          $this->model->order($sorts);
        }
      } elseif (!empty($this->sorts)) {
        reset($this->sorts);
        $sort = key($this->sorts);
        $this->datas = ArrayTool::sort($this->datas, $sort, $this->sorts[$sort]);
      }
    }
    if (isset($this->model)) {
      // debug Query
      //echo $this->model->toArray()->limit($this->perPage, $start)->text();
      // query ข้อมูล
      $this->datas = $this->model->toArray()->limit($this->perPage, $start)->execute();
      // รายการสุดท้าย
      $end = $this->perPage + 1;
      // รายการแรก
      $start = -1;
    } elseif (isset($this->datas)) {
      // รายการสุดท้าย
      $end = $start + $this->perPage - 1;
      // รายการแรก
      $start = $start - 2;
    } else {
      $end = 0;
    }
    if (!empty($this->headers)) {
      // property ของ ตาราง
      $prop = array();
      $c = array();
      if (isset($this->class)) {
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
      if (sizeof($c) > 0) {
        $prop[] = ' class="'.implode(' ', $c).'"';
      }
      // table
      $content[] = '<div class="tablebody"><table'.implode('', $prop).'>';
      if ($this->showCaption) {
        $content[] = '<caption>'.$caption.'</caption>';
      }
      $row = array();
      $i = 0;
      $colCount = 0;
      $colspan = 0;
      foreach ($this->headers as $key => $attributes) {
        if ($colspan === 0) {
          if (!$this->hideCheckbox && $i == $this->checkCol) {
            $row[] = '<th class="check-column"><a class="checkall icon-uncheck"></a></th>';
            $colCount++;
          }
          if ($i == $this->dragColumn) {
            $row[] = '<th></th>';
            $colCount++;
          }
          if (isset($attributes['colspan'])) {
            $colspan = $attributes['colspan'] - 1;
          }
          $row[] = $this->th($i, $key, $attributes);
          $i++;
        } else {
          $colspan--;
        }
        $colCount++;
      }
      if ($colspan === 0) {
        if (!empty($this->buttons)) {
          $row[] = $this->th($i, '', array('text' => ''));
          $colCount++;
          $i++;
        }
      } else {
        $colspan--;
      }
      if ($colspan === 0) {
        if ($this->pmButton) {
          $row[] = $this->th($i, '', array('text' => ''));
          $colCount++;
        }
      } else {
        $colspan--;
      }
      // thead
      $content[] = '<thead><tr>'.implode('', $row).'</tr></thead>';
      // tbody
      if (!empty($this->datas)) {
        $content[] = '<tbody>'.$this->tbody($start, $end).'</tbody>';
      }
      // tfoot
      $tfoot = null;
      if (isset($this->onCreateFooter)) {
        $tfoot = call_user_func($this->onCreateFooter);
      } elseif (!$this->hideCheckbox && $this->checkCol > -1) {
        $tfoot = '<tr>';
        $tfoot .= '<td colspan="'.$this->checkCol.'"></td>';
        $tfoot .= '<td class="check-column"><a class="checkall icon-uncheck"></a></td>';
        $tfoot .= '<td colspan="'.($colCount - $this->checkCol - 1).'"></td>';
        $tfoot .= '</tr>';
      }
      if (!empty($tfoot)) {
        $content[] = '<tfoot>'.$tfoot.'</tfoot>';
      }
      $content[] = '</table></div>';
      $table_nav = array();
      foreach ($this->actions as $item) {
        $table_nav[] = $this->addAction($item);
      }
      if (!empty($this->addNew)) {
        $prop = array();
        foreach ($this->addNew as $k => $v) {
          if ($k != 'text') {
            $prop[$k] = $k.'="'.$v.'"';
          }
        }
        if (preg_match('/^((.*)\s+)?(icon-[a-z0-9\-_]+)(\s+(.*))?$/', $this->addNew['class'], $match)) {
          $prop['class'] = 'class="'.trim($match[2].' '.(isset($match[5]) ? $match[5] : '')).'"';
          $table_nav[] = '<a '.implode(' ', $prop).'><span class="'.$match[3].'">'.(isset($this->addNew['text']) ? $this->addNew['text'] : '').'</span></a>';
        } else {
          $table_nav[] = '<a '.implode(' ', $prop).'>'.(isset($this->addNew['text']) ? $this->addNew['text'] : '').'</a>';
        }
      }
      if (!empty($table_nav)) {
        $content[] = '<div class="table_nav action">'.implode('', $table_nav).'</div>';
      }
      // แบ่งหน้า
      if ($this->perPage > 0) {
        $content[] = '<div class="splitpage">'.$this->uri->pagination($totalpage, $page).'</div>';
      }
    }
    $content[] = '</div>';
    if ($this->enableJavascript) {
      $script = array(
        'page' => $page,
        'search' => $search,
        'sort' => $this->sort,
        'action' => $this->action,
        'actionCallback' => $this->actionCallback,
        'actionConfirm' => $this->actionConfirm,
        'onBeforeDelete' => $this->onBeforeDelete,
        'onInitRow' => $this->onInitRow,
        'onAddRow' => $this->onAddRow,
        'pmButton' => $this->pmButton,
        'dragColumn' => $this->dragColumn
      );
      $this->javascript[] = 'var table = new GTable("'.$this->id.'", '.json_encode($script).');';
    }
    if (!empty($this->javascript)) {
      $content[] = "<script>\n".implode("\n", $this->javascript)."\n</script>";
    }
    return implode("\n", $content);
  }

  /**
   * render tbody
   *
   * @param int $start ข้อมูลเริ่มต้น (เริ่มที่ 1)
   * @return string
   */
  public function tbody($start, $end)
  {
    $row = array();
    $n = 0;
    foreach ($this->datas as $o => $items) {
      if ($this->perPage <= 0 || ($n > $start && $n < $end)) {
        $src_items = $items;
        // id ของข้อมูล
        $id = isset($items[$this->primaryKey]) ? $items[$this->primaryKey] : $o;
        $prop = (object)array(
            'id' => $this->id.'_'.$id
        );
        if (isset($this->onRow)) {
          $items = call_user_func($this->onRow, $items, $o, $prop);
        }
        if (isset($this->dragColumn)) {
          $prop->class = (empty($prop->class) ? 'sort' : $prop->class.' sort');
        }
        // แถว
        $p = array();
        foreach ($prop as $k => $v) {
          $p[] = $k.'="'.$v.'"';
        }
        $row[] = '<tr '.implode(' ', $p).'>';
        // แสดงผลข้อมูล
        $i = 0;
        foreach ($this->headers as $field => $attributes) {
          if (!empty($field) && !in_array($field, $this->hideColumns)) {
            if (!$this->hideCheckbox && $i == $this->checkCol) {
              $row[] = '<td headers="r'.$id.'" class="check-column"><a id="check_'.$id.'" class="icon-uncheck"></a></td>';
            }
            if ($i == $this->dragColumn) {
              $row[] = '<td class=center><a id="move_'.$id.'" title="'.Language::get('Drag and drop to reorder').'" class="icon-move"></a></td>';
            }
            $properties = isset($this->cols[$field]) ? $this->cols[$field] : array();
            $text = isset($items[$field]) ? $items[$field] : '';
            $th = isset($attributes['text']) ? $attributes['text'] : $field;
            $row[] = $this->td($id, $i, $properties, $text, $th);
            $i++;
          }
        }
        if (!empty($this->buttons)) {
          $buttons = array();
          foreach ($this->buttons as $btn => $attributes) {
            if (isset($this->onCreateButton)) {
              $attributes = call_user_func($this->onCreateButton, $btn, $attributes, $items);
            }
            if ($attributes && $attributes !== false) {
              $buttons[] = $this->button($btn, $attributes);
            }
          }
          if (!empty($buttons)) {
            $module_id = isset($items['module_id']) ? $items['module_id'] : 0;
            $patt = array();
            $replace = array();
            $keys = array_keys($src_items);
            rsort($keys);
            foreach ($keys as $k) {
              if (!is_array($src_items[$k])) {
                $patt[] = ":$k";
                $replace[] = $src_items[$k];
              }
            }
            $prop = array('class' => 'buttons');
            if (isset($this->cols['buttons']) && isset($this->cols['buttons']['class'])) {
              $prop = array('class' => $this->cols['buttons']['class'].' buttons');
            }
            $row[] = str_replace($patt, $replace, $this->td($id, $i, $prop, implode('', $buttons), ''));
          } else {
            $row[] = $this->td($id, $i, array(), '', '');
          }
        }
        if ($this->pmButton) {
          $row[] = '<td class="icons"><div><a class="icon-plus" title="'.Language::get('Add').'"></a><a class="icon-minus" title="'.Language::get('Remove').'"></a></div></td>';
        }
        $row[] = '</tr>';
      }
      $n++;
    }
    return implode("\n", $row);
  }

  /**
   * render th
   *
   * @param int $i ลำดับคอลัมน์
   * @param string $column ชื่อคอลัมน์
   * @param array $properties properties ของ th
   * @return string
   */
  private function th($i, $column, $properties)
  {
    $c = array();
    $c['id'] = 'id="c'.$i.'"';
    if (!empty($properties['sort'])) {
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
   * render td
   *
   * @param int $id id ของ แถว
   * @param int $i ลำดับคอลัมน์
   * @param array $properties ชื่อคอลัมน์
   * @param string $text ข้อความใน td
   * @return string
   */
  private function td($id, $i, $properties, $text, $th)
  {
    $c = array('data-text' => 'data-text="'.$th.'"');
    foreach ($properties as $key => $value) {
      $c[$key] = $key.'="'.$value.'"';
    }
    $c = implode(' ', $c);
    if ($i == 0) {
      $c .= ' id="r'.$id.'" headers="c'.$i.'"';
      return '<th '.$c.'>'.$text.'</th>';
    } else {
      $c .= ' headers="c'.$i.' r'.$id.'"';
      return '<td '.$c.'>'.$text.'</td>';
    }
  }

  /**
   * render button
   *
   * @param array $properties properties ของ button
   * @return string
   */
  private function button($btn, $properties)
  {
    $prop = array();
    foreach ($properties as $key => $value) {
      if ($key === 'id') {
        $prop[$key] = $key.'="'.$btn.'_'.$value.'"';
      } elseif ($key !== 'text') {
        $prop[$key] = $key.'="'.$value.'"';
      }
    }
    if (!empty($properties['class']) && preg_match('/(.*)\s?(icon\-[a-z0-9\-_]+)($|\s(.*))/', $properties['class'], $match)) {
      $class = array();
      foreach (array(1, 4)as $i) {
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
        return '<a '.implode(' ', $prop).'><span class="'.$match[2].' button_w_text"><span class=mobile>'.$properties['text'].'</span></span></a>';
      }
    } else {
      return '<a'.(empty($prop) ? '' : ' '.implode(' ', $prop)).'></a>';
    }
  }

  /**
   * สร้าง select หรือ button ด้านล่างตาราง (actions)
   *
   * @param array $item
   * @return string
   */
  private function addAction($item)
  {
    if (preg_match('/^((.*)\s+)?(icon-[a-z0-9\-_]+)(\s+(.*))?$/', $item['class'], $match)) {
      $match[2] = trim($match[2].' '.(isset($match[5]) ? $match[5] : ''));
    }
    if (isset($item['options'])) {
      // select
      $rows = array();
      foreach ($item['options'] as $key => $text) {
        $rows[] = '<option value="'.$key.'">'.$text.'</option>';
      }
      return '<fieldset><select id="'.$item['id'].'">'.implode('', $rows).'</select><label for="'.$item['id'].'" class="button '.$item['class'].' action"><span>'.$item['text'].'</span></label></fieldset>';
    } else {
      // link, button
      $prop = array();
      if (empty($match[3])) {
        $text = $item['text'];
        $prop[] = 'class="'.$item['class'].'"';
      } else {
        $text = '<span class="'.$match[3].'">'.$item['text'].'</span>';
        $prop[] = 'class="'.$match[2].'"';
      }
      foreach ($item as $k => $v) {
        if ($k != 'class' && $k != 'text') {
          $prop[] = $k.'="'.$v.'"';
        }
      }
      if (isset($item['href'])) {
        // link
        return '<a '.implode(' ', $prop).'>'.$text.'</a>';
      } else {
        // button
        return '<button '.implode(' ', $prop).' type="button">'.$text.'</button>';
      }
    }
  }

  /**
   * สร้าง select ด้านบนตาราง (filters)
   *
   * @param array $item
   * @return string
   */
  private function addFilter($item)
  {
    $row = '<fieldset><label>'.$item['text'].' <select name="'.$item['name'].'">';
    if (!empty($item['options'])) {
      foreach ($item['options'] as $key => $text) {
        $sel = (string)$key == $item['value'] ? ' selected' : '';
        $row .= '<option value="'.$key.'"'.$sel.'>'.$text.'</option>';
      }
    }
    $row .= '</select></label></fieldset>';
    return $row;
  }
}